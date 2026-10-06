<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Service;

/**
 * Projeccao de consumo e de custo.
 *
 * Nao tem dependencias e nao toca na base de dados: recebe periodos de consumo
 * ja calculados -- venham eles de leituras ao contador ou de faturas -- e
 * devolve uma projeccao. E testavel sem servidor nenhum, que e o que permite
 * verificar que ela se recusa a inventar.
 *
 * A regra que molda esta classe: **uma previsao sem base suficiente nao e uma
 * previsao, e um palpite com ar de numero**. Por isso nada aqui devolve um
 * valor sem dizer em que se baseia, e com menos de dois periodos nao devolve
 * valor nenhum. Um numero errado com duas casas decimais e pior do que a
 * ausencia dele: parece credivel.
 *
 * O caso que mais importa acautelar e a sazonalidade. Projectar o inverno a
 * partir de setembro da sempre um numero, e esse numero esta sempre errado --
 * sem aquecimento e com dias longos, setembro e o mes que menos se parece com
 * janeiro. Enquanto nao houver um ano de historico, isso sai como ressalva
 * explicita.
 */
class ForecastService {
	/** Abaixo disto nao ha ritmo que se possa projectar. */
	private const MIN_PERIODS = 2;

	/** Meses em que se aquece a casa, em Portugal continental. */
	private const HEATING_MONTHS = [11, 12, 1, 2, 3];

	public const CONFIDENCE_NONE = 'none';
	public const CONFIDENCE_LOW = 'low';
	public const CONFIDENCE_MEDIUM = 'medium';
	public const CONFIDENCE_HIGH = 'high';

	/**
	 * Projecta consumo (e custo, se houver modelo) para os proximos dias.
	 *
	 * @param list<array{periodFrom: string, periodTo: string, byRegister: array<string, float>, isEstimate?: bool}> $periods
	 * @param array|null $costModel saido de costModelFromInvoice(), ou null para so projectar kWh
	 */
	public function forecast(array $periods, string $asOf, int $horizonDays, ?array $costModel = null): array {
		$periods = $this->sorted($periods);
		$basis = $this->basis($periods);

		if (count($periods) < self::MIN_PERIODS || $basis['daysObserved'] <= 0) {
			return [
				'basis' => $basis,
				'confidence' => self::CONFIDENCE_NONE,
				'perDay' => null,
				'projected' => null,
				'cost' => null,
				'caveats' => [
					'Nao ha historico que chegue para projectar. Sao precisos pelo menos '
					. self::MIN_PERIODS . ' periodos de consumo -- por leituras do contador ou por faturas.',
				],
			];
		}

		$perDay = $this->perDay($periods, $basis['daysObserved']);
		$projected = $this->project($perDay, $horizonDays);
		$caveats = $this->caveats($periods, $basis, $asOf, $horizonDays);

		return [
			'basis' => $basis,
			'confidence' => $this->confidence($basis['daysObserved']),
			'perDay' => $perDay,
			'projected' => $projected,
			'cost' => $costModel === null ? null : $this->cost($projected, $horizonDays, $costModel),
			'caveats' => $caveats,
		];
	}

