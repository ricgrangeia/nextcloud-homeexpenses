<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Controller;

use OCA\HomeExpenses\Service\ForecastService;
use OCA\HomeExpenses\Service\GasService;
use OCA\HomeExpenses\Service\ImportQueue;
use OCA\HomeExpenses\Service\InvoiceService;
use OCA\HomeExpenses\Service\MeterService;
use OCA\HomeExpenses\Service\ReadingService;
use OCA\HomeExpenses\Service\TariffService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A UNICA superficie de API da app -- usada tanto pela interface web como por
 * clientes externos (um agente de IA com uma app password).
 *
 * As outras apps desta familia tem dois conjuntos de controladores, um para o
 * SPA e outro para a API, com as mesmas operacoes escritas duas vezes. Aqui
 * nao: o que o agente consegue fazer e exatamente o que a UI faz, e nao ha
 * como os dois divergirem com o tempo.
 *
 * Autenticacao: Basic Auth com uma app password do Nextcloud, e o cabecalho
 * `OCS-APIRequest: true` em todos os pedidos.
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private MeterService $meterService,
		private ReadingService $readingService,
		private GasService $gasService,
		private TariffService $tariffService,
		private InvoiceService $invoiceService,
		private ImportQueue $importQueue,
		private ForecastService $forecastService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	private function getUserId(): string {
		return $this->userSession->getUser()->getUID();
	}

	private function notFound(): DataResponse {
		return new DataResponse(['message' => 'Not found'], Http::STATUS_NOT_FOUND);
	}

	private function badRequest(string $message): DataResponse {
		return new DataResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
	}

	/** @throws \Exception se a data nao for interpretavel */
	private function date(?string $value): ?\DateTimeImmutable {
		if ($value === null || trim($value) === '') {
			return null;
		}
		return new \DateTimeImmutable($value);
	}

	private function today(): \DateTimeImmutable {
		return new \DateTimeImmutable('today');
	}

	// --- Descoberta -------------------------------------------------------

	#[PublicPage]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/help')]
	public function help(): DataResponse {
		return new DataResponse([
			'description' =>
				'Consumos de Casa regista leituras dos contadores de eletricidade e agua, e garrafas de gas '
				. 'desde que entram ate ficarem vazias. Nao e uma app de contas a pagar -- para faturas com '
				. 'data-limite e estado de pagamento, a app certa e "bills" (Contas). Esta mede CONSUMO.',
			'authentication' => [
				'method' => 'HTTP Basic Auth com uma app password do Nextcloud (NAO a password da conta)',
				'howToGet' => 'Nextcloud: Definicoes > Seguranca > Dispositivos e sessoes > "Criar nova app password". O valor so e mostrado uma vez.',
				'requiredHeader' => 'OCS-APIRequest: true (em todos os pedidos; convencao da API OCS do Nextcloud)',
			],
			'concepts' => [
				'meter' => 'Um contador. Tem N registos (registers). Agua tem um so (TOTAL); eletricidade tem um, dois (V, FV) ou tres (V, C, P).',
				'register' => 'Um escalao do mostrador. V=Vazio, C=Cheias, P=Ponta, FV=Fora de Vazio, TOTAL=registo unico.',
				'reading' => 'Um evento de leitura: TODOS os registos do contador lidos na mesma data. Os valores vao por codigo, nao por id.',
				'readAt' => 'A data que esta no contador, nao a data em que foi lancada na app. Lancar hoje uma leitura de ha um mes tem de usar a data de ha um mes, senao o consumo por dia fica errado.',
				'isEstimate' => 'Marca leituras estimadas pela distribuidora. Misturadas com leituras reais sem distincao, estragam qualquer media.',
				'digits' => 'Numero de digitos do mostrador. So com ele se consegue corrigir a volta ao zero (99999 -> 0). Sem ele, uma leitura que desce e assinalada como anomalia.',
				'gasCycle' => 'Uma garrafa, da data em que foi instalada ate a data em que acabou (removedAt). Enquanto removedAt for null, esta em uso.',
				'tareKg' => 'Peso da garrafa VAZIA, gravado na gola. Varia entre garrafas do mesmo tipo. Sem ele nao ha nivel nem dias restantes -- o calculo devolve null em vez de inventar.',
				'weighing' => 'Peso TOTAL na balanca (garrafa + gas). O nivel deriva-se: (bruto - tara) / nominal.',
				'derived' => 'Consumo, nivel, duracao e comparacao de tarifarios NUNCA sao guardados -- sao sempre calculados a partir dos valores em bruto.',
				'invoice' => 'Um DOCUMENTO FISCAL lido do PDF da fatura, identificado pelo ATCUD. Um PDF traz mais do que um: numa fatura da EDP, a eletricidade e a Contribuicao Audiovisual sao documentos separados.',
				'atcud' => 'Codigo unico de documento fiscal portugues. E a chave de idempotencia da importacao: reimportar o mesmo PDF devolve os documentos em "existing" em vez de duplicar.',
				'invoiceVsReading' => 'Fontes COMPLEMENTARES, nao alternativas. A fatura so traz os escaloes que o contrato fatura: num contrato bi-horario traz V e FV, nunca C e P separados. So as leituras que tu fazes ao mostrador permitem responder a "o tri-horario sairia mais barato?". Importar faturas NAO substitui ler o contador.',
			],
			'tipTriVsBi' =>
				'Em Portugal e normal ter um contador tri-horario (tres registos V/C/P) com um contrato '
				. 'bi-horario (dois escaloes: Vazio e Fora de Vazio), porque as horas de Vazio coincidem nas '
				. 'duas opcoes e Fora de Vazio = Cheias + Ponta. Regista SEMPRE os tres registos em bruto: '
				. 'e isso que permite ao endpoint /compare responder com exatidao se o tri-horario sairia mais '
				. 'barato. Ao criar o contador, passa registerCodes=["V","C","P"] mesmo com tariffOption="bi".',
			'quickReference' => [
				'POST /api/v1/invoices/import' => 'Poe uma ou mais faturas em PDF na FILA. Devolve 202 e nao espera pela leitura, que demora ate um minuto por ficheiro. Multipart em "file" (ou "file[]" para varios), ou um em base64 em "content" (com "filename"). Opcional: "meterId". O resultado chega por notificacao do Nextcloud e fica em GET /api/v1/invoices/imports.',
				'GET /api/v1/invoices/imports' => 'Estado da fila, mais recente primeiro. Cada item tem status (pending|running|done|failed) e, quando terminado, "result" com {created, existing, warnings}. LE SEMPRE os avisos: uma linha de energia que nao entre nos totais aparece la, e ignora-la deixa o total a menos com ar de certo.',
				'GET /api/v1/invoices' => 'Faturas importadas, com o consumo por escalao ja somado (as linhas vem partidas por taxa de IVA na fatura; aqui ja estao juntas). Filtro opcional: meterId.',
				'GET /api/v1/invoices/{id}' => 'Uma fatura: as linhas como vieram do fornecedor, mais o consumo agregado.',
				'PUT /api/v1/invoices/{id}' => 'Liga a fatura a um contador (meterId), ou desliga com meterId nulo.',
				'DELETE /api/v1/invoices/{id}' => 'Apaga a fatura e as suas linhas.',
				'GET /api/v1/meters/{id}/series' => 'A serie de consumo do contador, juntando leituras e faturas sem as somar (periodos sobrepostos contam uma vez, e ganha a leitura). Cada periodo traz "source" (reading|invoice), "days" e "totalPerDay". USA totalPerDay para comparar periodos: eles tem duracoes diferentes, e comparar totais de periodos desiguais le-se mal.',
				'GET /api/v1/meters/{id}/forecast' => 'Projecta consumo e custo para os proximos "days" dias (por omissao 30). Usa leituras e faturas sem as somar -- periodos sobrepostos contam uma vez, e ganha a leitura. LE "confidence" e "caveats" ANTES de usar os numeros: com base insuficiente devolve "projected" nulo, e nesse caso nao ha numero nenhum a extrair daqui. O custo so vem se houver fatura importada deste contador.',
				['method' => 'GET', 'path' => '/api/v1/overview', 'summary' => 'Tudo de uma vez: contadores com ultima leitura, garrafas em uso e medias'],
				['method' => 'GET', 'path' => '/api/v1/meters', 'summary' => 'Listar contadores (?kind=electricity|water, ?includeArchived=true)'],
				['method' => 'POST', 'path' => '/api/v1/meters', 'summary' => 'Criar contador (name, kind, unit, tariffOption simples|bi|tri, registerCodes[], location, serial, digits, installedAt)'],
				['method' => 'GET', 'path' => '/api/v1/meters/{id}', 'summary' => 'Contador + registos + leituras + serie de consumo calculada (por periodo e por dia) + anomalias'],
				['method' => 'PUT', 'path' => '/api/v1/meters/{id}', 'summary' => 'Alterar contador (qualquer campo; archived=true para arquivar)'],
				['method' => 'DELETE', 'path' => '/api/v1/meters/{id}', 'summary' => 'Apagar contador e todas as suas leituras'],
				['method' => 'PUT', 'path' => '/api/v1/meters/{id}/registers', 'summary' => 'Redefinir os registos (codes[]). APAGA as leituras: valores de um registo que deixou de existir nao sao interpretaveis'],
				['method' => 'GET', 'path' => '/api/v1/meters/{meterId}/readings', 'summary' => 'Leituras de um contador, por data crescente'],
				['method' => 'POST', 'path' => '/api/v1/meters/{meterId}/readings', 'summary' => 'Lancar leitura (readAt, values={"V":1234,"C":567,"P":89}, isEstimate, source, note)'],
				['method' => 'PUT', 'path' => '/api/v1/readings/{id}', 'summary' => 'Corrigir leitura (readAt, values, isEstimate, note)'],
				['method' => 'DELETE', 'path' => '/api/v1/readings/{id}', 'summary' => 'Apagar leitura'],
				['method' => 'GET', 'path' => '/api/v1/meters/{meterId}/compare', 'summary' => 'Quanto custaria o consumo real em cada tarifario registado (?from=&to=). Responde a "o tri-horario sairia mais barato?"'],
				['method' => 'GET', 'path' => '/api/v1/gas', 'summary' => 'Garrafas: tipos, ciclos com nivel/duracao/ritmo, as que estao em uso, e a media por tipo'],
				['method' => 'GET', 'path' => '/api/v1/gas/types', 'summary' => 'Tipos de garrafa (marca + gas + peso nominal)'],
				['method' => 'POST', 'path' => '/api/v1/gas/types', 'summary' => 'Criar tipo (brand, gasType butano|propano, nominalKg, defaultTareKg)'],
				['method' => 'PUT', 'path' => '/api/v1/gas/types/{id}', 'summary' => 'Alterar tipo'],
				['method' => 'DELETE', 'path' => '/api/v1/gas/types/{id}', 'summary' => 'Apagar tipo (recusa se tiver ciclos; arquiva-se em vez disso)'],
				['method' => 'POST', 'path' => '/api/v1/gas/cycles', 'summary' => 'Garrafa nova em uso (bottleTypeId, installedAt, appliance esquentador|fogao|ambos|outro, tareKg, pricePaid, supplier)'],
				['method' => 'PUT', 'path' => '/api/v1/gas/cycles/{id}', 'summary' => 'Alterar ciclo. Garrafa acabou: removedAt="YYYY-MM-DD"'],
				['method' => 'DELETE', 'path' => '/api/v1/gas/cycles/{id}', 'summary' => 'Apagar ciclo e as suas pesagens'],
				['method' => 'GET', 'path' => '/api/v1/gas/cycles/{cycleId}/weighings', 'summary' => 'Pesagens de uma garrafa'],
				['method' => 'POST', 'path' => '/api/v1/gas/cycles/{cycleId}/weighings', 'summary' => 'Registar pesagem (weighedAt, grossKg = peso total na balanca)'],
				['method' => 'DELETE', 'path' => '/api/v1/gas/weighings/{id}', 'summary' => 'Apagar pesagem'],
				['method' => 'GET', 'path' => '/api/v1/tariffs', 'summary' => 'Tarifarios (?kind=electricity|water)'],
				['method' => 'POST', 'path' => '/api/v1/tariffs', 'summary' => 'Criar tarifario (name, kind, tariffOption, validFrom, prices={"V":0.10,"FV":0.18}, standingChargeDay)'],
				['method' => 'PUT', 'path' => '/api/v1/tariffs/{id}', 'summary' => 'Alterar tarifario'],
				['method' => 'DELETE', 'path' => '/api/v1/tariffs/{id}', 'summary' => 'Apagar tarifario'],
			],
		]);
	}

	// --- Vista geral -------------------------------------------------------

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/overview')]
	public function overview(): DataResponse {
		$userId = $this->getUserId();

		$meters = [];
		foreach ($this->meterService->findAll($userId) as $meter) {
			$detail = $this->readingService->detail((int)$meter->getId(), $userId);
			$readings = $detail['readings'];
			$periods = $detail['series']['periods'];

			$meters[] = [
				'meter' => $detail['meter'],
				'registers' => $detail['registers'],
				'readingCount' => count($readings),
				'lastReading' => $readings === [] ? null : $readings[count($readings) - 1],
				'lastPeriod' => $periods === [] ? null : $periods[count($periods) - 1],
				'totals' => $detail['series']['totals'],
				'anomalies' => $detail['series']['anomalies'],
			];
		}

		return new DataResponse([
			'meters' => $meters,
			'gas' => $this->gasService->overview($userId, $this->today()),
		]);
	}

	// --- Contadores --------------------------------------------------------

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/meters')]
	public function listMeters(bool $includeArchived = false, ?string $kind = null): DataResponse {
		return new DataResponse($this->meterService->findAll($this->getUserId(), $includeArchived, $kind));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/meters')]
	public function createMeter(
		string $name,
		string $kind = 'electricity',
		?string $unit = null,
		string $tariffOption = 'simples',
		?array $registerCodes = null,
		?string $location = null,
		?string $serial = null,
		int $digits = 0,
		?string $installedAt = null,
		?string $notes = null,
	): DataResponse {
		try {
			$result = $this->meterService->create(
				$this->getUserId(), $name, $kind, $unit, $tariffOption, $registerCodes,
				$location, $serial, $digits, $this->date($installedAt), $notes,
			);
		} catch (\Exception $e) {
			return $this->badRequest($e->getMessage());
		}

		return new DataResponse([
			'meter' => $result['meter']->jsonSerialize(),
			'registers' => array_map(static fn ($r) => $r->jsonSerialize(), $result['registers']),
		], Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/meters/{id}', requirements: ['id' => '\d+'])]
	public function getMeter(int $id): DataResponse {
		try {
			return new DataResponse($this->readingService->detail($id, $this->getUserId()));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/meters/{id}', requirements: ['id' => '\d+'])]
	public function updateMeter(
		int $id,
		?string $name = null,
		?string $location = null,
		bool $locationProvided = false,
		?string $serial = null,
		bool $serialProvided = false,
		?string $unit = null,
		?int $digits = null,
		?string $tariffOption = null,
		?string $installedAt = null,
		bool $installedAtProvided = false,
		?string $removedAt = null,
		bool $removedAtProvided = false,
		?string $notes = null,
		bool $notesProvided = false,
		?bool $archived = null,
	): DataResponse {
		try {
			return new DataResponse($this->meterService->update(
				$id, $this->getUserId(), $name,
				$location, $locationProvided,
				$serial, $serialProvided,
				$unit, $digits, $tariffOption,
				$this->date($installedAt), $installedAtProvided,
				$this->date($removedAt), $removedAtProvided,
				$notes, $notesProvided, $archived,
			));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\Exception $e) {
			return $this->badRequest($e->getMessage());
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/meters/{id}', requirements: ['id' => '\d+'])]
	public function deleteMeter(int $id): DataResponse {
		try {
			$this->meterService->delete($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/meters/{id}/registers', requirements: ['id' => '\d+'])]
	public function setRegisters(int $id, array $codes): DataResponse {
		try {
			$registers = $this->meterService->setRegisters($id, $this->getUserId(), $codes);
			return new DataResponse(array_map(static fn ($r) => $r->jsonSerialize(), $registers));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	// --- Leituras -----------------------------------------------------------

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/meters/{meterId}/readings', requirements: ['meterId' => '\d+'])]
	public function listReadings(int $meterId): DataResponse {
		try {
			return new DataResponse($this->readingService->findAll($meterId, $this->getUserId()));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/meters/{meterId}/readings', requirements: ['meterId' => '\d+'])]
	public function createReading(
		int $meterId,
		string $readAt,
		array $values,
		bool $isEstimate = false,
		string $source = 'manual',
		?string $note = null,
	): DataResponse {
		try {
			$date = $this->date($readAt);
			if ($date === null) {
				return $this->badRequest('readAt e obrigatorio (data que esta no contador, YYYY-MM-DD).');
			}
			return new DataResponse(
				$this->readingService->create($meterId, $this->getUserId(), $date, $values, $isEstimate, $source, $note),
				Http::STATUS_CREATED,
			);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->badRequest($e->getMessage());
		} catch (\Exception) {
			return $this->badRequest('Data invalida.');
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/readings/{id}', requirements: ['id' => '\d+'])]
	public function updateReading(
		int $id,
		?string $readAt = null,
		?array $values = null,
		?bool $isEstimate = null,
		?string $source = null,
		?string $note = null,
		bool $noteProvided = false,
	): DataResponse {
		try {
			return new DataResponse($this->readingService->update(
				$id, $this->getUserId(), $this->date($readAt), $values, $isEstimate, $source, $note, $noteProvided,
			));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->badRequest($e->getMessage());
		} catch (\Exception) {
			return $this->badRequest('Data invalida.');
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/readings/{id}', requirements: ['id' => '\d+'])]
	public function deleteReading(int $id): DataResponse {
		try {
			$this->readingService->delete($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/meters/{meterId}/compare', requirements: ['meterId' => '\d+'])]
	public function compare(int $meterId, ?string $from = null, ?string $to = null): DataResponse {
		try {
			return new DataResponse($this->tariffService->compareForMeter(
				$meterId, $this->getUserId(), $this->date($from), $this->date($to),
			));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\Exception) {
			return $this->badRequest('Data invalida.');
		}
	}

	// --- Gas ------------------------------------------------------------------

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/gas')]
	public function gasOverview(): DataResponse {
		return new DataResponse($this->gasService->overview($this->getUserId(), $this->today()));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/gas/types')]
	public function listBottleTypes(bool $includeArchived = false): DataResponse {
		return new DataResponse($this->gasService->findAllTypes($this->getUserId(), $includeArchived));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/gas/types')]
	public function createBottleType(
		string $brand,
		string $gasType = 'butano',
		float $nominalKg = 13.0,
		?float $defaultTareKg = null,
		?string $notes = null,
	): DataResponse {
		return new DataResponse(
			$this->gasService->createType($this->getUserId(), $brand, $gasType, $nominalKg, $defaultTareKg, $notes),
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/gas/types/{id}', requirements: ['id' => '\d+'])]
	public function updateBottleType(
		int $id,
		?string $brand = null,
		?string $gasType = null,
		?float $nominalKg = null,
		?float $defaultTareKg = null,
		bool $defaultTareKgProvided = false,
		?string $notes = null,
		bool $notesProvided = false,
		?bool $archived = null,
	): DataResponse {
		try {
			return new DataResponse($this->gasService->updateType(
				$id, $this->getUserId(), $brand, $gasType, $nominalKg,
				$defaultTareKg, $defaultTareKgProvided, $notes, $notesProvided, $archived,
			));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/gas/types/{id}', requirements: ['id' => '\d+'])]
	public function deleteBottleType(int $id): DataResponse {
		try {
			$this->gasService->deleteType($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/gas/cycles')]
	public function createCycle(
		int $bottleTypeId,
		string $installedAt,
		string $appliance = 'ambos',
		?float $tareKg = null,
		?float $pricePaid = null,
		?string $supplier = null,
		?string $note = null,
	): DataResponse {
		try {
			$date = $this->date($installedAt);
			if ($date === null) {
				return $this->badRequest('installedAt e obrigatorio (YYYY-MM-DD).');
			}
			return new DataResponse(
				$this->gasService->createCycle($this->getUserId(), $bottleTypeId, $date, $appliance, $tareKg, $pricePaid, $supplier, $note),
				Http::STATUS_CREATED,
			);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\Exception) {
			return $this->badRequest('Data invalida.');
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/gas/cycles/{id}', requirements: ['id' => '\d+'])]
	public function updateCycle(
		int $id,
		?int $bottleTypeId = null,
		?string $appliance = null,
		?string $installedAt = null,
		?string $removedAt = null,
		bool $removedAtProvided = false,
		?float $tareKg = null,
		bool $tareKgProvided = false,
		?float $pricePaid = null,
		bool $pricePaidProvided = false,
		?string $supplier = null,
		bool $supplierProvided = false,
		?string $note = null,
		bool $noteProvided = false,
	): DataResponse {
		try {
			// Passar removedAt implica fechar o ciclo -- nao e preciso mandar
			// tambem o par removedAtProvided, que so existe para o poder REABRIR
			// (removedAt=null explicito).
			$removed = $this->date($removedAt);
			return new DataResponse($this->gasService->updateCycle(
				$id, $this->getUserId(), $bottleTypeId, $appliance, $this->date($installedAt),
				$removed, $removedAtProvided || $removed !== null,
				$tareKg, $tareKgProvided || $tareKg !== null,
				$pricePaid, $pricePaidProvided || $pricePaid !== null,
				$supplier, $supplierProvided || $supplier !== null,
				$note, $noteProvided || $note !== null,
			));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\Exception) {
			return $this->badRequest('Data invalida.');
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/gas/cycles/{id}', requirements: ['id' => '\d+'])]
	public function deleteCycle(int $id): DataResponse {
		try {
			$this->gasService->delete($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/gas/cycles/{cycleId}/weighings', requirements: ['cycleId' => '\d+'])]
	public function listWeighings(int $cycleId): DataResponse {
		try {
			return new DataResponse($this->gasService->weighings($cycleId, $this->getUserId()));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/gas/cycles/{cycleId}/weighings', requirements: ['cycleId' => '\d+'])]
	public function addWeighing(int $cycleId, string $weighedAt, float $grossKg, ?string $note = null): DataResponse {
		try {
			$date = $this->date($weighedAt);
			if ($date === null) {
				return $this->badRequest('weighedAt e obrigatorio (YYYY-MM-DD).');
			}
			return new DataResponse(
				$this->gasService->addWeighing($cycleId, $this->getUserId(), $date, $grossKg, $note),
				Http::STATUS_CREATED,
			);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\Exception) {
			return $this->badRequest('Data invalida.');
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/gas/weighings/{id}', requirements: ['id' => '\d+'])]
	public function deleteWeighing(int $id): DataResponse {
		try {
			$this->gasService->deleteWeighing($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	// --- Tarifarios -------------------------------------------------------------

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/tariffs')]
	public function listTariffs(?string $kind = null): DataResponse {
		return new DataResponse($this->tariffService->findAll($this->getUserId(), $kind));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tariffs')]
	public function createTariff(
		string $name,
		string $validFrom,
		string $kind = 'electricity',
		string $tariffOption = 'simples',
		array $prices = [],
		?string $validTo = null,
		?float $standingChargeDay = null,
		string $currency = 'EUR',
		?string $notes = null,
	): DataResponse {
		try {
			$from = $this->date($validFrom);
			if ($from === null) {
				return $this->badRequest('validFrom e obrigatorio (YYYY-MM-DD).');
			}
			return new DataResponse(
				$this->tariffService->create(
					$this->getUserId(), $name, $from, $kind, $tariffOption, $prices,
					$this->date($validTo), $standingChargeDay, $currency, $notes,
				),
				Http::STATUS_CREATED,
			);
		} catch (\Exception) {
			return $this->badRequest('Data invalida.');
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/tariffs/{id}', requirements: ['id' => '\d+'])]
	public function updateTariff(
		int $id,
		?string $name = null,
		?string $tariffOption = null,
		?string $validFrom = null,
		?string $validTo = null,
		bool $validToProvided = false,
		?array $prices = null,
		?float $standingChargeDay = null,
		bool $standingChargeDayProvided = false,
		?string $currency = null,
		?string $notes = null,
		bool $notesProvided = false,
	): DataResponse {
		try {
			$to = $this->date($validTo);
			return new DataResponse($this->tariffService->update(
				$id, $this->getUserId(), $name, $tariffOption, $this->date($validFrom),
				$to, $validToProvided || $to !== null,
				$prices,
				$standingChargeDay, $standingChargeDayProvided || $standingChargeDay !== null,
				$currency, $notes, $notesProvided || $notes !== null,
			));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\Exception) {
			return $this->badRequest('Data invalida.');
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/tariffs/{id}', requirements: ['id' => '\d+'])]
	public function deleteTariff(int $id): DataResponse {
		try {
			$this->tariffService->delete($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	// ---------------------------------------------------------------- Faturas

	/**
	 * Poe uma ou mais faturas em PDF na fila de importacao.
	 *
	 * Devolve logo, sem ler nada: a leitura de um PDF demora ate um minuto e
	 * nao e trabalho para um pedido web. Fica em fila, e o resultado chega
	 * pelas notificacoes do Nextcloud -- e fica tambem em
	 * GET /api/v1/invoices/imports, que e onde estao os avisos por inteiro.
	 *
	 * Aceita varios ficheiros no mesmo pedido (`file[]` em multipart), ou um
	 * em base64 no corpo JSON em `content`. O segundo existe para um agente
	 * que nao consiga montar um multipart.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/import')]
	public function importInvoice(
		?string $content = null,
		?string $filename = null,
		?int $meterId = null,
	): DataResponse {
		$files = $this->uploadedFiles();

		if ($files === [] && $content !== null && $content !== '') {
			$bytes = base64_decode($content, true);
			if ($bytes === false) {
				return $this->badRequest('O campo "content" tem de ser base64 valido.');
			}
			$files[] = ['name' => $filename ?? 'fatura.pdf', 'bytes' => $bytes];
		}

		if ($files === []) {
			return $this->badRequest(
				'Falta o ficheiro: envia os PDF em multipart no campo "file" (ou "file[]" para '
				. 'varios), ou um em base64 em "content".'
			);
		}

		$queued = [];
		$rejected = [];
		foreach ($files as $file) {
			if ($file['bytes'] === '') {
				$rejected[] = ['filename' => $file['name'], 'reason' => 'ficheiro vazio'];
				continue;
			}
			$queued[] = $this->importQueue
				->enqueue($this->getUserId(), $file['bytes'], $file['name'], $meterId)
				->jsonSerialize();
		}

		return new DataResponse([
			'queued' => $queued,
			'rejected' => $rejected,
			'note' => 'As faturas sao lidas em fundo. O resultado chega pelas notificacoes do '
				. 'Nextcloud, e fica em GET /api/v1/invoices/imports com os avisos por inteiro.',
		], Http::STATUS_ACCEPTED);
	}

	/**
	 * O estado da fila de importacao, mais recente primeiro.
	 *
	 * E aqui que estao os avisos completos de cada importacao. A notificacao
	 * diz quantos ha; o que eles dizem esta neste endpoint.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/imports')]
	public function listImports(): DataResponse {
		return new DataResponse($this->importQueue->findAllForUser($this->getUserId()));
	}

	/**
	 * Normaliza o que o PHP poe em $_FILES, que tem formas diferentes para um
	 * ficheiro e para varios.
	 *
	 * @return list<array{name: string, bytes: string}>
	 */
	private function uploadedFiles(): array {
		$uploaded = $this->request->getUploadedFile('file');
		if (!is_array($uploaded)) {
			return [];
		}

		// Varios: cada chave e um array paralelo.
		if (is_array($uploaded['error'] ?? null)) {
			$out = [];
			foreach ($uploaded['error'] as $i => $error) {
				if ($error !== UPLOAD_ERR_OK) {
					continue;
				}
				$out[] = [
					'name' => (string)($uploaded['name'][$i] ?? 'fatura.pdf'),
					'bytes' => (string)file_get_contents($uploaded['tmp_name'][$i]),
				];
			}
			return $out;
		}

		if (($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return [];
		}

		return [[
			'name' => (string)($uploaded['name'] ?? 'fatura.pdf'),
			'bytes' => (string)file_get_contents($uploaded['tmp_name']),
		]];
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices')]
	public function listInvoices(?int $meterId = null): DataResponse {
		return new DataResponse($this->invoiceService->findAll($this->getUserId(), $meterId));
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/{id}')]
	public function getInvoice(int $id): DataResponse {
		try {
			return new DataResponse($this->invoiceService->detail($id, $this->getUserId()));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	/**
	 * Liga uma fatura a um contador, ou desliga-a passando `meterId` nulo.
	 *
	 * Fica a parte da importacao porque nem todos os documentos de um PDF
	 * pertencem a um contador: a Contribuicao Audiovisual nao pertence a
	 * nenhum.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/invoices/{id}')]
	public function updateInvoice(int $id, ?int $meterId = null): DataResponse {
		try {
			return new DataResponse($this->invoiceService->assignMeter($id, $this->getUserId(), $meterId));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/invoices/{id}')]
	public function deleteInvoice(int $id): DataResponse {
		try {
			$this->invoiceService->delete($id, $this->getUserId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	/**
	 * A serie de consumo do contador, juntando leituras e faturas.
	 *
	 * E o que alimenta tabelas e graficos. As duas fontes nao se somam:
	 * periodos sobrepostos sao o mesmo consumo contado duas vezes, e ganha a
	 * leitura. Cada periodo diz de onde veio, em `source`.
	 *
	 * Repara em `totalPerDay`: os periodos tem duracoes diferentes, e
	 * comparar totais de periodos desiguais le-se mal -- a barra maior pode
	 * ser so a mais comprida.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/meters/{id}/series')]
	public function series(int $id): DataResponse {
		$userId = $this->getUserId();

		try {
			$detail = $this->readingService->detail($id, $userId);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}

		return new DataResponse($this->forecastService->unified(
			$this->forecastService->fromSeries($detail['series']['periods'] ?? []),
			$this->forecastService->fromInvoices($this->invoiceService->consumptionSeries($userId, $id))
		) + ['unit' => $detail['meter']['unit'] ?? null]);
	}

	// --------------------------------------------------------------- Previsao

	/**
	 * Projecta consumo e custo para os proximos dias.
	 *
	 * Usa as duas fontes -- leituras do contador e faturas importadas -- sem
	 * as somar: um periodo de leituras que se sobreponha a uma fatura e o
	 * mesmo consumo contado duas vezes. Ganha a leitura, que vem do mostrador
	 * e traz os escaloes todos.
	 *
	 * O custo so aparece se houver uma fatura de onde o derivar: sem ela
	 * sabe-se o preco da energia mas nao o da potencia, do acesso as redes nem
	 * dos impostos, que sao mais de metade do que se paga.
	 *
	 * LE SEMPRE `caveats` e `confidence`. Esta resposta devolve `projected`
	 * nulo quando nao ha base que chegue, e nesse caso nao ha numero nenhum a
	 * extrair daqui.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/meters/{id}/forecast')]
	public function forecast(int $id, int $days = 30): DataResponse {
		$userId = $this->getUserId();
		$days = max(1, min($days, 730));

		try {
			$detail = $this->readingService->detail($id, $userId);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}

		$fromReadings = $this->forecastService->fromSeries($detail['series']['periods'] ?? []);
		$fromInvoices = $this->forecastService->fromInvoices(
			$this->invoiceService->consumptionSeries($userId, $id)
		);
		$merged = $this->forecastService->merge($fromReadings, $fromInvoices);

		$costModel = null;
		$priced = $this->invoiceService->latestPricedInvoice($userId, $id);
		if ($priced !== null) {
			$costModel = $this->forecastService->costModelFromInvoice($priced['lines'], $priced['days']);
		}

		$forecast = $this->forecastService->forecast(
			$merged['periods'],
			$this->today()->format('Y-m-d'),
			$days,
			$costModel
		);

		if ($costModel === null) {
			$forecast['caveats'][] = 'Nao ha nenhuma fatura importada para este contador, por isso nao '
				. 'se projecta custo -- so consumo. Importa uma fatura para a app saber quanto custam a '
				. 'potencia, o acesso as redes e os impostos.';
		}

		return new DataResponse($forecast + [
			'sources' => $merged['used'],
			'costModelFrom' => $priced['invoice'] ?? null,
		]);
	}
}
