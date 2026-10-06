<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Tests\Unit\Service;

use OCA\HomeExpenses\Service\ForecastService;
use OCA\HomeExpenses\Service\InvoiceParser;
use PHPUnit\Framework\TestCase;

class ForecastServiceTest extends TestCase {
	private ForecastService $service;

	protected function setUp(): void {
		$this->service = new ForecastService();
	}

	private function period(string $from, string $to, array $byRegister, bool $estimate = false): array {
		return ['periodFrom' => $from, 'periodTo' => $to, 'byRegister' => $byRegister, 'isEstimate' => $estimate];
	}

	/**
	 * O comportamento mais importante desta classe e recusar. Com um periodo
	 * so nao ha ritmo nenhum que se possa projectar, e devolver um numero
	 * seria dar ar de facto a um palpite.
	 */
	public function testRecusaProjectarComUmSoPeriodo(): void {
		$result = $this->service->forecast(
			[$this->period('2026-09-01', '2026-09-30', ['V' => 100.0])],
			'2026-10-01',
			30
		);

		$this->assertSame(ForecastService::CONFIDENCE_NONE, $result['confidence']);
		$this->assertNull($result['projected']);
		$this->assertNull($result['perDay']);
		$this->assertStringContainsString('historico que chegue', $result['caveats'][0]);
	}

	public function testProjectaAoRitmoObservado(): void {
		$result = $this->service->forecast([
			$this->period('2026-07-01', '2026-07-31', ['V' => 30.0, 'FV' => 60.0]),
			$this->period('2026-07-31', '2026-08-30', ['V' => 30.0, 'FV' => 60.0]),
		], '2026-08-30', 30);

		// 60 dias observados, 60 kWh em V e 120 em FV -> 1 e 2 kWh/dia
		$this->assertSame(1.0, $result['perDay']['byRegister']['V']);
		$this->assertSame(2.0, $result['perDay']['byRegister']['FV']);
		$this->assertSame(30.0, $result['projected']['byRegister']['V']);
		$this->assertSame(60.0, $result['projected']['byRegister']['FV']);
		$this->assertSame(90.0, $result['projected']['total']);
	}

	/**
	 * A ressalva que mais importa: projectar o inverno a partir de dados de
	 * verao da sempre um numero, e esse numero esta sempre curto.
	 */
	public function testAvisaQuandoProjectaInvernoSemHistoricoDeInverno(): void {
		$result = $this->service->forecast([
			$this->period('2026-07-01', '2026-07-31', ['V' => 30.0]),
			$this->period('2026-07-31', '2026-08-30', ['V' => 30.0]),
		], '2026-12-01', 60);

		$this->assertNotNull($result['projected']);
		$this->assertStringContainsString(
			'meses de aquecimento',
			implode(' ', $result['caveats'])
		);
		$this->assertStringContainsString('ficar curto', implode(' ', $result['caveats']));
	}

	public function testAvisaQuandoOHistoricoEDeInvernoEAProjeccaoNao(): void {
		$result = $this->service->forecast([
			$this->period('2026-12-01', '2026-12-31', ['V' => 200.0]),
			$this->period('2026-12-31', '2027-01-30', ['V' => 200.0]),
		], '2027-06-01', 30);

		$this->assertStringContainsString('por cima', implode(' ', $result['caveats']));
	}

	public function testAvisaSobreEstimativasNaBase(): void {
		$result = $this->service->forecast([
			$this->period('2026-07-01', '2026-07-31', ['V' => 30.0], true),
			$this->period('2026-07-31', '2026-08-30', ['V' => 30.0]),
		], '2026-08-30', 30);

		$this->assertStringContainsString('estimativa', implode(' ', $result['caveats']));
	}

	public function testConfiancaCresceComOHistorico(): void {
		$short = $this->service->forecast([
			$this->period('2026-08-01', '2026-08-31', ['V' => 30.0]),
			$this->period('2026-08-31', '2026-09-30', ['V' => 30.0]),
		], '2026-09-30', 30);

		$long = $this->service->forecast([
			$this->period('2025-09-01', '2026-03-01', ['V' => 180.0]),
			$this->period('2026-03-01', '2026-09-30', ['V' => 180.0]),
		], '2026-09-30', 30);

		$this->assertSame(ForecastService::CONFIDENCE_LOW, $short['confidence']);
		$this->assertSame(ForecastService::CONFIDENCE_HIGH, $long['confidence']);
	}

