<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Service;

use OCA\HomeExpenses\AppInfo\Application;
use OCA\HomeExpenses\Db\Invoice;
use OCA\HomeExpenses\Db\InvoiceLine;
use OCA\HomeExpenses\Db\InvoiceLineMapper;
use OCA\HomeExpenses\Db\InvoiceMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Importa faturas a partir de um PDF.
 *
 * A leitura do PDF e feita por um servico externo que descodifica o QR fiscal
 * ATCUD e extrai as linhas. Isso fica fora da app de proposito: e trabalho de
 * OCR e de renderizacao de PDF, que nao tem lugar dentro de um processo web do
 * Nextcloud, e que serve qualquer fatura portuguesa -- nao so as da luz.
 *
 * A chamada e feita do lado do servidor, e nao do navegador, para que a
 * importacao seja um endpoint como os outros: um agente importa uma fatura
 * exatamente como a interface o faz.
 */
class InvoiceService {
	private const DEFAULT_ENDPOINT = 'https://qrcode.appa8.com';
	private const CONFIG_KEY = 'invoice_reader_url';

	public function __construct(
		private InvoiceMapper $invoices,
		private InvoiceLineMapper $lines,
		private InvoiceParser $parser,
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function readerUrl(): string {
		return rtrim(
			$this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, self::DEFAULT_ENDPOINT),
			'/'
		);
	}

	/**
	 * Le um PDF e guarda os documentos fiscais que ele contiver.
	 *
	 * Um PDF traz mais do que um documento: a eletricidade e a Contribuicao
	 * Audiovisual chegam como faturas separadas. Devolve-se o que foi criado,
	 * o que ja existia e os avisos -- um aviso nao impede a importacao, mas o
	 * utilizador tem de o ver.
	 *
	 * @return array{created: list<array>, existing: list<array>, warnings: list<string>}
	 * @throws InvoiceImportException
	 */
	public function import(string $userId, string $contents, string $filename, ?int $meterId = null): array {
		$payload = $this->read($contents, $filename);
		$parsed = $this->parser->parse($payload);

		if ($parsed['documents'] === []) {
			throw new InvoiceImportException(
				'Nao se encontrou nenhum documento fiscal com QR code neste ficheiro. '
				. 'A fatura tem de ser o PDF original do fornecedor, nao uma fotografia nem uma impressao.'
			);
		}

		$created = [];
		$existing = [];

		foreach ($parsed['documents'] as $doc) {
			if ($doc['atcud'] === '') {
				$parsed['warnings'][] = 'Um documento sem ATCUD foi ignorado: nao ha como evitar duplica-lo.';
				continue;
			}

			$already = $this->invoices->findByAtcud($doc['atcud'], $userId);
			if ($already !== null) {
				$existing[] = $already->jsonSerialize();
				continue;
			}

			$created[] = $this->persist($userId, $doc, $filename, $meterId)->jsonSerialize();
		}

		return [
			'created' => $created,
			'existing' => $existing,
			'warnings' => array_values($parsed['warnings']),
		];
	}

	private function persist(string $userId, array $doc, string $filename, ?int $meterId): Invoice {
		$invoice = new Invoice();
		$invoice->setUserId($userId);
		$invoice->setMeterId($meterId);
		$invoice->setSupplier($doc['supplier'] !== '' ? mb_substr($doc['supplier'], 0, 128) : null);
		$invoice->setDocType($doc['docType'] !== '' ? $doc['docType'] : null);
		$invoice->setDocNumber($doc['docNumber'] !== '' ? mb_substr($doc['docNumber'], 0, 128) : null);
		$invoice->setAtcud($doc['atcud']);
		$invoice->setIssuedAt($this->date($doc['issuedAt']));
		$invoice->setPeriodFrom($this->date($doc['periodFrom']));
		$invoice->setPeriodTo($this->date($doc['periodTo']));
		$invoice->setCurrency('EUR');
		$invoice->setTotalNet($doc['totalNet']);
		$invoice->setTotalVat($doc['totalVat']);
		$invoice->setTotalGross($doc['totalGross']);
		$invoice->setVerified($doc['verified']);
		$invoice->setSourceName(mb_substr($filename, 0, 255));
		$invoice->setCreatedAt(new \DateTimeImmutable());

		$invoice = $this->invoices->insert($invoice);

		foreach ($doc['lines'] as $row) {
			$line = new InvoiceLine();
			$line->setInvoiceId($invoice->getId());
			$line->setDescription(mb_substr($row['description'], 0, 255));
			$line->setKind($row['kind']);
			$line->setRegisterCode($row['registerCode']);
			$line->setIsEstimate($row['isEstimate']);
			$line->setPeriodFrom($this->date($row['periodFrom']));
			$line->setPeriodTo($this->date($row['periodTo']));
			$line->setQuantity($row['quantity']);
			$line->setUnitPrice($row['unitPrice']);
			$line->setDiscount($row['discount']);
			$line->setVatRate($row['vatRate']);
			$line->setTotalNet($row['totalNet']);
			$line->setPage($row['page']);
			$this->lines->insert($line);
		}

		return $invoice;
	}