	/**
	 * Deriva um modelo de custo das linhas de uma fatura.
	 *
	 * O que uma fatura ensina e que nao esta em lado nenhum senao nela: quanto
	 * custa o dia de potencia, quanto custa o dia de acesso as redes, e quanto
	 * pesam os impostos. Sem isto so se consegue prever a energia, que e menos
	 * de metade do que se paga.
	 *
	 * A divisao do IVA entre taxa reduzida e normal NAO e calculada a partir
	 * de uma regra: le-se a proporcao que a fatura mostra. A regra legal muda,
	 * e uma regra codificada e uma regra que a legislacao parte em silencio.
	 *
	 * @param list<array> $lines linhas de fatura ja classificadas
	 */
	public function costModelFromInvoice(array $lines, int $invoiceDays): array {
		$energyPrices = [];
		$perDay = 0.0;
		$perKwh = 0.0;
		$perMonth = 0.0;
		$reducedBase = 0.0;
		$totalBase = 0.0;

		foreach ($lines as $line) {
			$kind = $line['kind'] ?? InvoiceParser::KIND_OTHER;
			$qty = (float)($line['quantity'] ?? 0);
			$price = (float)($line['unitPrice'] ?? 0);
			$net = (float)($line['totalNet'] ?? ($qty * $price));

			if (($line['vatRate'] ?? null) !== null) {
				$totalBase += $net;
				if ((float)$line['vatRate'] < 10.0) {
					$reducedBase += $net;
				}
			}

			switch ($kind) {
				case InvoiceParser::KIND_ENERGY:
					$code = $line['registerCode'] ?? null;
					if ($code !== null && $price > 0) {
						$energyPrices[$code] = $price;
					}
					break;
				case InvoiceParser::KIND_POWER:
				case InvoiceParser::KIND_NETWORK:
					// Cobrados ao dia. Divide-se pelo numero de dias da fatura
					// para o modelo ser independente do tamanho do periodo.
					$perDay += $invoiceDays > 0 ? $net / $invoiceDays : 0.0;
					break;
				case InvoiceParser::KIND_LEVY:
					$description = mb_strtolower((string)($line['description'] ?? ''));
					if (str_contains($description, 'iec')) {
						$perKwh += $price;
					} elseif (str_contains($description, 'audiovisual')) {
						$perMonth += $price;
					} else {
						$perDay += $invoiceDays > 0 ? $net / $invoiceDays : 0.0;
					}
					break;
			}
		}

		return [
			'energyPrices' => $energyPrices,
			'fixedPerDay' => round($perDay, 6),
			'leviesPerKwh' => round($perKwh, 6),
			'leviesPerMonth' => round($perMonth, 4),
			// Proporcao do valor tributado a taxa reduzida, lida da fatura.
			'reducedVatShare' => $totalBase > 0 ? round($reducedBase / $totalBase, 4) : 0.0,
			'vatReduced' => 0.06,
			'vatNormal' => 0.23,
			'basedOnDays' => $invoiceDays,
		];
	}

	private function cost(array $projected, int $horizonDays, array $costModel): array {
		$energy = 0.0;
		$unpriced = [];

		foreach ($projected['byRegister'] as $code => $kwh) {
			$price = $costModel['energyPrices'][$code] ?? null;
			if ($price === null) {
				// Um escalao sem preco nao vale zero. Marca-se, e o total fica
				// declarado como incompleto -- um total a menos parece credivel,
				// que e exatamente o problema.
				$unpriced[] = $code;
				continue;
			}
			$energy += $kwh * $price;
		}

		$fixed = $costModel['fixedPerDay'] * $horizonDays;
		$levies = $costModel['leviesPerKwh'] * $projected['total']
			+ $costModel['leviesPerMonth'] * ($horizonDays / 30.0);

		$net = $energy + $fixed + $levies;
		$share = $costModel['reducedVatShare'];
		$vat = $net * ($share * $costModel['vatReduced'] + (1 - $share) * $costModel['vatNormal']);

		return [
			'energy' => round($energy, 2),
			'fixed' => round($fixed, 2),
			'levies' => round($levies, 2),
			'net' => round($net, 2),
			'vat' => round($vat, 2),
			'gross' => round($net + $vat, 2),
			'unpriced' => $unpriced,
			'complete' => $unpriced === [],
		];
	}

	/** @param list<array> $periods */
	private function perDay(array $periods, int $daysObserved): array {
		$totals = [];
		$total = 0.0;

		foreach ($periods as $period) {
			foreach ($period['byRegister'] as $code => $kwh) {
				$totals[$code] = ($totals[$code] ?? 0.0) + (float)$kwh;
				$total += (float)$kwh;
			}
		}

		$byRegister = [];
		foreach ($totals as $code => $sum) {
			$byRegister[$code] = round($sum / $daysObserved, 4);
		}
		ksort($byRegister);

		return ['byRegister' => $byRegister, 'total' => round($total / $daysObserved, 4)];
	}

