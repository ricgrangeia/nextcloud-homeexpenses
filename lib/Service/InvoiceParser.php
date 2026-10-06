<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Service;

/**
 * Transforma a resposta do servico de leitura de faturas em linhas classificadas.
 *
 * Consome o **esquema v1** de /api/v1/document/extract?linhas=true. Nesse
 * esquema cada documento traz as suas proprias linhas (nao ha que as cruzar
 * por ATCUD), e traz uma conferencia das linhas contra o total declarado no
 * QR fiscal -- `lines_match` com `lines_sum`. Isso e o que permite nao ter de
 * confiar na extracao: ela diz se fecha.
 *
 * Nao tem dependencias e nao toca na base de dados: recebe o array devolvido
 * por /api/v1/document/full e devolve estrutura. E assim porque esta e a parte
 * que mais facilmente erra em silencio -- um "Vazio" lido como "Fora vazio" mete
 * kWh no escalao errado e a app passa a mentir com confianca. Sendo pura, testa-se
 * contra faturas reais sem servidor nenhum a volta.
 *
 * Tres coisas que uma fatura da EDP faz e que nao sao obvias:
 *
 *  1. As linhas vem partidas por taxa de IVA, nao por registo. "Consumo real
 *     Vazio 20 set a 28 set" aparece duas vezes -- 21 kWh a 6% e 22 kWh a 23% --
 *     porque parte do consumo leva taxa reduzida. Tratar cada linha como um
 *     registo da metade do consumo. Tem de se somar por (registo, periodo).
 *
 *  2. Um periodo de faturacao pode conter mais do que uma tarifa. Numa mudanca
 *     de Simples para bi-horario a meio do mes, a mesma fatura traz linhas
 *     "Simples" de um intervalo e "Vazio"/"Fora vazio" de outro.
 *
 *  3. O consumo pode ser real ou estimado, e a fatura di-lo na descricao
 *     ("Consumo real" vs "Consumo estimado"). Misturar os dois sem distincao
 *     estraga qualquer media.
 */
class InvoiceParser {
	/**
	 * Abreviaturas de mes como a EDP as escreve nas descricoes das linhas.
	 */
	private const MONTHS = [
		'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4, 'mai' => 5, 'jun' => 6,
		'jul' => 7, 'ago' => 8, 'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12,
	];

	/**
	 * Como cada escalao aparece escrito, e para que codigo de registo mapeia.
	 *
	 * A ordem importa, e e a unica coisa que impede o erro mais caro deste
	 * ficheiro. Tres rotulos contem a palavra "vazio" e significam coisas
	 * diferentes: "Fora vazio" e o escalao caro, "Super Vazio" e um escalao a
	 * parte dos tarifarios tetra-horarios, e "Vazio" e o barato. Testado pela
	 * ordem errada, o consumo caro ia para o escalao barato e o total ficava
	 * plausivel -- errado, mas plausivel, que e o pior dos casos.
	 */
	private const REGISTERS = [
		'fora vazio' => 'FV',
		'fora de vazio' => 'FV',
		'super vazio' => 'SV',
		'vazio normal' => 'V',
		'vazio' => 'V',
		'cheias' => 'C',
		'ponta' => 'P',
		'simples' => 'TOTAL',
	];

	/** A quantidade medida: kWh de eletricidade, m3 de agua. */
	public const KIND_ENERGY = 'energy';
	/** Cobrado ao dia independentemente do consumo: potencia, tarifa fixa. */
	public const KIND_POWER = 'power';
	public const KIND_NETWORK = 'network';
	/** Servico derivado do consumo medido, nao medido ele proprio: saneamento, residuos. */
	public const KIND_SERVICE = 'service';
	public const KIND_LEVY = 'levy';
	public const KIND_OTHER = 'other';

	/**
	 * Unidades que um encargo medido nunca tem.
	 *
	 * Esta e a guarda mais util deste ficheiro. Numa fatura de agua, a taxa
	 * de recursos hidricos e a de residuos sao cobradas POR m3 DE AGUA, por
	 * isso trazem a mesma quantidade que o consumo sem o serem. Numa fatura
	 * real, somar tudo o que diz "m3" dava 126,582 m3 quando a casa gastou
	 * 25,833 -- quase cinco vezes mais. A unidade nao chega para distinguir
	 * esses casos, mas apanha os outros dois: nada cobrado "ao dia" ou "em
	 * percentagem" e uma medicao.
	 */
	private const NEVER_MEASURED_UNITS = ['dias', 'dia', '%'];

