<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Um documento fiscal lido de um PDF de fatura.
 *
 * Um PDF da EDP traz mais do que um: a eletricidade e a Contribuicao
 * Audiovisual sao faturas separadas, cada uma com o seu ATCUD. Por isso a
 * unidade aqui e o documento fiscal, nao o ficheiro.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method ?int getMeterId()
 * @method void setMeterId(?int $meterId)
 * @method ?string getSupplier()
 * @method void setSupplier(?string $supplier)
 * @method ?string getDocType()
 * @method void setDocType(?string $docType)
 * @method ?string getDocNumber()
 * @method void setDocNumber(?string $docNumber)
 * @method string getAtcud()
 * @method void setAtcud(string $atcud)
 * @method ?\DateTimeImmutable getIssuedAt()
 * @method void setIssuedAt(?\DateTimeImmutable $issuedAt)
 * @method ?\DateTimeImmutable getPeriodFrom()
 * @method void setPeriodFrom(?\DateTimeImmutable $periodFrom)
 * @method ?\DateTimeImmutable getPeriodTo()
 * @method void setPeriodTo(?\DateTimeImmutable $periodTo)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method ?float getTotalNet()
 * @method void setTotalNet(?float $totalNet)
 * @method ?float getTotalVat()
 * @method void setTotalVat(?float $totalVat)
 * @method ?float getTotalGross()
 * @method void setTotalGross(?float $totalGross)
 * @method bool getVerified()
 * @method void setVerified(bool $verified)
 * @method ?string getSourceName()
 * @method void setSourceName(?string $sourceName)
 */
class Invoice extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected ?int $meterId = null;
	protected ?string $supplier = null;
	protected ?string $docType = null;
	protected ?string $docNumber = null;
	protected string $atcud = '';
	protected ?\DateTimeImmutable $issuedAt = null;
	protected ?\DateTimeImmutable $periodFrom = null;
	protected ?\DateTimeImmutable $periodTo = null;
	protected string $currency = 'EUR';
	protected ?float $totalNet = null;
	protected ?float $totalVat = null;
	protected ?float $totalGross = null;
	protected bool $verified = false;
	protected ?string $sourceName = null;
	protected ?\DateTimeImmutable $createdAt = null;

	public function __construct() {
		$this->addType('meterId', Types::INTEGER);
		$this->addType('issuedAt', Types::DATE_IMMUTABLE);
		$this->addType('periodFrom', Types::DATE_IMMUTABLE);
		$this->addType('periodTo', Types::DATE_IMMUTABLE);
		$this->addType('totalNet', Types::FLOAT);
		$this->addType('totalVat', Types::FLOAT);
		$this->addType('totalGross', Types::FLOAT);
		$this->addType('verified', Types::BOOLEAN);
		$this->addType('createdAt', Types::DATETIME_IMMUTABLE);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'meterId' => $this->meterId,
			'supplier' => $this->supplier,
			'docType' => $this->docType,
			'docNumber' => $this->docNumber,
			'atcud' => $this->atcud,
			'issuedAt' => $this->issuedAt?->format('Y-m-d'),
			'periodFrom' => $this->periodFrom?->format('Y-m-d'),
			'periodTo' => $this->periodTo?->format('Y-m-d'),
			'currency' => $this->currency,
			'totalNet' => $this->totalNet,
			'totalVat' => $this->totalVat,
			'totalGross' => $this->totalGross,
			'verified' => $this->verified,
			'sourceName' => $this->sourceName,
		];
	}
}
