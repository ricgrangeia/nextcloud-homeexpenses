<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Service;

use OCA\HomeExpenses\Db\BottleType;
use OCA\HomeExpenses\Db\GasCycle;
use OCA\HomeExpenses\Db\Meter;
use OCA\HomeExpenses\Db\MeterRegister;
use OCA\HomeExpenses\Db\Reading;
use OCA\HomeExpenses\Db\ReadingValue;
use OCA\HomeExpenses\Db\Weighing;

/**
 * Tudo o que se DERIVA das leituras e pesagens: consumo entre leituras, nivel
 * da garrafa, duracao de um ciclo, e o custo que cada tarifario daria.
 *
 * Nada disto e persistido. A razao e a mesma pela qual a app Contas nao guarda
 * "em atraso": no momento em que se corrige uma leitura lancada com um digito
 * trocado, qualquer valor derivado que estivesse gravado passaria a mentir, e
 * nada no sistema saberia que tinha de o recalcular.
 *
 * Classe sem dependencias de proposito -- recebe entidades e devolve arrays,
 * o que a torna testavel sem base de dados.
 */
class ConsumptionService {
	/**
	 * Diferenca entre duas leituras do mesmo registo, com correcao de volta ao
	 * zero. Um contador de 5 digitos que passe de 99.990 para 10 nao consumiu
	 * -99.980 -- consumiu 20. Sem o numero de digitos nao ha como distinguir
	 * isso de uma leitura mal lancada, por isso so se corrige quando o
	 * contador declara quantos digitos tem.
	 */
	public function delta(float $previous, float $current, int $digits): float {
		$delta = $current - $previous;
		if ($delta < 0 && $digits > 0) {
			$delta += 10 ** $digits;
		}
		return $delta;
	}

	/**
	 * Serie de consumo de um contador: um periodo por cada par de leituras
	 * consecutivas, com o consumo de cada registo e o ritmo por dia.
	 *
	 * @param MeterRegister[] $registers
	 * @param Reading[] $readings ordenadas por read_at ascendente
	 * @param array<int, ReadingValue[]> $valuesByReadingId
	 * @return array{periods: list<array>, totals: array<string, float>, anomalies: list<array>}
	 */
	public function meterSeries(Meter $meter, array $registers, array $readings, array $valuesByReadingId): array {
		$registerById = [];
		foreach ($registers as $register) {
			$registerById[$register->getId()] = $register;
		}

		$periods = [];
		$totals = [];
		$anomalies = [];
		$digits = $meter->getDigits();

		for ($i = 1, $n = count($readings); $i < $n; $i++) {
			$previous = $readings[$i - 1];
			$current = $readings[$i];

			$from = $previous->getReadAt();
			$to = $current->getReadAt();
			if ($from === null || $to === null) {
				continue;
			}

			$days = (int)$from->diff($to)->days;

			$previousByRegister = $this->indexByRegister($valuesByReadingId[$previous->getId()] ?? []);
			$currentByRegister = $this->indexByRegister($valuesByReadingId[$current->getId()] ?? []);

			$byRegister = [];
			$periodTotal = 0.0;

			foreach ($registerById as $registerId => $register) {
				if (!isset($previousByRegister[$registerId], $currentByRegister[$registerId])) {
					continue;
				}

				$before = $previousByRegister[$registerId];
				$after = $currentByRegister[$registerId];
				$consumed = $this->delta($before, $after, $digits);

				// Um valor que desce num contador que so sobe, e sem numero de
				// digitos que explique a volta, e quase de certeza um erro de
				// lancamento. Nao se corrige em silencio -- assinala-se, para
				// quem le saber que aquele periodo nao e de confianca.
				if ($consumed < 0) {
					$anomalies[] = [
						'readingId' => $current->getId(),
						'registerCode' => $register->getCode(),
						'readAt' => $to->format('Y-m-d'),
						'previous' => $before,
						'current' => $after,
						'reason' => 'A leitura desceu. Verifica o valor, ou define o numero de digitos do contador se ele deu a volta.',
					];
				}

				$code = $register->getCode();
				$byRegister[$code] = [
					'code' => $code,
					'label' => $register->getLabel(),
					'previous' => $before,
					'current' => $after,
					'consumed' => round($consumed, 3),
					'perDay' => $days > 0 ? round($consumed / $days, 3) : null,
				];
				$periodTotal += $consumed;
				$totals[$code] = round(($totals[$code] ?? 0.0) + $consumed, 3);
			}

			$periods[] = [
				'from' => $from->format('Y-m-d'),
				'to' => $to->format('Y-m-d'),
				'days' => $days,
				'isEstimate' => $previous->getIsEstimate() || $current->getIsEstimate(),
				'byRegister' => $byRegister,
				'total' => round($periodTotal, 3),
				'totalPerDay' => $days > 0 ? round($periodTotal / $days, 3) : null,
			];
		}

		return [
			'periods' => $periods,
			'totals' => $totals,
			'anomalies' => $anomalies,
		];
	}

