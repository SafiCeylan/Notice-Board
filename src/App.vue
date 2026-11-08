<!--
  Duyuru Panosu — ana ekran.

  Düzen: solda duyuru listesi, sağda detay. NcAppContent'in vertical-split düzeni
  dar ekranda tek sütuna iner, bu yüzden ayrı bir mobil düzen yazılmadı.

  Yayıncılar ek olarak: "Yeni duyuru" düğmesi, taslak/zamanlanmışları gösteren
  yönetim listesi ve detayda düzenle/sil eylemleri görür. Düğmeyi gizlemek bir
  kolaylıktır, güvenlik değil — gerçek kontrol her zaman sunucuda.
-->
<template>
	<NcContent app-name="noticeboard">
		<NcAppContent layout="vertical-split"
			:show-details="showDetails"
			@update:showDetails="showDetails = $event">
			<template #list>
				<div class="nb-list">
					<div class="nb-list__header">
						<h2 class="nb-list__title">{{ t('noticeboard', 'Duyurular') }}</h2>
						<NcButton v-if="canPublish"
							variant="primary"
							:aria-label="t('noticeboard', 'Yeni duyuru')"
							@click="openComposer(null)">
							<template #icon><Plus :size="20" /></template>
							{{ t('noticeboard', 'Yeni') }}
						</NcButton>
					</div>

					<div v-if="canPublish" class="nb-list__tabs">
						<button type="button"
							class="nb-tab"
							:class="{ 'nb-tab--active': !manageMode }"
							@click="setMode(false)">
							{{ t('noticeboard', 'Bana gelenler') }}
						</button>
						<button type="button"
							class="nb-tab"
							:class="{ 'nb-tab--active': manageMode }"
							@click="setMode(true)">
							{{ t('noticeboard', 'Tümü (yönetim)') }}
						</button>
					</div>

					<NcLoadingIcon v-if="loading" class="nb-list__loading" :size="32" />

					<NcEmptyContent v-else-if="loadError"
						:name="t('noticeboard', 'Duyurular yüklenemedi')"
						:description="loadError">
						<template #icon><AlertCircle :size="48" /></template>
						<template #action>
							<NcButton @click="loadList">{{ t('noticeboard', 'Yeniden dene') }}</NcButton>
						</template>
					</NcEmptyContent>

					<NcEmptyContent v-else-if="!announcements.length"
						:name="emptyTitle"
						:description="emptyDescription">
						<template #icon><Bullhorn :size="48" /></template>
						<template v-if="canPublish" #action>
							<NcButton variant="primary" @click="openComposer(null)">
								{{ t('noticeboard', 'İlk duyuruyu oluştur') }}
							</NcButton>
						</template>
					</NcEmptyContent>

					<ul v-else class="nb-list__items">
						<AnnouncementListItem v-for="item in announcements"
							:key="item.id"
							:announcement="item"
							:active="item.id === selectedId"
							@select="select" />
					</ul>

					<!--
					  Sayfalama olmadan 50. duyurudan sonrası ERİŞİLEMEZ kalıyordu:
					  sunucu sayfa boyutunu sınırlıyor ama arayüz devamını hiç
					  istemiyordu. Kurumsal bir panoda bu, eski duyuruların
					  kaybolması demek.
					-->
					<div v-if="hasMore && !loading" class="nb-list__more">
						<NcButton :disabled="loadingMore" @click="loadMore">
							{{ loadingMore
								? t('noticeboard', 'Yükleniyor…')
								: t('noticeboard', 'Daha fazla göster') }}
						</NcButton>
					</div>
				</div>
			</template>

			<NcLoadingIcon v-if="detailLoading" class="nb-detail-loading" :size="44" />

			<NcEmptyContent v-else-if="detailError"
				:name="t('noticeboard', 'Duyuru açılamadı')"
				:description="detailError">
				<template #icon><AlertCircle :size="64" /></template>
			</NcEmptyContent>

			<div v-else-if="selected" class="nb-detail-wrapper">
				<div v-if="canEditSelected" class="nb-detail-actions">
					<NcButton @click="openComposer(selected)">
						<template #icon><Pencil :size="20" /></template>
						{{ t('noticeboard', 'Düzenle') }}
					</NcButton>
					<NcButton variant="error" @click="confirmDelete">
						<template #icon><Delete :size="20" /></template>
						{{ t('noticeboard', 'Sil') }}
					</NcButton>
				</div>

				<AnnouncementDetail :announcement="selected" />
			</div>

			<NcEmptyContent v-else
				:name="t('noticeboard', 'Duyuru Panosu')"
				:description="t('noticeboard', 'Okumak için soldan bir duyuru seçin.')">
				<template #icon><Bullhorn :size="64" /></template>
			</NcEmptyContent>
		</NcAppContent>

		<AnnouncementComposer v-if="composerOpen"
			:key="composerKey"
			:existing="composerTarget"
			:current-uid="currentUid"
			@close="composerOpen = false"
			@saved="onSaved" />
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { defineAsyncComponent } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'

