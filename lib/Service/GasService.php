<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Service;

use OCA\HomeExpenses\Db\BottleType;
use OCA\HomeExpenses\Db\BottleTypeMapper;
use OCA\HomeExpenses\Db\GasCycle;
use OCA\HomeExpenses\Db\GasCycleMapper;
use OCA\HomeExpenses\Db\Weighing;
use OCA\HomeExpenses\Db\WeighingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

class GasService {
	public function __construct(
		private BottleTypeMapper $typeMapper,
		private GasCycleMapper $cycleMapper,
		private WeighingMapper $weighingMapper,
		private ConsumptionService $consumption,
	) {
	}

	// --- Tipos de garrafa ------------------------------------------------

	/** @return BottleType[] */
	public function findAllTypes(string $userId, bool $includeArchived = false): array {
		return $this->typeMapper->findAllForUser($userId, $includeArchived);
	}

	public function createType(
		string $userId,
		string $brand,
		string $gasType = BottleType::GAS_BUTANO,
		float $nominalKg = 13.0,
		?float $defaultTareKg = null,
		?string $notes = null,
	): BottleType {
		$type = new BottleType();
		$type->setUserId($userId);
		$type->setBrand($brand);
		$type->setGasType($gasType);
		$type->setNominalKg($nominalKg);
		$type->setDefaultTareKg($defaultTareKg);
		$type->setNotes($notes);
		$type->setArchived(false);

		return $this->typeMapper->insert($type);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function updateType(
		int $id,
		string $userId,
		?string $brand = null,
		?string $gasType = null,
		?float $nominalKg = null,
		?float $defaultTareKg = null,
		bool $defaultTareKgProvided = false,
		?string $notes = null,
		bool $notesProvided = false,
		?bool $archived = null,
	): BottleType {
		$type = $this->typeMapper->find($id, $userId);

		if ($brand !== null) {
			$type->setBrand($brand);
		}
		if ($gasType !== null) {
			$type->setGasType($gasType);
		}
		if ($nominalKg !== null) {
			$type->setNominalKg($nominalKg);
		}
		if ($defaultTareKgProvided) {
			$type->setDefaultTareKg($defaultTareKg);
		}
		if ($notesProvided) {
			$type->setNotes($notes);
		}
		if ($archived !== null) {
			$type->setArchived($archived);
		}

		return $this->typeMapper->update($type);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function deleteType(int $id, string $userId): void {
		$type = $this->typeMapper->find($id, $userId);

		// Apagar o tipo deixaria os ciclos sem forma de saber o nominal nem a
		// tara -- ou seja, sem nivel nem duracao calculaveis. Arquivar mantem
		// o historico legivel e tira-o das listas.
		if ($this->cycleMapper->findAllForBottleType($id) !== []) {
			throw new \RuntimeException(
				'Este tipo de garrafa tem ciclos registados. Arquiva-o (archived=true) '
				. 'em vez de o apagar, para o historico continuar a fazer sentido.'
			);
		}

		$this->typeMapper->delete($type);
	}

	// --- Ciclos ------------------------------------------------------------

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function createCycle(
		string $userId,
		int $bottleTypeId,
		\DateTimeImmutable $installedAt,
		string $appliance = GasCycle::APPLIANCE_AMBOS,
		?float $tareKg = null,
		?float $pricePaid = null,
		?string $supplier = null,
		?string $note = null,
	): GasCycle {
		$type = $this->typeMapper->find($bottleTypeId, $userId);

		$cycle = new GasCycle();
		$cycle->setUserId($userId);
		$cycle->setBottleTypeId($bottleTypeId);
		$cycle->setAppliance($appliance);
		$cycle->setInstalledAt($installedAt);
		// A tara por omissao do tipo e so um ponto de partida: o que conta e a
		// que esta gravada na gola DESTA garrafa, que deve ser corrigida na
		// primeira pesagem.
		$cycle->setTareKg($tareKg ?? $type->getDefaultTareKg());
		$cycle->setPricePaid($pricePaid);
		$cycle->setSupplier($supplier);
		$cycle->setNote($note);
		$cycle->setCreatedAt(new \DateTimeImmutable());

		return $this->cycleMapper->insert($cycle);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function updateCycle(
		int $id,
		string $userId,
		?int $bottleTypeId = null,
		?string $appliance = null,
		?\DateTimeImmutable $installedAt = null,
		?\DateTimeImmutable $removedAt = null,
		bool $removedAtProvided = false,
		?float $tareKg = null,
		bool $tareKgProvided = false,
		?float $pricePaid = null,
		bool $pricePaidProvided = false,
		?string $supplier = null,
		bool $supplierProvided = false,
		?string $note = null,
		bool $noteProvided = false,
	): GasCycle {
		$cycle = $this->cycleMapper->find($id, $userId);

		if ($bottleTypeId !== null) {
			$this->typeMapper->find($bottleTypeId, $userId);
			$cycle->setBottleTypeId($bottleTypeId);
		}
		if ($appliance !== null) {
			$cycle->setAppliance($appliance);
		}
		if ($installedAt !== null) {
			$cycle->setInstalledAt($installedAt);
		}
		if ($removedAtProvided) {
			$cycle->setRemovedAt($removedAt);
		}
		if ($tareKgProvided) {
			$cycle->setTareKg($tareKg);
		}
		if ($pricePaidProvided) {
			$cycle->setPricePaid($pricePaid);
		}
		if ($supplierProvided) {
			$cycle->setSupplier($supplier);
		}
		if ($noteProvided) {
			$cycle->setNote($note);
		}

		return $this->cycleMapper->update($cycle);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function delete(int $id, string $userId): void {
		$cycle = $this->cycleMapper->find($id, $userId);
		$this->weighingMapper->deleteAllForCycle($id);
		$this->cycleMapper->delete($cycle);
	}

	// --- Pesagens ----------------------------------------------------------

	/**
	 * @return Weighing[]
	 * @throws DoesNotExistException|MultipleObjectsReturnedException
	 */
	public function weighings(int $cycleId, string $userId): array {
		$this->cycleMapper->find($cycleId, $userId);
		return $this->weighingMapper->findAllForCycle($cycleId);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function addWeighing(
		int $cycleId,
		string $userId,
		\DateTimeImmutable $weighedAt,
		float $grossKg,
		?string $note = null,
	): Weighing {
		$this->cycleMapper->find($cycleId, $userId);

		$weighing = new Weighing();
		$weighing->setCycleId($cycleId);
		$weighing->setWeighedAt($weighedAt);
		$weighing->setGrossKg($grossKg);
		$weighing->setNote($note);

		return $this->weighingMapper->insert($weighing);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function deleteWeighing(int $id, string $userId): void {
		$weighing = $this->weighingMapper->find($id);
		$this->cycleMapper->find($weighing->getCycleId(), $userId);
		$this->weighingMapper->delete($weighing);
	}

	// --- Vista -------------------------------------------------------------

	/**
	 * Ciclos com tudo o que se deriva deles, e a media por tipo de garrafa --
	 * que e a resposta a "quanto tempo rende uma garrafa".
	 */
	public function overview(string $userId, \DateTimeImmutable $today): array {
		$types = $this->typeMapper->findAllForUser($userId, true);
		$typeById = [];
		foreach ($types as $type) {
			$typeById[$type->getId()] = $type;
		}

		$cycles = $this->cycleMapper->findAllForUser($userId);

		$detailed = [];
		$statsByType = [];

		foreach ($cycles as $cycle) {
			$type = $typeById[$cycle->getBottleTypeId()] ?? null;
			if ($type === null) {
				continue;
			}

			$weighings = $this->weighingMapper->findAllForCycle((int)$cycle->getId());
			$stats = $this->consumption->cycleStats($cycle, $type, $weighings, $today);

			$detailed[] = $cycle->jsonSerialize() + [
				'bottleType' => $type->jsonSerialize(),
				'weighings' => array_map(static fn (Weighing $w) => $w->jsonSerialize(), $weighings),
				'stats' => $stats,
			];

			$statsByType[$type->getId()][] = [
				'durationDays' => $stats['durationDays'],
				'closed' => $stats['closed'],
				'pricePaid' => $cycle->getPricePaid(),
			];
		}

		$averages = [];
		foreach ($statsByType as $typeId => $rows) {
			$type = $typeById[$typeId] ?? null;
			if ($type === null) {
				continue;
			}
			$averages[] = [
				'bottleTypeId' => $typeId,
				'label' => trim($type->getBrand() . ' ' . $type->getGasType() . ' ' . $type->getNominalKg() . 'kg'),
			] + $this->consumption->averageCycleDuration($rows);
		}

		return [
			'bottleTypes' => array_map(static fn (BottleType $t) => $t->jsonSerialize(), $types),
			'cycles' => $detailed,
			'openCycles' => array_values(array_filter($detailed, static fn (array $c) => $c['removedAt'] === null)),
			'averagesByType' => $averages,
		];
	}
}
