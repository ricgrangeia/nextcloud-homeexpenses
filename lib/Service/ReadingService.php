<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Service;

use OCA\HomeExpenses\Db\MeterMapper;
use OCA\HomeExpenses\Db\MeterRegisterMapper;
use OCA\HomeExpenses\Db\Reading;
use OCA\HomeExpenses\Db\ReadingMapper;
use OCA\HomeExpenses\Db\ReadingValue;
use OCA\HomeExpenses\Db\ReadingValueMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

class ReadingService {
	public function __construct(
		private ReadingMapper $readingMapper,
		private ReadingValueMapper $valueMapper,
		private MeterMapper $meterMapper,
		private MeterRegisterMapper $registerMapper,
		private ConsumptionService $consumption,
	) {
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	private function assertMeterOwned(int $meterId, string $userId): void {
		$this->meterMapper->find($meterId, $userId);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	private function assertReadingOwned(int $readingId, string $userId): Reading {
		$reading = $this->readingMapper->find($readingId);
		$this->assertMeterOwned($reading->getMeterId(), $userId);
		return $reading;
	}

	/**
	 * Leituras de um contador, cada uma com os valores de todos os registos.
	 *
	 * @return list<array>
	 * @throws DoesNotExistException|MultipleObjectsReturnedException
	 */
	public function findAll(int $meterId, string $userId): array {
		$this->assertMeterOwned($meterId, $userId);

		$readings = $this->readingMapper->findAllForMeter($meterId);
		$registers = $this->registerMapper->findAllForMeter($meterId);
		$valuesByReading = $this->valuesByReading($readings);

		$codeById = [];
		foreach ($registers as $register) {
			$codeById[$register->getId()] = $register->getCode();
		}

		$out = [];
		foreach ($readings as $reading) {
			$values = [];
			foreach ($valuesByReading[$reading->getId()] ?? [] as $value) {
				$code = $codeById[$value->getRegisterId()] ?? null;
				if ($code !== null) {
					$values[$code] = $value->getValue();
				}
			}
			$out[] = $reading->jsonSerialize() + ['values' => $values];
		}

		return $out;
	}

	/**
	 * Contador + registos + leituras + tudo o que se deriva delas.
	 *
	 * @throws DoesNotExistException|MultipleObjectsReturnedException
	 */
	public function detail(int $meterId, string $userId): array {
		$meter = $this->meterMapper->find($meterId, $userId);
		$registers = $this->registerMapper->findAllForMeter($meterId);
		$readings = $this->readingMapper->findAllForMeter($meterId);
		$valuesByReading = $this->valuesByReading($readings);

		$series = $this->consumption->meterSeries($meter, $registers, $readings, $valuesByReading);

		return [
			'meter' => $meter->jsonSerialize(),
			'registers' => array_map(static fn ($r) => $r->jsonSerialize(), $registers),
			'readings' => $this->findAll($meterId, $userId),
			'series' => $series,
		];
	}

	/**
	 * Lanca uma leitura. Os valores vem por CODIGO de registo ('V', 'C', 'P',
	 * 'TOTAL'), nao por id -- um id de registo nao significa nada para quem
	 * esta a olhar para o mostrador, nem para um agente que leu o /help.
	 *
	 * @param array<string, float|int|string> $valuesByCode
	 * @throws DoesNotExistException|MultipleObjectsReturnedException
	 * @throws \InvalidArgumentException se algum codigo nao existir no contador
	 */
	public function create(
		int $meterId,
		string $userId,
		\DateTimeImmutable $readAt,
		array $valuesByCode,
		bool $isEstimate = false,
		string $source = 'manual',
		?string $note = null,
	): array {
		$this->assertMeterOwned($meterId, $userId);
		$registers = $this->registerMapper->findAllForMeter($meterId);

		$registerByCode = [];
		foreach ($registers as $register) {
			$registerByCode[strtoupper($register->getCode())] = $register;
		}

		// Um codigo desconhecido e quase sempre um engano de quem chama
		// ('VAZIO' em vez de 'V'). Aceitar em silencio criaria uma leitura
		// sem valores, que parece gravada e nao conta para nada.
		$normalised = [];
		$unknown = [];
		foreach ($valuesByCode as $code => $value) {
			$code = strtoupper(trim((string)$code));
			if (!isset($registerByCode[$code])) {
				$unknown[] = $code;
				continue;
			}
			$normalised[$code] = (float)$value;
		}

		if ($unknown !== []) {
			throw new \InvalidArgumentException(
				'Registos desconhecidos neste contador: ' . implode(', ', $unknown)
				. '. Os deste contador sao: ' . implode(', ', array_keys($registerByCode)) . '.'
			);
		}
		if ($normalised === []) {
			throw new \InvalidArgumentException('Uma leitura tem de trazer pelo menos um valor.');
		}

		$reading = new Reading();
		$reading->setMeterId($meterId);
		$reading->setReadAt($readAt);
		$reading->setIsEstimate($isEstimate);
		$reading->setSource($source);
		$reading->setNote($note);
		$reading->setCreatedAt(new \DateTimeImmutable());
		$reading = $this->readingMapper->insert($reading);

		foreach ($normalised as $code => $value) {
			$entry = new ReadingValue();
			$entry->setReadingId((int)$reading->getId());
			$entry->setRegisterId((int)$registerByCode[$code]->getId());
			$entry->setValue($value);
			$this->valueMapper->insert($entry);
		}

		$missing = array_values(array_diff(array_keys($registerByCode), array_keys($normalised)));

		return $reading->jsonSerialize() + [
			'values' => $normalised,
			// Nao e erro -- da-se para quem chamou saber que a serie vai ficar
			// com um buraco naqueles registos, em vez de o descobrir depois.
			'missingRegisters' => $missing,
		];
	}

	/**
	 * @param array<string, float|int|string>|null $valuesByCode
	 * @throws DoesNotExistException|MultipleObjectsReturnedException
	 */
	public function update(
		int $id,
		string $userId,
		?\DateTimeImmutable $readAt = null,
		?array $valuesByCode = null,
		?bool $isEstimate = null,
		?string $source = null,
		?string $note = null,
		bool $noteProvided = false,
	): array {
		$reading = $this->assertReadingOwned($id, $userId);

		if ($readAt !== null) {
			$reading->setReadAt($readAt);
		}
		if ($isEstimate !== null) {
			$reading->setIsEstimate($isEstimate);
		}
		if ($source !== null) {
			$reading->setSource($source);
		}
		if ($noteProvided) {
			$reading->setNote($note);
		}
		$reading = $this->readingMapper->update($reading);

		$values = [];
		if ($valuesByCode !== null) {
			$registers = $this->registerMapper->findAllForMeter($reading->getMeterId());
			$registerByCode = [];
			foreach ($registers as $register) {
				$registerByCode[strtoupper($register->getCode())] = $register;
			}

			$unknown = [];
			$normalised = [];
			foreach ($valuesByCode as $code => $value) {
				$code = strtoupper(trim((string)$code));
				if (!isset($registerByCode[$code])) {
					$unknown[] = $code;
					continue;
				}
				$normalised[$code] = (float)$value;
			}
			if ($unknown !== []) {
				throw new \InvalidArgumentException(
					'Registos desconhecidos neste contador: ' . implode(', ', $unknown) . '.'
				);
			}

			$this->valueMapper->deleteAllForReading((int)$reading->getId());
			foreach ($normalised as $code => $value) {
				$entry = new ReadingValue();
				$entry->setReadingId((int)$reading->getId());
				$entry->setRegisterId((int)$registerByCode[$code]->getId());
				$entry->setValue($value);
				$this->valueMapper->insert($entry);
			}
			$values = $normalised;
		}

		return $reading->jsonSerialize() + ['values' => $values];
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function delete(int $id, string $userId): void {
		$reading = $this->assertReadingOwned($id, $userId);
		$this->valueMapper->deleteAllForReading((int)$reading->getId());
		$this->readingMapper->delete($reading);
	}

	/**
	 * @param Reading[] $readings
	 * @return array<int, \OCA\HomeExpenses\Db\ReadingValue[]>
	 */
	private function valuesByReading(array $readings): array {
		$ids = array_map(static fn (Reading $r) => (int)$r->getId(), $readings);
		$grouped = [];
		foreach ($this->valueMapper->findAllForReadings($ids) as $value) {
			$grouped[$value->getReadingId()][] = $value;
		}
		return $grouped;
	}
}
