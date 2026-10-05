<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getMeterId()
 * @method void setMeterId(int $meterId)
 * @method string getCode()
 * @method void setCode(string $code)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 */
class MeterRegister extends Entity implements \JsonSerializable {
	public const CODE_VAZIO = 'V';
	public const CODE_CHEIAS = 'C';
	public const CODE_PONTA = 'P';
	public const CODE_FORA_VAZIO = 'FV';
	public const CODE_TOTAL = 'TOTAL';

	protected int $meterId = 0;
	protected string $code = self::CODE_TOTAL;
	protected string $label = '';
	protected int $sortOrder = 0;

	public function __construct() {
		$this->addType('meterId', Types::INTEGER);
		$this->addType('code', Types::STRING);
		$this->addType('label', Types::STRING);
		$this->addType('sortOrder', Types::INTEGER);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'meterId' => $this->meterId,
			'code' => $this->code,
			'label' => $this->label,
			'sortOrder' => $this->sortOrder,
		];
	}
}
