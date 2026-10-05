<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Service;

use OCA\HomeExpenses\Db\Meter;
use OCA\HomeExpenses\Db\MeterMapper;
use OCA\HomeExpenses\Db\MeterRegister;
use OCA\HomeExpenses\Db\MeterRegisterMapper;
use OCA\HomeExpenses\Db\ReadingMapper;
use OCA\HomeExpenses\Db\ReadingValueMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

class MeterService {
	public const LABELS = [
		MeterRegister::CODE_VAZIO => 'Vazio',
		MeterRegister::CODE_CHEIAS => 'Cheias',
		MeterRegister::CODE_PONTA => 'Ponta',
		MeterRegister::CODE_FORA_VAZIO => 'Fora de Vazio',
		MeterRegister::CODE_TOTAL => 'Total',
	];

	public function __construct(
		private MeterMapper $meterMapper,
		private MeterRegisterMapper $registerMapper,
		private ReadingMapper $readingMapper,
		private ReadingValueMapper $readingValueMapper,
	) {
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function find(int $id, string $userId): Meter {
		return $this->meterMapper->find($id, $userId);
	}

	/** @return Meter[] */
	public function findAll(string $userId, bool $includeArchived = false, ?string $kind = null): array {
		return $this->meterMapper->findAllForUser($userId, $includeArchived, $kind);
	}

	/** @return MeterRegister[] */
	public function registers(int $meterId, string $userId): array {
		$this->meterMapper->find($meterId, $userId);
		return $this->registerMapper->findAllForMeter($meterId);
	}

	/**
	 * Registos por omissao quando nao sao indicados explicitamente.
	 *
	 * Nota: isto deriva do que o CONTRATO fatura, que e so um palpite razoavel.
	 * O que manda e o que o mostrador do contador mostra -- um contador
	 * tri-horario faturado em bi-horario e o caso normal em Portugal, e nesse
	 * caso passa-se ['V','C','P'] explicitamente, que e o que permite depois
	 * comparar os dois tarifarios.
	 *
	 * @return string[]
	 */
	public function defaultRegisterCodes(string $kind, string $tariffOption): array {
		if ($kind !== Meter::KIND_ELECTRICITY) {
			return [MeterRegister::CODE_TOTAL];
		}
		return match ($tariffOption) {
			'tri' => [MeterRegister::CODE_VAZIO, MeterRegister::CODE_CHEIAS, MeterRegister::CODE_PONTA],
			'bi' => [MeterRegister::CODE_VAZIO, MeterRegister::CODE_FORA_VAZIO],
			default => [MeterRegister::CODE_TOTAL],
		};
	}

	/**
	 * @param string[]|null $registerCodes
	 * @return array{meter: Meter, registers: MeterRegister[]}
	 */
	public function create(
		string $userId,
		string $name,
		string $kind = Meter::KIND_ELECTRICITY,
		?string $unit = null,
		string $tariffOption = 'simples',
		?array $registerCodes = null,
		?string $location = null,
		?string $serial = null,
		int $digits = 0,
		?\DateTimeImmutable $installedAt = null,
		?string $notes = null,
	): array {
		$meter = new Meter();
		$meter->setUserId($userId);
		$meter->setName($name);
		$meter->setKind($kind);
		$meter->setUnit($unit ?? ($kind === Meter::KIND_WATER ? 'm3' : 'kWh'));
		$meter->setTariffOption($tariffOption);
		$meter->setLocation($location);
		$meter->setSerial($serial);
		$meter->setDigits($digits);
		$meter->setInstalledAt($installedAt);
		$meter->setNotes($notes);
		$meter->setCreatedAt(new \DateTimeImmutable());
		$meter->setArchived(false);

		$meter = $this->meterMapper->insert($meter);

		$codes = $registerCodes ?? $this->defaultRegisterCodes($kind, $tariffOption);
		$registers = $this->replaceRegisters((int)$meter->getId(), $codes);

		return ['meter' => $meter, 'registers' => $registers];
	}

	/**
	 * @param string[] $codes
	 * @return MeterRegister[]
	 */
	private function replaceRegisters(int $meterId, array $codes): array {
		$this->registerMapper->deleteAllForMeter($meterId);

		$registers = [];
		$sort = 0;
		foreach ($codes as $code) {
			$code = strtoupper(trim((string)$code));
			if ($code === '') {
				continue;
			}
			$register = new MeterRegister();
			$register->setMeterId($meterId);
			$register->setCode($code);
			$register->setLabel(self::LABELS[$code] ?? $code);
			$register->setSortOrder($sort++);
			$registers[] = $this->registerMapper->insert($register);
		}

		return $registers;
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function update(
		int $id,
		string $userId,
		?string $name = null,
		?string $location = null,
		bool $locationProvided = false,
		?string $serial = null,
		bool $serialProvided = false,
		?string $unit = null,
		?int $digits = null,
		?string $tariffOption = null,
		?\DateTimeImmutable $installedAt = null,
		bool $installedAtProvided = false,
		?\DateTimeImmutable $removedAt = null,
		bool $removedAtProvided = false,
		?string $notes = null,
		bool $notesProvided = false,
		?bool $archived = null,
	): Meter {
		$meter = $this->meterMapper->find($id, $userId);

		if ($name !== null) {
			$meter->setName($name);
		}
		if ($locationProvided) {
			$meter->setLocation($location);
		}
		if ($serialProvided) {
			$meter->setSerial($serial);
		}
		if ($unit !== null) {
			$meter->setUnit($unit);
		}
		if ($digits !== null) {
			$meter->setDigits($digits);
		}
		if ($tariffOption !== null) {
			$meter->setTariffOption($tariffOption);
		}
		if ($installedAtProvided) {
			$meter->setInstalledAt($installedAt);
		}
		if ($removedAtProvided) {
			$meter->setRemovedAt($removedAt);
		}
		if ($notesProvided) {
			$meter->setNotes($notes);
		}
		if ($archived !== null) {
			$meter->setArchived($archived);
		}

		return $this->meterMapper->update($meter);
	}

	/**
	 * Substitui os registos de um contador. Apaga as leituras, porque um valor
	 * lancado contra um registo que deixou de existir nao e recuperavel nem
	 * interpretavel -- e melhor perde-lo de forma visivel do que guardar uma
	 * serie que ja nao corresponde ao mostrador.
	 *
	 * @param string[] $codes
	 * @return MeterRegister[]
	 * @throws DoesNotExistException|MultipleObjectsReturnedException
	 */
	public function setRegisters(int $meterId, string $userId, array $codes): array {
		$this->meterMapper->find($meterId, $userId);

		$readingIds = array_map(
			static fn ($reading) => (int)$reading->getId(),
			$this->readingMapper->findAllForMeter($meterId),
		);
		$this->readingValueMapper->deleteAllForReadings($readingIds);
		$this->readingMapper->deleteAllForMeter($meterId);

		return $this->replaceRegisters($meterId, $codes);
	}

	/** @throws DoesNotExistException|MultipleObjectsReturnedException */
	public function delete(int $id, string $userId): void {
		$meter = $this->meterMapper->find($id, $userId);

		$readingIds = array_map(
			static fn ($reading) => (int)$reading->getId(),
			$this->readingMapper->findAllForMeter($id),
		);
		$this->readingValueMapper->deleteAllForReadings($readingIds);
		$this->readingMapper->deleteAllForMeter($id);
		$this->registerMapper->deleteAllForMeter($id);
		$this->meterMapper->delete($meter);
	}
}