	/**
	 * @param array $full resposta de /api/v1/document/full
	 * @return array{documents: list<array>, warnings: list<string>}
	 */
	public function parse(array $full): array {
		$documents = [];
		$warnings = [];

		// Avisos do proprio servico de extracao, ao nivel do ficheiro.
		foreach ($full['file']['warnings'] ?? [] as $warning) {
			$warnings[] = (string)$warning;
		}

		foreach ($full['documents'] ?? [] as $doc) {
			$header = $doc['document'] ?? [];
			$verification = $doc['verification'] ?? [];

			$lines = [];
			foreach ($doc['lines'] ?? [] as $row) {
				$lines[] = $this->classify($row);
			}

			// Tres conferencias, e e preciso que as tres fechem. A terceira --
			// `lines_match` -- e a que importa aqui: diz que a soma das linhas
			// extraidas bate com o total que o QR fiscal declara. Sem ela, as
			// linhas podiam estar incompletas sem nada o denunciar.
			$verified = ($verification['totals_match'] ?? false) === true
				&& ($verification['taxes_match'] ?? false) === true
				&& ($verification['lines_match'] ?? false) === true;

			if (!$verified) {
				$warnings[] = sprintf(
					'O documento %s nao fecha nas contas (%s) e nao sera usado em calculos.',
					$header['number'] ?? ($header['atcud'] ?? '?'),
					$this->whyNot($verification)
				);
			}

			foreach ($doc['warnings'] ?? [] as $warning) {
				$warnings[] = (string)$warning;
			}

			// A EDP escreve o intervalo em cada linha; a ADRA nao escreve
			// nenhum. Quando nao ha nenhum, deduz-se -- ver inferPeriod().
			$inferred = $this->inferPeriod($lines, (string)($header['date'] ?? ''));
			if ($inferred !== null) {
				[$lines, $inferredFrom, $inferredTo, $inferredDays] = $inferred;
				$warnings[] = sprintf(
					'A fatura %s nao escreve o periodo. Foi deduzido dos %d dias da tarifa fixa, '
					. 'a terminar na data de emissao: %s a %s. Corrige se nao bater.',
					$header['number'] ?? '?', $inferredDays, $inferredFrom, $inferredTo
				);
			}

			$documents[] = [
				'atcud' => (string)($header['atcud'] ?? ''),
				'supplier' => (string)($header['seller']['name'] ?? ''),
				'docType' => (string)($header['type'] ?? ''),
				'docNumber' => (string)($header['number'] ?? ''),
				'issuedAt' => (string)($header['date'] ?? ''),
				'totalNet' => $this->float($doc['totals']['taxable'] ?? null),
				'totalVat' => $this->float($doc['totals']['tax_total'] ?? null),
				'totalGross' => $this->float($doc['totals']['gross'] ?? null),
				'verified' => $verified,
				'periodFrom' => $this->earliest($lines),
				'periodTo' => $this->latest($lines),
				'periodInferred' => $inferred !== null,
				// A referencia e a data-limite sao do ficheiro, nao do
				// documento: um PDF com duas faturas tem um so pagamento.
				'dueDate' => (string)($full['file']['payment']['due_date'] ?? ''),
				'paymentReference' => (string)($full['file']['payment']['reference'] ?? ''),
				'lines' => $lines,
				'consumption' => $this->aggregate($lines),
			];
		}

		// Qualquer linha de energia que nao chegue aos totais tem de ser dita.
		// Excluir em silencio e o modo de falha mais perigoso desta classe:
		// os numeros continuam a parecer bem, so que a menos.
		foreach ($documents as $doc) {
			foreach ($doc['lines'] as $line) {
				if ($line['kind'] !== self::KIND_ENERGY) {
					continue;
				}
				$reason = match (true) {
					$line['registerCode'] === null => 'nao se reconheceu o escalao',
					$line['periodFrom'] === null => 'nao traz intervalo de datas',
					$line['quantity'] === null => 'nao traz quantidade',
					default => null,
				};
				if ($reason !== null) {
					$warnings[] = sprintf(
						'Linha de energia fora dos totais (%s): "%s"%s.',
						$reason,
						$line['description'],
						$line['quantity'] !== null ? sprintf(' — %s unidades', $line['quantity']) : ''
					);
				}
			}
		}

		return ['documents' => $documents, 'warnings' => $warnings];
	}

