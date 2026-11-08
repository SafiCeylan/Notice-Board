<!--
  Duyuru oluşturma / düzenleme ekranı (Faz 4 — projenin kalbi).

  Düzen: solda form, sağda canlı önizleme. Önizleme, okuyucunun göreceği bileşenin
  (AnnouncementDetail) ta kendisidir — ayrı bir "önizleme görünümü" yazılmadı, çünkü
  ayrı yazılsaydı zamanla gerçek görünümden ayrışır ve önizleme yalan söylerdi.
-->
<template>
	<!--
	  ⚠️ `show` BİZDE tutulur. NcModal'ın kendi hâline bırakılırsa X'e/Esc'e basınca
	  modal kendini HEMEN gizliyor, `close`'u 300 ms sonra yayıyordu. Kaydedilmemiş
	  değişiklik uyarısında "Yazmaya devam et" seçilince modal görünmez ama açık
	  kalıyor, bileşen hiç kapanmıyordu — sonraki "Düzenle"/"Yeni" tıklamaları
	  boşa gidiyordu (5 Eki 2026). Artık kapanma kararını tryClose() veriyor.
	-->
	<NcModal size="full"
		:show="true"
		:name="isEdit ? t('noticeboard', 'Duyuruyu düzenle') : t('noticeboard', 'Yeni duyuru')"
		@update:show="onShowChange">
		<div class="nb-composer">
			<!-- ---------------- FORM ---------------- -->
			<div class="nb-composer__form">
				<h2 class="nb-composer__heading">
					{{ isEdit ? t('noticeboard', 'Duyuruyu düzenle') : t('noticeboard', 'Yeni duyuru') }}
				</h2>

				<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

				<label class="nb-field">
					<span class="nb-field__label">{{ t('noticeboard', 'Başlık') }} *</span>
					<NcTextField v-model="form.subject"
						:placeholder="t('noticeboard', 'Örn. Yıllık izin talepleri 15 Ekim’e kadar')"
						:maxlength="255" />
					<span class="nb-field__hint">{{ form.subject.length }} / 255</span>
				</label>

				<div class="nb-field">
					<span class="nb-field__label">{{ t('noticeboard', 'Metin') }}</span>
					<RichTextEditor v-model="form.message" :announcement-id="existing?.id ?? null" />
				</div>

				<!-- Kapak görseli -->
				<div class="nb-field">
					<span class="nb-field__label">{{ t('noticeboard', 'Kapak görseli') }}</span>
					<div v-if="coverPreview" class="nb-cover__frame">
						<img class="nb-cover__img"
							:src="coverPreview"
							:style="{ objectPosition: coverObjectPosition }"
							alt="">
						<span v-if="hasPendingCover" class="nb-cover__badge">
							{{ t('noticeboard', 'Düzenlendi · kaydedince yüklenecek') }}
						</span>
					</div>

					<div class="nb-cover__actions">
						<NcButton :disabled="coverBusy" @click="pickCover">
							<template #icon><ImageOutline :size="20" /></template>
							{{ t('noticeboard', 'Dosyalarımdan seç') }}
						</NcButton>
						<NcButton :disabled="coverBusy" @click="$refs.coverInput.click()">
							<template #icon><TrayArrowUp :size="20" /></template>
							{{ t('noticeboard', 'Bilgisayardan yükle') }}
						</NcButton>
						<NcButton v-if="form.coverFileId || hasPendingCover"
							:disabled="coverBusy"
							@click="editCurrentCover">
							<template #icon>
								<NcLoadingIcon v-if="coverBusy" :size="20" />
								<ImageEditOutline v-else :size="20" />
							</template>
							{{ t('noticeboard', 'Düzenle') }}
						</NcButton>
						<NcButton v-if="form.coverFileId || hasPendingCover"
							variant="tertiary"
							:disabled="coverBusy"
							@click="clearCover">
							{{ t('noticeboard', 'Kaldır') }}
						</NcButton>
						<input ref="coverInput"
							class="nb-cover__input"
							type="file"
							:accept="coverMimeTypes.join(',')"
							@change="onCoverUpload">
					</div>
					<span class="nb-field__hint">
						{{ t('noticeboard', 'Seçtiğiniz görsel düzenleyicide açılır: kırpın, döndürün, renklerini ayarlayın. Asıl dosyanıza dokunulmaz; düzenlenmiş kopya dosyalarınızdaki “{folder}” klasörüne kaydedilir.', { folder: coverFolder }) }}
					</span>
				</div>

				<CoverEditor v-if="editor"
					:src="editor.source.url"
					:mime="editor.source.mime"
					:initial-state="editor.state"
					:announcement="form"
					@apply="onEditorApply"
					@cancel="closeEditor" />

				<!-- Ekler -->
				<div class="nb-field">
					<span class="nb-field__label">{{ t('noticeboard', 'Ekler') }}</span>
					<ul v-if="form.attachments.length" class="nb-attachments">
						<li v-for="(file, index) in form.attachments" :key="file.fileId">
							<Paperclip :size="16" />
							<span class="nb-attachments__name">{{ file.fileName }}</span>
							<NcButton variant="tertiary"
								:aria-label="t('noticeboard', 'Ekten çıkar')"
								@click="form.attachments.splice(index, 1)">
								<template #icon><Close :size="18" /></template>
							</NcButton>
						</li>
					</ul>
					<NcButton @click="pickAttachments">
						<template #icon><Paperclip :size="20" /></template>
						{{ t('noticeboard', 'Dosya ekle') }}
					</NcButton>
				</div>

				<!-- Sınıflandırma -->
				<div class="nb-row">
					<!-- Kategori LİSTEDEN seçilir (7 Eki 2026): her seçenek, kapaksız duyuruda
					     alacağı ikon ve renkle görünür. Listede olmayan için "Diğer" yazı alanı açar.
					     <label> değil <div>: NcSelect kendi etiketini taşır, iç içe etiket tıklamayı bozar. -->
					<div class="nb-field nb-field--grow">
						<span id="nb-category-label" class="nb-field__label">{{ t('noticeboard', 'Kategori') }}</span>
						<NcSelect v-model="categoryChoice"
							:options="categoryOptions"
							label="label"
							track-by="key"
							:clearable="true"
							:label-outside="true"
							aria-labelledby="nb-category-label"
							:placeholder="t('noticeboard', 'Kategori seçin (isteğe bağlı)')">
							<template #option="option">
								<span class="nb-category">
									<span class="nb-category__swatch">
										<Pencil v-if="option.key === otherCategory" :size="16" />
										<AutoCover v-else :announcement="{ category: option.label }" compact :icon-size="16" />
									</span>
									{{ option.label }}
								</span>
							</template>
							<template #selected-option="option">
								<span class="nb-category">
									<span class="nb-category__swatch">
										<Pencil v-if="option.key === otherCategory" :size="16" />
										<AutoCover v-else :announcement="{ category: option.label }" compact :icon-size="16" />
									</span>
									{{ option.key === otherCategory ? (form.category || option.label) : option.label }}
								</span>
							</template>
						</NcSelect>
						<NcTextField v-if="categoryOther"
							v-model="form.category"
							class="nb-category__custom"
							:label="t('noticeboard', 'Kategori adı')"
							:placeholder="t('noticeboard', 'Örn. Satış')"
							:maxlength="64" />
						<span class="nb-field__hint">
							{{ t('noticeboard', 'Duyurunun konusu. Kapak görseli yoksa panodaki renk ve simge buradan gelir. Kimin göreceğini aşağıdaki “Kimler görsün” belirler.') }}
						</span>
					</div>

					<label class="nb-field">
						<span class="nb-field__label">{{ t('noticeboard', 'Öncelik') }}</span>
						<select v-model.number="form.priority" class="nb-select">
							<option :value="0">{{ t('noticeboard', 'Normal') }}</option>
							<option :value="1">{{ t('noticeboard', 'Önemli') }}</option>
							<option :value="2">{{ t('noticeboard', 'Acil') }}</option>
						</select>
					</label>
				</div>

				<NcCheckboxRadioSwitch :model-value="form.pinned" @update:model-value="form.pinned = $event">
					{{ t('noticeboard', 'Listenin en üstüne sabitle') }}
				</NcCheckboxRadioSwitch>

				<!-- Hedefleme (K3) -->
				<div class="nb-field nb-section">
					<span class="nb-field__label">{{ t('noticeboard', 'Kimler görsün') }}</span>
					<!-- ⚠️ `model-value` ile `value` AYNI TÜRDE olmalı; radyo düğmesi
					     ikisini eşitlikle karşılaştırır. Önce modele boolean verilip
					     value'ya metin konmuştu ve hiçbir seçenek işaretlenmiyordu. -->
					<NcCheckboxRadioSwitch :model-value="audience"
						type="radio"
						name="nb-audience"
						value="everyone"
						@update:model-value="audience = $event">
						{{ t('noticeboard', 'Tüm kullanıcılar') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch :model-value="audience"
						type="radio"
						name="nb-audience"
						value="groups"
						@update:model-value="audience = $event">
						{{ t('noticeboard', 'Yalnızca seçtiğim gruplar') }}
					</NcCheckboxRadioSwitch>

					<NcSelect v-if="!everyone"
						v-model="selectedGroups"
						class="nb-field__select"
						:options="groupOptions"
						:loading="loadingGroups"
						:multiple="true"
						:close-on-select="false"
						label="label"
						track-by="id"
						:placeholder="t('noticeboard', 'Grup seçin veya aramak için yazın')"
						@search="searchGroups" />

					<span v-if="!everyone" class="nb-field__hint">
						{{ t('noticeboard', 'Gruplar Nextcloud’un kendi grup listesinden gelir; burada yeni grup oluşturulmaz.') }}
					</span>
				</div>

				<!-- Dağıtım anahtarları (K6) + zamanlama (K5) -->
				<details class="nb-advanced" :open="hasSchedule">
					<summary>{{ t('noticeboard', 'Dağıtım ve zamanlama seçenekleri') }}</summary>

					<div class="nb-advanced__body">
						<NcCheckboxRadioSwitch :model-value="form.options.activity"
							@update:model-value="form.options.activity = $event">
							{{ t('noticeboard', 'Etkinlik akışına düşsün') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch :model-value="form.options.notification"
							@update:model-value="form.options.notification = $event">
							{{ t('noticeboard', 'Bildirim gönderilsin') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch :model-value="form.options.email"
							@update:model-value="form.options.email = $event">
							{{ t('noticeboard', 'E-posta gönderilsin') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch :model-value="form.options.comments"
							@update:model-value="form.options.comments = $event">
							{{ t('noticeboard', 'Yorumlara izin ver') }}
						</NcCheckboxRadioSwitch>
						<!-- Talk kurulu ve uyumlu değilse anahtar hiç görünmez (sunucu: TalkGateway). -->
						<template v-if="talkAvailable">
							<NcCheckboxRadioSwitch :model-value="form.options.talk"
								@update:model-value="form.options.talk = $event">
								{{ t('noticeboard', 'Talk mesajı gönderilsin') }}
							</NcCheckboxRadioSwitch>
							<span class="nb-field__hint nb-advanced__hint">
								{{ t('noticeboard', 'Hedeflenen kişilerin Talk’taki “Duyuru Panosu” sohbetine mesaj düşer (yanıt ve arama kapalı); size de bir kopyası gelir.') }}
							</span>
						</template>

						<div class="nb-row">
							<label class="nb-field nb-field--grow">
								<span class="nb-field__label">{{ t('noticeboard', 'Yayın zamanı') }}</span>
								<input v-model="publishAtLocal" type="datetime-local" class="nb-input">
								<span class="nb-field__hint">{{ t('noticeboard', 'Boş bırakılırsa hemen yayınlanır.') }}</span>
							</label>

							<label class="nb-field nb-field--grow">
								<span class="nb-field__label">{{ t('noticeboard', 'Yayından kalkma zamanı') }}</span>
								<input v-model="deleteAtLocal" type="datetime-local" class="nb-input">
								<span class="nb-field__hint">{{ t('noticeboard', 'Boş bırakılırsa süresiz kalır.') }}</span>
							</label>
						</div>
					</div>
				</details>

				<div class="nb-composer__actions">
					<NcButton variant="primary" :disabled="!canSave || saving" @click="save(false)">
						<template #icon><Send :size="20" /></template>
						{{ publishButtonLabel }}
					</NcButton>
					<NcButton :disabled="!canSave || saving" @click="save(true)">
						<template #icon><ContentSave :size="20" /></template>
						{{ t('noticeboard', 'Taslak olarak kaydet') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="saving" @click="tryClose">
						{{ t('noticeboard', 'Vazgeç') }}
					</NcButton>
				</div>
			</div>

			<!-- ---------------- ÖNİZLEME ---------------- -->
			<div class="nb-composer__preview">
				<div class="nb-composer__preview-label">
					<Eye :size="18" />
					{{ t('noticeboard', 'Önizleme') }}
					<span class="nb-composer__preview-tabs" role="tablist">
						<button v-for="tab in previewTabs"
							:key="tab.id"
							type="button"
							role="tab"
							class="nb-composer__preview-tab"
							:class="{ 'nb-composer__preview-tab--on': previewMode === tab.id }"
							:aria-selected="previewMode === tab.id ? 'true' : 'false'"
							@click="previewMode = tab.id">
							{{ tab.label }}
						</button>
					</span>
				</div>
				<AnnouncementDetail v-if="previewMode === 'page'" :announcement="preview" :cover-src="coverPreview" />
				<div v-else class="nb-composer__board">
					<!-- Pano paneliyle aynı genişlik (320 px panel, 288 px içerik). -->
					<div class="nb-composer__board-panel">
						<div class="nb-composer__board-title">
							<Bullhorn :size="18" />
							{{ t('noticeboard', 'Duyuru vitrini') }}
						</div>
						<SpotlightSlide :announcement="preview" :cover-src="coverPreview" />
					</div>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { getCurrentUser } from '@nextcloud/auth'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { defineAsyncComponent } from 'vue'
import { getFilePickerBuilder } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'

import Bullhorn from 'vue-material-design-icons/Bullhorn.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import ImageEditOutline from 'vue-material-design-icons/ImageEditOutline.vue'
import ImageOutline from 'vue-material-design-icons/ImageOutline.vue'
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Send from 'vue-material-design-icons/Send.vue'
import TrayArrowUp from 'vue-material-design-icons/TrayArrowUp.vue'

import AnnouncementDetail from './AnnouncementDetail.vue'
import AutoCover from './AutoCover.vue'
import RichTextEditor from './RichTextEditor.vue'
import SpotlightSlide from './SpotlightSlide.vue'
import { createAnnouncement, fetchGroups, updateAnnouncement } from '../api.js'
import { OTHER_CATEGORY, categoryPresets, findPreset } from '../cover/categories.js'
import { coverFileName } from '../cover/imageTools.js'
import { uploadCover } from '../cover/uploadCover.js'
import { coverPosition } from '../format.js'

/** Kapak olabilecek türler — sunucudaki FileController::MAX_INLINE_IMAGE_TYPES'ın alt kümesi. */
const COVER_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']

/** Bilgisayardan yüklenen görselin tavanı; daha büyüğü tarayıcıda tuvale sığmayabilir. */
const MAX_COVER_UPLOAD_BYTES = 30 * 1024 * 1024

/** Talk kurulu ve uyumlu mu (PageController → TalkGateway::isAvailable). */
const TALK_AVAILABLE = loadState('noticeboard', 'talkAvailable', false)

/** Unix saniye → <input type="datetime-local"> değeri (yerel saat). */
const toLocalInput = seconds => {
	if (!seconds) {
		return ''
	}
	const d = new Date(seconds * 1000)
	const pad = n => String(n).padStart(2, '0')

	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** <input type="datetime-local"> değeri → unix saniye. */
const fromLocalInput = value => {
	if (!value) {
		return null
	}
	const ms = new Date(value).getTime()

	return Number.isNaN(ms) ? null : Math.floor(ms / 1000)
}

const emptyForm = () => ({
	subject: '',
	message: '',
	category: '',
	priority: 0,
	pinned: false,
	coverFileId: null,
	// Eski odak noktası (0.3.0). Düzenleyiciden geçen kapakta null: görsel zaten
	// istenen yerden kırpılmış. Eski duyurularda kayıtlı değer korunur.
	coverX: null,
	coverY: null,
	attachments: [],
	// Talk anahtarı yalnız Talk kullanılabilirken varsayılan açık; değilse gizli ve kapalı.
	options: { activity: true, notification: true, email: false, comments: true, talk: TALK_AVAILABLE },
})

export default {
	name: 'AnnouncementComposer',

	components: {
		AnnouncementDetail,
		AutoCover,
		Bullhorn,
		Close,
		ContentSave,
		// Kırpma kütüphanesi yalnız düzenleyici açılınca yüklensin.
		CoverEditor: defineAsyncComponent(() => import('./CoverEditor.vue')),
		Eye,
		ImageEditOutline,
		ImageOutline,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextField,
		Paperclip,
		Pencil,
		RichTextEditor,
		Send,
		SpotlightSlide,
		TrayArrowUp,
	},

	props: {
		/** Düzenlenecek duyuru; null ise yeni duyuru oluşturulur. */
		existing: {
			type: Object,
			default: null,
		},
		currentUid: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'saved'],

	data() {
		return {
			form: emptyForm(),
			/** 'everyone' | 'groups' — radyo düğmesiyle aynı türde olmak zorunda. */
			audience: 'everyone',
			selectedGroups: [],
			groupOptions: [],
			loadingGroups: false,
			publishAtLocal: '',
			deleteAtLocal: '',
			saving: false,
			error: '',
			dirty: false,
			previewMode: 'page',
			/** Düzenlenen duyurunun hedef grupları sunucudan gelmediyse true (bkz. loadExisting). */
			groupsUnknown: false,
			coverMimeTypes: COVER_MIME_TYPES,
			/** "Diğer" seçildi ya da kayıtlı kategori hazır listede yok: elle yazma alanı açık. */
			categoryOther: false,
			otherCategory: OTHER_CATEGORY,
			talkAvailable: TALK_AVAILABLE,
			/**
			 * Kapağın bu oturumdaki ASIL kaynağı { url (blob:), mime, name }. Yeniden
			 * "Düzenle"ye basılınca düzenlenmiş kopya değil bu açılır — kırpılan kenar
			 * geri alınabilsin ve kalite her düzenlemede düşmesin.
			 */
			coverSource: null,
			/** Düzenleyicinin son durumu; yeniden açılınca kaldığı yerden devam eder. */
			coverEditState: null,
			/** Düzenlenmiş, henüz yüklenmemiş kapak { blob, url, extension }. */
			localCover: null,
			/** Açık düzenleyici { src, mime, state } ya da null. */
			editor: null,
			coverBusy: false,
		}
	},

	computed: {
		isEdit() {
			return this.existing !== null
		},

		everyone() {
			return this.audience === 'everyone'
		},

		canSave() {
			return this.form.subject.trim().length > 0 && !this.groupsUnknown
		},

		hasSchedule() {
			return Boolean(this.publishAtLocal || this.deleteAtLocal)
		},

		publishButtonLabel() {
			if (this.publishAtLocal && fromLocalInput(this.publishAtLocal) > Date.now() / 1000) {
				return t('noticeboard', 'Zamanla')
			}

			return this.isEdit ? t('noticeboard', 'Kaydet ve yayınla') : t('noticeboard', 'Yayınla')
		},

		/**
		 * Kapak önizlemesi. Henüz kaydedilmemiş bir kapak için duyurunun kendi kapak
		 * ucu çalışmaz (duyuruda o fileId yok), o yüzden Nextcloud'un genel önizleme
		 * ucundan gösterilir.
		 */
		coverPreview() {
			if (this.localCover) {
				return this.localCover.url
			}

			if (!this.form.coverFileId) {
				return ''
			}

			if (this.isEdit && this.form.coverFileId === this.existing.coverFileId) {
				return generateUrl('/apps/noticeboard/announcements/{id}/cover', { id: this.existing.id })
			}

			return `${generateUrl('/core/preview')}?fileId=${this.form.coverFileId}&x=1200&y=1200&a=1`
		},

		hasPendingCover() {
			return this.localCover !== null && !this.form.coverFileId
		},

		coverObjectPosition() {
			return coverPosition(this.form)
		},

		coverFolder() {
			return t('noticeboard', 'Duyuru kapakları')
		},

		categoryOptions() {
			return [
				...categoryPresets(t),
				{ key: OTHER_CATEGORY, label: t('noticeboard', 'Diğer (kendim yazayım)') },
			]
		},

		/**
		 * Seçim kutusunun değeri `form.category` METNİNDEN türetilir — kayda yazılan
		 * tek gerçek odur. Hazır etiketse o seçenek, elle yazılmışsa "Diğer".
		 */
		categoryChoice: {
			get() {
				if (this.categoryOther) {
					return this.categoryOptions.at(-1)
				}

				return findPreset(this.categoryOptions.slice(0, -1), this.form.category)
			},
			set(option) {
				if (!option) {
					this.categoryOther = false
					this.form.category = ''
				} else if (option.key === OTHER_CATEGORY) {
					// Hazır bir etiketten "Diğer"e geçilince o etiket yazı alanına taşınmasın.
					if (findPreset(this.categoryOptions.slice(0, -1), this.form.category)) {
						this.form.category = ''
					}
					this.categoryOther = true
				} else {
					this.categoryOther = false
					this.form.category = option.label
				}
			},
		},

		previewTabs() {
			return [
				{ id: 'page', label: t('noticeboard', 'Duyuru sayfası') },
				{ id: 'board', label: t('noticeboard', 'Pano vitrini') },
			]
		},

		/** Önizleme, detay bileşeninin beklediği biçimde sahte bir duyuru nesnesi. */
		preview() {
			return {
				id: this.existing?.id ?? 0,
				authorUid: this.existing?.authorUid || this.currentUid,
				// Yeni duyuruda yazar oturumdaki kişidir; adı Nextcloud'un oturum bilgisinden.
				authorName: this.existing?.authorName || getCurrentUser()?.displayName || this.currentUid,
				subject: this.form.subject || t('noticeboard', 'Başlıksız duyuru'),
				message: this.form.message,
				category: this.form.category,
				priority: this.form.priority,
				pinned: this.form.pinned,
				// Kapak adresi detay bileşenine ayrıca (coverSrc) verilir: henüz
				// kaydedilmemiş bir kapak duyurunun kendi ucundan çekilemez.
				// Yüklenmeyi bekleyen kapakta henüz kimlik yok; önizleme kapağı yine göstersin.
				coverFileId: this.form.coverFileId ?? (this.localCover ? -1 : null),
				coverX: this.form.coverX,
				coverY: this.form.coverY,
				status: this.previewStatus,
				createdAt: this.existing?.createdAt ?? Math.floor(Date.now() / 1000),
				publishAt: fromLocalInput(this.publishAtLocal),
				deleteAt: fromLocalInput(this.deleteAtLocal),
				groups: this.everyone ? [] : this.selectedGroups.map(g => g.id),
				attachments: this.form.attachments.map((f, i) => ({ id: -i - 1, fileId: f.fileId, fileName: f.fileName })),
				options: this.form.options,
			}
		},

		previewStatus() {
			const publishAt = fromLocalInput(this.publishAtLocal)

			return publishAt && publishAt > Date.now() / 1000 ? 'scheduled' : 'published'
		},
	},

	watch: {
		form: {
			deep: true,
			handler() {
				this.dirty = true
			},
		},

		/**
		 * Grup seçimine geçildiği anda listeyi doldur. Aksi halde kullanıcı boş bir
		 * kutu görüp bir şey yazması gerektiğini anlamıyor.
		 */
		audience(value) {
			this.dirty = true
			if (value === 'groups' && this.groupOptions.length === 0) {
				this.searchGroups('')
			}
		},

		selectedGroups() {
			this.dirty = true
		},
	},

	mounted() {
		if (this.existing) {
			this.loadExisting()
			if (!this.everyone) {
				this.searchGroups('')
			}
		}
		window.addEventListener('beforeunload', this.warnOnLeave)
		// İlk doldurma "değişiklik" sayılmasın.
		this.$nextTick(() => {
			this.dirty = false
		})
	},

	beforeUnmount() {
		window.removeEventListener('beforeunload', this.warnOnLeave)
		if (this.editor && this.editor.source !== this.coverSource) {
			URL.revokeObjectURL(this.editor.source.url)
		}
		this.releaseCoverSource()
		this.releaseLocalCover()
	},

	methods: {
		t,

		loadExisting() {
			const a = this.existing

			this.form = {
				subject: a.subject,
				message: a.message,
				category: a.category || '',
				priority: a.priority,
				pinned: a.pinned,
				coverFileId: a.coverFileId,
				coverX: a.coverX ?? null,
				coverY: a.coverY ?? null,
				attachments: a.attachments.map(f => ({ fileId: f.fileId, fileName: f.fileName })),
				options: { ...a.options },
			}

			// Eski (elle yazılmış) kategori hazır listede yoksa "Diğer" olarak açılır, metni korunur.
			this.categoryOther = Boolean(this.form.category) && !findPreset(categoryPresets(t), this.form.category)

			// 🔐 Sunucu hedef grupları yalnız düzenleyebilene gönderir (forViewer).
			// Gruplar gelmediyse "herkese açık" SANILMAZ: öyle sanılsaydı kayıt
			// hedeflemeyi sessizce silip duyuruyu herkese açardı. Kaydetme kapanır.
			if (!Array.isArray(a.groups)) {
				this.groupsUnknown = true
				this.error = t('noticeboard', 'Hedef gruplar okunamadı. Hedeflemeyi bozmamak için kaydetme kapatıldı; sayfayı yenileyip tekrar deneyin.')
				return
			}
			this.audience = a.groups.length === 0 ? 'everyone' : 'groups'
			this.selectedGroups = a.groups.map(id => ({ id, label: id }))
			this.publishAtLocal = toLocalInput(a.publishAt)
			this.deleteAtLocal = toLocalInput(a.deleteAt)
		},

		warnOnLeave(event) {
			if (this.dirty) {
				event.preventDefault()
				event.returnValue = ''
			}
		},

		/** NcModal'ın X düğmesi ve Esc tuşu buraya düşer. */
		onShowChange(show) {
			if (!show) {
				this.tryClose()
			}
		},

		async tryClose() {
			if (!this.dirty) {
				this.$emit('close')
				return
			}

			const { showConfirmation } = await import('@nextcloud/dialogs')

			const kapat = await showConfirmation({
				name: t('noticeboard', 'Kaydedilmemiş değişiklikler'),
				text: t('noticeboard', 'Kaydedilmemiş değişiklikler var. Yine de kapatılsın mı?'),
				labelConfirm: t('noticeboard', 'Kapat ve at'),
				labelReject: t('noticeboard', 'Yazmaya devam et'),
				severity: 'warning',
			})

			if (kapat) {
				this.$emit('close')
			}
		},

		/**
		 * ⚠️ Dosya seçicide `addButton`/`setButtonFactory` ÇAĞRILMAZSA onay düğmesi
		 * hiç oluşmaz; kullanıcı dosyayı seçer ama ilerleyemez (23 Eyl 2026'da sahada
		 * yaşandı). Düğme fabrikası ayrıca seçim yapılmadan düğmeyi pasif tutar.
		 */
		async pickCover() {
			const picker = getFilePickerBuilder(t('noticeboard', 'Kapak görseli seç'))
				.setMultiSelect(false)
				.setMimeTypeFilter(COVER_MIME_TYPES)
				.allowDirectories(false)
				.setButtonFactory(nodes => [{
					label: nodes.length
						? t('noticeboard', 'Bu görseli kullan')
						: t('noticeboard', 'Bir görsel seçin'),
					variant: 'primary',
					disabled: nodes.length === 0,
					callback: picked => this.applyCover(picked[0]),
				}])
				.build()

			try {
				await picker.pickNodes()
			} catch (e) {
				// Kullanıcı seçiciyi kapattı — yapacak bir şey yok.
			}
		},

		/**
		 * Dosyalardan seçilen görsel önce indirilip yerel blob olarak düzenleyicide
		 * açılır: tuval aynı kökenden gelen görselle bile kimlik bilgili isteklerde
		 * kirlenebiliyor, yerel blob bu ihtimali tamamen kaldırıyor.
		 */
		async applyCover(node) {
			if (!node?.source) {
				return
			}
			await this.openEditorFrom(node.source, node.basename ?? '', null)
		},

		onCoverUpload(event) {
			const file = event.target.files?.[0]
			// Aynı dosya yeniden seçilebilsin diye alan hemen boşaltılır.
			event.target.value = ''
			if (!file) {
				return
			}
			this.error = ''
			if (!COVER_MIME_TYPES.includes(file.type)) {
				this.error = t('noticeboard', 'Kapak için JPEG, PNG, GIF ya da WebP görsel seçin.')
				return
			}
			if (file.size > MAX_COVER_UPLOAD_BYTES) {
				this.error = t('noticeboard', 'Görsel çok büyük (en fazla {size} MB).', { size: MAX_COVER_UPLOAD_BYTES / 1024 / 1024 })
				return
			}
			this.editor = {
				source: { url: URL.createObjectURL(file), mime: file.type, name: file.name },
				state: null,
			}
		},

		/** Kapak zaten varsa: bu oturumdaki asıl kaynaktan, yoksa kayıtlı kapaktan aç. */
		async editCurrentCover() {
			if (this.coverSource) {
				this.editor = { source: this.coverSource, state: this.coverEditState }
				return
			}
			// Kayıtlı kapak zaten kırpılmış olabilir; varsayılan oran onu yeniden kırpmasın.
			await this.openEditorFrom(this.coverPreview, '', { aspect: 'original' })
		},

		async openEditorFrom(url, name, state) {
			this.error = ''
			this.coverBusy = true
			try {
				const { data: blob } = await axios.get(url, { responseType: 'blob' })
				if (!COVER_MIME_TYPES.includes(blob.type)) {
					throw new Error(blob.type)
				}
				this.editor = {
					source: { url: URL.createObjectURL(blob), mime: blob.type, name },
					state,
				}
			} catch (e) {
				this.error = t('noticeboard', 'Görsel açılamadı.')
			} finally {
				this.coverBusy = false
			}
		},

		/**
		 * Düzenleyicide açılan kaynak ancak "Uygula"da kapağın asıl kaynağı olur;
		 * vazgeçilirse önceki kapak ve kaynağı olduğu gibi kalır.
		 */
		onEditorApply({ blob, extension, state }) {
			if (this.editor.source !== this.coverSource) {
				this.releaseCoverSource()
				this.coverSource = this.editor.source
			}
			this.releaseLocalCover()
			this.localCover = { blob, extension, url: URL.createObjectURL(blob) }
			this.coverEditState = state
			// Eski kapak kimliği bırakılır: kaydederken düzenlenmiş görsel yüklenip yerine geçer.
			this.form.coverFileId = null
			this.form.coverX = null
			this.form.coverY = null
			this.editor = null
			this.dirty = true
		},

		closeEditor() {
			if (this.editor.source !== this.coverSource) {
				URL.revokeObjectURL(this.editor.source.url)
			}
			this.editor = null
		},

		clearCover() {
			this.form.coverFileId = null
			this.form.coverX = null
			this.form.coverY = null
			this.releaseLocalCover()
			this.releaseCoverSource()
			this.coverEditState = null
		},

		releaseCoverSource() {
			if (this.coverSource) {
				URL.revokeObjectURL(this.coverSource.url)
				this.coverSource = null
			}
		},

		releaseLocalCover() {
			if (this.localCover) {
				URL.revokeObjectURL(this.localCover.url)
				this.localCover = null
			}
		},

		/** Düzenlenmiş kapak yalnızca kayıtta yüklenir; kimliği bir kez alınınca tekrar yüklenmez. */
		async uploadPendingCover() {
			if (!this.hasPendingCover) {
				return
			}
			const fileName = coverFileName(this.coverSource?.name, this.localCover.extension)
			this.form.coverFileId = await uploadCover(this.currentUid, this.coverFolder, fileName, this.localCover.blob)
		},

		async pickAttachments() {
			const picker = getFilePickerBuilder(t('noticeboard', 'Dosya ekle'))
				.setMultiSelect(true)
				.allowDirectories(false)
				.setButtonFactory(nodes => [{
					label: nodes.length
						? t('noticeboard', '{count} dosyayı ekle', { count: nodes.length })
						: t('noticeboard', 'Dosya seçin'),
					variant: 'primary',
					disabled: nodes.length === 0,
					callback: picked => this.addAttachments(picked),
				}])
				.build()

			try {
				await picker.pickNodes()
			} catch (e) {
				// Vazgeçildi.
			}
		},

		addAttachments(nodes) {
			for (const node of nodes) {
				if (!node?.fileid) {
					continue
				}
				if (!this.form.attachments.some(f => f.fileId === node.fileid)) {
					this.form.attachments.push({ fileId: node.fileid, fileName: node.basename ?? '' })
				}
			}
		},

		async searchGroups(query) {
			this.loadingGroups = true
			try {
				this.groupOptions = await fetchGroups(query)
			} catch (error) {
				this.groupOptions = []
			} finally {
				this.loadingGroups = false
			}
		},

		async save(asDraft) {
			this.error = ''

			const publishAt = fromLocalInput(this.publishAtLocal)
			const deleteAt = fromLocalInput(this.deleteAtLocal)

			// Sunucu da kontrol ediyor ama kullanıcıyı isteğe göndermeden uyarmak
			// daha iyi bir deneyim.
			if (publishAt && deleteAt && deleteAt <= publishAt) {
				this.error = t('noticeboard', 'Yayından kalkma zamanı, yayın zamanından sonra olmalı.')
				return
			}

			this.saving = true

			try {
				await this.uploadPendingCover()
			} catch (error) {
				this.error = t('noticeboard', 'Kapak görseli dosyalarınıza yüklenemedi. Depolama kotanızı kontrol edip tekrar deneyin.')
				this.saving = false
				return
			}

			const payload = {
				subject: this.form.subject.trim(),
				message: this.form.message,
				category: this.form.category.trim() || null,
				priority: this.form.priority,
				pinned: this.form.pinned,
				coverFileId: this.form.coverFileId,
				coverX: this.form.coverFileId ? this.form.coverX : null,
				coverY: this.form.coverFileId ? this.form.coverY : null,
				publishAt,
				deleteAt,
				groups: this.everyone ? [] : this.selectedGroups.map(g => g.id),
				attachments: this.form.attachments,
				options: this.form.options,
				draft: asDraft,
			}

			try {
				const saved = this.isEdit
					? await updateAnnouncement(this.existing.id, payload)
					: await createAnnouncement(payload)

				this.dirty = false
				this.$emit('saved', saved)
			} catch (error) {
				this.error = error.response?.data?.ocs?.data?.message
					|| error.response?.data?.ocs?.meta?.message
					|| t('noticeboard', 'Duyuru kaydedilemedi.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.nb-composer {
	display: grid;
	grid-template-columns: minmax(420px, 1fr) 1fr;
	height: 100%;
	overflow: hidden;
}

.nb-composer__form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	overflow-y: auto;
	padding: 24px;
}

.nb-composer__heading {
	margin: 0;
	font-size: 1.3em;
}

.nb-composer__preview {
	overflow-y: auto;
	border-left: 1px solid var(--color-border);
	background-color: var(--color-background-dark);
}

/* Önizleme şeridi Nextcloud ana rengini taşır ki formdan ayrıldığı belli olsun. */
.nb-composer__preview-label {
	position: sticky;
	top: 0;
	z-index: 1;
	display: flex;
	gap: 6px;
	align-items: center;
	padding: 10px 16px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text, var(--color-main-text));
	font-weight: 600;
}

.nb-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.nb-field--grow {
	flex: 1 1 200px;
}

/* Kategori seçeneği: kapaksız duyuruda alacağı simge + renk, adıyla yan yana. */
.nb-category {
	display: inline-flex;
	gap: 8px;
	align-items: center;
	min-width: 0;
}

.nb-category__swatch {
	display: inline-flex;
	flex: 0 0 auto;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	overflow: hidden;
	border-radius: 6px;
	background-color: var(--color-background-dark);
}

.nb-category__custom {
	margin-top: 6px;
}

.nb-field__label {
	font-weight: 600;
}

.nb-field__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.nb-field__select {
	margin-top: 6px;
}

.nb-row {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
}

.nb-input,
.nb-select {
	width: 100%;
	min-height: 36px;
	padding: 6px 10px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

/* Formdaki kapak, vitrinle aynı 16:9 çerçevede — yayıncı kırpılmış hâlini görür. */
.nb-cover__frame {
	position: relative;
	width: 100%;
	max-width: 360px;
	aspect-ratio: 16 / 9;
	overflow: hidden;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-dark);
}

.nb-cover__img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.nb-cover__badge {
	position: absolute;
	bottom: 8px;
	left: 8px;
	padding: 2px 8px;
	border-radius: 999px;
	background-color: rgba(0, 0, 0, 0.65);
	color: #fff;
	font-size: 0.8em;
}

.nb-cover__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.nb-cover__input {
	display: none;
}

.nb-composer__preview-tabs {
	display: flex;
	gap: 4px;
	margin-left: auto;
}

/* ⚠️ Çıplak <button>: NC çekirdeğinin genel düğme kuralları sıfırlanır (Hata #35). */
.nb-composer__preview-tab,
.nb-composer__preview-tab:hover,
.nb-composer__preview-tab:focus,
.nb-composer__preview-tab:active {
	min-height: 0;
	margin: 0;
	padding: 2px 10px;
	border: 1px solid transparent;
	border-radius: 999px;
	background: transparent;
	box-shadow: none;
	color: inherit;
	font-size: 0.85em;
	font-weight: normal;
	cursor: pointer;
}

.nb-composer__preview-tab:hover {
	border-color: var(--color-primary-element);
}

.nb-composer__preview-tab:focus-visible {
	outline: 2px solid var(--color-primary-element);
}

.nb-composer__preview-tab--on,
.nb-composer__preview-tab--on:hover,
.nb-composer__preview-tab--on:focus {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
	font-weight: 600;
}

/* Pano önizlemesi: NC panosundaki panel ölçüsü (320 px, iç boşluk 16 px). */
.nb-composer__board {
	display: flex;
	justify-content: center;
	padding: 32px 16px;
}

.nb-composer__board-panel {
	width: 320px;
	max-width: 100%;
	padding: 0 16px 16px;
	border-radius: var(--border-radius-container-large, 16px);
	background-color: var(--color-main-background);
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.12);
}

.nb-composer__board-title {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 16px 0 12px;
	font-weight: 600;
}

.nb-attachments {
	margin: 0 0 8px;
	padding: 0;
	list-style: none;
}

.nb-attachments li {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 2px 0;
}

.nb-attachments__name {
	flex: 1 1 auto;
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
}

/* Hedefleme ve gelişmiş seçenekler, sol kenarında ana renk şeridiyle
   formun geri kalanından ayrılır. */
.nb-section,
.nb-advanced {
	padding: 12px 12px 12px 16px;
	border: 1px solid var(--color-border);
	border-left: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.nb-advanced summary {
	cursor: pointer;
	font-weight: 600;
}

.nb-advanced__body {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 12px;
}

/* Anahtarın açıklaması: anahtar metninin hizasında, ona yapışık. */
.nb-advanced__hint {
	margin-top: -8px;
	padding-inline-start: 44px;
}

.nb-composer__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding-top: 8px;
	padding-bottom: 24px;
}

/* Dar ekranda önizleme forma yer açmak için alta iner. */
@media (max-width: 1024px) {
	.nb-composer {
		grid-template-columns: 1fr;
		grid-template-rows: auto auto;
		overflow-y: auto;
	}

	.nb-composer__preview {
		border-top: 1px solid var(--color-border);
		border-left: none;
	}
}
</style>
