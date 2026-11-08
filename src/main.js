/**
 * Duyuru Panosu — Vue uygulamasının giriş noktası.
 * templates/main.php içindeki #noticeboard düğümüne bağlanır.
 */

import { createApp } from 'vue'
import { t, n } from '@nextcloud/l10n'

import App from './App.vue'

const app = createApp(App)

// Nextcloud'un çeviri fonksiyonlarını şablonlarda $t / $n olarak kullanılabilir yap.
app.config.globalProperties.$t = t
app.config.globalProperties.$n = n

app.mount('#noticeboard')
