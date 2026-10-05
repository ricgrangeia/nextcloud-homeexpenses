<template>
	<NcContent app-name="homeexpenses">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem :to="{ name: 'overview' }" name="Visão geral">
					<template #icon><HomeIcon :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :to="{ name: 'meters' }" name="Contadores">
					<template #icon><GaugeIcon :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :to="{ name: 'gas' }" name="Garrafas de gás">
					<template #icon><FireIcon :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :to="{ name: 'tariffs' }" name="Tarifários">
					<template #icon><CurrencyEurIcon :size="20" /></template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<RouterView />
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'

import HomeIcon from 'vue-material-design-icons/Home.vue'
import GaugeIcon from 'vue-material-design-icons/Gauge.vue'
import FireIcon from 'vue-material-design-icons/Fire.vue'
import CurrencyEurIcon from 'vue-material-design-icons/CurrencyEur.vue'
</script>

<style>
.he-page {
	padding: 24px;
	max-width: 1100px;
}

.he-page h2 {
	margin: 0 0 4px;
	font-size: 22px;
}

.he-page h3 {
	margin: 28px 0 8px;
	font-size: 17px;
}

.he-hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 20px;
	max-width: 70ch;
	line-height: 1.5;
}

.he-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	margin-bottom: 12px;
	background: var(--color-main-background);
}

.he-card-head {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 12px;
	margin-bottom: 8px;
}

.he-card-head strong {
	font-size: 16px;
}

.he-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 12px;
}

.he-stat .he-stat-label {
	display: block;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: .03em;
}

.he-stat .he-stat-value {
	font-size: 20px;
	font-weight: 600;
}

.he-table {
	width: 100%;
	border-collapse: collapse;
}

.he-table th,
.he-table td {
	text-align: left;
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
	font-variant-numeric: tabular-nums;
}

.he-table th {
	font-size: 12px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.he-table td.he-num,
.he-table th.he-num {
	text-align: right;
}

.he-form {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
	margin-bottom: 16px;
}

.he-form > * {
	min-width: 150px;
}

.he-bar {
	height: 8px;
	border-radius: 4px;
	background: var(--color-background-dark);
	overflow: hidden;
	margin-top: 6px;
}

.he-bar span {
	display: block;
	height: 100%;
	background: var(--color-primary-element);
}

.he-warn {
	border-left: 3px solid var(--color-warning);
	padding: 8px 12px;
	margin: 12px 0;
	background: var(--color-background-hover);
	border-radius: 0 var(--border-radius) var(--border-radius) 0;
}

.he-empty {
	color: var(--color-text-maxcontrast);
	padding: 24px 0;
}
</style>