	private function project(array $perDay, int $horizonDays): array {
		$byRegister = [];
		foreach ($perDay['byRegister'] as $code => $rate) {
			$byRegister[$code] = round($rate * $horizonDays, 2);
		}

		return [
			'days' => $horizonDays,
			'byRegister' => $byRegister,
			'total' => round($perDay['total'] * $horizonDays, 2),
		];
	}

	/** @param list<array> $periods */
	private function basis(array $periods): array {
		if ($periods === []) {
			return ['periods' => 0, 'daysObserved' => 0, 'from' => null, 'to' => null, 'hasEstimates' => false];
		}

		$days = 0;
		$hasEstimates = false;
		foreach ($periods as $period) {
			$days += $this->days($period['periodFrom'], $period['periodTo']);
			$hasEstimates = $hasEstimates || ($period['isEstimate'] ?? false);
		}

		return [
			'periods' => count($periods),
			'daysObserved' => $days,
			'from' => $periods[0]['periodFrom'],
			'to' => $periods[count($periods) - 1]['periodTo'],
			'hasEstimates' => $hasEstimates,
		];
	}

	/** @param list<array> $periods */
	private function caveats(array $periods, array $basis, string $asOf, int $horizonDays): array {
		$caveats = [];

		if ($basis['daysObserved'] < 60) {
			$caveats[] = sprintf(
				'A base sao %d dias de consumo. E pouco: um mes atipico puxa a projeccao inteira.',
				$basis['daysObserved']
			);
		}

		if ($basis['hasEstimates']) {
			$caveats[] = 'Ha periodos marcados como estimativa da distribuidora na base do calculo. '
				. 'Uma estimativa errada propaga-se para a previsao.';
		}

		if ($basis['daysObserved'] < 365) {
			$observed = $this->monthsBetween($basis['from'], $basis['to']);
			$ahead = $this->monthsBetween($asOf, $this->addDays($asOf, $horizonDays));

			$observedHeating = array_intersect($observed, self::HEATING_MONTHS) !== [];
			$aheadHeating = array_intersect($ahead, self::HEATING_MONTHS) !== [];

			if ($aheadHeating && !$observedHeating) {
				$caveats[] = 'A projeccao cai em meses de aquecimento, mas o historico nao tem nenhum. '
					. 'O consumo de inverno nao se parece com o do resto do ano, e este numero vai ficar curto.';
			} elseif ($observedHeating && !$aheadHeating) {
				$caveats[] = 'O historico e de meses de aquecimento e a projeccao nao. '
					. 'Este numero vai ficar por cima do que vais gastar.';
			} else {
				$caveats[] = 'Ha menos de um ano de historico, por isso a sazonalidade ainda nao esta '
					. 'contabilizada -- a projeccao repete o ritmo recente.';
			}
		}

		return $caveats;
	}

	/**
	 * Junta os periodos vindos das leituras com os vindos das faturas.
	 *
	 * As duas fontes medem o MESMO consumo: um periodo de leituras que se
	 * sobreponha a uma fatura e a mesma eletricidade contada duas vezes, e
	 * somar os dois duplicaria o consumo da casa. Por isso nao se somam --
	 * escolhe-se.
	 *
	 * Ganha a leitura, por duas razoes: vem do mostrador e nao de quem factura,
	 * e traz os escaloes todos. A fatura so traz os que o contrato factura --
	 * num contrato bi-horario, nunca tera Cheias e Ponta separados -- e deixar
	 * uma fatura substituir uma leitura perderia essa distincao, que e a que
	 * permite responder se o tri-horario compensava.
	 *
	 * @param list<array> $fromReadings
	 * @param list<array> $fromInvoices
	 * @return array{periods: list<array>, used: array{readings: int, invoices: int, discarded: int}}
	 */
	public function merge(array $fromReadings, array $fromInvoices): array {
		$periods = $fromReadings;
		$kept = 0;
		$reconciled = 0;

		foreach ($fromInvoices as $invoicePeriod) {
			$match = null;
			foreach ($periods as $index => $readingPeriod) {
				if (($readingPeriod['source'] ?? 'reading') === 'reading'
					&& $this->overlaps($invoicePeriod, $readingPeriod)) {
					$match = $index;
					break;
				}
			}

			if ($match === null) {
				$periods[] = $invoicePeriod;
				$kept++;
				continue;
			}

			// Sobrepoe-se a uma leitura: nao entra na serie, para nao contar
			// o mesmo consumo duas vezes, mas tambem nao se perde. Fica
			// anexado como o que foi FACTURADO naquele intervalo, que e o que
			// permite ver quanto a estimativa se afastou do contador.
			$periods[$match]['billed'] = $this->billedAgainst($invoicePeriod, $periods[$match]);
			$reconciled++;
		}

		return [
			'periods' => $this->sorted($periods),
			'used' => [
				'readings' => count($fromReadings),
				'invoices' => $kept,
				'reconciled' => $reconciled,
				// Mantido pelo nome antigo: nada e deitado fora, mas ha
				// consumidores desta chave.
				'discarded' => $reconciled,
			],
		];
	}

