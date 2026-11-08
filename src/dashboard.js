/**
 * Pano bileşenlerinin giriş noktası (K8).
 *
 * Nextcloud panosu, PHP'deki widget kimliğiyle burada kaydedilen geri çağrıyı
 * eşleştirir. ⚠️ Kimlikler PHP tarafındaki `WIDGET_ID` sabitleriyle BİREBİR aynı
 * olmalı; ayrışırsa widget panoda boş bir kutu olarak görünür.
 */

import { createApp } from 'vue'
import { t, n } from '@nextcloud/l10n'

import CardWidget from './widgets/CardWidget.vue'
import ListWidget from './widgets/ListWidget.vue'
import SpotlightWidget from './widgets/SpotlightWidget.vue'

const widgets = {
	'noticeboard-list': ListWidget,
	'noticeboard-card': CardWidget,
	'noticeboard-spotlight': SpotlightWidget,
}

document.addEventListener('DOMContentLoaded', () => {
	if (!window.OCA?.Dashboard?.register) {
		return
	}

	for (const [id, component] of Object.entries(widgets)) {
		window.OCA.Dashboard.register(id, el => {
			// Pano, içerik alanına masaüstünde sabit yükseklik (424 px) veriyor ama
			// bağlandığımız öğenin yüksekliği içeriğe göre. %100 verilmezse widget
			// alanı dolduramaz; vitrinin gezinme noktaları ortada asılı kalır.
			el.style.height = '100%'

			const app = createApp(component)
			app.config.globalProperties.$t = t
			app.config.globalProperties.$n = n
			app.mount(el)
		})
	}
})
