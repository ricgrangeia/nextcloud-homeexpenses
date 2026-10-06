<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Tests\Unit\Service;

use OCA\HomeExpenses\Service\InvoiceParser;
use PHPUnit\Framework\TestCase;

/**
 * Os numeros destes testes sao inventados, mas a forma e a redaccao vem de uma
 * fatura real da EDP. E a redaccao que importa: e sobre ela que o classificador
 * decide, e e nela que ele pode errar em silencio.
 */
class InvoiceParserTest extends TestCase {
	private InvoiceParser $parser;

	protected function setUp(): void {
		$this->parser = new InvoiceParser();
	}

	private function payload(array $rows, array $overrides = []): array {
		$doc = array_merge([
			'page_number' => 2,
			'seller' => ['descricao' => 'EDP COMERCIAL'],
			'document' => [
				'type' => 'FT', 'date' => '2026-10-02',
				'number' => 'FT2026 X/1', 'atcud' => 'AAAA-1',
			],
			'taxes' => ['tax_total' => 10.0],
			'totals' => ['base' => 50.0, 'gross' => 60.0],
			'verification' => ['matches' => true, 'taxes_match' => true],
		], $overrides);

		foreach ($rows as &$row) {
			$row['atcud'] ??= $doc['document']['atcud'];
		}

		return [
			'invoice' => ['documents' => [$doc]],
			'items' => ['rows' => $rows],
		];
	}

	private function line(string $description, float $qty, float $price, int $vat): array {
		return [
			'description' => $description, 'quantity' => $qty,
			'unit_price' => $price, 'vat_rate' => $vat,
			'total_excl_vat' => round($qty * $price, 2), 'page' => 2,
		];
	}

