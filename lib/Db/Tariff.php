<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getTariffOption()
 * @method void setTariffOption(string $tariffOption)
 * @method \DateTimeImmutable getValidFrom()
 * @method void setValidFrom(\DateTimeImmutable $validFrom)
 * @method ?\DateTimeImmutable getValidTo()
 * @method void setValidTo(?\DateTimeImmutable $validTo)
 * @method ?float getStandingChargeDay()
 * @method void setStandingChargeDay(?float $standingChargeDay)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 */
class Tariff extends Entity implements \JsonSerializable {
	protected string $userId = '';
	protected string $kind = 'electricity';
	protected string $name = '';
	protected string $tariffOption = 'simples';
	protected ?\DateTimeImmutable $validFrom = null;
	protected ?\DateTimeImmutable $validTo = null;
	protected ?float $standingChargeDay = null;
	protected string $currency = 'EUR';
	protected ?string $notes = null;

	public function __construct() {
		$this->addType('userId', Types::STRING);
		$this->addType('kind', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('tariffOption', Types::STRING);
		$this->addType('validFrom', Types::DATE_IMMUTABLE);
		$this->addType('validTo', Types::DATE_IMMUTABLE);
		$this->addType('standingChargeDay', Types::FLOAT);
		$this->addType('currency', Types::STRING);
		$this->addType('notes', Types::TEXT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'kind' => $this->kind,
			'name' => $this->name,
			'tariffOption' => $this->tariffOption,
			'validFrom' => $this->validFrom?->format('Y-m-d'),
			'validTo' => $this->validTo?->format('Y-m-d'),
			'standingChargeDay' => $this->standingChargeDay,
			'currency' => $this->currency,
			'notes' => $this->notes,
		];
	}
}
