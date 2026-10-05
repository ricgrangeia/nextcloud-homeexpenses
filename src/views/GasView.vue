<template>
	<div class="he-page">
		<h2>Garrafas de gás</h2>
		<p class="he-hint">
			A tara é o peso da garrafa vazia, gravado na gola — e varia de garrafa para garrafa, mesmo
			entre garrafas iguais. Sem ela há duração, mas não há nível nem estimativa de quando acaba.
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<h3>Tipos de garrafa</h3>
			<form class="he-form" @submit.prevent="createType">
				<NcTextField v-model="typeForm.brand" label="Marca" placeholder="Galp" required />
				<NcSelect v-model="typeForm.gasType" :options="gasTypes" :reduce="(o) => o.value" label="label" input-label="Gás" :clearable="false" />
				<NcTextField v-model="typeForm.nominalKg" type="number" step="any" label="Peso nominal (kg)" placeholder="13" />
				<NcTextField v-model="typeForm.defaultTareKg" type="number" step="any" label="Tara típica (kg)" placeholder="15.2" />
				<NcButton type="primary" native-type="submit">Adicionar tipo</NcButton>
			</form>

			<table v-if="types.length" class="he-table">
				<thead>
					<tr><th>Marca</th><th>Gás</th><th class="he-num">Nominal</th><th class="he-num">Tara típica</th><th /></tr>
				</thead>
				<tbody>
					<tr v-for="type in types" :key="type.id">
						<td>{{ type.brand }}</td>
						<td>{{ type.gasType }}</td>
						<td class="he-num">{{ type.nominalKg }} kg</td>
						<td class="he-num">{{ type.defaultTareKg ? type.defaultTareKg + ' kg' : '—' }}</td>
						<td class="he-num"><NcButton type="tertiary" @click="removeType(type)">Apagar</NcButton></td>
					</tr>
				</tbody>
			</table>

			<h3>Garrafa nova</h3>
			<form v-if="types.length" class="he-form" @submit.prevent="createCycle">
				<NcSelect v-model="cycleForm.bottleTypeId" :options="typeOptions" :reduce="(o) => o.value" label="label" input-label="Tipo" :clearable="false" />
				<NcDateTimePickerNative v-model="cycleForm.installedAt" label="Entrou em" type="date" />
				<NcSelect v-model="cycleForm.appliance" :options="appliances" :reduce="(o) => o.value" label="label" input-label="Alimenta" :clearable="false" />
				<NcTextField v-model="cycleForm.tareKg" type="number" step="any" label="Tara (kg)" placeholder="gravada na gola" />
				<NcTextField v-model="cycleForm.pricePaid" type="number" step="any" label="Preço pago" placeholder="34.00" />
				<NcButton type="primary" native-type="submit">Registar</NcButton>
			</form>
			<p v-else class="he-empty">Cria primeiro um tipo de garrafa.</p>

			<h3>Histórico</h3>
			<p v-if="!cycles.length" class="he-empty">Ainda não há garrafas registadas.</p>

			<div v-for="cycle in cycles" :key="cycle.id" class="he-card">
				<div class="he-card-head">
					<strong>
						{{ cycle.bottleType.brand }} {{ cycle.bottleType.gasType }} {{ cycle.bottleType.nominalKg }} kg
						<span v-if="!cycle.removedAt"> — em uso</span>
					</strong>
					<span class="he-hint" style="margin:0">
						{{ formatDate(cycle.installedAt) }}
						<template v-if="cycle.removedAt"> → {{ formatDate(cycle.removedAt) }}</template>
						· {{ cycle.stats.durationDays }} dias · {{ applianceLabel(cycle.appliance) }}
					</span>
				</div>

				<div class="he-grid">
					<div class="he-stat">
						<span class="he-stat-label">Nível</span>
						<span class="he-stat-value">{{ cycle.stats.level ? cycle.stats.level.levelPct + '%' : '—' }}</span>
						<div v-if="cycle.stats.level" class="he-bar">
							<span :style="{ width: cycle.stats.level.levelPct + '%' }" />
						</div>
					</div>
					<div class="he-stat">
						<span class="he-stat-label">Consumo</span>
						<span class="he-stat-value">{{ formatNumber(cycle.stats.kgPerDay, 3) }} kg/dia</span>
					</div>
					<div class="he-stat">
						<span class="he-stat-label">Custo</span>
						<span class="he-stat-value">{{ formatMoney(cycle.pricePaid) }}</span>
						<span v-if="cycle.stats.costPerDay" class="he-stat-label">
							{{ formatMoney(cycle.stats.costPerDay) }} / dia
						</span>
					</div>
				</div>

				<form class="he-form" style="margin-top:16px" @submit.prevent="addWeighing(cycle)">
					<NcDateTimePickerNative v-model="weighForm[cycle.id].weighedAt" label="Pesado em" type="date" />
					<NcTextField v-model="weighForm[cycle.id].grossKg" type="number" step="any" label="Peso na balança (kg)" placeholder="total, com a garrafa" />
					<NcButton native-type="submit">Registar pesagem</NcButton>
					<NcButton v-if="!cycle.removedAt" type="warning" @click="closeCycle(cycle)">Acabou</NcButton>
					<NcButton type="tertiary" @click="removeCycle(cycle)">Apagar</NcButton>
				</form>

				<table v-if="cycle.weighings.length" class="he-table">
					<thead><tr><th>Data</th><th class="he-num">Bruto</th><th class="he-num">Gás</th><th /></tr></thead>
					<tbody>
						<tr v-for="w in cycle.weighings" :key="w.id">
							<td>{{ formatDate(w.weighedAt) }}</td>
							<td class="he-num">{{ formatNumber(w.grossKg) }} kg</td>
							<td class="he-num">
								{{ cycle.stats.tareKg ? formatNumber(w.grossKg - cycle.stats.tareKg) + ' kg' : '—' }}
							</td>
							<td class="he-num"><NcButton type="tertiary" @click="removeWeighing(w)">Apagar</NcButton></td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'