	/**
	 * O que a fatura cobrou, posto ao lado do que o contador mediu.
	 *
	 * A diferenca so se calcula quando os intervalos coincidem exactamente.
	 * Quando nao coincidem, mostram-se os dois e diz-se que nao coincidem --
	 * repartir consumo por dias para os fazer bater seria inventar uma
	 * distribuicao que ninguem mediu, e o numero resultante teria ar de
	 * exacto.
	 *
	 * @return array
	 */
	private function billedAgainst(array $invoice, array $reading): array {
		$billedTotal = array_sum(array_map('floatval', $invoice['byRegister']));
		$readTotal = array_sum(array_map('floatval', $reading['byRegister']));

		$sameSpan = $invoice['periodFrom'] === $reading['periodFrom']
			&& $invoice['periodTo'] === $reading['periodTo'];

		return [
			'from' => $invoice['periodFrom'],
			'to' => $invoice['periodTo'],
			'byRegister' => array_map(
				static fn ($q) => round((float)$q, 3),
				$invoice['byRegister']
			),
			'total' => round($billedTotal, 3),
			'isEstimate' => (bool)($invoice['isEstimate'] ?? false),
			'sameSpan' => $sameSpan,
			// Positivo: a fatura cobrou mais do que o contador andou.
			'difference' => $sameSpan ? round($billedTotal - $readTotal, 3) : null,
		];
	}

	private function overlaps(array $a, array $b): bool {
		return $a['periodFrom'] < $b['periodTo'] && $b['periodFrom'] < $a['periodTo'];
	}

	/**
	 * Converte a serie do ConsumptionService (leituras) para a forma que esta
	 * classe consome.
	 *
	 * @param list<array> $seriesPeriods
	 * @return list<array>
	 */
	public function fromSeries(array $seriesPeriods): array {
		$out = [];
		foreach ($seriesPeriods as $period) {
			$byRegister = [];
			foreach ($period['byRegister'] ?? [] as $code => $entry) {
				$byRegister[$code] = (float)(is_array($entry) ? ($entry['consumed'] ?? 0) : $entry);
			}
			$out[] = [
				'periodFrom' => $period['from'],
				'periodTo' => $period['to'],
				'byRegister' => $byRegister,
				'isEstimate' => (bool)($period['isEstimate'] ?? false),
				'source' => 'reading',
			];
		}
		return $out;
	}

	/**
	 * Converte o consumo agregado das faturas para a mesma forma.
	 *
	 * Uma fatura pode trazer mais do que um intervalo -- numa mudanca de
	 * tarifario a meio do mes traz dois -- e cada um conta como periodo.
	 *
	 * @param list<array> $invoiceConsumption
	 * @return list<array>
	 */
	public function fromInvoices(array $invoiceConsumption): array {
		$byPeriod = [];
		foreach ($invoiceConsumption as $entry) {
			if ($entry['periodFrom'] === null || $entry['periodTo'] === null) {
				continue;
			}
			$key = $entry['periodFrom'] . '|' . $entry['periodTo'];
			$byPeriod[$key]['periodFrom'] = $entry['periodFrom'];
			$byPeriod[$key]['periodTo'] = $entry['periodTo'];
			$byPeriod[$key]['byRegister'][$entry['registerCode']] = (float)$entry['quantity'];
			$byPeriod[$key]['isEstimate'] = ($byPeriod[$key]['isEstimate'] ?? false) || $entry['isEstimate'];
			$byPeriod[$key]['source'] = 'invoice';
		}

		return array_values($byPeriod);
	}

