<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Service;

/**
 * Transforma a resposta do servico de leitura de faturas em linhas classificadas.
 *
 * Consome o **esquema v1** de /api/v1/document/extract?linhas=true. Nesse
 * esquema cada documento traz as suas proprias linhas (nao ha que as cruzar
 * por ATCUD), e traz uma conferencia das linhas contra o total declarado no
 * QR fiscal -- `lines_match` com `lines_sum`. Isso e o que permite nao ter de
 * confiar na extracao: ela diz se fecha.
 *
 * Nao tem dependencias e nao toca na base de dados: recebe o array devolvido
 * por /api/v1/document/full e devolve estrutura. E assim porque esta e a parte
 * que mais facilmente erra em silencio -- um "Vazio" lido como "Fora vazio" mete
 * kWh no escalao errado e a app passa a mentir com confianca. Sendo pura, testa-se
 * contra faturas reais sem servidor nenhum a volta.
 *
 * Tres coisas que uma fatura da EDP faz e que nao sao obvias:
 *
 *  1. As linhas vem partidas por taxa de IVA, nao por registo. "Consumo real
 *     Vazio 20 set a 28 set" aparece duas vezes -- 21 kWh a 6% e 22 kWh a 23% --
 *     porque parte do consumo leva taxa reduzida. Tratar cada linha como um
 *     registo da metade do consumo. Tem de se somar por (registo, periodo).
 *
 *  2. Um periodo de faturacao pode conter mais do que uma tarifa. Numa mudanca
 *     de Simples para bi-horario a meio do mes, a mesma fatura traz linhas
 *     "Simples" de um intervalo e "Vazio"/"Fora vazio" de outro.
 *
 *  3. O consumo pode ser real ou estimado, e a fatura di-lo na descricao
 *     ("Consumo real" vs "Consumo estimado"). Misturar os dois sem distincao
 *     estraga qualquer media.
 */
class InvoiceParser {
	/**
	 * Abreviaturas de mes como a EDP as escreve nas descricoes das linhas.
	 */
	private const MONTHS = [
		'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4, 'mai' => 5, 'jun' => 6,
		'jul' => 7, 'ago' => 8, 'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12,
	];

	/**
	 * Como cada escalao aparece escrito, e para que codigo de registo mapeia.
	 *
	 * A ordem importa, e e a unica coisa que impede o erro mais caro deste
	 * ficheiro. Tres rotulos contem a palavra "vazio" e significam coisas
	 * diferentes: "Fora vazio" e o escalao caro, "Super Vazio" e um escalao a
	 * parte dos tarifarios tetra-horarios, e "Vazio" e o barato. Testado pela
	 * ordem errada, o consumo caro ia para o escalao barato e o total ficava
	 * plausivel -- errado, mas plausivel, que e o pior dos casos.
	 */
	private const REGISTERS = [
		'fora vazio' => 'FV',
		'fora de vazio' => 'FV',
		'super vazio' => 'SV',
		'vazio normal' => 'V',
		'vazio' => 'V',
		'cheias' => 'C',
		'ponta' => 'P',
		'simples' => 'TOTAL',
	];

	public const KIND_ENERGY = 'energy';
	public const KIND_POWER = 'power';
	public const KIND_NETWORK = 'network';
	public const KIND_LEVY = 'levy';
	public const KIND_OTHER = 'other';