	/**
	 * Preenche o intervalo quando a fatura nao o escreve.
	 *
	 * A EDP poe "29 ago a 19 set 2026" em cada linha; a ADRA nao poe nada. O
	 * que a ADRA poe sao linhas de tarifa fixa cobradas "31 dias" -- e isso e
	 * um facto escrito na fatura, nao um palpite: a tarifa fixa cobre
	 * exactamente o periodo faturado. Dai sai a DURACAO. O fim ancora-se na
	 * data de emissao, e isso ja e suposicao, por isso sai um aviso a dizer
	 * de onde veio e a convidar a corrigir.
	 *
	 * So se deduz quando NENHUMA linha de consumo tem datas. Se umas tiverem
	 * e outras nao, as que nao tem sao suspeitas -- foi assim que apareceu
	 * uma linha a mais numa fatura da EDP -- e dar-lhes o periodo do
	 * documento fa-las-ia entrar nos totais, que e precisamente o que nao se
	 * quer.
	 *
	 * @return array{0: list<array>, 1: string, 2: string, 3: int}|null
	 */
	private function inferPeriod(array $lines, string $issuedAt): ?array {
		if ($issuedAt === '') {
			return null;
		}

		$energy = array_filter($lines, static fn (array $l) => $l['kind'] === self::KIND_ENERGY);
		if ($energy === []) {
			return null;
		}
		foreach ($energy as $line) {
			if ($line['periodFrom'] !== null) {
				return null;
			}
		}

		// Os dias das linhas cobradas ao dia. Se discordarem entre si, nao ha
		// uma duracao -- nao se escolhe uma a sorte.
		$days = [];
		foreach ($lines as $line) {
			if (in_array($line['unit'] ?? '', ['dias', 'dia'], true) && $line['quantity'] !== null) {
				$days[] = (int)round($line['quantity']);
			}
		}
		$days = array_values(array_unique($days));
		if (count($days) !== 1 || $days[0] < 1) {
			return null;
		}

		try {
			$to = new \DateTimeImmutable($issuedAt);
		} catch (\Exception) {
			return null;
		}
		$from = $to->modify('-' . ($days[0] - 1) . ' days');

		$fromText = $from->format('Y-m-d');
		$toText = $to->format('Y-m-d');
		foreach ($lines as &$line) {
			if ($line['kind'] === self::KIND_ENERGY && $line['periodFrom'] === null) {
				$line['periodFrom'] = $fromText;
				$line['periodTo'] = $toText;
			}
		}

		return [$lines, $fromText, $toText, $days[0]];
	}

	/**
	 * Diz qual das conferencias falhou, para o aviso nao ser so "nao fecha".
	 */
	private function whyNot(array $verification): string {
		$failed = [];
		if (($verification['totals_match'] ?? false) !== true) {
			$failed[] = 'o total nao bate com o QR';
		}
		if (($verification['taxes_match'] ?? false) !== true) {
			$failed[] = 'o IVA nao bate';
		}
		if (($verification['lines_match'] ?? false) !== true) {
			$failed[] = 'a soma das linhas nao bate com o total';
		}
		return $failed === [] ? 'razao desconhecida' : implode(', ', $failed);
	}

