/**
 * Üç widget'ın ortak veri mantığı (K8).
 *
 * Tasarımlar farklı ama kaynak aynı: uygulamanın kendi duyuru API'si. Böylece
 * panodaki widget, uygulamadaki listeyle AYNI hedefleme ve zamanlama kurallarına
 * uyar — veri mantığı kopyalansaydı bir gün ayrışır ve widget, kullanıcının
 * görmemesi gereken bir duyuruyu gösterebilirdi.
 *
 * TAZELEME: 5 dakikada bir, sessizce. Eskiden PHP'deki `IReloadableWidget`
 * yapıyordu; o arayüz `IAPIWidgetV2`'yi miras aldığı için kaldırıldı (Hata #33)
 * ve iş buraya taşındı. Sekme görünmezken durur; geri gelince hemen tazeler.
 *
 * ⚠️ Ad çakışması: bu karışım (mixin) bileşenle birleşir. Vitrin kendi
 * `timer`/`startTimer`/`stopTimer`'ını kullanıyor; buradaki adlar `refresh*`.
 */

import { fetchAnnouncements } from '../api.js'

/** Duyurular saniyelik veri değil; daha sık tazelemek sunucuyu boşuna yorar. */
export const REFRESH_MS = 5 * 60 * 1000

export const widgetData = (limit = 7) => ({
	data() {
		return {
			announcements: [],
			loading: true,
			failed: false,
			refreshTimer: null,
		}
	},

	async mounted() {
		document.addEventListener('visibilitychange', this.onRefreshVisibility)
		await this.loadAnnouncements()
		this.startRefresh()
	},

	beforeUnmount() {
		document.removeEventListener('visibilitychange', this.onRefreshVisibility)
		this.stopRefresh()
	},

	methods: {
		/**
		 * @param {object} options
		 * @param {boolean} options.silent tazelemede yükleniyor simgesi gösterme;
		 *   kullanıcı okurken içerik yerini dönen simgeye bırakıp geri gelmesin
		 */
		async loadAnnouncements({ silent = false } = {}) {
			if (!silent) {
				this.loading = true
			}
			this.failed = false

			try {
				this.announcements = await fetchAnnouncements({ limit })
			} catch (error) {
				// Sessiz tazeleme başarısızsa eldeki liste korunur; geçici bir ağ
				// hatası yüzünden dolu widget boş hata kutusuna dönmesin.
				if (!silent || this.announcements.length === 0) {
					this.failed = true
					this.announcements = []
				}
			} finally {
				this.loading = false
			}
		},

		startRefresh() {
			this.stopRefresh()
			if (!document.hidden) {
				this.refreshTimer = setInterval(() => this.loadAnnouncements({ silent: true }), REFRESH_MS)
			}
		},

		stopRefresh() {
			if (this.refreshTimer !== null) {
				clearInterval(this.refreshTimer)
				this.refreshTimer = null
			}
		},

		onRefreshVisibility() {
			if (document.hidden) {
				this.stopRefresh()
			} else {
				this.loadAnnouncements({ silent: true })
				this.startRefresh()
			}
		},
	},
})