	/**
	 * @param array $full resposta de /api/v1/document/full
	 * @return array{documents: list<array>, warnings: list<string>}
	 */
	public function parse(array $full): array {
		$documents = [];
		$warnings = [];

		// Avisos do proprio servico de extracao, ao nivel do ficheiro.
		foreach ($full['file']['warnings'] ?? [] as $warning) {
			$warnings[] = (string)$warning;
		}

		foreach ($full['documents'] ?? [] as $doc) {
			$header = $doc['document'] ?? [];
			$verification = $doc['verification'] ?? [];

			$lines = [];
			foreach ($doc['lines'] ?? [] as $row) {
				$lines[] = $this->classify($row);
			}

			// Tres conferencias, e e preciso que as tres fechem. A terceira --
			// `lines_match` -- e a que importa aqui: diz que a soma das linhas
			// extraidas bate com o total que o QR fiscal declara. Sem ela, as
			// linhas podiam estar incompletas sem nada o denunciar.
			$verified = ($verification['totals_match'] ?? false) === true
				&& ($verification['taxes_match'] ?? false) === true
				&& ($verification['lines_match'] ?? false) === true;

			if (!$verified) {
				$warnings[] = sprintf(
					'O documento %s nao fecha nas contas (%s) e nao sera usado em calculos.',
					$header['number'] ?? ($header['atcud'] ?? '?'),
					$this->whyNot($verification)
				);
			}

			foreach ($doc['warnings'] ?? [] as $warning) {
				$warnings[] = (string)$warning;
			}

			$documents[] = [
				'atcud' => (string)($header['atcud'] ?? ''),
				'supplier' => (string)($header['seller']['name'] ?? ''),
				'docType' => (string)($header['type'] ?? ''),
				'docNumber' => (string)($header['number'] ?? ''),
				'issuedAt' => (string)($header['date'] ?? ''),
				'totalNet' => $this->float($doc['totals']['taxable'] ?? null),
				'totalVat' => $this->float($doc['totals']['tax_total'] ?? null),
				'totalGross' => $this->float($doc['totals']['gross'] ?? null),
				'verified' => $verified,
				'periodFrom' => $this->earliest($lines),
				'periodTo' => $this->latest($lines),
				// A referencia e a data-limite sao do ficheiro, nao do
				// documento: um PDF com duas faturas tem um so pagamento.
				'dueDate' => (string)($full['file']['payment']['due_date'] ?? ''),
				'paymentReference' => (string)($full['file']['payment']['reference'] ?? ''),
				'lines' => $lines,
				'consumption' => $this->aggregate($lines),
			];
		}

		// Qualquer linha de energia que nao chegue aos totais tem de ser dita.
		// Excluir em silencio e o modo de falha mais perigoso desta classe:
		// os numeros continuam a parecer bem, so que a menos.
		foreach ($documents as $doc) {
			foreach ($doc['lines'] as $line) {
				if ($line['kind'] !== self::KIND_ENERGY) {
					continue;
				}
				$reason = match (true) {
					$line['registerCode'] === null => 'nao se reconheceu o escalao',
					$line['periodFrom'] === null => 'nao traz intervalo de datas',
					$line['quantity'] === null => 'nao traz quantidade',
					default => null,
				};
				if ($reason !== null) {
					$warnings[] = sprintf(
						'Linha de energia fora dos totais (%s): "%s"%s.',
						$reason,
						$line['description'],
						$line['quantity'] !== null ? sprintf(' — %s unidades', $line['quantity']) : ''
					);
				}
			}
		}

		return ['documents' => $documents, 'warnings' => $warnings];
	}

	/**
	 * Diz qual das conferencias falhou, para o aviso nao ser so "nao fecha".
	 */
	private function whyNot(array $verification): string {
		$failed = [];
		if (($verification['totals_match'] ?? false) !== true) {
			$failed[] = 'o total nao bate com o QR';
		}
		if (($verification['taxes_match'] ?? false) !== true) {
			$failed[] = 'o IVA nao bate';
		}
		if (($verification['lines_match'] ?? false) !== true) {
			$failed[] = 'a soma das linhas nao bate com o total';
		}
		return $failed === [] ? 'razao desconhecida' : implode(', ', $failed);
	}

	/**
	 * Classifica uma linha: que tipo de encargo e, que escalao, que intervalo.
	 */
	private function classify(array $row): array {
		$description = (string)($row['description'] ?? '');
		$lower = $this->fold($description);

		$kind = self::KIND_OTHER;
		$registerCode = null;
		$isEstimate = false;

		if (str_contains($lower, 'consumo')) {
			$kind = self::KIND_ENERGY;
			// "Consumo estimado" existe e tem de se distinguir de "Consumo real":
			// uma estimativa da distribuidora misturada com leituras reais
			// estraga medias e previsoes.
			$isEstimate = str_contains($lower, 'estimado');
			$registerCode = $this->register($lower);
		} elseif (str_contains($lower, 'energia')) {
			$kind = self::KIND_ENERGY;
			$registerCode = $this->register($lower);
		} elseif (str_contains($lower, 'potencia')) {
			$kind = self::KIND_POWER;
		} elseif (str_contains($lower, 'redes') || str_contains($lower, 'acesso')) {
			$kind = self::KIND_NETWORK;
		} elseif ($this->isLevy($lower)) {
			$kind = self::KIND_LEVY;
		}

		[$from, $to] = $this->period($description);

		return [
			'description' => $description,
			'kind' => $kind,
			'registerCode' => $registerCode,
			'isEstimate' => $isEstimate,
			'periodFrom' => $from,
			'periodTo' => $to,
			'quantity' => $this->float($row['quantity'] ?? null),
			'unit' => isset($row['unit']) ? (string)$row['unit'] : null,
			'unitPrice' => $this->float($row['unit_price'] ?? null),
			'discount' => $this->float($row['discount'] ?? null),
			'vatRate' => $this->vatPercent($row['vat_rate'] ?? null),
			'totalNet' => $this->float($row['net'] ?? null),
			'page' => (int)($row['raw']['page'] ?? $row['page'] ?? 0),
		];
	}

	private function register(string $lower): ?string {
		foreach (self::REGISTERS as $needle => $code) {
			if (str_contains($lower, $needle)) {
				return $code;
			}
		}
		return null;
	}

