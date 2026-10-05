<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Service;

use OCA\HomeExpenses\Db\MeterMapper;
use OCA\HomeExpenses\Db\MeterRegisterMapper;
use OCA\HomeExpenses\Db\Reading;
use OCA\HomeExpenses\Db\ReadingMapper;
use OCA\HomeExpenses\Db\ReadingValueMapper;
use OCA\HomeExpenses\Db\Tariff;
use OCA\HomeExpenses\Db\TariffMapper;
use OCA\HomeExpenses\Db\TariffPrice;
use OCA\HomeExpenses\Db\TariffPriceMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

class TariffService {
	public function __construct(
		private TariffMapper $tariffMapper,
		private TariffPriceMapper $priceMapper,
		private MeterMapper $meterMapper,
		private MeterRegisterMapper $registerMapper,
		private ReadingMapper $readingMapper,
		private ReadingValueMapper $valueMapper,
		private ConsumptionService $consumption,
	) {
	}

	/** @return list<array> */
	public function findAll(string $userId, ?string $kind = null): array {
		$out = [];
		foreach ($this->tariffMapper->findAllForUser($userId, $kind) as $tariff) {
			$out[] = $this->withPrices($tariff);
		}
		return $out;
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function find(int $id, string $userId): array {
		return $this->withPrices($this->tariffMapper->find($id, $userId));
	}

	/**
	 * @param array<string, float|int|string> $prices preco por codigo de registo
	 */
	public function create(
		string $userId,
		string $name,
		\DateTimeImmutable $validFrom,
		string $kind = 'electricity',
		string $tariffOption = 'simples',
		array $prices = [],
		?\DateTimeImmutable $validTo = null,
		?float $standingChargeDay = null,
		string $currency = 'EUR',
		?string $notes = null,
	): array {
		$tariff = new Tariff();
		$tariff->setUserId($userId);
		$tariff->setName($name);
		$tariff->setKind($kind);
		$tariff->setTariffOption($tariffOption);
		$tariff->setValidFrom($validFrom);
		$tariff->setValidTo($validTo);
		$tariff->setStandingChargeDay($standingChargeDay);
		$tariff->setCurrency($currency);
		$tariff->setNotes($notes);

		$tariff = $this->tariffMapper->insert($tariff);
		$this->replacePrices((int)$tariff->getId(), $prices);

		return $this->withPrices($tariff);
	}

	/**
	 * @param array<string, float|int|string>|null $prices
	 * @throws DoesNotExistException|MultipleObjectsReturnedException
	 */
	public function update(
		int $id,
		string $userId,
		?string $name = null,
		?string $tariffOption = null,
		?\DateTimeImmutable $validFrom = null,
		?\DateTimeImmutable $validTo = null,
		bool $validToProvided = false,
		?array $prices = null,
		?float $standingChargeDay = null,
		bool $standingChargeDayProvided = false,
		?string $currency = null,
		?string $notes = null,
		bool $notesProvided = false,
	): array {
		$tariff = $this->tariffMapper->find($id, $userId);

		if ($name !== null) {
			$tariff->setName($name);
		}
		if ($tariffOption !== null) {
			$tariff->setTariffOption($tariffOption);
		}
		if ($validFrom !== null) {
			$tariff->setValidFrom($validFrom);
		}
		if ($validToProvided) {
			$tariff->setValidTo($validTo);
		}
		if ($standingChargeDayProvided) {
			$tariff->setStandingChargeDay($standingChargeDay);
		}
		if ($currency !== null) {
			$tariff->setCurrency($currency);
		}
		if ($notesProvided) {
			$tariff->setNotes($notes);
		}

		$tariff = $this->tariffMapper->update($tariff);

		if ($prices !== null) {
			$this->replacePrices((int)$tariff->getId(), $prices);
		}

		return $this->withPrices($tariff);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function delete(int $id, string $userId): void {
		$tariff = $this->tariffMapper->find($id, $userId);
		$this->priceMapper->deleteAllForTariff($id);
		$this->tariffMapper->delete($tariff);
	}

	/**
	 * Quanto teria custado o consumo real de um contador, em cada tarifario
	 * registado para aquele tipo de servico.
	 *
	 * Com um contador tri-horario faturado em bi-horario -- o caso normal em
	 * Portugal -- e isto que responde a "o tri-horario sairia mais barato?",
	 * sobre o consumo que de facto houve e nao sobre um perfil tipico.
	 *
	 * @throws DoesNotExistException|MultipleObjectsReturnedException
	 */
	public function compareForMeter(
		int $meterId,
		string $userId,
		?\DateTimeImmutable $from = null,
		?\DateTimeImmutable $to = null,
	): array {
		$meter = $this->meterMapper->find($meterId, $userId);
		$registers = $this->registerMapper->findAllForMeter($meterId);

		$readings = array_values(array_filter(
			$this->readingMapper->findAllForMeter($meterId),
			static function (Reading $reading) use ($from, $to): bool {
				$date = $reading->getReadAt();
				if ($date === null) {
					return false;
				}
				if ($from !== null && $date < $from) {
					return false;
				}
				return !($to !== null && $date > $to);
			},
		));

		if (count($readings) < 2) {
			return [
				'meter' => $meter->jsonSerialize(),
				'error' => 'Sao precisas pelo menos duas leituras no intervalo para haver consumo que comparar.',
				'readings' => count($readings),
			];
		}

		$ids = array_map(static fn (Reading $r) => (int)$r->getId(), $readings);
		$grouped = [];
		foreach ($this->valueMapper->findAllForReadings($ids) as $value) {
			$grouped[$value->getReadingId()][] = $value;
		}

		$series = $this->consumption->meterSeries($meter, $registers, $readings, $grouped);

		$first = $readings[0]->getReadAt();
		$last = $readings[count($readings) - 1]->getReadAt();
		$days = ($first !== null && $last !== null) ? (int)$first->diff($last)->days : 0;

		$tariffs = [];
		foreach ($this->tariffMapper->findAllForUser($userId, $meter->getKind()) as $tariff) {
			$prices = [];
			foreach ($this->priceMapper->findAllForTariff((int)$tariff->getId()) as $price) {
				$prices[$price->getRegisterCode()] = $price->getPricePerUnit();
			}
			$tariffs[] = [
				'id' => (int)$tariff->getId(),
				'name' => $tariff->getName(),
				'option' => $tariff->getTariffOption(),
				'prices' => $prices,
				'standingChargeDay' => $tariff->getStandingChargeDay(),
			];
		}

		if ($tariffs === []) {
			return [
				'meter' => $meter->jsonSerialize(),
				'error' => 'Nao ha tarifarios registados para ' . $meter->getKind() . '. Cria pelo menos um com os precos por escalao.',
				'consumption' => $series['totals'],
				'days' => $days,
			];
		}

		return [
			'meter' => $meter->jsonSerialize(),
			'from' => $first?->format('Y-m-d'),
			'to' => $last?->format('Y-m-d'),
			'unit' => $meter->getUnit(),
			'comparison' => $this->consumption->compareTariffs($series['totals'], $tariffs, $days),
			'anomalies' => $series['anomalies'],
		];
	}

	/** @param array<string, float|int|string> $prices */
	private function replacePrices(int $tariffId, array $prices): void {
		$this->priceMapper->deleteAllForTariff($tariffId);
		foreach ($prices as $code => $value) {
			$code = strtoupper(trim((string)$code));
			if ($code === '') {
				continue;
			}
			$price = new TariffPrice();
			$price->setTariffId($tariffId);
			$price->setRegisterCode($code);
			$price->setPricePerUnit((float)$value);
			$this->priceMapper->insert($price);
		}
	}

	private function withPrices(Tariff $tariff): array {
		$prices = [];
		foreach ($this->priceMapper->findAllForTariff((int)$tariff->getId()) as $price) {
			$prices[$price->getRegisterCode()] = $price->getPricePerUnit();
		}
		return $tariff->jsonSerialize() + ['prices' => $prices];
	}
}