	/**
	 * Preco por unidade para um codigo de registo dentro de um tarifario.
	 *
	 * A regra de mapeamento e o que torna possivel comparar tarifarios com
	 * numeros de escaloes diferentes: um contador tri-horario tem registos
	 * V/C/P, mas um tarifario bi-horario so tem precos para V e FV. Como
	 * "fora de vazio" e, por definicao, cheias + ponta, o consumo de C e de P
	 * valorizam-se ambos ao preco de FV.
	 *
	 * @param array<string, float> $pricesByCode
	 */
	public function resolvePrice(string $code, array $pricesByCode): ?float {
		if (isset($pricesByCode[$code])) {
			return $pricesByCode[$code];
		}
		if (($code === MeterRegister::CODE_CHEIAS || $code === MeterRegister::CODE_PONTA)
			&& isset($pricesByCode[MeterRegister::CODE_FORA_VAZIO])) {
			return $pricesByCode[MeterRegister::CODE_FORA_VAZIO];
		}
		if (isset($pricesByCode[MeterRegister::CODE_TOTAL])) {
			return $pricesByCode[MeterRegister::CODE_TOTAL];
		}
		return null;
	}

	/**
	 * Quanto custaria um dado consumo num dado tarifario.
	 *
	 * @param array<string, float> $totalsByCode consumo por codigo de registo
	 * @param array<string, float> $pricesByCode preco por unidade por codigo
	 * @return array{energy: float, standing: float, total: float, unpriced: list<string>}
	 */
	public function costFor(array $totalsByCode, array $pricesByCode, ?float $standingChargeDay = null, int $days = 0): array {
		$energy = 0.0;
		$unpriced = [];

		foreach ($totalsByCode as $code => $consumed) {
			$price = $this->resolvePrice((string)$code, $pricesByCode);
			if ($price === null) {
				// Nao se assume zero: um escalao sem preco faz o total mentir
				// para baixo, e um total errado para baixo e pior do que um
				// total ausente, porque parece credivel.
				$unpriced[] = (string)$code;
				continue;
			}
			$energy += $consumed * $price;
		}

		$standing = ($standingChargeDay !== null && $days > 0) ? $standingChargeDay * $days : 0.0;

		return [
			'energy' => round($energy, 2),
			'standing' => round($standing, 2),
			'total' => round($energy + $standing, 2),
			'unpriced' => $unpriced,
		];
	}

	/**
	 * Compara varios tarifarios sobre o MESMO consumo real.
	 *
	 * E isto que justifica guardar os tres registos em bruto mesmo tendo um
	 * contrato bi-horario: com V, C e P separados, a resposta a "o tri-horario
	 * sairia mais barato?" e aritmetica sobre valores medidos, nao uma
	 * estimativa a partir de perfis de consumo tipicos.
	 *
	 * @param array<string, float> $totalsByCode
	 * @param list<array{id: int|null, name: string, option: string, prices: array<string, float>, standingChargeDay: float|null}> $tariffs
	 */
	public function compareTariffs(array $totalsByCode, array $tariffs, int $days = 0): array {
		$results = [];
		foreach ($tariffs as $tariff) {
			$cost = $this->costFor($totalsByCode, $tariff['prices'], $tariff['standingChargeDay'] ?? null, $days);
			$results[] = [
				'tariffId' => $tariff['id'] ?? null,
				'name' => $tariff['name'],
				'option' => $tariff['option'],
				'cost' => $cost,
				'comparable' => $cost['unpriced'] === [],
			];
		}

		// So se ordenam e comparam os que tem todos os escaloes com preco --
		// comparar um total completo com um total incompleto daria sempre
		// vencedor ao incompleto.
		$comparable = array_values(array_filter($results, static fn (array $r) => $r['comparable']));
		usort($comparable, static fn (array $a, array $b) => $a['cost']['total'] <=> $b['cost']['total']);

		$cheapest = $comparable[0] ?? null;
		foreach ($results as &$result) {
			$result['savingVsCheapest'] = ($cheapest !== null && $result['comparable'])
				? round($result['cost']['total'] - $cheapest['cost']['total'], 2)
				: null;
		}
		unset($result);

		return [
			'days' => $days,
			'consumption' => $totalsByCode,
			'results' => $results,
			'cheapest' => $cheapest,
		];
	}

	// --- Gas -------------------------------------------------------------

	/**
	 * Nivel da garrafa a partir de uma pesagem.
	 *
	 * A tara (peso da garrafa vazia) vem gravada na gola e varia de garrafa
	 * para garrafa, mesmo entre garrafas do mesmo tipo. Sem ela nao ha nivel
	 * nenhum para calcular -- devolve-se null em vez de inventar um valor com
	 * a tara nominal, que daria um erro sistematico de meio quilo.
	 *
	 * @return array{netKg: float, levelPct: float}|null
	 */
	public function bottleLevel(float $grossKg, ?float $tareKg, float $nominalKg): ?array {
		if ($tareKg === null || $nominalKg <= 0) {
			return null;
		}
		$net = $grossKg - $tareKg;
		$pct = ($net / $nominalKg) * 100;

		return [
			'netKg' => round($net, 2),
			'levelPct' => round(max(0.0, min(100.0, $pct)), 1),
		];
	}

