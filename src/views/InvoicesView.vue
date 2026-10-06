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
			<input ref="fileInput" type="file" accept="application/pdf" multiple @change="pick">
			<NcSelect v-if="meters.length" v-model="meterId" :options="meterOptions"
				:reduce="(o) => o.value" label="label" input-label="Contador" />
			<NcButton type="primary" native-type="submit" :disabled="!files.length || busy">
				{{ files.length > 1 ? `Importar ${files.length} faturas` : 'Importar' }}
			</NcButton>
		</form>

		<p class="he-hint">
			Podes enviar várias de uma vez. Ficam em fila e são lidas em segundo plano — podes
			fechar a página. O Nextcloud notifica-te quando cada uma estiver pronta.
		</p>

		<template v-if="queue.length">
			<h3>Fila de importação</h3>
			<div v-for="job in queue" :key="job.id" class="he-card">
				<div class="he-card-head">
					<strong>{{ job.filename }}</strong>
					<span class="he-hint" style="margin:0">{{ statusLabel(job) }}</span>
				</div>

				<div v-if="job.status === 'failed'" class="he-warn">{{ job.error }}</div>

				<template v-if="job.result">
					<p class="he-hint" style="margin:0 0 8px">
						{{ job.result.created.length }} documento(s) importado(s)<template
							v-if="job.result.existing.length">, {{ job.result.existing.length }}
							já existia(m) — reconhecidos pelo ATCUD, não duplicados</template>.
					</p>
					<div v-for="(warning, i) in job.result.warnings" :key="i" class="he-warn">
						{{ warning }}
					</div>
				</template>
			</div>
		</template>

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
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
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
const files = ref([])
const fileInput = ref(null)
const meterId = ref(null)
const queue = ref([])
let poller = null

const meterOptions = computed(() => meters.value.map((m) => ({ value: m.id, label: m.name })))

const STATUS = {
	pending: 'na fila',
	running: 'a ler…',
	done: 'pronta',
	failed: 'falhou',
}

const statusLabel = (job) => {
	if (job.status === 'pending' && job.attempts > 0) {
		return `a repetir (tentativa ${job.attempts + 1})`
	}
	return STATUS[job.status] ?? job.status
}

const pendingWork = computed(() =>
	queue.value.some((job) => job.status === 'pending' || job.status === 'running'))

const totalKwh = (invoice) =>
	invoice.consumption.reduce((sum, row) => sum + (row.quantity ?? 0), 0)

const pick = (event) => {
	files.value = Array.from(event.target.files ?? [])
}

/**
 * Enquanto houver trabalho na fila, pergunta de vez em quando. O cron do
 * Nextcloud corre tipicamente de 5 em 5 minutos, por isso não vale a pena
 * perguntar depressa — isto é só para a página se atualizar sozinha a quem
 * ficar a olhar.
 */
const poll = () => {
	clearInterval(poller)
	if (!pendingWork.value) {
		return
	}
	poller = setInterval(async () => {
		queue.value = await api.listImports()
		if (!pendingWork.value) {
			clearInterval(poller)
			await load()
		}
	}, 10000)
}

onBeforeUnmount(() => clearInterval(poller))

const load = async () => {
	loading.value = true
	try {
		const [list, meterList, jobs] = await Promise.all([
			api.listInvoices(), api.listMeters(), api.listImports(),
		])
		invoices.value = list
		meters.value = meterList
		queue.value = jobs
		poll()
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
	if (!files.value.length) {
		return
	}
	busy.value = true
	try {
		const result = await api.importInvoices(files.value, meterId.value)
		showSuccess(
			result.queued.length === 1
				? 'Fatura na fila. Serás notificado quando estiver lida.'
				: `${result.queued.length} faturas na fila. Serás notificado à medida que forem lidas.`
		)
		files.value = []
		if (fileInput.value) {
			fileInput.value.value = ''
		}
		queue.value = await api.listImports()
		poll()
	} catch (error) {
		showError(error?.response?.data?.ocs?.data?.message ?? 'Não foi possível pôr na fila.')
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
