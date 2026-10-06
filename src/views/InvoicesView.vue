<template>
	<div class="he-page">
		<h2>Faturas</h2>
		<p class="he-hint">
			O PDF original do fornecedor, não uma fotografia nem uma impressão — é o QR code fiscal
			que é lido. Dele saem os preços por escalão, a potência, o acesso às redes e os impostos,
			que é o que falta para saber quanto vai custar o mês seguinte. As linhas extraídas são
			conferidas contra o total declarado no QR: se não fecharem, a fatura fica marcada e
			não entra em contas.
		</p>

		<div class="he-warn">
			<strong>Importar faturas não substitui ler o contador.</strong>
			A fatura só traz os escalões que o contrato fatura: num contrato bi-horário traz Vazio e
			Fora de Vazio, nunca Cheias e Ponta separados. Só as tuas leituras ao mostrador respondem
			a <em>“o tri-horário sairia mais barato?”</em>.
		</div>

		<form class="he-form" @submit.prevent="upload">
			<input ref="fileInput" type="file" accept="application/pdf" @change="pick">
			<NcSelect v-if="meters.length" v-model="meterId" :options="meterOptions"
				:reduce="(o) => o.value" label="label" input-label="Contador" />
			<NcButton type="primary" native-type="submit" :disabled="!file || busy">
				{{ busy ? 'A ler…' : 'Importar' }}
			</NcButton>
		</form>

		<p v-if="busy" class="he-hint">
			A leitura renderiza as páginas e procura os QR codes; num PDF de várias páginas demora
			até um minuto.
		</p>

		<div v-if="lastResult" class="he-card">
			<div class="he-card-head">
				<strong>
					{{ lastResult.created.length }} documento(s) importado(s)<template
						v-if="lastResult.existing.length">, {{ lastResult.existing.length }} já existia(m)</template>
				</strong>
			</div>
			<p v-if="lastResult.existing.length" class="he-hint" style="margin:0 0 8px">
				Um documento já importado é reconhecido pelo ATCUD e não é duplicado.
			</p>
			<div v-for="(warning, i) in lastResult.warnings" :key="i" class="he-warn">{{ warning }}</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="!invoices.length" class="he-empty">Ainda não há faturas importadas.</p>

		<div v-for="invoice in invoices" :key="invoice.id" class="he-card">
			<div class="he-card-head">
				<strong>{{ invoice.supplier || 'Fornecedor desconhecido' }}</strong>
				<span class="he-hint" style="margin:0">
					{{ invoice.docNumber }} · emitida {{ formatDate(invoice.issuedAt) }}
				</span>
			</div>

			<div v-if="!invoice.verified" class="he-warn">
				As contas deste documento não fecham com o que o QR fiscal declara — ou o total, ou o
				IVA, ou a soma das linhas. Fica guardado, mas não entra em cálculos nem em previsões.
			</div>

			<div class="he-grid">
				<div class="he-stat">
					<span class="he-stat-label">Período</span>
					<span class="he-stat-value" style="font-size:15px">
						{{ formatDate(invoice.periodFrom) }} → {{ formatDate(invoice.periodTo) }}
					</span>
				</div>
				<div class="he-stat">
					<span class="he-stat-label">Total</span>
					<span class="he-stat-value">{{ formatMoney(invoice.totalGross) }}</span>
					<span class="he-stat-label">{{ formatMoney(invoice.totalVat) }} de IVA</span>
				</div>
				<div class="he-stat">
					<span class="he-stat-label">Consumo</span>
					<span class="he-stat-value">{{ formatNumber(totalKwh(invoice)) }} kWh</span>
				</div>
				<div v-if="invoice.dueDate" class="he-stat">
					<span class="he-stat-label">Pagar até</span>
					<span class="he-stat-value" style="font-size:15px">{{ formatDate(invoice.dueDate) }}</span>
					<span v-if="invoice.paymentReference" class="he-stat-label">
						ref. {{ invoice.paymentReference }}
					</span>
				</div>
			</div>

			<table v-if="invoice.consumption.length" class="he-table" style="margin-top:12px">
				<thead>
					<tr><th>De</th><th>A</th><th>Escalão</th><th class="he-num">kWh</th><th class="he-num">€/kWh</th><th class="he-num">Valor</th></tr>
				</thead>
				<tbody>
					<tr v-for="(row, i) in invoice.consumption" :key="i">
						<td>{{ formatDate(row.periodFrom) }}</td>
						<td>{{ formatDate(row.periodTo) }}</td>
						<td>{{ REGISTER_LABELS[row.registerCode] || row.registerCode }}{{ row.isEstimate ? ' (estimado)' : '' }}</td>
						<td class="he-num">{{ formatNumber(row.quantity) }}</td>
						<td class="he-num">{{ row.unitPrice ?? '—' }}</td>
						<td class="he-num">{{ formatMoney(row.net) }}</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="he-hint" style="margin:8px 0 0">
				Este documento não tem linhas de consumo — é o caso da Contribuição Audiovisual,
				que a EDP fatura como documento separado.
			</p>

			<div class="he-form" style="margin-top:12px">
				<NcSelect v-if="meters.length" :model-value="invoice.meterId" :options="meterOptions"
					:reduce="(o) => o.value" label="label" input-label="Contador"
					@update:model-value="(v) => assign(invoice, v)" />
				<NcButton type="tertiary" @click="remove(invoice)">Apagar</NcButton>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError, showSuccess } from '@nextcloud/dialogs'

import api from '../api/client.js'
import { formatDate, formatNumber, formatMoney, REGISTER_LABELS } from '../utils/format.js'

const loading = ref(true)
const busy = ref(false)
const invoices = ref([])
const meters = ref([])
const file = ref(null)
const fileInput = ref(null)
const meterId = ref(null)
const lastResult = ref(null)

const meterOptions = computed(() => meters.value.map((m) => ({ value: m.id, label: m.name })))

const totalKwh = (invoice) =>
	invoice.consumption.reduce((sum, row) => sum + (row.quantity ?? 0), 0)

const pick = (event) => {
	file.value = event.target.files?.[0] ?? null
}

const load = async () => {
	loading.value = true
	try {
		const [list, meterList] = await Promise.all([api.listInvoices(), api.listMeters()])
		invoices.value = list
		meters.value = meterList
		if (meterId.value === null && meterList.length === 1) {
			meterId.value = meterList[0].id
		}
	} catch (error) {
		showError('Não foi possível carregar as faturas.')
	} finally {
		loading.value = false
	}
}

const upload = async () => {
	if (!file.value) {
		return
	}
	busy.value = true
	lastResult.value = null
	try {
		lastResult.value = await api.importInvoice(file.value, meterId.value)
		if (lastResult.value.created.length) {
			showSuccess(`${lastResult.value.created.length} documento(s) importado(s).`)
		}
		file.value = null
		if (fileInput.value) {
			fileInput.value.value = ''
		}
		await load()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível ler a fatura.')
	} finally {
		busy.value = false
	}
}

const assign = async (invoice, value) => {
	try {
		await api.updateInvoice(invoice.id, { meterId: value })
		await load()
	} catch (error) {
		showError('Não foi possível associar ao contador.')
	}
}

const remove = async (invoice) => {
	if (!window.confirm(`Apagar a fatura ${invoice.docNumber}?`)) {
		return
	}
	try {
		await api.deleteInvoice(invoice.id)
		await load()
	} catch (error) {
		showError('Não foi possível apagar.')
	}
}

onMounted(load)
</script>
