<!--
  Yayıncı atama ekranı (K1).

  Yönetici burada hangi hesapların ve grupların duyuru yayınlayabileceğini seçer.
  Yöneticiler zaten her zaman yayınlayabilir — bu liste onlara EK olarak yetki verir.
-->
<template>
	<NcSettingsSection :name="t('noticeboard', 'Duyuru Panosu')"
		:description="t('noticeboard', 'Duyuru yayınlayabilecek hesap ve grupları seçin. Yöneticiler bu listeden bağımsız olarak her zaman duyuru yayınlayabilir.')">
		<div class="nb-admin">
			<div class="nb-admin__field">
				<label for="nb-users">{{ t('noticeboard', 'Yayıncı hesaplar') }}</label>
				<NcSelect input-id="nb-users"
					v-model="selectedUsers"
					:options="userOptions"
					:loading="loadingUsers"
					:multiple="true"
					:close-on-select="false"
					label="label"
					track-by="id"
					:placeholder="t('noticeboard', 'Hesap aramak için yazın')"
					@search="searchUsers" />
			</div>

			<div class="nb-admin__field">
				<label for="nb-groups">{{ t('noticeboard', 'Yayıncı gruplar') }}</label>
				<NcSelect input-id="nb-groups"
					v-model="selectedGroups"
					:options="groupOptions"
					:loading="loadingGroups"
					:multiple="true"
					:close-on-select="false"
					label="label"
					track-by="id"
					:placeholder="t('noticeboard', 'Grup aramak için yazın')"
					@search="searchGroups" />
			</div>

			<div class="nb-admin__actions">
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ saving ? t('noticeboard', 'Kaydediliyor…') : t('noticeboard', 'Kaydet') }}
				</NcButton>
				<span v-if="statusMessage" class="nb-admin__status" :class="{ 'nb-admin__status--error': statusIsError }">
					{{ statusMessage }}
				</span>
			</div>
		</div>
	</NcSettingsSection>

	<!-- Vitrin widget'ı: kurum geneli, son kullanıcı bir şey ayarlamaz. -->
	<NcSettingsSection :name="t('noticeboard', 'Duyuru vitrini')"
		:description="t('noticeboard', 'Panodaki vitrin widget’ının herkes için nasıl davranacağını seçin.')">
		<div class="nb-admin">
			<div class="nb-admin__field">
				<label for="nb-spot-interval">{{ t('noticeboard', 'Dönme süresi') }}</label>
				<select id="nb-spot-interval" v-model.number="spotlight.interval" class="nb-admin__select">
					<option v-for="option in intervalOptions" :key="option.value" :value="option.value">
						{{ option.label }}
					</option>
				</select>
			</div>

			<div class="nb-admin__field">
				<span class="nb-admin__label">{{ t('noticeboard', 'Vitrinde gösterilecekler') }}</span>
				<!-- ⚠️ model-value ile value AYNI türde (metin) olmalı — Hata #13. -->
				<NcCheckboxRadioSwitch :model-value="spotlight.filter"
					type="radio"
					name="nb-spot-filter"
					value="all"
					@update:model-value="spotlight.filter = $event">
					{{ t('noticeboard', 'En yeni duyurular') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :model-value="spotlight.filter"
					type="radio"
					name="nb-spot-filter"
					value="featured"
					@update:model-value="spotlight.filter = $event">
					{{ t('noticeboard', 'Yalnız sabitlenmiş, önemli ve acil duyurular') }}
				</NcCheckboxRadioSwitch>
				<p class="nb-admin__hint">
					{{ t('noticeboard', 'Böyle bir duyuru yoksa vitrin boş kalmaz, en yeni duyuruları gösterir.') }}
				</p>
			</div>

			<div class="nb-admin__actions">
				<NcButton variant="primary" :disabled="savingSpotlight" @click="saveSpotlight">
					{{ savingSpotlight ? t('noticeboard', 'Kaydediliyor…') : t('noticeboard', 'Kaydet') }}
				</NcButton>
				<span v-if="spotlightStatus" class="nb-admin__status" :class="{ 'nb-admin__status--error': spotlightStatusIsError }">
					{{ spotlightStatus }}
				</span>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'

const API = generateOcsUrl('apps/noticeboard/api/v1')

export default {
	name: 'AdminSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcSettingsSection,
	},

	data() {
		return {
			selectedUsers: [],
			selectedGroups: [],
			userOptions: [],
			groupOptions: [],
			loadingUsers: false,
			loadingGroups: false,
			saving: false,
			statusMessage: '',
			statusIsError: false,
			spotlight: { interval: 8, filter: 'all' },
			savingSpotlight: false,
			spotlightStatus: '',
			spotlightStatusIsError: false,
		}
	},

	computed: {
		/** Sunucudaki sınırlarla aynı (SpotlightSettings: 0 ya da 4–60 sn). */
		intervalOptions() {
			const options = [
				{ value: 5, label: t('noticeboard', '{n} saniye', { n: 5 }) },
				{ value: 8, label: t('noticeboard', '{n} saniye (varsayılan)', { n: 8 }) },
				{ value: 12, label: t('noticeboard', '{n} saniye', { n: 12 }) },
				{ value: 20, label: t('noticeboard', '{n} saniye', { n: 20 }) },
				{ value: 30, label: t('noticeboard', '{n} saniye', { n: 30 }) },
				{ value: 0, label: t('noticeboard', 'Kendiliğinden dönmesin') },
			]
			// occ ile listede olmayan geçerli bir değer (ör. 45) ayarlandıysa kutu boş görünmesin.
			const current = this.spotlight.interval
			if (!options.some(option => option.value === current)) {
				options.splice(-1, 0, { value: current, label: t('noticeboard', '{n} saniye', { n: current }) })
			}

			return options
		},
	},

	async mounted() {
		await Promise.all([this.loadSettings(), this.loadSpotlight()])
	},

	methods: {
		t,

		async loadSettings() {
			try {
				const { data } = await axios.get(`${API}/settings/publishers`)
				const settings = data.ocs.data

				// Hesaplar sunucudan e-posta etiketiyle gelir (AD'de kimlik rakamdır).
				this.selectedUsers = settings.publisherUsers
					?? settings.publisherUids.map(id => ({ id, label: id }))
				this.selectedGroups = settings.publisherGids.map(id => ({ id, label: id }))
			} catch (error) {
				this.setStatus(t('noticeboard', 'Ayarlar okunamadı.'), true)
			}
		},

		async searchUsers(query) {
			this.loadingUsers = true
			try {
				const { data } = await axios.get(`${API}/settings/users`, { params: { search: query } })
				this.userOptions = data.ocs.data
			} catch (error) {
				this.userOptions = []
			} finally {
				this.loadingUsers = false
			}
		},

		async searchGroups(query) {
			this.loadingGroups = true
			try {
				// Oluşturma ekranıyla aynı uç; yöneticiler de yayıncı sayıldığı için
				// burada da çalışır.
				const { data } = await axios.get(`${API}/groups`, { params: { search: query } })
				this.groupOptions = data.ocs.data
			} catch (error) {
				this.groupOptions = []
			} finally {
				this.loadingGroups = false
			}
		},

		async save() {
			this.saving = true
			try {
				await axios.post(`${API}/settings/publishers`, {
					publisherUids: this.selectedUsers.map(item => item.id),
					publisherGids: this.selectedGroups.map(item => item.id),
				})
				this.setStatus(t('noticeboard', 'Kaydedildi.'), false)
			} catch (error) {
				this.setStatus(t('noticeboard', 'Kaydedilemedi.'), true)
			} finally {
				this.saving = false
			}
		},

		async loadSpotlight() {
			try {
				const { data } = await axios.get(`${API}/settings/spotlight`)
				this.spotlight = data.ocs.data
			} catch (error) {
				this.setSpotlightStatus(t('noticeboard', 'Ayarlar okunamadı.'), true)
			}
		},

		async saveSpotlight() {
			this.savingSpotlight = true
			try {
				const { data } = await axios.post(`${API}/settings/spotlight`, this.spotlight)
				this.spotlight = data.ocs.data
				this.setSpotlightStatus(t('noticeboard', 'Kaydedildi. Pano bir sonraki açılışta yeni ayarla çalışır.'), false)
			} catch (error) {
				this.setSpotlightStatus(error.response?.data?.ocs?.data?.message || t('noticeboard', 'Kaydedilemedi.'), true)
			} finally {
				this.savingSpotlight = false
			}
		},

		setSpotlightStatus(message, isError) {
			this.spotlightStatus = message
			this.spotlightStatusIsError = isError
			setTimeout(() => {
				this.spotlightStatus = ''
			}, 5000)
		},

		setStatus(message, isError) {
			this.statusMessage = message
			this.statusIsError = isError
			setTimeout(() => {
				this.statusMessage = ''
			}, 4000)
		},
	},
}
</script>

<style scoped>
.nb-admin__field {
	max-width: 480px;
	margin-bottom: 16px;
}

.nb-admin__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

/*
 * Çok seçimli kutular (yayıncı hesap/grup): NcSelect uzun e-posta etiketlerinde
 * her seçimi ayrı satıra dizer ve kutu seçim sayısıyla sınırsız uzar; ayrıca
 * kendi `min-width: 260px` değeri dar ekranda alanı aşar. Kutu alanın genişliğini
 * geçmez, seçimler ~4 satırdan sonra kutunun İÇİNDE kayar. Uzun etiket kısaltması
 * ve tam metin (title) NcSelect'in kendisinden gelir.
 */
.nb-admin__field :deep(.nc-select.v-select.select) {
	min-width: 0;
	max-width: 100%;
}

.nb-admin__field :deep(.nc-select.v-select.select .vs__selected-options) {
	max-height: 132px;
	overflow-y: auto;
}

.nb-admin__label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.nb-admin__select {
	min-width: 240px;
}

.nb-admin__hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.nb-admin__actions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.nb-admin__status {
	color: var(--color-text-maxcontrast);
}

.nb-admin__status--error {
	color: var(--color-error);
}
</style>