	/**
	 * Um escalao projectado sem preco no modelo nao vale zero: o total fica
	 * marcado como incompleto. Um total a menos parece credivel, que e
	 * exatamente o problema.
	 */
	public function testEscalaoSemPrecoNaoValeZero(): void {
		$result = $this->service->forecast([
			$this->period('2026-07-01', '2026-07-31', ['V' => 30.0, 'FV' => 60.0]),
			$this->period('2026-07-31', '2026-08-30', ['V' => 30.0, 'FV' => 60.0]),
		], '2026-08-30', 30, [
			'energyPrices' => ['V' => 0.10],
			'fixedPerDay' => 0.0, 'leviesPerKwh' => 0.0, 'leviesPerMonth' => 0.0,
			'reducedVatShare' => 0.0, 'vatReduced' => 0.06, 'vatNormal' => 0.23,
		]);

		$this->assertFalse($result['cost']['complete']);
		$this->assertSame(['FV'], $result['cost']['unpriced']);
		$this->assertSame(3.0, $result['cost']['energy'], 'so os 30 kWh de V foram cobrados');
	}

	/**
	 * O modelo de custo sai da fatura, com os numeros reais da que validou o
	 * classificador: potencia 0,4253/dia, redes 0,1718/dia, IEC 0,001/kWh,
	 * CAV 2,85/mes.
	 */
	public function testModeloDeCustoSaiDaFatura(): void {
		$lines = [
			['kind' => InvoiceParser::KIND_ENERGY, 'registerCode' => 'V',
				'quantity' => 43.0, 'unitPrice' => 0.1119, 'totalNet' => 4.81, 'vatRate' => 23.0],
			['kind' => InvoiceParser::KIND_ENERGY, 'registerCode' => 'FV',
				'quantity' => 82.0, 'unitPrice' => 0.1935, 'totalNet' => 15.87, 'vatRate' => 23.0],
			['kind' => InvoiceParser::KIND_POWER, 'description' => 'Potência (3,45 kVA)',
				'quantity' => 9.0, 'unitPrice' => 0.4253, 'totalNet' => 3.83, 'vatRate' => 23.0],
			['kind' => InvoiceParser::KIND_NETWORK, 'description' => 'Tarifa de acesso às redes',
				'quantity' => 9.0, 'unitPrice' => 0.1718, 'totalNet' => 1.55, 'vatRate' => 23.0],
			['kind' => InvoiceParser::KIND_LEVY, 'description' => 'IEC',
				'quantity' => 125.0, 'unitPrice' => 0.001, 'totalNet' => 0.13, 'vatRate' => 23.0],
			['kind' => InvoiceParser::KIND_LEVY, 'description' => 'Contribuição Audiovisual 1 mês',
				'quantity' => 1.0, 'unitPrice' => 2.85, 'totalNet' => 2.85, 'vatRate' => 6.0],
		];

		$model = $this->service->costModelFromInvoice($lines, 9);

		$this->assertSame(0.1119, $model['energyPrices']['V']);
		$this->assertSame(0.1935, $model['energyPrices']['FV']);
		// (3.83 + 1.55) / 9 dias
		$this->assertEqualsWithDelta(0.5978, $model['fixedPerDay'], 0.0001);
		$this->assertSame(0.001, $model['leviesPerKwh']);
		$this->assertSame(2.85, $model['leviesPerMonth']);
	}

	/**
	 * A proporcao de IVA reduzido e LIDA da fatura, nao calculada por regra.
	 * A regra legal muda; uma regra codificada parte-se em silencio.
	 */
	public function testProporcaoDeIvaReduzidoELidaDaFatura(): void {
		$model = $this->service->costModelFromInvoice([
			['kind' => InvoiceParser::KIND_ENERGY, 'registerCode' => 'V',
				'quantity' => 20.0, 'unitPrice' => 0.1, 'totalNet' => 30.0, 'vatRate' => 6.0],
			['kind' => InvoiceParser::KIND_ENERGY, 'registerCode' => 'V',
				'quantity' => 50.0, 'unitPrice' => 0.1, 'totalNet' => 70.0, 'vatRate' => 23.0],
		], 30);

		$this->assertSame(0.3, $model['reducedVatShare'], '30 de 100 a taxa reduzida');
	}

