<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getBottleTypeId()
 * @method void setBottleTypeId(int $bottleTypeId)
 * @method string getAppliance()
 * @method void setAppliance(string $appliance)
 * @method \DateTimeImmutable getInstalledAt()
 * @method void setInstalledAt(\DateTimeImmutable $installedAt)
 * @method ?\DateTimeImmutable getRemovedAt()
 * @method void setRemovedAt(?\DateTimeImmutable $removedAt)
 * @method ?float getTareKg()
 * @method void setTareKg(?float $tareKg)
 * @method ?float getPricePaid()
 * @method void setPricePaid(?float $pricePaid)
 * @method ?string getSupplier()
 * @method void setSupplier(?string $supplier)
 * @method ?string getNote()
 * @method void setNote(?string $note)
 * @method ?\DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(?\DateTimeImmutable $createdAt)
 */
class GasCycle extends Entity implements \JsonSerializable {
	public const APPLIANCE_ESQUENTADOR = 'esquentador';
	public const APPLIANCE_FOGAO = 'fogao';
	public const APPLIANCE_AMBOS = 'ambos';
	public const APPLIANCE_OUTRO = 'outro';

	protected string $userId = '';
	protected int $bottleTypeId = 0;
	protected string $appliance = self::APPLIANCE_AMBOS;
	protected ?\DateTimeImmutable $installedAt = null;
	protected ?\DateTimeImmutable $removedAt = null;
	protected ?float $tareKg = null;
	protected ?float $pricePaid = null;
	protected ?string $supplier = null;
	protected ?string $note = null;
	protected ?\DateTimeImmutable $createdAt = null;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('bottleTypeId', Types::INTEGER);
		$this->addType('appliance', Types::STRING);
		$this->addType('installedAt', Types::DATE_IMMUTABLE);
		$this->addType('removedAt', Types::DATE_IMMUTABLE);
		$this->addType('tareKg', Types::FLOAT);
		$this->addType('pricePaid', Types::FLOAT);
		$this->addType('supplier', Types::STRING);
		$this->addType('note', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME_IMMUTABLE);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'bottleTypeId' => $this->bottleTypeId,
			'appliance' => $this->appliance,
			'installedAt' => $this->installedAt?->format('Y-m-d'),
			'removedAt' => $this->removedAt?->format('Y-m-d'),
			'tareKg' => $this->tareKg,
			'pricePaid' => $this->pricePaid,
			'supplier' => $this->supplier,
			'note' => $this->note,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
