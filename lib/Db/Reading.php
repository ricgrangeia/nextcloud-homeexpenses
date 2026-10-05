<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getMeterId()
 * @method void setMeterId(int $meterId)
 * @method \DateTimeImmutable getReadAt()
 * @method void setReadAt(\DateTimeImmutable $readAt)
 * @method bool getIsEstimate()
 * @method void setIsEstimate(bool $isEstimate)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method ?string getNote()
 * @method void setNote(?string $note)
 * @method ?\DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(?\DateTimeImmutable $createdAt)
 */
class Reading extends Entity implements \JsonSerializable {
	protected int $meterId = 0;
	protected ?\DateTimeImmutable $readAt = null;
	protected bool $isEstimate = false;
	protected string $source = 'manual';
	protected ?string $note = null;
	protected ?\DateTimeImmutable $createdAt = null;

	public function __construct() {
		$this->addType('meterId', Types::INTEGER);
		$this->addType('readAt', Types::DATE_IMMUTABLE);
		$this->addType('isEstimate', Types::BOOLEAN);
		$this->addType('source', Types::STRING);
		$this->addType('note', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME_IMMUTABLE);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'meterId' => $this->meterId,
			'readAt' => $this->readAt?->format('Y-m-d'),
			'isEstimate' => $this->isEstimate,
			'source' => $this->source,
			'note' => $this->note,
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
