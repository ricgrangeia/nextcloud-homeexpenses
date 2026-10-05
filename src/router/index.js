import { createRouter, createWebHashHistory } from 'vue-router'

import OverviewView from '../views/OverviewView.vue'
import MetersView from '../views/MetersView.vue'
import MeterView from '../views/MeterView.vue'
import GasView from '../views/GasView.vue'
import TariffsView from '../views/TariffsView.vue'

export default createRouter({
	history: createWebHashHistory(),
	routes: [
		{ path: '/', name: 'overview', component: OverviewView },
		{ path: '/meters', name: 'meters', component: MetersView },
		{ path: '/meters/:id', name: 'meter', component: MeterView, props: true },
		{ path: '/gas', name: 'gas', component: GasView },
		{ path: '/tariffs', name: 'tariffs', component: TariffsView },
	],
})
