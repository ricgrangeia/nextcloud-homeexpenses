export const today = () => new Date().toISOString().slice(0, 10)

export const formatDate = (value) => {
	if (!value) {
		return '—'
	}
	const [y, m, d] = value.split('-')
	return `${d}/${m}/${y}`
}

export const formatNumber = (value, digits = 2) => {
	if (value === null || value === undefined) {
		return '—'
	}
	return Number(value).toLocaleString('pt-PT', {
		minimumFractionDigits: 0,
		maximumFractionDigits: digits,
	})
}

export const formatMoney = (value, currency = 'EUR') => {
	if (value === null || value === undefined) {
		return '—'
	}
	return Number(value).toLocaleString('pt-PT', { style: 'currency', currency })
}

export const REGISTER_LABELS = {
	V: 'Vazio',
	C: 'Cheias',
	P: 'Ponta',
	FV: 'Fora de Vazio',
	TOTAL: 'Total',
}

export const APPLIANCES = [
	{ value: 'esquentador', label: 'Esquentador' },
	{ value: 'fogao', label: 'Fogão' },
	{ value: 'ambos', label: 'Esquentador e fogão' },
	{ value: 'outro', label: 'Outro' },
]
