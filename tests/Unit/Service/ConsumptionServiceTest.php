<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Tests\Unit\Service;

use OCA\HomeExpenses\Db\BottleType;
use OCA\HomeExpenses\Db\GasCycle;
use OCA\HomeExpenses\Db\Meter;
use OCA\HomeExpenses\Db\MeterRegister;
use OCA\HomeExpenses\Db\Reading;
use OCA\HomeExpenses\Db\ReadingValue;
use OCA\HomeExpenses\Db\Weighing;
use OCA\HomeExpenses\Service\ConsumptionService;
use PHPUnit\Framework\TestCase;

class ConsumptionServiceTest extends TestCase {
	private ConsumptionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new ConsumptionService();
	}

	private function meter(int $digits = 0, string $unit = 'kWh'): Meter {
		$meter = new Meter();
		$meter->setName('Contador');
		$meter->setKind(Meter::KIND_ELECTRICITY);
		$meter->setUnit($unit);
		$meter->setDigits($digits);
		return $meter;
	}

	private function register(int $id, string $code): MeterRegister {
		$register = new MeterRegister();
		$register->setId($id);
		$register->setCode($code);
		$register->setLabel($code);
		return $register;
	}

	private function reading(int $id, string $date, bool $estimate = false): Reading {
		$reading = new Reading();
		$reading->setId($id);
		$reading->setReadAt(new \DateTimeImmutable($date));
		$reading->setIsEstimate($estimate);
		return $reading;
	}

	private function value(int $readingId, int $registerId, float $value): ReadingValue {
		$entry = new ReadingValue();
		$entry->setReadingId($readingId);
		$entry->setRegisterId($registerId);
		$entry->setValue($value);
		return $entry;
	}

	// --- delta / volta ao zero -------------------------------------------

	public function testDeltaIsPlainDifference(): void {
		self::assertSame(120.0, $this->service->delta(1000.0, 1120.0, 0));
	}

	public function testDeltaCorrectsRolloverWhenDigitsAreKnown(): void {
		// Contador de 5 digitos: 99.990 -> 10 sao 20 kWh, nao -99.980.
		self::assertSame(20.0, $this->service->delta(99990.0, 10.0, 5));
	}

	public function testDeltaLeavesNegativeAloneWithoutDigits(): void {
		// Sem numero de digitos nao ha como distinguir uma volta de um erro de
		// lancamento -- fica negativo, para ser assinalado como anomalia.
		self::assertSame(-100.0, $this->service->delta(500.0, 400.0, 0));
	}

	// --- serie de consumo --------------------------------------------------

	public function testMeterSeriesComputesPerRegisterAndPerDay(): void {
		$meter = $this->meter();
		$registers = [$this->register(1, 'V'), $this->register(2, 'C'), $this->register(3, 'P')];

		$readings = [$this->reading(10, '2026-01-01'), $this->reading(11, '2026-01-11')];
		$values = [
			10 => [$this->value(10, 1, 1000.0), $this->value(10, 2, 2000.0), $this->value(10, 3, 500.0)],
			11 => [$this->value(11, 1, 1100.0), $this->value(11, 2, 2200.0), $this->value(11, 3, 530.0)],
		];

		$series = $this->service->meterSeries($meter, $registers, $readings, $values);

		self::assertCount(1, $series['periods']);
		$period = $series['periods'][0];
		self::assertSame(10, $period['days']);
		self::assertSame(100.0, $period['byRegister']['V']['consumed']);
		self::assertSame(10.0, $period['byRegister']['V']['perDay']);
		self::assertSame(200.0, $period['byRegister']['C']['consumed']);
		self::assertSame(30.0, $period['byRegister']['P']['consumed']);
		self::assertSame(330.0, $period['total']);
		self::assertSame(33.0, $period['totalPerDay']);
		self::assertSame(['V' => 100.0, 'C' => 200.0, 'P' => 30.0], $series['totals']);
		self::assertSame([], $series['anomalies']);
	}

	public function testMeterSeriesFlagsReadingThatWentDown(): void {
		$meter = $this->meter();
		$registers = [$this->register(1, 'TOTAL')];
		$readings = [$this->reading(10, '2026-01-01'), $this->reading(11, '2026-02-01')];
		$values = [
			10 => [$this->value(10, 1, 500.0)],
			11 => [$this->value(11, 1, 400.0)],
		];

		$series = $this->service->meterSeries($meter, $registers, $readings, $values);

		self::assertCount(1, $series['anomalies']);
		self::assertSame('TOTAL', $series['anomalies'][0]['registerCode']);
		self::assertSame(11, $series['anomalies'][0]['readingId']);
	}

	public function testMeterSeriesMarksPeriodTouchingAnEstimate(): void {
		$meter = $this->meter();
		$registers = [$this->register(1, 'TOTAL')];
		$readings = [$this->reading(10, '2026-01-01'), $this->reading(11, '2026-02-01', true)];
		$values = [
			10 => [$this->value(10, 1, 100.0)],
			11 => [$this->value(11, 1, 200.0)],
		];

		$series = $this->service->meterSeries($meter, $registers, $readings, $values);

		self::assertTrue($series['periods'][0]['isEstimate']);
	}

	// --- precos e comparacao de tarifarios ---------------------------------

	public function testCheiasAndPontaFallBackToForaDeVazioPrice(): void {
		// A regra que torna comparavel um contador tri-horario com um
		// tarifario bi-horario: fora de vazio = cheias + ponta.
		$prices = ['V' => 0.10, 'FV' => 0.20];

		self::assertSame(0.10, $this->service->resolvePrice('V', $prices));
		self::assertSame(0.20, $this->service->resolvePrice('C', $prices));
		self::assertSame(0.20, $this->service->resolvePrice('P', $prices));
	}

	public function testResolvePriceReturnsNullWhenNothingApplies(): void {
		self::assertNull($this->service->resolvePrice('V', ['FV' => 0.2]));
	}

	public function testCostForReportsUnpricedInsteadOfAssumingZero(): void {
		$cost = $this->service->costFor(['V' => 100.0, 'C' => 50.0], ['V' => 0.10], null, 0);

		self::assertSame(10.0, $cost['energy']);
		self::assertSame(['C'], $cost['unpriced']);
	}

	public function testCostForAddsStandingCharge(): void {
		$cost = $this->service->costFor(['TOTAL' => 100.0], ['TOTAL' => 0.15], 0.25, 30);

		self::assertSame(15.0, $cost['energy']);
		self::assertSame(7.5, $cost['standing']);
		self::assertSame(22.5, $cost['total']);
	}

	public function testCompareTariffsPicksTheCheaperOption(): void {
		// Consumo real de um contador tri-horario, faturado em bi-horario.
		$totals = ['V' => 300.0, 'C' => 400.0, 'P' => 100.0];

		$comparison = $this->service->compareTariffs($totals, [
			['id' => 1, 'name' => 'Bi-horario', 'option' => 'bi', 'prices' => ['V' => 0.10, 'FV' => 0.20], 'standingChargeDay' => null],
			['id' => 2, 'name' => 'Tri-horario', 'option' => 'tri', 'prices' => ['V' => 0.10, 'C' => 0.18, 'P' => 0.30], 'standingChargeDay' => null],
		], 30);

		// bi:  300*0.10 + 500*0.20          = 30 + 100 = 130
		// tri: 300*0.10 + 400*0.18 + 100*0.30 = 30 + 72 + 30 = 132
		self::assertSame(130.0, $comparison['results'][0]['cost']['total']);
		self::assertSame(132.0, $comparison['results'][1]['cost']['total']);
		self::assertSame('Bi-horario', $comparison['cheapest']['name']);
		self::assertSame(2.0, $comparison['results'][1]['savingVsCheapest']);
	}

	public function testCompareTariffsExcludesIncompleteOnesFromWinning(): void {
		$totals = ['V' => 100.0, 'C' => 100.0];

		$comparison = $this->service->compareTariffs($totals, [
			['id' => 1, 'name' => 'Completo', 'option' => 'bi', 'prices' => ['V' => 0.10, 'FV' => 0.20], 'standingChargeDay' => null],
			['id' => 2, 'name' => 'So vazio', 'option' => 'bi', 'prices' => ['V' => 0.01], 'standingChargeDay' => null],
		], 30);

		// O incompleto daria 1 EUR e ganharia, se contasse.
		self::assertSame('Completo', $comparison['cheapest']['name']);
		self::assertFalse($comparison['results'][1]['comparable']);
		self::assertNull($comparison['results'][1]['savingVsCheapest']);
	}

	// --- gas -----------------------------------------------------------------

	public function testBottleLevelFromGrossWeight(): void {
		// Garrafa de 13 kg, tara 15,2 kg, na balanca 22,2 kg -> 7 kg de gas.
		$level = $this->service->bottleLevel(22.2, 15.2, 13.0);

		self::assertNotNull($level);
		self::assertSame(7.0, $level['netKg']);
		self::assertSame(53.8, $level['levelPct']);
	}

	public function testBottleLevelIsNullWithoutTare(): void {
		// Sem a tara gravada na gola nao ha nivel -- nao se inventa um.
		self::assertNull($this->service->bottleLevel(22.2, null, 13.0));
	}

	public function testBottleLevelClampsToZero(): void {
		$level = $this->service->bottleLevel(14.0, 15.2, 13.0);
		self::assertSame(0.0, $level['levelPct']);
	}

	public function testCycleStatsUsesWeighingsForRate(): void {
		$type = new BottleType();
		$type->setNominalKg(13.0);
		$type->setDefaultTareKg(15.0);

		$cycle = new GasCycle();
		$cycle->setId(1);
		$cycle->setInstalledAt(new \DateTimeImmutable('2026-01-01'));
		$cycle->setTareKg(15.0);

		$weighings = [
			$this->weighing('2026-01-01', 28.0),
			$this->weighing('2026-01-21', 24.0),
		];

		$stats = $this->service->cycleStats($cycle, $type, $weighings, new \DateTimeImmutable('2026-01-21'));

		// 4 kg em 20 dias.
		self::assertSame(0.2, $stats['kgPerDay']);
		self::assertSame('weighings', $stats['kgPerDaySource']);
		self::assertSame(9.0, $stats['level']['netKg']);
		// 9 kg restantes a 0,2 kg/dia = 45 dias.
		self::assertSame(45, $stats['daysRemaining']);
		self::assertSame('2026-03-07', $stats['estimatedEmptyDate']);
		self::assertFalse($stats['closed']);
	}

	public function testCycleStatsFallsBackToFullCycleRateWhenClosed(): void {
		$type = new BottleType();
		$type->setNominalKg(13.0);

		$cycle = new GasCycle();
		$cycle->setId(1);
		$cycle->setInstalledAt(new \DateTimeImmutable('2026-01-01'));
		$cycle->setRemovedAt(new \DateTimeImmutable('2026-03-02'));
		$cycle->setPricePaid(34.0);

		$stats = $this->service->cycleStats($cycle, $type, [], new \DateTimeImmutable('2026-06-01'));

		self::assertTrue($stats['closed']);
		self::assertSame(60, $stats['durationDays']);
		self::assertSame(0.217, $stats['kgPerDay']);
		self::assertSame('fullCycle', $stats['kgPerDaySource']);
		self::assertSame(0.567, $stats['costPerDay']);
		// Duracao conta ate ao fim do ciclo, nao ate hoje.
		self::assertNull($stats['daysRemaining']);
	}

	public function testCycleStatsSignalsMissingTare(): void {
		$type = new BottleType();
		$type->setNominalKg(13.0);

		$cycle = new GasCycle();
		$cycle->setId(1);
		$cycle->setInstalledAt(new \DateTimeImmutable('2026-01-01'));

		$stats = $this->service->cycleStats($cycle, $type, [$this->weighing('2026-01-05', 27.0)], new \DateTimeImmutable('2026-01-10'));

		self::assertTrue($stats['needsTare']);
		self::assertNull($stats['level']);
	}

	public function testAverageCycleDurationIgnoresOpenCycles(): void {
		$averages = $this->service->averageCycleDuration([
			['durationDays' => 60, 'closed' => true, 'pricePaid' => 34.0],
			['durationDays' => 80, 'closed' => true, 'pricePaid' => 36.0],
			['durationDays' => 5, 'closed' => false, 'pricePaid' => 34.0],
		]);

		self::assertSame(2, $averages['cycles']);
		self::assertSame(70, $averages['averageDays']);
		self::assertSame(60, $averages['shortestDays']);
		self::assertSame(80, $averages['longestDays']);
		self::assertSame(35.0, $averages['averageCost']);
	}

	public function testAverageCycleDurationWithNoClosedCycles(): void {
		$averages = $this->service->averageCycleDuration([
			['durationDays' => 5, 'closed' => false, 'pricePaid' => null],
		]);

		self::assertSame(0, $averages['cycles']);
		self::assertNull($averages['averageDays']);
	}

	private function weighing(string $date, float $grossKg): Weighing {
		$weighing = new Weighing();
		$weighing->setWeighedAt(new \DateTimeImmutable($date));
		$weighing->setGrossKg($grossKg);
		return $weighing;
	}
}