import { formatDate, formatNumber, formatMoney, APPLIANCES } from '../utils/format.js'

const loading = ref(true)
const types = ref([])
const cycles = ref([])
const weighForm = reactive({})

const appliances = APPLIANCES
const gasTypes = [
	{ value: 'butano', label: 'Butano' },
	{ value: 'propano', label: 'Propano' },
]

const typeForm = reactive({ brand: '', gasType: 'butano', nominalKg: '13', defaultTareKg: '' })
const cycleForm = reactive({ bottleTypeId: null, installedAt: new Date(), appliance: 'ambos', tareKg: '', pricePaid: '' })

const typeOptions = computed(() => types.value.map((t) => ({
	value: t.id,
	label: `${t.brand} ${t.gasType} ${t.nominalKg} kg`,
})))

const applianceLabel = (value) => APPLIANCES.find((a) => a.value === value)?.label ?? value

const toIsoDate = (date) => {
	const d = date instanceof Date ? date : new Date(date)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const load = async () => {
	loading.value = true
	try {
		const data = await api.gas()
		types.value = data.bottleTypes ?? []
		cycles.value = data.cycles ?? []
		for (const cycle of cycles.value) {
			if (!weighForm[cycle.id]) {
				weighForm[cycle.id] = { weighedAt: new Date(), grossKg: '' }
			}
		}
		if (!cycleForm.bottleTypeId && types.value.length) {
			cycleForm.bottleTypeId = types.value[0].id
		}
	} catch (error) {
		showError('Não foi possível carregar as garrafas.')
	} finally {
		loading.value = false
	}
}

const createType = async () => {
	if (!typeForm.brand.trim()) {
		return
	}
	try {
		await api.createBottleType({
			brand: typeForm.brand.trim(),
			gasType: typeForm.gasType,
			nominalKg: Number(typeForm.nominalKg) || 13,
			defaultTareKg: typeForm.defaultTareKg === '' ? null : Number(typeForm.defaultTareKg),
		})
		typeForm.brand = ''
		typeForm.defaultTareKg = ''
		await load()
	} catch (error) {
		showError('Não foi possível criar o tipo.')
	}
}

const removeType = async (type) => {
	try {
		await api.deleteBottleType(type.id)
		await load()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível apagar o tipo.')
	}
}

const createCycle = async () => {
	try {
		await api.createCycle({
			bottleTypeId: cycleForm.bottleTypeId,
			installedAt: toIsoDate(cycleForm.installedAt),
			appliance: cycleForm.appliance,
			tareKg: cycleForm.tareKg === '' ? null : Number(cycleForm.tareKg),
			pricePaid: cycleForm.pricePaid === '' ? null : Number(cycleForm.pricePaid),
		})
		cycleForm.tareKg = ''
		cycleForm.pricePaid = ''
		showSuccess('Garrafa registada.')
		await load()
	} catch (error) {
		showError('Não foi possível registar a garrafa.')
	}
}

const closeCycle = async (cycle) => {
	const when = window.prompt('Em que dia acabou? (AAAA-MM-DD)', toIsoDate(new Date()))
	if (!when) {
		return
	}
	try {
		await api.updateCycle(cycle.id, { removedAt: when })
		await load()
	} catch (error) {
		showError('Não foi possível fechar o ciclo.')
	}
}

const removeCycle = async (cycle) => {
	if (!window.confirm('Apagar esta garrafa e as suas pesagens?')) {
		return
	}
	try {
		await api.deleteCycle(cycle.id)
		await load()
	} catch (error) {
		showError('Não foi possível apagar.')
	}
}

const addWeighing = async (cycle) => {
	const entry = weighForm[cycle.id]
	if (!entry?.grossKg) {
		return
	}
	try {
		await api.addWeighing(cycle.id, {
			weighedAt: toIsoDate(entry.weighedAt),
			grossKg: Number(entry.grossKg),
		})
		entry.grossKg = ''
		await load()
	} catch (error) {
		showError('Não foi possível registar a pesagem.')
	}
}

const removeWeighing = async (weighing) => {
	try {
		await api.deleteWeighing(weighing.id)
		await load()
	} catch (error) {
		showError('Não foi possível apagar a pesagem.')
	}
}

onMounted(load)
</script>