	/**
	 * A armadilha central: a EDP parte cada consumo em duas linhas, uma a 6% e
	 * outra a 23%, porque parte do consumo leva IVA reduzido. Quem tratar cada
	 * linha como um registo fica com metade do consumo.
	 */
	public function testSomaAsLinhasPartidasPorTaxaDeIva(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo real Vazio 20 set a 28 set 2026', 21, 0.1119, 6),
			$this->line('Consumo real Vazio 20 set a 28 set 2026', 22, 0.1119, 23),
		]));

		$consumption = $result['documents'][0]['consumption'];
		$this->assertCount(1, $consumption, 'as duas linhas sao o mesmo registo');
		$this->assertSame('V', $consumption[0]['registerCode']);
		$this->assertSame(43.0, $consumption[0]['quantity']);
		$this->assertSame(0.1119, $consumption[0]['unitPrice']);
	}

	/**
	 * "Fora vazio" contem "vazio". Testado pela ordem errada, todo o consumo
	 * fora de vazio ia para o escalao V -- o erro mais caro possivel aqui,
	 * porque V e o escalao barato e o total parecia plausivel.
	 */
	public function testForaVazioNaoEConfundidoComVazio(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo real Fora vazio 20 set a 28 set 2026', 82, 0.1935, 23),
			$this->line('Consumo real Vazio 20 set a 28 set 2026', 43, 0.1119, 23),
		]));

		$codes = array_column($result['documents'][0]['consumption'], 'registerCode');
		sort($codes);
		$this->assertSame(['FV', 'V'], $codes);
	}

	/**
	 * Uma mudanca de tarifario a meio do mes poe dois regimes na mesma fatura.
	 * Tem de sair como dois intervalos distintos, senao o consumo de 31 dias
	 * aparece como se fosse todo ao preco novo.
	 */
	public function testSeparaDoisRegimesNaMesmaFatura(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo real Simples 29 ago a 19 set 2026', 321, 0.1671, 23),
			$this->line('Consumo real Vazio 20 set a 28 set 2026', 43, 0.1119, 23),
			$this->line('Consumo real Fora vazio 20 set a 28 set 2026', 82, 0.1935, 23),
		]));

		$doc = $result['documents'][0];
		$this->assertSame('2026-08-29', $doc['periodFrom']);
		$this->assertSame('2026-09-28', $doc['periodTo']);

		$byCode = array_column($doc['consumption'], null, 'registerCode');
		$this->assertSame('2026-08-29', $byCode['TOTAL']['periodFrom']);
		$this->assertSame('2026-09-19', $byCode['TOTAL']['periodTo']);
		$this->assertSame('2026-09-20', $byCode['V']['periodFrom']);
	}

	/**
	 * O ano so aparece no fim da descricao. Num intervalo que atravessa o fim
	 * do ano, o inicio pertence ao ano anterior -- senao o periodo daria
	 * negativo e o consumo por dia vinha com o sinal trocado.
	 */
	public function testIntervaloQueAtravessaOFimDoAno(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo real Vazio 20 dez a 15 jan 2027', 90, 0.1119, 23),
		]));

		$period = $result['documents'][0]['consumption'][0];
		$this->assertSame('2026-12-20', $period['periodFrom']);
		$this->assertSame('2027-01-15', $period['periodTo']);
	}

	public function testDistingueConsumoEstimadoDeReal(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo estimado Vazio 1 out a 31 out 2026', 50, 0.1119, 23),
			$this->line('Consumo real Cheias 1 out a 31 out 2026', 60, 0.1700, 23),
		]));

		$byCode = array_column($result['documents'][0]['consumption'], null, 'registerCode');
		$this->assertTrue($byCode['V']['isEstimate']);
		$this->assertFalse($byCode['C']['isEstimate']);
	}

	/**
	 * Basta uma das parcelas de IVA ser estimada para o agregado nao poder ser
	 * tratado como leitura real.
	 */
	public function testEstimativaContaminaOAgregado(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo real Vazio 1 out a 31 out 2026', 20, 0.1119, 6),
			$this->line('Consumo estimado Vazio 1 out a 31 out 2026', 30, 0.1119, 23),
		]));

		$this->assertTrue($result['documents'][0]['consumption'][0]['isEstimate']);
	}

	public function testClassificaPotenciaRedesEImpostos(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Potência (3,45 kVA) 20 set a 28 set 2026', 9, 0.4253, 23),
			$this->line('Tarifa de acesso às redes -9 dias', 9, 0.1718, 23),
			$this->line('IEC', 446, 0.001, 23),
			$this->line('Contribuição Audiovisual 1 mês', 1, 2.85, 6),
		]));

		$kinds = array_column($result['documents'][0]['lines'], 'kind');
		$this->assertSame(
			[InvoiceParser::KIND_POWER, InvoiceParser::KIND_NETWORK,
				InvoiceParser::KIND_LEVY, InvoiceParser::KIND_LEVY],
			$kinds
		);
		$this->assertSame([], $result['documents'][0]['consumption'], 'nada disto e consumo');
	}

	/**
	 * Uma linha de energia que nao chegue aos totais tem de ser dita. Excluir
	 * em silencio deixa os numeros com ar de certos, so que a menos.
	 */
	public function testAvisaSobreLinhaDeEnergiaExcluida(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo real Vazio 1 out a 31 out 2026', 50, 0.1119, 23),
			$this->line('Energia Fora Vazio', 82, 0.0835, 23),
		]));

		$this->assertCount(1, $result['documents'][0]['consumption']);
		$this->assertCount(1, $result['warnings']);
		$this->assertStringContainsString('intervalo de datas', $result['warnings'][0]);
		$this->assertStringContainsString('82', $result['warnings'][0]);
	}

	/**
	 * Tres rotulos diferentes contem "vazio". Se a ordem de teste se partir,
	 * este teste apanha-o antes de a app dar o preco errado a kWh caros.
	 */
	public function testDistingueOsTresEscaloesQueContemVazio(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo real Fora vazio 1 out a 31 out 2026', 80, 0.1935, 23),
			$this->line('Consumo real Super Vazio 1 out a 31 out 2026', 30, 0.0800, 23),
			$this->line('Consumo real Vazio Normal 1 out a 31 out 2026', 40, 0.1119, 23),
		]));

		$byCode = array_column($result['documents'][0]['consumption'], null, 'registerCode');
		$this->assertSame(80.0, $byCode['FV']['quantity']);
		$this->assertSame(30.0, $byCode['SV']['quantity']);
		$this->assertSame(40.0, $byCode['V']['quantity']);
		$this->assertSame([], $result['warnings']);
	}

	public function testAvisaSobreEscalaoDesconhecido(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo real Escalao Novo 1 out a 31 out 2026', 10, 0.09, 23),
		]));

		$this->assertSame([], $result['documents'][0]['consumption']);
		$this->assertStringContainsString('escalao', $result['warnings'][0]);
	}

	/**
	 * Um documento cujo total nao fecha fica guardado, mas marcado: apagar o
	 * que nao se entende e pior do que guardar com uma marca.
	 */
	public function testDocumentoQueNaoFechaFicaMarcado(): void {
		$result = $this->parser->parse($this->payload(
			[$this->line('Consumo real Vazio 1 out a 31 out 2026', 10, 0.1, 23)],
			['verification' => ['matches' => false, 'taxes_match' => true]]
		));

		$this->assertFalse($result['documents'][0]['verified']);
		$this->assertStringContainsString('nao fecha', $result['warnings'][0]);
	}

	/**
	 * Um PDF da EDP traz mais do que um documento fiscal: a eletricidade e a
	 * Contribuicao Audiovisual sao faturas separadas. As linhas de cada uma
	 * distinguem-se pelo ATCUD.
	 */
	public function testVariosDocumentosFiscaisNoMesmoPdf(): void {
		$payload = [
			'invoice' => ['documents' => [
				[
					'seller' => ['descricao' => 'EDP COMERCIAL'],
					'document' => ['type' => 'FT', 'date' => '2026-10-02',
						'number' => 'FT/1', 'atcud' => 'AAAA-1'],
					'taxes' => ['tax_total' => 12.66],
					'totals' => ['base' => 84.19, 'gross' => 96.85],
					'verification' => ['matches' => true, 'taxes_match' => true],
				],
				[
					'seller' => ['descricao' => 'EDP COMERCIAL'],
					'document' => ['type' => 'FT', 'date' => '2026-10-02',
						'number' => 'FT/2', 'atcud' => 'BBBB-2'],
					'taxes' => ['tax_total' => 0.17],
					'totals' => ['base' => 2.85, 'gross' => 3.02],
					'verification' => ['matches' => true, 'taxes_match' => true],
				],
			]],
			'items' => ['rows' => [
				['atcud' => 'AAAA-1'] + $this->line('Consumo real Vazio 1 out a 31 out 2026', 43, 0.1119, 23),
				['atcud' => 'BBBB-2'] + $this->line('Contribuição Audiovisual 1 mês', 1, 2.85, 6),
			]],
		];

		$result = $this->parser->parse($payload);

		$this->assertCount(2, $result['documents']);
		$this->assertCount(1, $result['documents'][0]['consumption']);
		$this->assertSame(96.85, $result['documents'][0]['totalGross']);
		$this->assertSame([], $result['documents'][1]['consumption']);
		$this->assertSame(3.02, $result['documents'][1]['totalGross']);
	}
}
