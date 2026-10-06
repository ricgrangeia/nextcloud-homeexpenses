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

	/**
	 * Monta uma resposta no esquema v1 de /api/v1/document/extract?linhas=true.
	 */
	private function payload(array $lines, array $overrides = []): array {
		$doc = array_replace_recursive([
			'source' => ['kind' => 'qr', 'page' => 2],
			'document' => [
				'type' => 'FT', 'type_label' => 'Fatura', 'date' => '2026-10-02',
				'number' => 'FT2026 X/1', 'atcud' => 'AAAA-1',
				'seller' => ['nif' => '503504564', 'name' => 'EDP COMERCIAL'],
			],
			'taxes' => [['region' => 'PT', 'rate' => 'normal', 'base' => 50.0, 'vat' => 10.0]],
			'totals' => ['taxable' => 50.0, 'tax_total' => 10.0, 'gross' => 60.0],
			'verification' => ['totals_match' => true, 'taxes_match' => true, 'lines_match' => true],
			'warnings' => [],
			'lines' => $lines,
		], $overrides);

		return [
			'schema_version' => '1.0',
			'file' => [
				'pages' => 4,
				'documents_found' => 1,
				'payment' => ['reference' => '223151852', 'amount' => 60.0, 'due_date' => '2026-10-26'],
				'totals' => ['gross' => 60.0],
				'verification' => ['payment_matches_documents' => true],
				'warnings' => [],
			],
			'documents' => [$doc],
		];
	}

	/**
	 * Uma linha no esquema v1. Nota o `vat_rate` em FRACCAO -- e assim que o
	 * extractor a da, e e a diferenca que mais facilmente passaria despercebida.
	 */
	private function line(string $description, float $qty, float $price, float $vatPercent): array {
		return [
			'line_number' => 1,
			'description' => $description,
			'quantity' => $qty,
			'unit' => null,
			'unit_price' => $price,
			'discount' => 0.0,
			'vat_rate' => $vatPercent / 100,
			'net' => round($qty * $price, 2),
			'raw' => ['page' => 2],
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
			['verification' => ['totals_match' => false, 'taxes_match' => true, 'lines_match' => true]]
		));

		$this->assertFalse($result['documents'][0]['verified']);
		$this->assertStringContainsString('nao fecha', $result['warnings'][0]);
		$this->assertStringContainsString('nao bate com o QR', $result['warnings'][0]);
	}

	/**
	 * Um PDF da EDP traz mais do que um documento fiscal: a eletricidade e a
	 * Contribuicao Audiovisual sao faturas separadas. As linhas de cada uma
	 * distinguem-se pelo ATCUD.
	 */
	public function testVariosDocumentosFiscaisNoMesmoPdf(): void {
		$base = $this->payload([]);
		$base['file']['documents_found'] = 2;
		$base['documents'] = [
			array_replace_recursive($base['documents'][0], [
				'document' => ['number' => 'FT/1', 'atcud' => 'AAAA-1'],
				'totals' => ['taxable' => 84.19, 'tax_total' => 12.66, 'gross' => 96.85],
				'lines' => [$this->line('Consumo real Vazio 1 out a 31 out 2026', 43, 0.1119, 23)],
			]),
			array_replace_recursive($base['documents'][0], [
				'document' => ['number' => 'FT/2', 'atcud' => 'BBBB-2'],
				'totals' => ['taxable' => 2.85, 'tax_total' => 0.17, 'gross' => 3.02],
				'lines' => [$this->line('Contribuição Audiovisual 1 mês', 1, 2.85, 6)],
			]),
		];

		$result = $this->parser->parse($base);

		$this->assertCount(2, $result['documents']);
		$this->assertCount(1, $result['documents'][0]['consumption']);
		$this->assertSame(96.85, $result['documents'][0]['totalGross']);
		$this->assertSame([], $result['documents'][1]['consumption']);
		$this->assertSame(3.02, $result['documents'][1]['totalGross']);
	}

	/**
	 * O extractor da a taxa de IVA em fraccao (0.06). Guardada assim, um
	 * modelo de custo que teste "< 10 e taxa reduzida" trata 0.23 como
	 * reduzida e subestima o IVA em dois tercos -- sem nada falhar.
	 */
	public function testTaxaDeIvaEGuardadaEmPercentagem(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo real Vazio 1 out a 31 out 2026', 20, 0.1, 6),
			$this->line('Consumo real Cheias 1 out a 31 out 2026', 30, 0.1, 23),
		]));

		$rates = array_column($result['documents'][0]['lines'], 'vatRate');
		$this->assertSame([6.0, 23.0], $rates);
	}

	/**
	 * `lines_match` diz que a soma das linhas extraidas bate com o total que
	 * o QR fiscal declara. Sem ela, linhas em falta nao teriam quem as
	 * denunciasse.
	 */
	public function testLinhasQueNaoSomamAoTotalDoQrMarcamODocumento(): void {
		$result = $this->parser->parse($this->payload(
			[$this->line('Consumo real Vazio 1 out a 31 out 2026', 10, 0.1, 23)],
			['verification' => ['totals_match' => true, 'taxes_match' => true, 'lines_match' => false]]
		));

		$this->assertFalse($result['documents'][0]['verified']);
		$this->assertStringContainsString('soma das linhas', $result['warnings'][0]);
	}

	public function testGuardaReferenciaEDataLimiteDoPagamento(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo real Vazio 1 out a 31 out 2026', 10, 0.1, 23),
		]));

		$this->assertSame('2026-10-26', $result['documents'][0]['dueDate']);
		$this->assertSame('223151852', $result['documents'][0]['paymentReference']);
	}

	public function testPropagaAvisosDoProprioExtractor(): void {
		$payload = $this->payload([$this->line('Consumo real Vazio 1 out a 31 out 2026', 10, 0.1, 23)]);
		$payload['file']['warnings'] = ['A pagina 3 nao pode ser renderizada.'];

		$result = $this->parser->parse($payload);

		$this->assertContains('A pagina 3 nao pode ser renderizada.', $result['warnings']);
	}

	// ------------------------------------------------- Faturas de agua (ADRA)

	private function waterLine(string $description, float $qty, string $unit, float $price, float $vat): array {
		return [
			'description' => $description, 'quantity' => $qty, 'unit' => $unit,
			'unit_price' => $price, 'discount' => 0.0, 'vat_rate' => $vat / 100,
			'net' => round($qty * $price, 4), 'raw' => ['page' => 1],
		];
	}

	/**
	 * A armadilha mais cara de uma fatura de agua: a taxa de recursos
	 * hidricos e a de residuos sao cobradas POR m3 DE AGUA, e por isso
	 * trazem a mesma quantidade que o consumo sem o serem. Numa fatura real,
	 * somar tudo o que diz "m3" dava 126,582 m3 quando a casa gastou 25,833
	 * -- quase cinco vezes mais.
	 */
	public function testTaxasCobradasPorM3NaoSaoConsumo(): void {
		$result = $this->parser->parse($this->payload([
			$this->waterLine('Água (Tarifa Variável) - 1º Esc. Até 5 m3', 5.167, 'm3', 0.7167, 6),
			$this->waterLine('Água (Tarifa Variável) - 2º Esc. > 5 m3', 10.333, 'm3', 1.1183, 6),
			$this->waterLine('Água (Tarifa Variável) - 3º Esc. > 15 m3', 10.333, 'm3', 1.9189, 6),
			$this->waterLine('Água (Tarifa Fixa)', 31, 'dias', 0.2252, 6),
			$this->waterLine('Tx.Rec.Hídricos (Água)', 25.833, 'm3', 0.0285, 6),
			$this->waterLine('Tx.Rec.Hídricos (SAN)', 23.25, 'm3', 0.0161, 6),
			$this->waterLine('RU Variável', 25.833, 'm3', 0.16, 0),
			$this->waterLine('Taxa Gestão de Resíduos', 25.833, 'm3', 0.3019, 6),
		]));

		$consumption = $result['documents'][0]['consumption'];
		$this->assertCount(1, $consumption, 'os tres escaloes sao a mesma medicao');
		$this->assertSame('TOTAL', $consumption[0]['registerCode']);
		$this->assertEqualsWithDelta(25.833, $consumption[0]['quantity'], 0.001);
	}

	/**
	 * Os escaloes sao faixas de PRECO da mesma medicao, nao medicoes
	 * diferentes. Tratados como registos distintos, o contador de agua
	 * passava a ter tres registos que nao existem.
	 */
	public function testEscaloesDeAguaSomamNoRegistoUnico(): void {
		$result = $this->parser->parse($this->payload([
			$this->waterLine('Água (Tarifa Variável) - 1º Esc. Até 5 m3', 5.0, 'm3', 0.7167, 6),
			$this->waterLine('Água (Tarifa Variável) - 2º Esc. > 5 m3', 7.5, 'm3', 1.1183, 6),
			$this->waterLine('Água (Tarifa Fixa)', 31, 'dias', 0.2252, 6),
		]));

		$codes = array_column($result['documents'][0]['consumption'], 'registerCode');
		$this->assertSame(['TOTAL'], $codes);
		$this->assertSame(12.5, $result['documents'][0]['consumption'][0]['quantity']);
	}

	/**
	 * Sem nenhuma linha cobrada ao dia nao ha duracao de onde deduzir o
	 * periodo, e sem periodo o consumo nao entra. Fica avisado em vez de
	 * entrar sem data.
	 */
	public function testSemLinhaAoDiaNaoHaPeriodoLogoNaoHaConsumo(): void {
		$result = $this->parser->parse($this->payload([
			$this->waterLine('Água (Tarifa Variável) - 1º Esc.', 5.0, 'm3', 0.7167, 6),
		], ['document' => ['date' => '2026-08-31']]));

		$this->assertSame([], $result['documents'][0]['consumption']);
		$this->assertFalse($result['documents'][0]['periodInferred']);
		$this->assertStringContainsString('intervalo de datas', implode(' ', $result['warnings']));
	}

	public function testSaneamentoEResiduosSaoServicoNaoConsumo(): void {
		$result = $this->parser->parse($this->payload([
			$this->waterLine('Água (Tarifa Variável) - 1º Esc. Até 5 m3', 5.0, 'm3', 0.7167, 6),
			$this->waterLine('Água (Tarifa Fixa)', 31, 'dias', 0.2252, 6),
			$this->waterLine('Saneamento (Trf.Variável)', 90, '%', 35.0866, 6),
			$this->waterLine('Saneamento (Trf. Fixa)', 31, 'dias', 0.2401, 6),
			$this->waterLine('RU Fixo', 31, 'dias', 0.1833, 0),
		]));

		$kinds = array_column($result['documents'][0]['lines'], 'kind');
		$this->assertSame([
			InvoiceParser::KIND_ENERGY,
			InvoiceParser::KIND_POWER,
			InvoiceParser::KIND_SERVICE,
			InvoiceParser::KIND_SERVICE,
			InvoiceParser::KIND_SERVICE,
		], $kinds);
		$this->assertSame(5.0, $result['documents'][0]['consumption'][0]['quantity']);
	}

	/**
	 * Guarda independente da redaccao: o que e cobrado ao dia nao e medicao,
	 * por mais que a descricao se pareca com consumo.
	 */
	public function testCobradoAoDiaNuncaEConsumo(): void {
		$result = $this->parser->parse($this->payload([
			$this->waterLine('Água (Tarifa Variável) estranha', 31, 'dias', 0.2252, 6),
		]));

		$this->assertSame([], $result['documents'][0]['consumption']);
		$this->assertSame(InvoiceParser::KIND_POWER, $result['documents'][0]['lines'][0]['kind']);
	}

	/**
	 * A ADRA nao escreve o periodo em lado nenhum. Deduz-se a DURACAO das
	 * linhas de tarifa fixa -- facto escrito na fatura -- e ancora-se na data
	 * de emissao, que ja e suposicao e por isso sai avisada.
	 */
	public function testDeduzOPeriodoQuandoAFaturaNaoOEscreve(): void {
		$result = $this->parser->parse($this->payload([
			$this->waterLine('Água (Tarifa Variável) - 1º Esc. Até 5 m3', 5.0, 'm3', 0.7167, 6),
			$this->waterLine('Água (Tarifa Fixa)', 31, 'dias', 0.2252, 6),
		], ['document' => ['date' => '2026-08-31']]));

		$doc = $result['documents'][0];
		$this->assertSame('2026-08-01', $doc['periodFrom']);
		$this->assertSame('2026-08-31', $doc['periodTo']);
		$this->assertTrue($doc['periodInferred']);
		$this->assertSame(5.0, $doc['consumption'][0]['quantity']);
		$this->assertStringContainsString('deduzido dos 31 dias', implode(' ', $result['warnings']));
	}

	/**
	 * Se umas linhas tiverem datas e outras nao, as que nao tem sao
	 * suspeitas -- foi assim que apareceu uma linha a mais numa fatura da
	 * EDP. Dar-lhes o periodo do documento fa-las-ia entrar nos totais.
	 */
	public function testNaoDeduzQuandoHaLinhasComDatas(): void {
		$result = $this->parser->parse($this->payload([
			$this->line('Consumo real Vazio 1 out a 31 out 2026', 43, 0.1119, 23),
			$this->waterLine('Energia Fora Vazio', 82, '', 0.0835, 23),
			$this->waterLine('Potência (3,45 kVA)', 31, 'dias', 0.4253, 23),
		]));

		$doc = $result['documents'][0];
		$this->assertFalse($doc['periodInferred']);
		$this->assertCount(1, $doc['consumption'], 'a linha sem datas fica de fora');
		$this->assertSame(43.0, $doc['consumption'][0]['quantity']);
	}

	/**
	 * Linhas ao dia que discordem entre si nao dao uma duracao. Nao se
	 * escolhe uma a sorte.
	 */
	public function testNaoDeduzQuandoOsDiasDiscordam(): void {
		$result = $this->parser->parse($this->payload([
			$this->waterLine('Água (Tarifa Variável) - 1º Esc.', 5.0, 'm3', 0.7167, 6),
			$this->waterLine('Água (Tarifa Fixa)', 31, 'dias', 0.2252, 6),
			$this->waterLine('Saneamento (Trf. Fixa)', 22, 'dias', 0.2401, 6),
		], ['document' => ['date' => '2026-08-31']]));

		$this->assertFalse($result['documents'][0]['periodInferred']);
		$this->assertSame([], $result['documents'][0]['consumption']);
	}
}
