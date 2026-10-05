<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getBrand()
 * @method void setBrand(string $brand)
 * @method string getGasType()
 * @method void setGasType(string $gasType)
 * @method float getNominalKg()
 * @method void setNominalKg(float $nominalKg)
 * @method ?float getDefaultTareKg()
 * @method void setDefaultTareKg(?float $defaultTareKg)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 * @method bool getArchived()
 * @method void setArchived(bool $archived)
 */
class BottleType extends Entity implements \JsonSerializable {
	public const GAS_BUTANO = 'butano';
	public const GAS_PROPANO = 'propano';

	protected string $userId = '';
	protected string $brand = '';
	protected string $gasType = self::GAS_BUTANO;
	protected float $nominalKg = 13.0;
	protected ?float $defaultTareKg = null;
	protected ?string $notes = null;
	protected bool $archived = false;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('brand', Types::STRING);
		$this->addType('gasType', Types::STRING);
		$this->addType('nominalKg', Types::FLOAT);
		$this->addType('defaultTareKg', Types::FLOAT);
		$this->addType('notes', Types::TEXT);
		$this->addType('archived', Types::BOOLEAN);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'brand' => $this->brand,
			'gasType' => $this->gasType,
			'nominalKg' => $this->nominalKg,
			'defaultTareKg' => $this->defaultTareKg,
			'notes' => $this->notes,
			'archived' => $this->archived,
		];
	}
}
