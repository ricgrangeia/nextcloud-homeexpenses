<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * Uma linha de encargo de uma fatura, ja classificada.
 *
 * Guarda-se a linha como veio, nao o agregado: a fatura parte o mesmo consumo
 * em duas linhas por causa das taxas de IVA diferentes, e e util poder ver
 * essa divisao tal como o fornecedor a fez. A soma faz-se ao ler.
 *
 * @method int getInvoiceId()
 * @method void setInvoiceId(int $invoiceId)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method ?string getRegisterCode()
 * @method void setRegisterCode(?string $registerCode)
 * @method bool getIsEstimate()
 * @method void setIsEstimate(bool $isEstimate)
 * @method ?\DateTimeImmutable getPeriodFrom()
 * @method void setPeriodFrom(?\DateTimeImmutable $periodFrom)
 * @method ?\DateTimeImmutable getPeriodTo()
 * @method void setPeriodTo(?\DateTimeImmutable $periodTo)
 * @method ?float getQuantity()
 * @method void setQuantity(?float $quantity)
 * @method ?float getUnitPrice()
 * @method void setUnitPrice(?float $unitPrice)
 * @method ?float getDiscount()
 * @method void setDiscount(?float $discount)
 * @method ?float getVatRate()
 * @method void setVatRate(?float $vatRate)
 * @method ?float getTotalNet()
 * @method void setTotalNet(?float $totalNet)
 * @method int getPage()
 * @method void setPage(int $page)
 */
class InvoiceLine extends Entity implements \JsonSerializable {
	protected int $invoiceId = 0;
	protected string $description = '';
	protected string $kind = 'other';
	protected ?string $registerCode = null;
	protected bool $isEstimate = false;
	protected ?\DateTimeImmutable $periodFrom = null;
	protected ?\DateTimeImmutable $periodTo = null;
	protected ?float $quantity = null;
	protected ?float $unitPrice = null;
	protected ?float $discount = null;
	protected ?float $vatRate = null;
	protected ?float $totalNet = null;
	protected int $page = 0;

	public function __construct() {
		$this->addType('invoiceId', Types::INTEGER);
		$this->addType('isEstimate', Types::BOOLEAN);
		$this->addType('periodFrom', Types::DATE_IMMUTABLE);
		$this->addType('periodTo', Types::DATE_IMMUTABLE);
		$this->addType('quantity', Types::FLOAT);
		$this->addType('unitPrice', Types::FLOAT);
		$this->addType('discount', Types::FLOAT);
		$this->addType('vatRate', Types::FLOAT);
		$this->addType('totalNet', Types::FLOAT);
		$this->addType('page', Types::INTEGER);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'invoiceId' => $this->invoiceId,
			'description' => $this->description,
			'kind' => $this->kind,
			'registerCode' => $this->registerCode,
			'isEstimate' => $this->isEstimate,
			'periodFrom' => $this->periodFrom?->format('Y-m-d'),
			'periodTo' => $this->periodTo?->format('Y-m-d'),
			'quantity' => $this->quantity,
			'unitPrice' => $this->unitPrice,
			'discount' => $this->discount,
			'vatRate' => $this->vatRate,
			'totalNet' => $this->totalNet,
			'page' => $this->page,
		];
	}
}
