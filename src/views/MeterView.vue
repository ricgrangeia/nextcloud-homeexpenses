<template>
	<div class="he-page">
		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else-if="detail">
			<h2>{{ detail.meter.name }}</h2>
			<p class="he-hint">
				A data é a que está <strong>no contador</strong>, não a de hoje. Lançar hoje uma leitura
				de há um mês com a data de hoje faz o consumo por dia ficar errado.
			</p>

			<form class="he-form" @submit.prevent="addReading">
				<NcDateTimePickerNative v-model="form.readAt" label="Data da leitura" type="date" />
				<NcTextField
					v-for="register in detail.registers"
					:key="register.id"
					v-model="form.values[register.code]"
					type="number"
					step="any"
					:label="register.label"
					:placeholder="detail.meter.unit" />
				<NcCheckboxRadioSwitch v-model="form.isEstimate" type="switch">Estimativa</NcCheckboxRadioSwitch>
				<NcButton type="primary" native-type="submit" :disabled="saving">Lançar leitura</NcButton>
			</form>

			<div v-if="detail.series.anomalies.length" class="he-warn">
				<strong>Leituras a verificar</strong>
				<ul>
					<li v-for="(a, i) in detail.series.anomalies" :key="i">
						{{ formatDate(a.readAt) }} — {{ a.registerCode }}: {{ formatNumber(a.previous) }}
						→ {{ formatNumber(a.current) }}. {{ a.reason }}
					</li>
				</ul>
			</div>

			<h3>Consumo por período</h3>
			<p v-if="!detail.series.periods.length" class="he-empty">
				São precisas duas leituras para haver consumo que calcular.
			</p>
			<table v-else class="he-table">
				<thead>
					<tr>
						<th>De</th>
						<th>A</th>
						<th class="he-num">Dias</th>
						<th v-for="register in detail.registers" :key="register.id" class="he-num">
							{{ register.label }}
						</th>
						<th class="he-num">Total</th>
						<th class="he-num">Por dia</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(period, i) in reversedPeriods" :key="i">
						<td>{{ formatDate(period.from) }}</td>
						<td>{{ formatDate(period.to) }}{{ period.isEstimate ? ' *' : '' }}</td>
						<td class="he-num">{{ period.days }}</td>
						<td v-for="register in detail.registers" :key="register.id" class="he-num">
							{{ formatNumber(period.byRegister[register.code]?.consumed) }}
						</td>
						<td class="he-num"><strong>{{ formatNumber(period.total) }}</strong></td>
						<td class="he-num">{{ formatNumber(period.totalPerDay) }}</td>
					</tr>
				</tbody>
			</table>
			<p v-if="hasEstimates" class="he-hint">* período que toca numa leitura estimada.</p>

			<h3>Leituras</h3>
			<table class="he-table">
				<thead>
					<tr>
						<th>Data</th>
						<th v-for="register in detail.registers" :key="register.id" class="he-num">
							{{ register.label }}
						</th>
						<th>Origem</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="reading in reversedReadings" :key="reading.id">
						<td>{{ formatDate(reading.readAt) }}</td>
						<td v-for="register in detail.registers" :key="register.id" class="he-num">
							{{ formatNumber(reading.values[register.code]) }}
						</td>
						<td>{{ reading.isEstimate ? 'estimativa' : reading.source }}</td>
						<td class="he-num">
							<NcButton type="tertiary" @click="removeReading(reading)">Apagar</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<h3>Que tarifário sairia mais barato</h3>
			<NcButton :disabled="comparing" @click="runCompare">Comparar tarifários</NcButton>

			<template v-if="comparison">
				<p v-if="comparison.error" class="he-empty">{{ comparison.error }}</p>
				<template v-else>
					<p class="he-hint">
						Sobre o consumo real de {{ formatDate(comparison.from) }} a {{ formatDate(comparison.to) }}
						({{ comparison.comparison.days }} dias).
					</p>
					<table class="he-table">
						<thead>
							<tr>
								<th>Tarifário</th>
								<th>Opção</th>
								<th class="he-num">Energia</th>
								<th class="he-num">Termo fixo</th>
								<th class="he-num">Total</th>
								<th class="he-num">Diferença</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in comparison.comparison.results" :key="row.name">
								<td>{{ row.name }}</td>
								<td>{{ row.option }}</td>
								<td class="he-num">{{ formatMoney(row.cost.energy) }}</td>
								<td class="he-num">{{ formatMoney(row.cost.standing) }}</td>
								<td class="he-num"><strong>{{ formatMoney(row.cost.total) }}</strong></td>
								<td class="he-num">
									<template v-if="!row.comparable">
										sem preço para {{ row.cost.unpriced.join(', ') }}
									</template>
									<template v-else-if="row.savingVsCheapest === 0">mais barato</template>
									<template v-else>+{{ formatMoney(row.savingVsCheapest) }}</template>
								</td>
							</tr>
						</tbody>
					</table>
				</template>
			</template>
		</template>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'
import { formatDate, formatNumber, formatMoney } from '../utils/format.js'

const props = defineProps({ id: { type: [String, Number], required: true } })

const loading = ref(true)
const saving = ref(false)
const comparing = ref(false)
const detail = ref(null)
const comparison = ref(null)

const form = reactive({ readAt: new Date(), values: {}, isEstimate: false })

const reversedPeriods = computed(() => [...(detail.value?.series.periods ?? [])].reverse())
const reversedReadings = computed(() => [...(detail.value?.readings ?? [])].reverse())
const hasEstimates = computed(() => reversedPeriods.value.some((p) => p.isEstimate))

const toIsoDate = (date) => {
	const d = date instanceof Date ? date : new Date(date)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const load = async () => {
	loading.value = true
	try {
		detail.value = await api.getMeter(props.id)
	} catch (error) {
		showError('Não foi possível carregar o contador.')
	} finally {
		loading.value = false
	}
}

const addReading = async () => {
	const values = {}
	for (const [code, value] of Object.entries(form.values)) {
		if (value !== '' && value !== null && value !== undefined) {
			values[code] = Number(value)
		}
	}
	if (!Object.keys(values).length) {
		showError('Preenche pelo menos um registo.')
		return
	}

	saving.value = true
	try {
		await api.createReading(props.id, {
			readAt: toIsoDate(form.readAt),
			values,
			isEstimate: form.isEstimate,
		})
		form.values = {}
		showSuccess('Leitura lançada.')
		comparison.value = null
		await load()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível lançar a leitura.')
	} finally {
		saving.value = false
	}
}

const removeReading = async (reading) => {
	if (!window.confirm(`Apagar a leitura de ${formatDate(reading.readAt)}?`)) {
		return
	}
	try {
		await api.deleteReading(reading.id)
		comparison.value = null
		await load()
	} catch (error) {
		showError('Não foi possível apagar a leitura.')
	}
}

const runCompare = async () => {
	comparing.value = true
	try {
		comparison.value = await api.compare(props.id)
	} catch (error) {
		showError('Não foi possível comparar os tarifários.')
	} finally {
		comparing.value = false
	}
}

onMounted(load)
</script>
