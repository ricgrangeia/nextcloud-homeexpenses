<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getReadingId()
 * @method void setReadingId(int $readingId)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method float getValue()
 * @method void setValue(float $value)
 */
class ReadingValue extends Entity implements \JsonSerializable {
	protected int $readingId = 0;
	protected int $registerId = 0;
	protected float $value = 0.0;

	public function __construct() {
		$this->addType('readingId', Types::INTEGER);
		$this->addType('registerId', Types::INTEGER);
		$this->addType('value', Types::FLOAT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'readingId' => $this->readingId,
			'registerId' => $this->registerId,
			'value' => $this->value,
		];
	}
}
