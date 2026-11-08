/**
 * Yönetici ayarları ekranının giriş noktası.
 * templates/admin.php içindeki #noticeboard-admin düğümüne bağlanır.
 */

import { createApp } from 'vue'
import { t, n } from '@nextcloud/l10n'

import AdminSettings from './AdminSettings.vue'

const app = createApp(AdminSettings)

app.config.globalProperties.$t = t
app.config.globalProperties.$n = n

app.mount('#noticeboard-admin')
