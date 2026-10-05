<template>
	<div class="he-page">
		<h2>Tarifários</h2>
		<p class="he-hint">
			Os preços por escalão. Para comparar bi-horário com tri-horário sobre o teu consumo real,
			regista os dois: um com preços para <strong>V</strong> e <strong>FV</strong>, outro com
			<strong>V</strong>, <strong>C</strong> e <strong>P</strong>.
		</p>

		<form class="he-form" @submit.prevent="create">
			<NcTextField v-model="form.name" label="Nome" placeholder="EDP bi-horário" required />
			<NcSelect v-model="form.kind" :options="kinds" :reduce="(o) => o.value" label="label" input-label="Serviço" :clearable="false" />
			<NcSelect v-model="form.tariffOption" :options="options" :reduce="(o) => o.value" label="label" input-label="Opção" :clearable="false" />
			<NcDateTimePickerNative v-model="form.validFrom" label="Válido desde" type="date" />
			<NcTextField v-model="form.standingChargeDay" type="number" step="any" label="Termo fixo / dia" placeholder="0.25" />
		</form>

		<div class="he-form">
			<NcTextField v-for="code in codes" :key="code" v-model="form.prices[code]" type="number" step="any"
				:label="`Preço ${labels[code]}`" placeholder="0.1234" />
			<NcButton type="primary" @click="create">Criar tarifário</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="!tariffs.length" class="he-empty">Ainda não há tarifários.</p>

		<table v-else class="he-table">
			<thead>
				<tr><th>Nome</th><th>Serviço</th><th>Opção</th><th>Desde</th><th>Preços</th><th class="he-num">Termo fixo</th><th /></tr>
			</thead>
			<tbody>
				<tr v-for="tariff in tariffs" :key="tariff.id">
					<td>{{ tariff.name }}</td>
					<td>{{ tariff.kind === 'water' ? 'Água' : 'Eletricidade' }}</td>
					<td>{{ tariff.tariffOption }}</td>
					<td>{{ formatDate(tariff.validFrom) }}</td>
					<td>
						<span v-for="(price, code) in tariff.prices" :key="code" style="margin-right:10px">
							{{ code }}: {{ price }}
						</span>
					</td>
					<td class="he-num">{{ tariff.standingChargeDay ?? '—' }}</td>
					<td class="he-num"><NcButton type="tertiary" @click="remove(tariff)">Apagar</NcButton></td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'
import { formatDate, REGISTER_LABELS } from '../utils/format.js'

const loading = ref(true)
const tariffs = ref([])

const codes = ['V', 'C', 'P', 'FV', 'TOTAL']
const labels = REGISTER_LABELS

const kinds = [
	{ value: 'electricity', label: 'Eletricidade' },
	{ value: 'water', label: 'Água' },
]

const options = [
	{ value: 'simples', label: 'Simples' },
	{ value: 'bi', label: 'Bi-horário' },
	{ value: 'tri', label: 'Tri-horário' },
]

const form = reactive({
	name: '',
	kind: 'electricity',
	tariffOption: 'bi',
	validFrom: new Date(),
	standingChargeDay: '',
	prices: {},
})

const toIsoDate = (date) => {
	const d = date instanceof Date ? date : new Date(date)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const load = async () => {
	loading.value = true
	try {
		tariffs.value = await api.listTariffs()
	} catch (error) {
		showError('Não foi possível carregar os tarifários.')
	} finally {
		loading.value = false
	}
}

const create = async () => {
	if (!form.name.trim()) {
		return
	}
	const prices = {}
	for (const [code, value] of Object.entries(form.prices)) {
		if (value !== '' && value !== null && value !== undefined) {
			prices[code] = Number(value)
		}
	}
	try {
		await api.createTariff({
			name: form.name.trim(),
			kind: form.kind,
			tariffOption: form.tariffOption,
			validFrom: toIsoDate(form.validFrom),
			standingChargeDay: form.standingChargeDay === '' ? null : Number(form.standingChargeDay),
			prices,
		})
		form.name = ''
		form.prices = {}
		showSuccess('Tarifário criado.')
		await load()
	} catch (error) {
		showError('Não foi possível criar o tarifário.')
	}
}

const remove = async (tariff) => {
	try {
		await api.deleteTariff(tariff.id)
		await load()
	} catch (error) {
		showError('Não foi possível apagar.')
	}
}

onMounted(load)
</script>
