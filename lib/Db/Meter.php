<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getLocation()
 * @method void setLocation(?string $location)
 * @method ?string getSerial()
 * @method void setSerial(?string $serial)
 * @method string getUnit()
 * @method void setUnit(string $unit)
 * @method int getDigits()
 * @method void setDigits(int $digits)
 * @method string getTariffOption()
 * @method void setTariffOption(string $tariffOption)
 * @method ?\DateTimeImmutable getInstalledAt()
 * @method void setInstalledAt(?\DateTimeImmutable $installedAt)
 * @method ?\DateTimeImmutable getRemovedAt()
 * @method void setRemovedAt(?\DateTimeImmutable $removedAt)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 * @method ?\DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(?\DateTimeImmutable $createdAt)
 * @method bool getArchived()
 * @method void setArchived(bool $archived)
 */
class Meter extends Entity implements \JsonSerializable {
	public const KIND_ELECTRICITY = 'electricity';
	public const KIND_WATER = 'water';

	protected string $userId = '';
	protected string $kind = self::KIND_ELECTRICITY;
	protected string $name = '';
	protected ?string $location = null;
	protected ?string $serial = null;
	protected string $unit = 'kWh';
	protected int $digits = 0;
	protected string $tariffOption = 'simples';
	protected ?\DateTimeImmutable $installedAt = null;
	protected ?\DateTimeImmutable $removedAt = null;
	protected ?string $notes = null;
	protected ?\DateTimeImmutable $createdAt = null;
	protected bool $archived = false;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('kind', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('location', Types::STRING);
		$this->addType('serial', Types::STRING);
		$this->addType('unit', Types::STRING);
		$this->addType('digits', Types::INTEGER);
		$this->addType('tariffOption', Types::STRING);
		$this->addType('installedAt', Types::DATE_IMMUTABLE);
		$this->addType('removedAt', Types::DATE_IMMUTABLE);
		$this->addType('notes', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME_IMMUTABLE);
		$this->addType('archived', Types::BOOLEAN);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'kind' => $this->kind,
			'name' => $this->name,
			'location' => $this->location,
			'serial' => $this->serial,
			'unit' => $this->unit,
			'digits' => $this->digits,
			'tariffOption' => $this->tariffOption,
			'installedAt' => $this->installedAt?->format('Y-m-d'),
			'removedAt' => $this->removedAt?->format('Y-m-d'),
			'notes' => $this->notes,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'archived' => $this->archived,
		];
	}
}