	private function isLevy(string $lower): bool {
		foreach (['iec', 'dgeg', 'audiovisual', 'imposto'] as $needle) {
			if (str_contains($lower, $needle)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Le "29 ago a 19 set 2026" da descricao.
	 *
	 * O ano aparece uma so vez, no fim. Quando o intervalo atravessa o
	 * fim do ano -- "20 dez a 15 jan 2027" -- o mes inicial e maior do que o
	 * final, e o inicio pertence ao ano anterior. Sem isto, um periodo de
	 * dezembro a janeiro daria -346 dias e o consumo por dia saia negativo.
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	private function period(string $description): array {
		$months = implode('|', array_keys(self::MONTHS));
		$pattern = '/(\d{1,2})\s+(' . $months . ')\w*\s+a\s+(\d{1,2})\s+(' . $months . ')\w*\s+(\d{4})/u';

		if (preg_match($pattern, $this->fold($description), $m) !== 1) {
			return [null, null];
		}

		$startMonth = self::MONTHS[$m[2]];
		$endMonth = self::MONTHS[$m[4]];
		$endYear = (int)$m[5];
		$startYear = $startMonth > $endMonth ? $endYear - 1 : $endYear;

		return [
			sprintf('%04d-%02d-%02d', $startYear, $startMonth, (int)$m[1]),
			sprintf('%04d-%02d-%02d', $endYear, $endMonth, (int)$m[3]),
		];
	}

	/**
	 * Soma o consumo por (escalao, periodo).
	 *
	 * E aqui que se desfaz a divisao por taxa de IVA: as duas linhas de
	 * "Consumo real Vazio" do mesmo intervalo voltam a ser um unico numero.
	 */
	private function aggregate(array $lines): array {
		$byKey = [];
		foreach ($lines as $line) {
			if ($line['kind'] !== self::KIND_ENERGY || $line['registerCode'] === null) {
				continue;
			}
			if ($line['periodFrom'] === null || $line['quantity'] === null) {
				continue;
			}

			$key = $line['periodFrom'] . '|' . $line['periodTo'] . '|' . $line['registerCode'];
			if (!isset($byKey[$key])) {
				$byKey[$key] = [
					'periodFrom' => $line['periodFrom'],
					'periodTo' => $line['periodTo'],
					'registerCode' => $line['registerCode'],
					'quantity' => 0.0,
					'net' => 0.0,
					'isEstimate' => false,
					'unitPrices' => [],
				];
			}

			$byKey[$key]['quantity'] += $line['quantity'];
			$byKey[$key]['net'] += $line['totalNet'] ?? 0.0;
			// Basta uma das parcelas ser estimada para o agregado nao ser uma
			// leitura real.
			$byKey[$key]['isEstimate'] = $byKey[$key]['isEstimate'] || $line['isEstimate'];
			if ($line['unitPrice'] !== null) {
				$byKey[$key]['unitPrices'][] = $line['unitPrice'];
			}
		}

		$out = [];
		foreach ($byKey as $entry) {
			$prices = array_values(array_unique($entry['unitPrices']));
			$out[] = [
				'periodFrom' => $entry['periodFrom'],
				'periodTo' => $entry['periodTo'],
				'registerCode' => $entry['registerCode'],
				'quantity' => round($entry['quantity'], 3),
				'net' => round($entry['net'], 2),
				'isEstimate' => $entry['isEstimate'],
				// Se o mesmo escalao no mesmo intervalo trouxer precos
				// diferentes, nao ha "o preco" -- devolve-se null em vez de
				// escolher um a sorte.
				'unitPrice' => count($prices) === 1 ? $prices[0] : null,
			];
		}

		usort($out, static fn (array $a, array $b) => [$a['periodFrom'], $a['registerCode']]
			<=> [$b['periodFrom'], $b['registerCode']]);

		return $out;
	}

	private function earliest(array $lines): ?string {
		$dates = array_filter(array_column($lines, 'periodFrom'));
		return $dates === [] ? null : min($dates);
	}

	private function latest(array $lines): ?string {
		$dates = array_filter(array_column($lines, 'periodTo'));
		return $dates === [] ? null : max($dates);
	}

	/**
	 * Minusculas sem acentos, para comparar descricoes sem depender de como
	 * vem acentuado ("Potência" e "Potencia" sao a mesma coisa).
	 */
	private function fold(string $value): string {
		$value = mb_strtolower($value, 'UTF-8');
		return strtr($value, [
			'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
			'é' => 'e', 'ê' => 'e', 'í' => 'i',
			'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
			'ú' => 'u', 'ç' => 'c',
		]);
	}

	/**
	 * Normaliza a taxa de IVA para percentagem.
	 *
	 * O esquema v1 do extractor da a taxa em fraccao (0.06, 0.23); outras
	 * respostas dao-na em percentagem (6, 23). Guarda-se sempre em
	 * percentagem, que e como se le numa fatura -- e, mais importante, para
	 * nao haver codigo a jusante a ter de adivinhar qual das duas recebeu.
	 * Um modelo de custo que tome 0.23 por 23 trata tudo como taxa reduzida e
	 * subestima o IVA em dois tercos, sem nada falhar.
	 *
	 * Nenhuma taxa portuguesa cai entre 1% e 100%, por isso um valor <= 1
	 * (diferente de zero) so pode ser fraccao.
	 */
	private function vatPercent(mixed $value): ?float {
		$rate = $this->float($value);
		if ($rate === null) {
			return null;
		}
		return $rate > 0.0 && $rate <= 1.0 ? round($rate * 100, 2) : $rate;
	}

	private function float(mixed $value): ?float {
		return $value === null || $value === '' ? null : (float)$value;
	}
}