	/**
	 * A serie unificada, pronta para tabela e grafico.
	 *
	 * E a mesma juncao que alimenta a previsao, mas com o que falta para se
	 * poder olhar para ela: dias, consumo por dia, e de onde veio cada
	 * periodo.
	 *
	 * O `perDay` nao e enfeite. Os periodos tem duracoes diferentes -- uma
	 * fatura de 31 dias ao lado de um intervalo de 9 entre leituras -- e
	 * comparar totais de periodos desiguais e a maneira mais facil de ler mal
	 * um grafico: a barra maior pode ser so a mais comprida.
	 *
	 * @param list<array> $fromReadings
	 * @param list<array> $fromInvoices
	 * @return array{periods: list<array>, registers: list<string>, used: array}
	 */
	public function unified(array $fromReadings, array $fromInvoices): array {
		$merged = $this->merge($fromReadings, $fromInvoices);

		$registers = [];
		$periods = [];

		foreach ($merged['periods'] as $period) {
			$days = $this->days($period['periodFrom'], $period['periodTo']);
			$byRegister = [];
			$total = 0.0;

			foreach ($period['byRegister'] as $code => $quantity) {
				$registers[$code] = true;
				$byRegister[$code] = [
					'consumed' => round((float)$quantity, 3),
					'perDay' => $days > 0 ? round((float)$quantity / $days, 4) : null,
				];
				$total += (float)$quantity;
			}

			$entry = [
				'from' => $period['periodFrom'],
				'to' => $period['periodTo'],
				'days' => $days,
				'source' => $period['source'] ?? 'reading',
				'isEstimate' => (bool)($period['isEstimate'] ?? false),
				'byRegister' => $byRegister,
				'total' => round($total, 3),
				'totalPerDay' => $days > 0 ? round($total / $days, 4) : null,
			];

			// O que a fatura cobrou pelo mesmo intervalo, quando existe.
			// Nao substitui a leitura nem se soma a ela -- fica ao lado, para
			// se ver o desvio. Uma estimativa da distribuidora foi COBRADA, e
			// por isso e um facto que tem de aparecer, nao ruido a esconder.
			if (isset($period['billed'])) {
				$entry['billed'] = $period['billed'];
			}

			$periods[] = $entry;
		}

		$codes = array_keys($registers);
		sort($codes);

		return ['periods' => $periods, 'registers' => $codes, 'used' => $merged['used']];
	}

	private function confidence(int $daysObserved): string {
		return match (true) {
			$daysObserved >= 365 => self::CONFIDENCE_HIGH,
			$daysObserved >= 180 => self::CONFIDENCE_MEDIUM,
			default => self::CONFIDENCE_LOW,
		};
	}

	/** @param list<array> $periods */
	private function sorted(array $periods): array {
		usort($periods, static fn (array $a, array $b) => $a['periodFrom'] <=> $b['periodFrom']);
		return array_values($periods);
	}

	private function days(string $from, string $to): int {
		$a = new \DateTimeImmutable($from);
		$b = new \DateTimeImmutable($to);
		return max(0, (int)$a->diff($b)->days);
	}

	private function addDays(string $date, int $days): string {
		return (new \DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
	}

	/** @return list<int> os meses civis tocados pelo intervalo */
	private function monthsBetween(string $from, string $to): array {
		$cursor = (new \DateTimeImmutable($from))->modify('first day of this month');
		$end = new \DateTimeImmutable($to);
		$months = [];

		while ($cursor <= $end && count($months) < 24) {
			$months[] = (int)$cursor->format('n');
			$cursor = $cursor->modify('+1 month');
		}

		return array_values(array_unique($months));
	}
}