	/**
	 * Estado de um ciclo de garrafa: quanto durou (ou vai durando), que ritmo
	 * de consumo tem, e quando deve acabar.
	 *
	 * @param Weighing[] $weighings ordenadas por weighed_at ascendente
	 */
	public function cycleStats(GasCycle $cycle, BottleType $type, array $weighings, \DateTimeImmutable $today): array {
		$installedAt = $cycle->getInstalledAt();
		$removedAt = $cycle->getRemovedAt();
		$closed = $removedAt !== null;
		$tare = $cycle->getTareKg() ?? $type->getDefaultTareKg();
		$nominal = $type->getNominalKg();

		$endForDuration = $closed ? $removedAt : $today;
		$durationDays = $installedAt !== null ? (int)$installedAt->diff($endForDuration)->days : 0;

		$latest = $weighings === [] ? null : $weighings[count($weighings) - 1];
		$level = $latest !== null ? $this->bottleLevel($latest->getGrossKg(), $tare, $nominal) : null;

		// Ritmo de consumo: com duas pesagens ou mais, mede-se o que
		// efetivamente saiu da garrafa entre elas. E muito melhor do que
		// dividir o nominal pela duracao, que assume consumo constante desde o
		// primeiro dia e so fica disponivel quando a garrafa ja acabou.
		$kgPerDay = null;
		$kgPerDaySource = null;

		if (count($weighings) >= 2) {
			$first = $weighings[0];
			$last = $weighings[count($weighings) - 1];
			$spanDays = (int)$first->getWeighedAt()->diff($last->getWeighedAt())->days;
			$used = $first->getGrossKg() - $last->getGrossKg();
			if ($spanDays > 0 && $used > 0) {
				$kgPerDay = round($used / $spanDays, 3);
				$kgPerDaySource = 'weighings';
			}
		}

		if ($kgPerDay === null && $closed && $durationDays > 0) {
			$kgPerDay = round($nominal / $durationDays, 3);
			$kgPerDaySource = 'fullCycle';
		}

		$daysRemaining = null;
		$estimatedEmptyDate = null;
		if (!$closed && $kgPerDay !== null && $kgPerDay > 0 && $level !== null && $level['netKg'] > 0) {
			$daysRemaining = (int)floor($level['netKg'] / $kgPerDay);
			$estimatedEmptyDate = $today->add(new \DateInterval('P' . max(0, $daysRemaining) . 'D'))->format('Y-m-d');
		}

		$costPerDay = null;
		$price = $cycle->getPricePaid();
		if ($price !== null && $closed && $durationDays > 0) {
			$costPerDay = round($price / $durationDays, 3);
		}

		return [
			'cycleId' => $cycle->getId(),
			'closed' => $closed,
			'tareKg' => $tare,
			'nominalKg' => $nominal,
			'durationDays' => $durationDays,
			'level' => $level,
			'lastWeighedAt' => $latest?->getWeighedAt()?->format('Y-m-d'),
			'kgPerDay' => $kgPerDay,
			'kgPerDaySource' => $kgPerDaySource,
			'daysRemaining' => $daysRemaining,
			'estimatedEmptyDate' => $estimatedEmptyDate,
			'costPerDay' => $costPerDay,
			// Sem tara nao ha nivel nem dias restantes -- vale a pena dizer
			// porque, senao parece que a app simplesmente nao calcula.
			'needsTare' => $tare === null,
		];
	}

	/**
	 * Quanto rende, em media, uma garrafa -- a pergunta que originou a app.
	 * So conta ciclos fechados: um ciclo a meio puxaria a media para baixo.
	 *
	 * @param list<array{durationDays: int, closed: bool, pricePaid: float|null}> $cycles
	 */
	public function averageCycleDuration(array $cycles): array {
		$closed = array_values(array_filter($cycles, static fn (array $c) => $c['closed'] && $c['durationDays'] > 0));
		$count = count($closed);

		if ($count === 0) {
			return ['cycles' => 0, 'averageDays' => null, 'shortestDays' => null, 'longestDays' => null, 'averageCost' => null];
		}

		$durations = array_column($closed, 'durationDays');
		$prices = array_values(array_filter(array_column($closed, 'pricePaid'), static fn ($p) => $p !== null));

		return [
			'cycles' => $count,
			'averageDays' => (int)round(array_sum($durations) / $count),
			'shortestDays' => min($durations),
			'longestDays' => max($durations),
			'averageCost' => $prices === [] ? null : round(array_sum($prices) / count($prices), 2),
		];
	}

	/**
	 * @param ReadingValue[] $values
	 * @return array<int, float>
	 */
	private function indexByRegister(array $values): array {
		$indexed = [];
		foreach ($values as $value) {
			$indexed[$value->getRegisterId()] = $value->getValue();
		}
		return $indexed;
	}
}