import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Bullhorn from 'vue-material-design-icons/Bullhorn.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

import AnnouncementDetail from './components/AnnouncementDetail.vue'
import AnnouncementListItem from './components/AnnouncementListItem.vue'
import {
	deleteAnnouncement,
	fetchAllAnnouncements,
	fetchAnnouncement,
	fetchAnnouncements,
} from './api.js'

export default {
	name: 'App',

	components: {
		AlertCircle,
		// Oluşturma ekranı zengin metin editörünü ve dosya seçiciyi getiriyor —
		// birkaç yüz KB. Duyuruyu yalnızca OKUYAN kullanıcı bunu indirmesin diye
		// tembel yükleniyor; ilk açılışta yalnız yayıncı bedelini öder.
		AnnouncementComposer: defineAsyncComponent(() => import('./components/AnnouncementComposer.vue')),
		AnnouncementDetail,
		AnnouncementListItem,
		Bullhorn,
		Delete,
		NcAppContent,
		NcButton,
		NcContent,
		NcEmptyContent,
		NcLoadingIcon,
		Pencil,
		Plus,
	},

	data() {
		return {
			announcements: [],
			selected: null,
			selectedId: null,
			loading: true,
			detailLoading: false,
			loadError: '',
			detailError: '',
			showDetails: false,
			manageMode: false,
			loadingMore: false,
			// Sunucunun kabul ettiği en büyük sayfa boyutu 100; 25'lik sayfalar
			// ilk açılışı hızlı tutar ve "daha fazla" ile devam edilir.
			pageSize: 25,
			hasMore: false,
			composerOpen: false,
			composerTarget: null,
			// Her açılışta yeni bir oluşturma ekranı kurulsun; önceki düzenlemenin
			// form durumu bir sonrakine taşınmasın.
			composerKey: 0,
			// Detay isteklerinin sırası. Hızlı tıklamada geç dönen ESKİ yanıt yeni
			// seçimin üstüne yazılıyor, "Düzenle" yanlış duyuruyu açıyordu.
			detailRequest: 0,
			canPublish: loadState('noticeboard', 'canPublish', false),
			currentUid: loadState('noticeboard', 'currentUid', ''),
			isAdmin: loadState('noticeboard', 'isAdmin', false),
		}
	},

	computed: {
		/**
		 * Düzenle/sil düğmesi kime görünsün. Sunucudaki kuralın (PublisherService::canEdit)
		 * arayüz karşılığı: yönetici her duyuruyu, yayıncı kendi duyurusunu düzenler.
		 * Gizlemek güvenlik değil, sadeleştirmedir — gerçek kontrol API'de.
		 */
		canEditSelected() {
			if (!this.selected) {
				return false
			}

			return this.isAdmin || (this.canPublish && this.selected.authorUid === this.currentUid)
		},

		emptyTitle() {
			return this.manageMode
				? t('noticeboard', 'Henüz duyuru oluşturulmamış')
				: t('noticeboard', 'Henüz duyuru yok')
		},

		emptyDescription() {
			return this.manageMode
				? t('noticeboard', 'Oluşturduğunuz taslak ve zamanlanmış duyurular da burada listelenir.')
				: t('noticeboard', 'Size yönelik bir duyuru yayınlandığında burada görünecek.')
		},
	},

	async mounted() {
		await this.loadList()
		this.openFromHash()
		window.addEventListener('hashchange', this.openFromHash)
	},

	beforeUnmount() {
		window.removeEventListener('hashchange', this.openFromHash)
	},

	methods: {
		t,

		async setMode(manage) {
			if (this.manageMode === manage) {
				return
			}
			this.manageMode = manage
			await this.loadList()
		},

		/** Aktif sekmeye göre doğru uçtan bir sayfa çeker. */
		fetchPage(offset) {
			const params = { limit: this.pageSize, offset }

			return this.manageMode
				? fetchAllAnnouncements(params)
				: fetchAnnouncements(params)
		},

		async loadList() {
			this.loading = true
			this.loadError = ''
			try {
				const page = await this.fetchPage(0)
				this.announcements = page
				// Tam sayfa geldiyse devamı olabilir; eksikse liste bitmiştir.
				this.hasMore = page.length === this.pageSize
			} catch (error) {
				this.loadError = t('noticeboard', 'Sunucuya ulaşılamadı. Bağlantınızı kontrol edip yeniden deneyin.')
			} finally {
				this.loading = false
			}
		},

		async loadMore() {
			this.loadingMore = true
			try {
				const page = await this.fetchPage(this.announcements.length)
				this.announcements = this.announcements.concat(page)
				this.hasMore = page.length === this.pageSize
			} catch (error) {
				this.toast('error', t('noticeboard', 'Devamı yüklenemedi.'))
			} finally {
				this.loadingMore = false
			}
		},

		async select(id) {
			if (window.location.hash !== `#/announcement/${id}`) {
				window.location.hash = `#/announcement/${id}`
				return
			}

			await this.openAnnouncement(id)
		},

		async openAnnouncement(id) {
			const request = ++this.detailRequest
			this.selectedId = id
			this.showDetails = true
			this.detailLoading = true
			this.detailError = ''

			try {
				const announcement = await fetchAnnouncement(id)
				if (request !== this.detailRequest) {
					return // bu arada başka bir duyuru seçildi
				}
				this.selected = announcement
			} catch (error) {
				if (request !== this.detailRequest) {
					return
				}
				this.selected = null
				this.detailError = error.response?.status === 403
					? t('noticeboard', 'Bu duyuruya erişiminiz yok.')
					: t('noticeboard', 'Duyuru bulunamadı.')
			} finally {
				if (request === this.detailRequest) {
					this.detailLoading = false
				}
			}
		},

		openFromHash() {
			const match = window.location.hash.match(/^#\/announcement\/(\d+)$/)
			if (!match) {
				return
			}

			const id = Number(match[1])
			if (id !== this.selectedId || this.selected === null) {
				this.openAnnouncement(id)
			}
		},

		openComposer(existing) {
			this.composerTarget = existing
			this.composerKey++
			this.composerOpen = true
		},

		/** Bildirim kutusu da @nextcloud/dialogs'tan gelir; ana pakete girmesin. */
		async toast(type, message) {
			const dialogs = await import('@nextcloud/dialogs')
			type === 'error' ? dialogs.showError(message) : dialogs.showSuccess(message)
		},

		async onSaved(saved) {
			this.composerOpen = false
			this.toast('success', t('noticeboard', 'Duyuru kaydedildi.'))

			await this.loadList()
			await this.openAnnouncement(saved.id)
			// Adres çubuğu da kaydedilen duyuruyu göstersin; yoksa önceki
			// duyurunun adresi kalıyor, yenilemede yanlış duyuru açılıyordu.
			if (window.location.hash !== `#/announcement/${saved.id}`) {
				window.history.replaceState(null, '', `#/announcement/${saved.id}`)
			}
		},

		async confirmDelete() {
			// Nextcloud'un kendi onay diyalogu: tema uyumlu, klavyeyle gezilebilir
			// ve `window.confirm` gibi tarayıcı tarafından engellenmez.
			const { showConfirmation } = await import('@nextcloud/dialogs')

			const onaylandi = await showConfirmation({
				name: t('noticeboard', 'Duyuruyu sil'),
				text: t('noticeboard', '“{subject}” duyurusu silinsin mi? Bu işlem geri alınamaz.', { subject: this.selected.subject }),
				labelConfirm: t('noticeboard', 'Sil'),
				labelReject: t('noticeboard', 'Vazgeç'),
				severity: 'error',
			})

			if (!onaylandi) {
				return
			}

			try {
				await deleteAnnouncement(this.selected.id)
				this.toast('success', t('noticeboard', 'Duyuru silindi.'))

				this.selected = null
				this.selectedId = null
				this.showDetails = false
				window.location.hash = ''
				await this.loadList()
			} catch (error) {
				this.toast('error', t('noticeboard', 'Duyuru silinemedi.'))
			}
		},
	},
}
</script>

<style scoped>
.nb-list {
	padding: 8px;
}

.nb-list__header {
	display: flex;
	gap: 8px;
	align-items: center;
	justify-content: space-between;
	margin: 8px 12px 12px;
}

.nb-list__title {
	margin: 0;
	font-size: 1.1em;
}

.nb-list__tabs {
	display: flex;
	gap: 4px;
	margin: 0 12px 12px;
}

.nb-tab {
	flex: 1 1 auto;
	padding: 6px 10px;
	border: none;
	border-radius: var(--border-radius-large, 12px);
	background-color: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	font-size: 0.9em;
}

.nb-tab:hover {
	background-color: var(--color-background-hover);
}

.nb-tab--active {
	background-color: var(--color-primary-element-light);
	color: var(--color-main-text);
	font-weight: 600;
}

.nb-list__items {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.nb-list__loading,
.nb-detail-loading {
	margin: 40px auto;
}

.nb-detail-wrapper {
	position: relative;
}

.nb-detail-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	max-width: 760px;
	margin: 0 auto;
	padding: 16px 16px 0;
}
</style>
