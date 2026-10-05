import { createAppConfig } from '@nextcloud/vite-config'
import { resolve, join } from 'path'

export default createAppConfig({
	main: resolve(join('src', 'main.js')),
}, {
	createEmptyCSSEntryPoints: true,
})