	/**
	 * Classifica uma linha: que tipo de encargo e, que escalao, que intervalo.
	 */
	private function classify(array $row): array {
		$description = (string)($row['description'] ?? '');
		$lower = $this->fold($description);
		$unit = mb_strtolower(trim((string)($row['unit'] ?? '')));

		$kind = self::KIND_OTHER;
		$registerCode = null;
		$isEstimate = false;

		// A ordem destes testes e o que impede uma taxa de ser contada como
		// consumo. Os mais especificos primeiro: "Tx.Rec.Hidricos (Agua)"
		// contem "agua", e "Taxa Gestao de Residuos" contem "residuos" --
		// testados pela ordem errada, entravam no consumo da casa.
		if ($this->isLevy($lower)) {
			$kind = self::KIND_LEVY;
		} elseif (str_contains($lower, 'saneamento') || $this->isWaste($lower)) {
			// Saneamento e residuos sao cobrados a partir do consumo de agua
			// -- 90% do volume, no caso do saneamento -- mas nao sao agua
			// consumida. Contam para o custo, nunca para o consumo.
			$kind = self::KIND_SERVICE;
		} elseif (str_contains($lower, 'agua')) {
			// Agua ao m3 vem repartida por escaloes de preco. Os escaloes sao
			// faixas de tarifacao da MESMA medicao, nao medicoes diferentes:
			// somam-se todos no registo unico do contador.
			if (str_contains($lower, 'fixa')) {
				$kind = self::KIND_POWER;
			} else {
				$kind = self::KIND_ENERGY;
				$registerCode = 'TOTAL';
			}
		} elseif (str_contains($lower, 'consumo')) {
			$kind = self::KIND_ENERGY;
			// "Consumo estimado" existe e tem de se distinguir de "Consumo
			// real": uma estimativa da distribuidora misturada com leituras
			// reais estraga medias e previsoes.
			$isEstimate = str_contains($lower, 'estimado');
			$registerCode = $this->register($lower);
		} elseif (str_contains($lower, 'energia')) {
			$kind = self::KIND_ENERGY;
			$registerCode = $this->register($lower);
		} elseif (str_contains($lower, 'potencia')) {
			$kind = self::KIND_POWER;
		} elseif (str_contains($lower, 'redes') || str_contains($lower, 'acesso')) {
			$kind = self::KIND_NETWORK;
		}

		// Ultima guarda, independente da redaccao: o que e cobrado ao dia ou
		// em percentagem nao e uma medicao, por mais que a descricao o
		// pareca. Rebaixa-se em vez de se deixar entrar no consumo.
		if ($kind === self::KIND_ENERGY && in_array($unit, self::NEVER_MEASURED_UNITS, true)) {
			$kind = $unit === '%' ? self::KIND_SERVICE : self::KIND_POWER;
			$registerCode = null;
		}

		[$from, $to] = $this->period($description);

		return [
			'description' => $description,
			'kind' => $kind,
			'registerCode' => $registerCode,
			'isEstimate' => $isEstimate,
			'periodFrom' => $from,
			'periodTo' => $to,
			'quantity' => $this->float($row['quantity'] ?? null),
			'unit' => $unit === '' ? null : $unit,
			'unitPrice' => $this->float($row['unit_price'] ?? null),
			'discount' => $this->float($row['discount'] ?? null),
			'vatRate' => $this->vatPercent($row['vat_rate'] ?? null),
			'totalNet' => $this->float($row['net'] ?? null),
			'page' => (int)($row['raw']['page'] ?? $row['page'] ?? 0),
		];
	}

	/**
	 * Residuos urbanos. "RU" e curto demais para str_contains -- apanharia
	 * qualquer palavra que o contenha -- por isso vai com fronteira.
	 */
	private function isWaste(string $lower): bool {
		return preg_match('/\bru\b/u', $lower) === 1 || str_contains($lower, 'residuo');
	}

	private function register(string $lower): ?string {
		foreach (self::REGISTERS as $needle => $code) {
			if (str_contains($lower, $needle)) {
				return $code;
			}
		}
		return null;
	}

	private function isLevy(string $lower): bool {
		foreach (['audiovisual', 'imposto', 'hidricos', 'tx.rec', 'taxa gestao'] as $needle) {
			if (str_contains($lower, $needle)) {
				return true;
			}
		}
		// "iec" e "dgeg" sao curtos e apareceriam dentro de outras palavras.
		return preg_match('/\b(iec|dgeg)\b/u', $lower) === 1;
	}

