<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Um PDF a espera de ser lido.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method ?int getMeterId()
 * @method void setMeterId(?int $meterId)
 * @method string getFilename()
 * @method void setFilename(string $filename)
 * @method string getStorageName()
 * @method void setStorageName(string $storageName)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method ?string getError()
 * @method void setError(?string $error)
 * @method ?string getResult()
 * @method void setResult(?string $result)
 * @method ?\DateTimeImmutable getCreatedAt()
 * @method void setCreatedAt(?\DateTimeImmutable $createdAt)
 * @method ?\DateTimeImmutable getFinishedAt()
 * @method void setFinishedAt(?\DateTimeImmutable $finishedAt)
 */
class ImportJob extends Entity implements \JsonSerializable {
	public const PENDING = 'pending';
	public const RUNNING = 'running';
	public const DONE = 'done';
	public const FAILED = 'failed';

	/**
	 * Uma falha de rede nao deve condenar a fatura, mas tentar sem fim num
	 * PDF que nunca vai ser legivel enche a fila para sempre.
	 */
	public const MAX_ATTEMPTS = 3;

	protected string $userId = '';
	protected ?int $meterId = null;
	protected string $filename = '';
	protected string $storageName = '';
	protected int $size = 0;
	protected string $status = self::PENDING;
	protected int $attempts = 0;
	protected ?string $error = null;
	protected ?string $result = null;
	protected ?\DateTimeImmutable $createdAt = null;
	protected ?\DateTimeImmutable $finishedAt = null;

	public function __construct() {
		$this->addType('meterId', Types::INTEGER);
		$this->addType('size', Types::INTEGER);
		$this->addType('attempts', Types::INTEGER);
		$this->addType('error', Types::TEXT);
		$this->addType('result', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME_IMMUTABLE);
		$this->addType('finishedAt', Types::DATETIME_IMMUTABLE);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'meterId' => $this->meterId,
			'filename' => $this->filename,
			'size' => $this->size,
			'status' => $this->status,
			'attempts' => $this->attempts,
			'error' => $this->error,
			'result' => $this->result === null ? null : json_decode($this->result, true),
			'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
			'finishedAt' => $this->finishedAt?->format(\DateTimeInterface::ATOM),
		];
	}
}
