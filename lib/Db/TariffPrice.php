<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getTariffId()
 * @method void setTariffId(int $tariffId)
 * @method string getRegisterCode()
 * @method void setRegisterCode(string $registerCode)
 * @method float getPricePerUnit()
 * @method void setPricePerUnit(float $pricePerUnit)
 */
class TariffPrice extends Entity implements \JsonSerializable {
	protected int $tariffId = 0;
	protected string $registerCode = 'TOTAL';
	protected float $pricePerUnit = 0.0;

	public function __construct() {
		$this->addType('tariffId', Types::INTEGER);
		$this->addType('registerCode', Types::STRING);
		$this->addType('pricePerUnit', Types::FLOAT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'tariffId' => $this->tariffId,
			'registerCode' => $this->registerCode,
			'pricePerUnit' => $this->pricePerUnit,
		];
	}
}