	/**
	 * Le "29 ago a 19 set 2026" da descricao.
	 *
	 * O ano aparece uma so vez, no fim. Quando o intervalo atravessa o
	 * fim do ano -- "20 dez a 15 jan 2027" -- o mes inicial e maior do que o
	 * final, e o inicio pertence ao ano anterior. Sem isto, um periodo de
	 * dezembro a janeiro daria -346 dias e o consumo por dia saia negativo.
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	private function period(string $description): array {
		$months = implode('|', array_keys(self::MONTHS));
		$pattern = '/(\d{1,2})\s+(' . $months . ')\w*\s+a\s+(\d{1,2})\s+(' . $months . ')\w*\s+(\d{4})/u';

		if (preg_match($pattern, $this->fold($description), $m) !== 1) {
			return [null, null];
		}

		$startMonth = self::MONTHS[$m[2]];
		$endMonth = self::MONTHS[$m[4]];
		$endYear = (int)$m[5];
		$startYear = $startMonth > $endMonth ? $endYear - 1 : $endYear;

		return [
			sprintf('%04d-%02d-%02d', $startYear, $startMonth, (int)$m[1]),
			sprintf('%04d-%02d-%02d', $endYear, $endMonth, (int)$m[3]),
		];
	}

	/**
	 * Soma o consumo por (escalao, periodo).
	 *
	 * E aqui que se desfaz a divisao por taxa de IVA: as duas linhas de
	 * "Consumo real Vazio" do mesmo intervalo voltam a ser um unico numero.
	 */
	private function aggregate(array $lines): array {
		$byKey = [];
		foreach ($lines as $line) {
			if ($line['kind'] !== self::KIND_ENERGY || $line['registerCode'] === null) {
				continue;
			}
			if ($line['periodFrom'] === null || $line['quantity'] === null) {
				continue;
			}

			$key = $line['periodFrom'] . '|' . $line['periodTo'] . '|' . $line['registerCode'];
			if (!isset($byKey[$key])) {
				$byKey[$key] = [
					'periodFrom' => $line['periodFrom'],
					'periodTo' => $line['periodTo'],
					'registerCode' => $line['registerCode'],
					'quantity' => 0.0,
					'net' => 0.0,
					'isEstimate' => false,
					'unitPrices' => [],
				];
			}

			$byKey[$key]['quantity'] += $line['quantity'];
			$byKey[$key]['net'] += $line['totalNet'] ?? 0.0;
			// Basta uma das parcelas ser estimada para o agregado nao ser uma
			// leitura real.
			$byKey[$key]['isEstimate'] = $byKey[$key]['isEstimate'] || $line['isEstimate'];
			if ($line['unitPrice'] !== null) {
				$byKey[$key]['unitPrices'][] = $line['unitPrice'];
			}
		}

		$out = [];
		foreach ($byKey as $entry) {
			$prices = array_values(array_unique($entry['unitPrices']));
			$out[] = [
				'periodFrom' => $entry['periodFrom'],
				'periodTo' => $entry['periodTo'],
				'registerCode' => $entry['registerCode'],
				'quantity' => round($entry['quantity'], 3),
				'net' => round($entry['net'], 2),
				'isEstimate' => $entry['isEstimate'],
				// Se o mesmo escalao no mesmo intervalo trouxer precos
				// diferentes, nao ha "o preco" -- devolve-se null em vez de
				// escolher um a sorte.
				'unitPrice' => count($prices) === 1 ? $prices[0] : null,
			];
		}

		usort($out, static fn (array $a, array $b) => [$a['periodFrom'], $a['registerCode']]
			<=> [$b['periodFrom'], $b['registerCode']]);

		return $out;
	}

	private function earliest(array $lines): ?string {
		$dates = array_filter(array_column($lines, 'periodFrom'));
		return $dates === [] ? null : min($dates);
	}

	private function latest(array $lines): ?string {
		$dates = array_filter(array_column($lines, 'periodTo'));
		return $dates === [] ? null : max($dates);
	}

	/**
	 * Minusculas sem acentos, para comparar descricoes sem depender de como
	 * vem acentuado ("Potência" e "Potencia" sao a mesma coisa).
	 */
	private function fold(string $value): string {
		$value = mb_strtolower($value, 'UTF-8');
		return strtr($value, [
			'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
			'é' => 'e', 'ê' => 'e', 'í' => 'i',
			'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
			'ú' => 'u', 'ç' => 'c',
		]);
	}

	/**
	 * Normaliza a taxa de IVA para percentagem.
	 *
	 * O esquema v1 do extractor da a taxa em fraccao (0.06, 0.23); outras
	 * respostas dao-na em percentagem (6, 23). Guarda-se sempre em
	 * percentagem, que e como se le numa fatura -- e, mais importante, para
	 * nao haver codigo a jusante a ter de adivinhar qual das duas recebeu.
	 * Um modelo de custo que tome 0.23 por 23 trata tudo como taxa reduzida e
	 * subestima o IVA em dois tercos, sem nada falhar.
	 *
	 * Nenhuma taxa portuguesa cai entre 1% e 100%, por isso um valor <= 1
	 * (diferente de zero) so pode ser fraccao.
	 */
	private function vatPercent(mixed $value): ?float {
		$rate = $this->float($value);
		if ($rate === null) {
			return null;
		}
		return $rate > 0.0 && $rate <= 1.0 ? round($rate * 100, 2) : $rate;
	}

	private function float(mixed $value): ?float {
		return $value === null || $value === '' ? null : (float)$value;
	}
}