	/**
	 * @return array{invoice: array, lines: list<array>, consumption: list<array>}
	 * @throws DoesNotExistException
	 */
	public function detail(int $id, string $userId): array {
		$invoice = $this->invoices->find($id, $userId);
		$lines = $this->lines->findAllForInvoice($id);

		return [
			'invoice' => $invoice->jsonSerialize(),
			'lines' => array_map(static fn (InvoiceLine $l) => $l->jsonSerialize(), $lines),
			'consumption' => $this->consumptionOf($lines),
		];
	}

	/** @return list<array> */
	public function findAll(string $userId, ?int $meterId = null): array {
		$invoices = $this->invoices->findAll($userId, $meterId);
		$ids = array_map(static fn (Invoice $i) => $i->getId(), $invoices);
		$byInvoice = $this->lines->findAllForInvoices($ids);

		$out = [];
		foreach ($invoices as $invoice) {
			$lines = $byInvoice[$invoice->getId()] ?? [];
			$out[] = $invoice->jsonSerialize() + [
				'consumption' => $this->consumptionOf($lines),
				'lineCount' => count($lines),
			];
		}

		return $out;
	}

	/** @throws DoesNotExistException */
	public function delete(int $id, string $userId): void {
		$invoice = $this->invoices->find($id, $userId);
		$this->lines->deleteForInvoice($invoice->getId());
		$this->invoices->delete($invoice);
	}

	/** @throws DoesNotExistException */
	public function assignMeter(int $id, string $userId, ?int $meterId): array {
		$invoice = $this->invoices->find($id, $userId);
		$invoice->setMeterId($meterId);
		return $this->invoices->update($invoice)->jsonSerialize();
	}

	/**
	 * Soma as linhas de energia por (escalao, intervalo).
	 *
	 * E aqui que se desfaz a divisao por taxa de IVA que a fatura faz: as duas
	 * linhas do mesmo escalao no mesmo intervalo voltam a ser um numero so.
	 * Guardam-se as linhas como vieram e soma-se ao ler, para a divisao
	 * original continuar visivel.
	 *
	 * @param InvoiceLine[] $lines
	 * @return list<array>
	 */
	private function consumptionOf(array $lines): array {
		$byKey = [];
		foreach ($lines as $line) {
			if ($line->getKind() !== InvoiceParser::KIND_ENERGY) {
				continue;
			}
			$code = $line->getRegisterCode();
			$from = $line->getPeriodFrom();
			if ($code === null || $from === null || $line->getQuantity() === null) {
				continue;
			}

			$to = $line->getPeriodTo();
			$key = $from->format('Y-m-d') . '|' . ($to?->format('Y-m-d') ?? '') . '|' . $code;

			if (!isset($byKey[$key])) {
				$byKey[$key] = [
					'periodFrom' => $from->format('Y-m-d'),
					'periodTo' => $to?->format('Y-m-d'),
					'registerCode' => $code,
					'quantity' => 0.0,
					'net' => 0.0,
					'isEstimate' => false,
					'prices' => [],
				];
			}

			$byKey[$key]['quantity'] += $line->getQuantity();
			$byKey[$key]['net'] += $line->getTotalNet() ?? 0.0;
			$byKey[$key]['isEstimate'] = $byKey[$key]['isEstimate'] || $line->getIsEstimate();
			if ($line->getUnitPrice() !== null) {
				$byKey[$key]['prices'][] = $line->getUnitPrice();
			}
		}

		$out = [];
		foreach ($byKey as $entry) {
			$prices = array_values(array_unique($entry['prices']));
			$out[] = [
				'periodFrom' => $entry['periodFrom'],
				'periodTo' => $entry['periodTo'],
				'registerCode' => $entry['registerCode'],
				'quantity' => round($entry['quantity'], 3),
				'net' => round($entry['net'], 2),
				'isEstimate' => $entry['isEstimate'],
				'unitPrice' => count($prices) === 1 ? $prices[0] : null,
			];
		}

		usort($out, static fn (array $a, array $b) => [$a['periodFrom'], $a['registerCode']]
			<=> [$b['periodFrom'], $b['registerCode']]);

		return $out;
	}

	/**
	 * @throws InvoiceImportException
	 */
	private function read(string $contents, string $filename): array {
		$url = $this->readerUrl() . '/api/v1/document/full';

		try {
			$response = $this->clientService->newClient()->post($url, [
				'multipart' => [[
					'name' => 'file',
					'contents' => $contents,
					'filename' => $filename,
				]],
				// A leitura renderiza as paginas e procura QR codes; num PDF
				// de varias paginas leva o seu tempo.
				'timeout' => 180,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('Falhou a leitura da fatura', ['exception' => $e, 'url' => $url]);
			throw new InvoiceImportException(
				'Nao foi possivel contactar o servico de leitura de faturas (' . $this->readerUrl() . ').'
			);
		}

		$decoded = json_decode((string)$response->getBody(), true);
		if (!is_array($decoded) || !isset($decoded['invoice'])) {
			throw new InvoiceImportException('O servico de leitura devolveu uma resposta que nao se percebeu.');
		}

		return $decoded;
	}

	private function date(?string $value): ?\DateTimeImmutable {
		if ($value === null || $value === '') {
			return null;
		}
		try {
			return new \DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}
	}
}
