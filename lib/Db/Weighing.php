<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getCycleId()
 * @method void setCycleId(int $cycleId)
 * @method \DateTimeImmutable getWeighedAt()
 * @method void setWeighedAt(\DateTimeImmutable $weighedAt)
 * @method float getGrossKg()
 * @method void setGrossKg(float $grossKg)
 * @method ?string getNote()
 * @method void setNote(?string $note)
 */
class Weighing extends Entity implements \JsonSerializable {
	protected int $cycleId = 0;
	protected ?\DateTimeImmutable $weighedAt = null;
	protected float $grossKg = 0.0;
	protected ?string $note = null;

	public function __construct() {
		$this->addType('cycleId', Types::INTEGER);
		$this->addType('weighedAt', Types::DATE_IMMUTABLE);
		$this->addType('grossKg', Types::FLOAT);
		$this->addType('note', Types::TEXT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'cycleId' => $this->cycleId,
			'weighedAt' => $this->weighedAt?->format('Y-m-d'),
			'grossKg' => $this->grossKg,
			'note' => $this->note,
		];
	}
}
