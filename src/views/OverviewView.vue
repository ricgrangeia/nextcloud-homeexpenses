<template>
	<div class="he-page">
		<h2>Visão geral</h2>
		<p class="he-hint">
			O que a casa consumiu, a partir das leituras em bruto. Nada nesta página está guardado —
			é tudo calculado no momento, para continuar certo depois de qualquer correção.
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<h3>Contadores</h3>
			<p v-if="!meters.length" class="he-empty">
				Ainda não há contadores. Cria o primeiro em <RouterLink :to="{ name: 'meters' }">Contadores</RouterLink>.
			</p>

			<div v-for="entry in meters" :key="entry.meter.id" class="he-card">
				<div class="he-card-head">
					<strong>
						<RouterLink :to="{ name: 'meter', params: { id: entry.meter.id } }">
							{{ entry.meter.name }}
						</RouterLink>
					</strong>
					<span class="he-hint" style="margin:0">
						{{ entry.readingCount }} leituras · {{ entry.meter.unit }}
					</span>
				</div>

				<div v-if="entry.anomalies.length" class="he-warn">
					{{ entry.anomalies.length }} leitura(s) com valores a descer — verifica,
					ou define o número de dígitos se o contador deu a volta.
				</div>

				<p v-if="!entry.lastPeriod" class="he-empty" style="padding:8px 0">
					São precisas duas leituras para haver consumo que mostrar.
				</p>

				<div v-else class="he-grid">
					<div v-for="reg in entry.lastPeriod.byRegister" :key="reg.code" class="he-stat">
						<span class="he-stat-label">{{ reg.label || reg.code }}</span>
						<span class="he-stat-value">{{ formatNumber(reg.consumed) }}</span>
						<span class="he-stat-label">{{ formatNumber(reg.perDay, 2) }} / dia</span>
					</div>
					<div class="he-stat">
						<span class="he-stat-label">Total do período</span>
						<span class="he-stat-value">{{ formatNumber(entry.lastPeriod.total) }}</span>
						<span class="he-stat-label">
							{{ formatDate(entry.lastPeriod.from) }} → {{ formatDate(entry.lastPeriod.to) }}
							({{ entry.lastPeriod.days }} dias)
						</span>
					</div>
				</div>
			</div>

			<h3>Garrafas em uso</h3>
			<p v-if="!openCycles.length" class="he-empty">
				Nenhuma garrafa em uso. Regista uma em <RouterLink :to="{ name: 'gas' }">Garrafas de gás</RouterLink>.
			</p>

			<div v-for="cycle in openCycles" :key="cycle.id" class="he-card">
				<div class="he-card-head">
					<strong>{{ cycle.bottleType.brand }} {{ cycle.bottleType.gasType }} {{ cycle.bottleType.nominalKg }} kg</strong>
					<span class="he-hint" style="margin:0">{{ applianceLabel(cycle.appliance) }}</span>
				</div>

				<div v-if="cycle.stats.needsTare" class="he-warn">
					Falta a tara desta garrafa (está gravada na gola). Sem ela não há nível nem
					estimativa de quando acaba.
				</div>

				<div class="he-grid">
					<div class="he-stat">
						<span class="he-stat-label">Nível</span>
						<span class="he-stat-value">
							{{ cycle.stats.level ? cycle.stats.level.levelPct + '%' : '—' }}
						</span>
						<div v-if="cycle.stats.level" class="he-bar">
							<span :style="{ width: cycle.stats.level.levelPct + '%' }" />
						</div>
					</div>
					<div class="he-stat">
						<span class="he-stat-label">Em uso há</span>
						<span class="he-stat-value">{{ cycle.stats.durationDays }} dias</span>
						<span class="he-stat-label">desde {{ formatDate(cycle.installedAt) }}</span>
					</div>
					<div class="he-stat">
						<span class="he-stat-label">Consumo</span>
						<span class="he-stat-value">{{ formatNumber(cycle.stats.kgPerDay, 3) }} kg/dia</span>
						<span class="he-stat-label">{{ rateSource(cycle.stats.kgPerDaySource) }}</span>
					</div>
					<div class="he-stat">
						<span class="he-stat-label">Deve durar até</span>
						<span class="he-stat-value">{{ formatDate(cycle.stats.estimatedEmptyDate) }}</span>
						<span v-if="cycle.stats.daysRemaining !== null" class="he-stat-label">
							mais {{ cycle.stats.daysRemaining }} dias
						</span>
					</div>
				</div>
			</div>

			<h3>Quanto rende uma garrafa</h3>
			<p v-if="!averages.length" class="he-empty">
				Ainda não há garrafas acabadas. A média aparece quando a primeira garrafa for dada
				como vazia — uma garrafa a meio puxaria a média para baixo.
			</p>
			<table v-else class="he-table">
				<thead>
					<tr>
						<th>Tipo</th>
						<th class="he-num">Garrafas</th>
						<th class="he-num">Média</th>
						<th class="he-num">Mais curta</th>
						<th class="he-num">Mais longa</th>
						<th class="he-num">Custo médio</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in averages" :key="row.bottleTypeId">
						<td>{{ row.label }}</td>
						<td class="he-num">{{ row.cycles }}</td>
						<td class="he-num">{{ row.averageDays }} dias</td>
						<td class="he-num">{{ row.shortestDays }} dias</td>
						<td class="he-num">{{ row.longestDays }} dias</td>
						<td class="he-num">{{ formatMoney(row.averageCost) }}</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError } from '@nextcloud/dialogs'

import api from '../api/client.js'
import { formatDate, formatNumber, formatMoney, APPLIANCES } from '../utils/format.js'

const loading = ref(true)
const meters = ref([])
const gas = ref({ openCycles: [], averagesByType: [] })

const openCycles = computed(() => gas.value.openCycles ?? [])
const averages = computed(() => (gas.value.averagesByType ?? []).filter((row) => row.cycles > 0))

const applianceLabel = (value) => APPLIANCES.find((a) => a.value === value)?.label ?? value

const rateSource = (source) => {
	if (source === 'weighings') {
		return 'medido entre pesagens'
	}
	if (source === 'fullCycle') {
		return 'média do ciclo completo'
	}
	return 'sem dados suficientes'
}

onMounted(async () => {
	try {
		const data = await api.overview()
		meters.value = data.meters ?? []
		gas.value = data.gas ?? { openCycles: [], averagesByType: [] }
	} catch (error) {
		showError('Não foi possível carregar a visão geral.')
	} finally {
		loading.value = false
	}
})
</script>
