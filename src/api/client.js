import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Cliente da API OCS da app.
 *
 * A interface usa exatamente os mesmos endpoints que um agente externo usaria
 * -- nao ha um conjunto de rotas "para o SPA" e outro "para a API". O que se
 * consegue fazer aqui e o que se consegue fazer por fora, sempre.
 */
const base = generateOcsUrl('apps/homeexpenses/api/v1')

const ocs = axios.create({
	headers: { 'OCS-APIRequest': 'true' },
})

const unwrap = (response) => response.data?.ocs?.data

const request = async (method, path, { params, data } = {}) => {
	const response = await ocs.request({
		method,
		url: `${base}${path}`,
		params,
		data,
	})
	return unwrap(response)
}

export default {
	overview: () => request('get', '/overview'),

	// Contadores
	listMeters: (params) => request('get', '/meters', { params }),
	getMeter: (id) => request('get', `/meters/${id}`),
	createMeter: (data) => request('post', '/meters', { data }),
	updateMeter: (id, data) => request('put', `/meters/${id}`, { data }),
	deleteMeter: (id) => request('delete', `/meters/${id}`),

	// Leituras
	listReadings: (meterId) => request('get', `/meters/${meterId}/readings`),
	createReading: (meterId, data) => request('post', `/meters/${meterId}/readings`, { data }),
	updateReading: (id, data) => request('put', `/readings/${id}`, { data }),
	deleteReading: (id) => request('delete', `/readings/${id}`),
	compare: (meterId, params) => request('get', `/meters/${meterId}/compare`, { params }),

	// Gas
	gas: () => request('get', '/gas'),
	createBottleType: (data) => request('post', '/gas/types', { data }),
	updateBottleType: (id, data) => request('put', `/gas/types/${id}`, { data }),
	deleteBottleType: (id) => request('delete', `/gas/types/${id}`),
	createCycle: (data) => request('post', '/gas/cycles', { data }),
	updateCycle: (id, data) => request('put', `/gas/cycles/${id}`, { data }),
	deleteCycle: (id) => request('delete', `/gas/cycles/${id}`),
	addWeighing: (cycleId, data) => request('post', `/gas/cycles/${cycleId}/weighings`, { data }),
	deleteWeighing: (id) => request('delete', `/gas/weighings/${id}`),

	// Tarifarios
	listTariffs: (params) => request('get', '/tariffs', { params }),
	createTariff: (data) => request('post', '/tariffs', { data }),
	updateTariff: (id, data) => request('put', `/tariffs/${id}`, { data }),
	deleteTariff: (id) => request('delete', `/tariffs/${id}`),
}