	public function testCustoProjectadoSomaEnergiaFixosImpostosEIva(): void {
		$result = $this->service->forecast([
			$this->period('2026-07-01', '2026-07-31', ['V' => 50.0]),
			$this->period('2026-07-31', '2026-08-30', ['V' => 50.0]),
		], '2026-08-30', 30, [
			'energyPrices' => ['V' => 0.20],
			'fixedPerDay' => 0.50,
			'leviesPerKwh' => 0.001,
			'leviesPerMonth' => 3.00,
			'reducedVatShare' => 0.0,
			'vatReduced' => 0.06,
			'vatNormal' => 0.23,
		]);

		$cost = $result['cost'];
		$this->assertSame(50.0, $result['projected']['total']);
		$this->assertSame(10.0, $cost['energy']);   // 50 kWh x 0.20
		$this->assertSame(15.0, $cost['fixed']);    // 0.50 x 30 dias
		$this->assertSame(3.05, $cost['levies']);   // 50 x 0.001 + 3.00
		$this->assertSame(28.05, $cost['net']);
		$this->assertEqualsWithDelta(6.45, $cost['vat'], 0.01);
		$this->assertTrue($cost['complete']);
	}

	/**
	 * O erro que esta funcao existe para evitar: somar uma leitura e uma
	 * fatura que medem o mesmo consumo duplicaria a eletricidade da casa.
	 */
	public function testNaoContaDuasVezesOMesmoConsumo(): void {
		$readings = [$this->period('2026-09-01', '2026-09-30', ['V' => 40.0, 'C' => 30.0, 'P' => 30.0])];
		$invoices = [$this->period('2026-09-01', '2026-09-30', ['V' => 40.0, 'FV' => 60.0])];

		$merged = $this->service->merge($readings, $invoices);

		$this->assertCount(1, $merged['periods']);
		$this->assertSame(1, $merged['used']['discarded']);
		$this->assertSame(['V' => 40.0, 'C' => 30.0, 'P' => 30.0], $merged['periods'][0]['byRegister'],
			'ganha a leitura, que traz os tres escaloes');
	}

	public function testFaturaDeUmPeriodoSemLeiturasEAproveitada(): void {
		$readings = [$this->period('2026-09-01', '2026-09-30', ['V' => 40.0])];
		$invoices = [$this->period('2026-06-01', '2026-06-30', ['V' => 35.0, 'FV' => 50.0])];

		$merged = $this->service->merge($readings, $invoices);

		$this->assertCount(2, $merged['periods']);
		$this->assertSame(0, $merged['used']['discarded']);
		$this->assertSame('2026-06-01', $merged['periods'][0]['periodFrom'], 'ordenado por data');
	}

	public function testFromSeriesDesembrulhaOFormatoDoConsumptionService(): void {
		$periods = $this->service->fromSeries([[
			'from' => '2026-09-01', 'to' => '2026-09-30', 'isEstimate' => true,
			'byRegister' => ['V' => ['consumed' => 40.0, 'perDay' => 1.33]],
		]]);

		$this->assertSame(['V' => 40.0], $periods[0]['byRegister']);
		$this->assertTrue($periods[0]['isEstimate']);
		$this->assertSame('reading', $periods[0]['source']);
	}

	/**
	 * Uma fatura com mudanca de tarifario a meio traz dois intervalos, e cada
	 * um tem de contar como periodo seu -- juntos dariam um ritmo medio que
	 * nao corresponde a nenhum dos dois regimes.
	 */
	public function testFromInvoicesSeparaOsIntervalosDaMesmaFatura(): void {
		$periods = $this->service->fromInvoices([
			['periodFrom' => '2026-08-29', 'periodTo' => '2026-09-19', 'registerCode' => 'TOTAL',
				'quantity' => 321.0, 'isEstimate' => false],
			['periodFrom' => '2026-09-20', 'periodTo' => '2026-09-28', 'registerCode' => 'V',
				'quantity' => 43.0, 'isEstimate' => false],
			['periodFrom' => '2026-09-20', 'periodTo' => '2026-09-28', 'registerCode' => 'FV',
				'quantity' => 82.0, 'isEstimate' => false],
		]);

		$this->assertCount(2, $periods);
		$this->assertSame(['TOTAL' => 321.0], $periods[0]['byRegister']);
		$this->assertSame(['V' => 43.0, 'FV' => 82.0], $periods[1]['byRegister']);
	}

	public function testFromInvoicesIgnoraEntradasSemDatas(): void {
		$periods = $this->service->fromInvoices([
			['periodFrom' => null, 'periodTo' => null, 'registerCode' => 'FV',
				'quantity' => 82.0, 'isEstimate' => false],
		]);

		$this->assertSame([], $periods);
	}
}
