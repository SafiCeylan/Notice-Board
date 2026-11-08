<!--
  Kapak görseli düzenleyicisi (odak noktası işaretçisinin yerine, 7 Eki 2026).

  Yayıncı görseli kırpar, döndürür, çevirir, düzleştirir ve renklerini ayarlar;
  sağda kapağın PANODA nasıl görüneceğini canlı görür (vitrin slaydının kendisi +
  kart/liste küçük resimleri).

  ⚠️ Asıl dosyaya DOKUNULMAZ. "Uygula" yalnızca düzenlenmiş görseli (Blob) üst
  bileşene verir; yükleme, duyuru kaydedilirken yapılır — vazgeçilen düzenleme
  yayıncının dosyalarında yetim kopya bırakmasın.
-->
<template>
	<NcModal size="large"
		:show="true"
		:name="t('noticeboard', 'Kapak görselini düzenle')"
		@update:show="onShowChange">
		<div class="nb-editor">
			<h2 class="nb-editor__heading">{{ t('noticeboard', 'Kapak görselini düzenle') }}</h2>

			<div class="nb-editor__layout">
				<!-- ---------------- SAHNE + ARAÇLAR ---------------- -->
				<div class="nb-editor__main">
					<div class="nb-editor__stage" :style="{ '--nb-filter': filter }">
						<img ref="image"
							class="nb-editor__source"
							:src="src"
							alt=""
							@load="initCropper"
							@error="loadFailed = true">
						<!-- Yazı katmanı kırpma alanının tam üstünde; kayıtla aynı fonksiyonla çizilir. -->
						<canvas v-show="ready && hasText"
							ref="overlayCanvas"
							class="nb-editor__text-layer"
							aria-hidden="true" />
						<div v-if="!ready && !loadFailed" class="nb-editor__overlay">
							<NcLoadingIcon :size="32" />
						</div>
						<div v-if="loadFailed" class="nb-editor__overlay">
							<NcNoteCard type="error">{{ t('noticeboard', 'Görsel açılamadı.') }}</NcNoteCard>
						</div>
					</div>

					<div class="nb-editor__group" role="radiogroup" :aria-label="t('noticeboard', 'Kırpma oranı')">
						<span class="nb-editor__label">{{ t('noticeboard', 'Oran') }}</span>
						<div class="nb-editor__chips">
							<button v-for="preset in presets"
								:key="preset.id"
								type="button"
								role="radio"
								class="nb-editor__chip"
								:class="{ 'nb-editor__chip--on': aspect === preset.id }"
								:aria-checked="aspect === preset.id ? 'true' : 'false'"
								:disabled="!ready"
								@click="setAspect(preset.id)">
								{{ preset.label }}
							</button>
						</div>
					</div>

					<div class="nb-editor__tools">
						<NcButton variant="tertiary"
							:aria-label="t('noticeboard', 'Sola döndür')"
							:title="t('noticeboard', 'Sola döndür')"
							:disabled="!ready"
							@click="rotateQuarter(-1)">
							<template #icon><RotateLeft :size="20" /></template>
						</NcButton>
						<NcButton variant="tertiary"
							:aria-label="t('noticeboard', 'Sağa döndür')"
							:title="t('noticeboard', 'Sağa döndür')"
							:disabled="!ready"
							@click="rotateQuarter(1)">
							<template #icon><RotateRight :size="20" /></template>
						</NcButton>
						<NcButton variant="tertiary"
							:aria-label="t('noticeboard', 'Yatay çevir')"
							:title="t('noticeboard', 'Yatay çevir')"
							:disabled="!ready"
							@click="flip('x')">
							<template #icon><FlipHorizontal :size="20" /></template>
						</NcButton>
						<NcButton variant="tertiary"
							:aria-label="t('noticeboard', 'Dikey çevir')"
							:title="t('noticeboard', 'Dikey çevir')"
							:disabled="!ready"
							@click="flip('y')">
							<template #icon><FlipVertical :size="20" /></template>
						</NcButton>
						<span class="nb-editor__divider" aria-hidden="true" />
						<NcButton variant="tertiary"
							:aria-label="t('noticeboard', 'Yakınlaştır')"
							:title="t('noticeboard', 'Yakınlaştır')"
							:disabled="!ready"
							@click="zoom(0.1)">
							<template #icon><MagnifyPlus :size="20" /></template>
						</NcButton>
						<NcButton variant="tertiary"
							:aria-label="t('noticeboard', 'Uzaklaştır')"
							:title="t('noticeboard', 'Uzaklaştır')"
							:disabled="!ready"
							@click="zoom(-0.1)">
							<template #icon><MagnifyMinus :size="20" /></template>
						</NcButton>
						<span class="nb-editor__divider" aria-hidden="true" />
						<NcButton variant="tertiary" :disabled="!ready" @click="resetAll">
							<template #icon><Restore :size="20" /></template>
							{{ t('noticeboard', 'Sıfırla') }}
						</NcButton>
					</div>

					<label class="nb-editor__slider">
						<span class="nb-editor__label">
							{{ t('noticeboard', 'Düzleştir') }}
							<span class="nb-editor__value">{{ fineRotation > 0 ? '+' : '' }}{{ fineRotation }}°</span>
						</span>
						<input v-model.number="fineRotation"
							type="range"
							min="-45"
							max="45"
							step="1"
							:disabled="!ready"
							@input="applyRotation"
							@dblclick="fineRotation = 0; applyRotation()">
					</label>

					<p class="nb-editor__hint">
						{{ t('noticeboard', 'Görseli sürükleyerek kaydırın, fare tekerleğiyle yakınlaştırın. Çerçevenin köşelerinden kırpma alanını değiştirin.') }}
					</p>
				</div>

				<!-- ---------------- RENK + PANO ÖNİZLEMESİ ---------------- -->
				<aside class="nb-editor__side">
					<section class="nb-editor__section">
						<h3 class="nb-editor__section-title">{{ t('noticeboard', 'Renk') }}</h3>
						<label v-for="control in colorControls" :key="control.key" class="nb-editor__slider">
							<span class="nb-editor__label">
								{{ control.label }}
								<span class="nb-editor__value">{{ adjustments[control.key] - 100 > 0 ? '+' : '' }}{{ adjustments[control.key] - 100 }}</span>
							</span>
							<input v-model.number="adjustments[control.key]"
								type="range"
								:min="limits.min"
								:max="limits.max"
								step="1"
								:disabled="!ready"
								@dblclick="adjustments[control.key] = 100">
						</label>
					</section>

					<section class="nb-editor__section">
						<h3 class="nb-editor__section-title">{{ t('noticeboard', 'Yazı') }}</h3>
						<NcTextField v-model="overlay.text"
							:label="t('noticeboard', 'Kapağa yazı ekle')"
							:placeholder="t('noticeboard', 'Örn. SON GÜN 15 EKİM')"
							:maxlength="maxOverlayLength"
							:disabled="!ready" />
						<template v-if="hasText">
							<div v-for="group in overlayGroups"
								:key="group.key"
								class="nb-editor__group"
								role="radiogroup"
								:aria-label="group.label">
								<span class="nb-editor__label nb-editor__label--small">{{ group.label }}</span>
								<div class="nb-editor__chips">
									<button v-for="option in group.options"
										:key="option.value"
										type="button"
										role="radio"
										class="nb-editor__chip"
										:class="{ 'nb-editor__chip--on': overlay[group.key] === option.value }"
										:aria-checked="overlay[group.key] === option.value ? 'true' : 'false'"
										@click="overlay[group.key] = option.value">
										{{ option.label }}
									</button>
								</div>
							</div>
						</template>
					</section>

					<section class="nb-editor__section">
						<h3 class="nb-editor__section-title">{{ t('noticeboard', 'Panoda nasıl görünecek') }}</h3>
						<div class="nb-editor__panel">
							<SpotlightSlide :announcement="previewAnnouncement" :cover-src="livePreview" />
						</div>
						<div class="nb-editor__thumbs">
							<figure>
								<img v-if="livePreview" class="nb-editor__thumb nb-editor__thumb--card" :src="livePreview" alt="">
								<figcaption>{{ t('noticeboard', 'Kart') }}</figcaption>
							</figure>
							<figure>
								<img v-if="livePreview" class="nb-editor__thumb nb-editor__thumb--list" :src="livePreview" alt="">
								<figcaption>{{ t('noticeboard', 'Liste') }}</figcaption>
							</figure>
						</div>
						<p v-if="aspect !== 'wide'" class="nb-editor__hint">
							{{ t('noticeboard', 'Vitrin 16:9 gösterir; başka oranda kırpılan kapağın kenarları vitrinde kesilir.') }}
						</p>
					</section>
				</aside>
			</div>

			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

			<div class="nb-editor__actions">
				<NcButton variant="tertiary" :disabled="working" @click="$emit('cancel')">
					{{ t('noticeboard', 'Vazgeç') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!ready || working" @click="apply">
					<template #icon>
						<NcLoadingIcon v-if="working" :size="20" />
						<Check v-else :size="20" />
					</template>
					{{ t('noticeboard', 'Uygula') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import Cropper from 'cropperjs'
import 'cropperjs/dist/cropper.css'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { t } from '@nextcloud/l10n'

import Check from 'vue-material-design-icons/Check.vue'
import FlipHorizontal from 'vue-material-design-icons/FlipHorizontal.vue'
import FlipVertical from 'vue-material-design-icons/FlipVertical.vue'
import MagnifyMinus from 'vue-material-design-icons/MagnifyMinus.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import RotateRight from 'vue-material-design-icons/RotateRight.vue'

import SpotlightSlide from './SpotlightSlide.vue'
import {
	ADJUST_LIMITS,
	ASPECT_PRESETS,
	DEFAULT_ASPECT,
	MAX_OUTPUT_SIZE,
	NEUTRAL_ADJUSTMENTS,
	applyAdjustments,
	cssFilter,
	fitOutputSize,
	outputType,
} from '../cover/imageTools.js'
import { EMPTY_OVERLAY, MAX_OVERLAY_LENGTH, drawOverlay, hasOverlay } from '../cover/textOverlay.js'

/** Tuval yazısı da Nextcloud temasının yazı tipiyle çizilsin. */
const pageFont = () => getComputedStyle(document.body).fontFamily

/** Canlı önizleme küçük çizilir; her fare hareketinde tam boy çizmek takılır. */
const LIVE_PREVIEW_SIZE = 640
const LIVE_PREVIEW_DELAY_MS = 120

const PRESET_LABELS = {
	wide: () => t('noticeboard', '16:9 · Vitrin'),
	classic: () => '4:3',
	square: () => '1:1',
	original: () => t('noticeboard', 'Asıl oran'),
	free: () => t('noticeboard', 'Serbest'),
}

const canvasToBlob = (canvas, type, quality) => new Promise((resolve, reject) => {
	canvas.toBlob(blob => (blob ? resolve(blob) : reject(new Error('toBlob'))), type, quality)
})

export default {
	name: 'CoverEditor',

	components: {
		Check,
		FlipHorizontal,
		FlipVertical,
		MagnifyMinus,
		MagnifyPlus,
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcTextField,
		Restore,
		RotateLeft,
		RotateRight,
		SpotlightSlide,
	},

	props: {
		/** Düzenlenecek görselin adresi (yerel blob: adresi; kaynak tuval kirletmesin). */
		src: {
			type: String,
			required: true,
		},
		/** Kaynağın türü — kayıt biçimini belirler (bkz. outputType). */
		mime: {
			type: String,
			default: 'image/jpeg',
		},
		/** Aynı oturumda yeniden açılınca kaldığı yerden devam etmek için önceki durum. */
		initialState: {
			type: Object,
			default: null,
		},
		/** Önizlemedeki slayt için başlık/rozetler (oluşturma ekranındaki form). */
		announcement: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['apply', 'cancel'],

	data() {
		return {
			ready: false,
			loadFailed: false,
			working: false,
			error: '',
			aspect: this.initialState?.aspect ?? DEFAULT_ASPECT,
			quarterTurns: this.initialState?.quarterTurns ?? 0,
			fineRotation: this.initialState?.fineRotation ?? 0,
			adjustments: { ...NEUTRAL_ADJUSTMENTS, ...(this.initialState?.adjustments ?? {}) },
			overlay: { ...EMPTY_OVERLAY, ...(this.initialState?.overlay ?? {}) },
			maxOverlayLength: MAX_OVERLAY_LENGTH,
			livePreview: '',
			limits: ADJUST_LIMITS,
		}
	},

	computed: {
		presets() {
			return ASPECT_PRESETS.map(preset => ({ ...preset, label: PRESET_LABELS[preset.id]() }))
		},

		colorControls() {
			return [
				{ key: 'brightness', label: t('noticeboard', 'Parlaklık') },
				{ key: 'contrast', label: t('noticeboard', 'Karşıtlık') },
				{ key: 'saturation', label: t('noticeboard', 'Doygunluk') },
			]
		},

		filter() {
			return cssFilter(this.adjustments)
		},

		hasText() {
			return hasOverlay(this.overlay)
		},

		overlayGroups() {
			return [
				{
					key: 'position',
					label: t('noticeboard', 'Konum'),
					options: [
						{ value: 'top', label: t('noticeboard', 'Üst') },
						{ value: 'center', label: t('noticeboard', 'Orta') },
						{ value: 'bottom', label: t('noticeboard', 'Alt') },
					],
				},
				{
					key: 'style',
					label: t('noticeboard', 'Görünüm'),
					options: [
						{ value: 'band', label: t('noticeboard', 'Koyu şerit') },
						{ value: 'light', label: t('noticeboard', 'Açık şerit') },
						{ value: 'shadow', label: t('noticeboard', 'Gölgeli') },
					],
				},
				{
					key: 'size',
					label: t('noticeboard', 'Boyut'),
					options: [
						{ value: 'small', label: t('noticeboard', 'Küçük') },
						{ value: 'medium', label: t('noticeboard', 'Orta boy') },
						{ value: 'large', label: t('noticeboard', 'Büyük') },
					],
				},
			]
		},

		previewAnnouncement() {
			return {
				id: 0,
				subject: this.announcement.subject || t('noticeboard', 'Başlıksız duyuru'),
				message: this.announcement.message ?? '',
				category: this.announcement.category ?? '',
				priority: this.announcement.priority ?? 0,
				pinned: this.announcement.pinned ?? false,
				createdAt: this.announcement.createdAt ?? Math.floor(Date.now() / 1000),
				coverFileId: null,
			}
		},
	},

	watch: {
		// Önizleme renk ayarını ve yazıyı PİKSELE uygular (kayıtla aynı); değişince yeniden çiz.
		adjustments: {
			deep: true,
			handler() {
				this.scheduleLivePreview()
			},
		},
		overlay: {
			deep: true,
			handler() {
				this.drawStageOverlay()
				this.scheduleLivePreview()
			},
		},
	},

	beforeUnmount() {
		clearTimeout(this.previewTimer)
		this.cropper?.destroy()
		this.revokeLivePreview()
	},

	methods: {
		t,

		onShowChange(show) {
			if (!show && !this.working) {
				this.$emit('cancel')
			}
		},

		initCropper() {
			if (this.cropper) {
				return
			}
			// Cropper Vue'nun reaktif sistemine girmesin (data'da değil, örnek alanında).
			this.cropper = new Cropper(this.$refs.image, {
				viewMode: 1,
				dragMode: 'move',
				autoCropArea: 1,
				aspectRatio: this.aspectValue(this.aspect),
				background: true,
				guides: true,
				center: true,
				movable: true,
				zoomable: true,
				rotatable: true,
				scalable: true,
				toggleDragModeOnDblclick: false,
				wheelZoomRatio: 0.08,
				ready: () => {
					// ⚠️ Telefon fotoğrafında EXIF yönü varsa cropper görseli KENDİSİ
					// döndürür (ör. 90°) ve bunu başlangıç verisine yazar. Bizim
					// döndürmelerimiz mutlak (`rotateTo`) olduğu için bu taban eklenmezse
					// "Düzleştir"e dokunulduğu an dikey fotoğraf yan yatardı.
					this.baseRotation = this.cropper.getImageData().rotate || 0
					// "Asıl oran" görsel boyutu bilinmeden hesaplanamıyordu (kurulumda NaN =
					// serbest); boyut artık belli, oran kilitlensin. setData'dan ÖNCE:
					// oran değişimi kırpma kutusunu yeniden kurar.
					if (this.aspect === 'original') {
						this.cropper.setAspectRatio(this.aspectValue('original'))
					}
					if (this.initialState?.data) {
						this.cropper.setData(this.initialState.data)
					} else if (this.quarterTurns || this.fineRotation) {
						this.applyRotation()
					}
					this.ready = true
					this.$nextTick(this.drawStageOverlay)
					this.scheduleLivePreview()
				},
				crop: () => {
					this.drawStageOverlay()
					this.scheduleLivePreview()
				},
			})
		},

		/**
		 * Sahnedeki yazı katmanı: kırpma kutusunun konumuna/boyutuna oturtulur ve
		 * kayıttaki `drawOverlay` ile çizilir. Ölçüler orantılı olduğu için küçük
		 * kutuda da büyük çıktıdakiyle aynı yerde durur.
		 */
		drawStageOverlay() {
			const canvas = this.$refs.overlayCanvas
			if (!canvas || !this.cropper || !this.ready) {
				return
			}
			const box = this.cropper.getCropBoxData()
			const ratio = window.devicePixelRatio || 1
			Object.assign(canvas.style, {
				left: `${box.left}px`,
				top: `${box.top}px`,
				width: `${box.width}px`,
				height: `${box.height}px`,
			})
			canvas.width = Math.max(1, Math.round(box.width * ratio))
			canvas.height = Math.max(1, Math.round(box.height * ratio))
			const ctx = canvas.getContext('2d')
			ctx.clearRect(0, 0, canvas.width, canvas.height)
			drawOverlay(ctx, canvas.width, canvas.height, this.overlay, pageFont())
		},

		/** Toplam dönüş: EXIF tabanı + kullanıcının çeyrek dönüşleri + ince ayar. */
		totalRotation() {
			return (this.baseRotation || 0) + this.quarterTurns * 90 + this.fineRotation
		},

		/**
		 * Ön ayarın cropper oranı. "Asıl oran" görselin GÖRÜNEN yönüne göredir:
		 * EXIF tabanı + çeyrek dönüş tek sayıda 90° ise en ve boy yer değiştirir.
		 * Boyutlar cropper'dan okunur — `<img>`in kendi boyutu tarayıcının EXIF'e
		 * göre çevirdiği hâldir, cropper'ın yönü sıfırlanmış kopyasıyla uyuşmaz.
		 */
		aspectValue(id) {
			const preset = ASPECT_PRESETS.find(p => p.id === id)
			if (!preset) {
				return NaN
			}
			if (preset.value !== null) {
				return preset.value
			}
			const { naturalWidth: w, naturalHeight: h } = this.cropper?.getImageData() ?? {}
			if (!w || !h) {
				return NaN
			}
			const quarters = Math.round(((this.baseRotation || 0) + this.quarterTurns * 90) / 90)

			return quarters % 2 === 0 ? w / h : h / w
		},

		setAspect(id) {
			this.aspect = id
			this.cropper.setAspectRatio(this.aspectValue(id))
		},

		rotateQuarter(delta) {
			this.quarterTurns = (this.quarterTurns + delta + 4) % 4
			this.applyRotation()
			if (this.aspect === 'original') {
				this.cropper.setAspectRatio(this.aspectValue('original'))
			}
		},

		applyRotation() {
			this.cropper?.rotateTo(this.totalRotation())
		},

		flip(axis) {
			const data = this.cropper.getData()
			if (axis === 'x') {
				this.cropper.scaleX(-(data.scaleX ?? 1))
			} else {
				this.cropper.scaleY(-(data.scaleY ?? 1))
			}
		},

		zoom(ratio) {
			this.cropper.zoom(ratio)
		},

		/**
		 * Kırpma, dönüş, çevirme ve rengi sıfırlar. Yazıya DOKUNMAZ: yazılan metni
		 * bir tıkla silmek kullanıcıyı şaşırtırdı. `reset()` cropper'ın başlangıç
		 * verisine döner — EXIF tabanı orada olduğu için fotoğraf yan yatmaz.
		 */
		resetAll() {
			this.quarterTurns = 0
			this.fineRotation = 0
			this.adjustments = { ...NEUTRAL_ADJUSTMENTS }
			this.cropper.reset()
			this.cropper.setAspectRatio(this.aspectValue(this.aspect))
		},

		scheduleLivePreview() {
			clearTimeout(this.previewTimer)
			this.previewTimer = setTimeout(this.renderLivePreview, LIVE_PREVIEW_DELAY_MS)
		},

		/**
		 * Panodaki görünümün önizlemesi: kayıttakiyle AYNI adımlar (renk ayarı
		 * piksellere, sonra yazı) — yalnız küçük boyda. CSS filtresiyle gösterilseydi
		 * yazı da filtrelenir, kaydedilenden farklı görünürdü.
		 */
		async renderLivePreview() {
			if (!this.cropper || !this.ready) {
				return
			}
			const type = outputType(this.mime)
			const canvas = this.cropper.getCroppedCanvas({
				maxWidth: LIVE_PREVIEW_SIZE,
				maxHeight: LIVE_PREVIEW_SIZE,
				fillColor: type.mime === 'image/jpeg' ? '#ffffff' : 'transparent',
				imageSmoothingQuality: 'medium',
			})
			if (!canvas) {
				return
			}
			this.finishCanvas(canvas)
			try {
				// Kayıtla AYNI biçim: saydam PNG önizlemede JPEG'e çevrilseydi saydam
				// alan siyah görünür, kaydedilen kapakla ayrışırdı.
				const blob = await canvasToBlob(canvas, type.mime, type.quality)
				this.revokeLivePreview()
				this.livePreview = URL.createObjectURL(blob)
			} catch (e) {
				// Önizleme çizilemedi; sahne yine çalışır, kayıt ayrıca denenir.
			}
		},

		/** Kayıt ve önizlemenin ortak son adımları: renk ayarı piksellere, sonra yazı. */
		finishCanvas(canvas) {
			const ctx = canvas.getContext('2d')
			const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height)
			applyAdjustments(pixels.data, this.adjustments)
			ctx.putImageData(pixels, 0, 0)
			drawOverlay(ctx, canvas.width, canvas.height, this.overlay, pageFont())
		},

		revokeLivePreview() {
			if (this.livePreview) {
				URL.revokeObjectURL(this.livePreview)
			}
		},

		async apply() {
			this.error = ''
			this.working = true
			try {
				const type = outputType(this.mime)
				const data = this.cropper.getData()
				const size = fitOutputSize(data.width, data.height, MAX_OUTPUT_SIZE)
				const canvas = this.cropper.getCroppedCanvas({
					width: size.width,
					height: size.height,
					// JPEG saydamlık taşıyamaz; düzleştirmede açılan köşeler siyah kalmasın.
					fillColor: type.mime === 'image/jpeg' ? '#ffffff' : 'transparent',
					imageSmoothingEnabled: true,
					imageSmoothingQuality: 'high',
				})
				if (!canvas) {
					throw new Error('canvas')
				}

				this.finishCanvas(canvas)

				const blob = await canvasToBlob(canvas, type.mime, type.quality)

				this.$emit('apply', {
					blob,
					extension: type.extension,
					state: {
						aspect: this.aspect,
						quarterTurns: this.quarterTurns,
						fineRotation: this.fineRotation,
						adjustments: { ...this.adjustments },
						overlay: { ...this.overlay },
						data,
					},
				})
			} catch (e) {
				this.error = t('noticeboard', 'Görsel hazırlanamadı. Daha küçük bir görselle tekrar deneyin.')
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.nb-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px 24px 24px;
}

.nb-editor__heading {
	margin: 0;
	font-size: 1.25em;
}

.nb-editor__layout {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 300px;
	gap: 24px;
}

.nb-editor__main,
.nb-editor__side {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 0;
}

.nb-editor__stage {
	position: relative;
	height: 420px;
	overflow: hidden;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-darker, #333);
}

/* Cropper görseli sahnenin boyuna göre kendisi yerleştirir; ham görsel sahneyi taşırmasın. */
.nb-editor__source {
	display: block;
	max-width: 100%;
}

/*
 * Renk ayarı SAHNEDE CSS ile gösterilir (her fare hareketinde piksel işlemek
 * takılırdı); kayıtta aynı formül piksellere uygulanır. ⚠️ Sağdaki önizlemeler
 * zaten piksel işlenmiş görseldir — onlara filtre VERİLMEZ, yoksa iki kez uygulanır.
 */
.nb-editor__stage :deep(.cropper-canvas img),
.nb-editor__stage :deep(.cropper-view-box img) {
	filter: var(--nb-filter, none);
}

.nb-editor__text-layer {
	position: absolute;
	z-index: 2;
	pointer-events: none;
}

.nb-editor__label--small {
	font-size: 0.9em;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.nb-editor__stage :deep(.cropper-view-box) {
	outline-color: var(--color-primary-element);
}

.nb-editor__stage :deep(.cropper-line),
.nb-editor__stage :deep(.cropper-point) {
	background-color: var(--color-primary-element);
}

.nb-editor__overlay {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 24px;
}

.nb-editor__group {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.nb-editor__label {
	font-weight: 600;
}

.nb-editor__value {
	margin-left: 4px;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	font-variant-numeric: tabular-nums;
}

.nb-editor__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

/* ⚠️ Çıplak <button>: NC çekirdeğinin genel düğme kuralları sıfırlanır (Hata #35). */
.nb-editor__chip,
.nb-editor__chip:hover,
.nb-editor__chip:focus,
.nb-editor__chip:active {
	min-height: 0;
	margin: 0;
	padding: 4px 12px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 999px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: none;
	font-size: 0.9em;
	font-weight: normal;
	line-height: 1.5;
	cursor: pointer;
}

.nb-editor__chip:hover {
	background-color: var(--color-background-hover);
}

.nb-editor__chip:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.nb-editor__chip--on,
.nb-editor__chip--on:hover,
.nb-editor__chip--on:focus {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
}

.nb-editor__chip:disabled {
	opacity: 0.5;
	cursor: default;
}

.nb-editor__tools {
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
	align-items: center;
}

.nb-editor__divider {
	width: 1px;
	height: 24px;
	margin: 0 6px;
	background-color: var(--color-border);
}

.nb-editor__slider {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.nb-editor__slider input[type='range'] {
	width: 100%;
	min-height: 0;
	margin: 0;
	accent-color: var(--color-primary-element);
}

.nb-editor__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.nb-editor__section {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.nb-editor__section-title {
	margin: 0;
	font-size: 1em;
	font-weight: 600;
}

/* Pano paneli 320 px; içerik alanı 288 px — vitrin tam bu genişlikte çizilir. */
.nb-editor__panel {
	width: 100%;
	max-width: 288px;
	padding: 0;
}

.nb-editor__thumbs {
	display: flex;
	gap: 16px;
}

.nb-editor__thumbs figure {
	display: flex;
	flex-direction: column;
	gap: 4px;
	align-items: center;
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}

.nb-editor__thumb {
	border-radius: var(--border-radius, 8px);
	object-fit: cover;
	background-color: var(--color-background-dark);
}

.nb-editor__thumb--card {
	width: 64px;
	height: 64px;
}

.nb-editor__thumb--list {
	width: 44px;
	height: 44px;
}

.nb-editor__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

@media (max-width: 900px) {
	.nb-editor__layout {
		grid-template-columns: 1fr;
	}

	.nb-editor__stage {
		height: 320px;
	}
}
</style>
