<template>
	<div class="he-page">
		<h2>Contadores</h2>
		<p class="he-hint">
			Um contador de eletricidade tem um, dois ou três registos no mostrador. Se o teu mostra
			Vazio, Cheias e Ponta, escolhe três registos mesmo que o contrato fature bi-horário —
			é isso que depois permite comparar os dois tarifários com números reais.
		</p>

		<form class="he-form" @submit.prevent="create">
			<NcTextField v-model="form.name" label="Nome" placeholder="Contador da luz" required />
			<NcSelect v-model="form.kind" :options="kinds" :reduce="(o) => o.value" label="label" input-label="Tipo" :clearable="false" />
			<NcSelect v-model="form.registerPreset" :options="presets" :reduce="(o) => o.value" label="label" input-label="Registos" :clearable="false" />
			<NcSelect v-model="form.tariffOption" :options="options" :reduce="(o) => o.value" label="label" input-label="O contrato fatura" :clearable="false" />
			<NcTextField v-model="form.digits" type="number" label="Dígitos do mostrador" placeholder="0 = ignorar" />
			<NcButton type="primary" native-type="submit" :disabled="saving">Criar</NcButton>
		</form>

		<NcLoadingIcon v-if="loading" :size="32" />

		<p v-else-if="!meters.length" class="he-empty">Ainda não há contadores.</p>

		<table v-else class="he-table">
			<thead>
				<tr>
					<th>Nome</th>
					<th>Tipo</th>
					<th>Unidade</th>
					<th>Contrato</th>
					<th class="he-num">Dígitos</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="meter in meters" :key="meter.id">
					<td><RouterLink :to="{ name: 'meter', params: { id: meter.id } }">{{ meter.name }}</RouterLink></td>
					<td>{{ meter.kind === 'water' ? 'Água' : 'Eletricidade' }}</td>
					<td>{{ meter.unit }}</td>
					<td>{{ meter.tariffOption }}</td>
					<td class="he-num">{{ meter.digits || '—' }}</td>
					<td class="he-num">
						<NcButton type="tertiary" @click="remove(meter)">Apagar</NcButton>
					</td>
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
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'

const loading = ref(true)
const saving = ref(false)
const meters = ref([])

const kinds = [
	{ value: 'electricity', label: 'Eletricidade' },
	{ value: 'water', label: 'Água' },
]

const presets = [
	{ value: 'VCP', label: 'Vazio, Cheias e Ponta' },
	{ value: 'VFV', label: 'Vazio e Fora de Vazio' },
	{ value: 'TOTAL', label: 'Um só registo' },
]

const options = [
	{ value: 'simples', label: 'Simples' },
	{ value: 'bi', label: 'Bi-horário' },
	{ value: 'tri', label: 'Tri-horário' },
]

const form = reactive({
	name: '',
	kind: 'electricity',
	registerPreset: 'VCP',
	tariffOption: 'bi',
	digits: '',
})

const codesFor = (preset) => ({
	VCP: ['V', 'C', 'P'],
	VFV: ['V', 'FV'],
	TOTAL: ['TOTAL'],
}[preset] ?? ['TOTAL'])

const load = async () => {
	loading.value = true
	try {
		meters.value = await api.listMeters()
	} catch (error) {
		showError('Não foi possível carregar os contadores.')
	} finally {
		loading.value = false
	}
}

const create = async () => {
	if (!form.name.trim()) {
		return
	}
	saving.value = true
	try {
		await api.createMeter({
			name: form.name.trim(),
			kind: form.kind,
			tariffOption: form.tariffOption,
			registerCodes: codesFor(form.kind === 'water' ? 'TOTAL' : form.registerPreset),
			digits: Number(form.digits) || 0,
		})
		form.name = ''
		form.digits = ''
		showSuccess('Contador criado.')
		await load()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível criar o contador.')
	} finally {
		saving.value = false
	}
}

const remove = async (meter) => {
	if (!window.confirm(`Apagar "${meter.name}" e todas as suas leituras?`)) {
		return
	}
	try {
		await api.deleteMeter(meter.id)
		await load()
	} catch (error) {
		showError('Não foi possível apagar o contador.')
	}
}

onMounted(load)
</script>
