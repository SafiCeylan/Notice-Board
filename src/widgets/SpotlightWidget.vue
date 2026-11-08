<!--
  Pano bileşeni 3/3 — VİTRİN.
  Tek duyuruyu büyük gösterir ve aralarında kayarak döner. Amacı en çok duyuruyu
  göstermek değil, BİR duyuruyu fark ettirmek.

  ⚠️ Dönme, sayfa görünmezken DURUR (arka plandaki sekmede zamanlayıcı boşuna iş
  ve pil tüketir) ve fare/klavye odağı vitrinin üstündeyken DURAKLAR (okurken
  slayt elinden kaçmasın — WCAG 2.2.2).
-->
<template>
	<div class="nb-widget"
		@mouseenter="hovering = true"
		@mouseleave="hovering = false"
		@focusin="focused = true"
		@focusout="onFocusOut">
		<NcLoadingIcon v-if="loading" :size="24" class="nb-widget__center" />

		<p v-else-if="failed" class="nb-widget__center nb-widget__error">
			{{ t('noticeboard', 'Duyurular yüklenemedi.') }}
		</p>

		<NcEmptyContent v-else-if="!slides.length"
			:name="t('noticeboard', 'Duyuru yok')"
			:description="t('noticeboard', 'Size yönelik bir duyuru yok.')">
			<template #icon><Bullhorn :size="32" /></template>
		</NcEmptyContent>

		<div v-else class="nb-spot">
			<div class="nb-spot__viewport"
				@pointerdown="onSwipeStart"
				@pointerup="onSwipeEnd"
				@pointercancel="swipeStartX = null">
				<Transition :name="`nb-slide-${direction}`">
					<SpotlightSlide :key="current.id"
						class="nb-spot__slide"
						:announcement="current"
						:href="linkTo(current.id)" />
				</Transition>
			</div>

			<div v-if="slides.length > 1" class="nb-spot__nav">
				<NcButton variant="tertiary"
					:aria-label="t('noticeboard', 'Önceki duyuru')"
					@click="step(-1, true)">
					<template #icon><ChevronLeft :size="20" /></template>
				</NcButton>

				<span class="nb-spot__dots">
					<button v-for="(item, i) in slides"
						:key="item.id"
						type="button"
						class="nb-spot__dot"
						:class="{ 'nb-spot__dot--on': i === index }"
						:aria-label="item.subject"
						:aria-current="i === index ? 'true' : undefined"
						@click="go(i)">
						<span class="nb-spot__dot-mark" />
					</button>
				</span>

				<NcButton variant="tertiary"
					:aria-label="t('noticeboard', 'Sonraki duyuru')"
					@click="step(1, true)">
					<template #icon><ChevronRight :size="20" /></template>
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Bullhorn from 'vue-material-design-icons/Bullhorn.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'

import SpotlightSlide from '../components/SpotlightSlide.vue'
import { widgetData } from './useAnnouncements.js'
import { announcementLink } from './link.js'
import { coverUrl } from '../api.js'

/** Yönetici ayarı yoksa (eski sunucu, ilk durum gelmedi) kullanılan değerler. */
const DEFAULT_SETTINGS = { interval: 8, filter: 'all' }
const SLIDE_COUNT = 5
/** Bu kadar pikselden kısa kaydırma, tıklama sayılır. */
const SWIPE_MIN_PX = 40

export default {
	name: 'SpotlightWidget',

	components: { Bullhorn, ChevronLeft, ChevronRight, NcButton, NcEmptyContent, NcLoadingIcon, SpotlightSlide },

	// Öne çıkanlar süzülebilsin diye daha fazlası çekilir; gösterilen en fazla SLIDE_COUNT.
	mixins: [widgetData(20)],

	data() {
		return {
			index: 0,
			/** 'next' | 'prev' — geçiş animasyonunun yönü. */
			direction: 'next',
			timer: null,
			hovering: false,
			focused: false,
			swipeStartX: null,
			settings: { ...DEFAULT_SETTINGS, ...loadState('noticeboard', 'spotlight', DEFAULT_SETTINGS) },
		}
	},

	computed: {
		/**
		 * Gösterilecek slaytlar. "Öne çıkanlar" seçiliyse sabitlenmiş/önemli/acil
		 * duyurular; hiç yoksa vitrin boş kalmasın diye en yeniler.
		 */
		slides() {
			if (this.settings.filter === 'featured') {
				const featured = this.announcements.filter(a => a.pinned || a.priority > 0)
				if (featured.length) {
					return featured.slice(0, SLIDE_COUNT)
				}
			}

			return this.announcements.slice(0, SLIDE_COUNT)
		},

		current() {
			return this.slides[this.index] ?? {}
		},

		autoRotate() {
			return this.settings.interval > 0 && this.slides.length > 1
		},

		paused() {
			return this.hovering || this.focused
		},
	},

	watch: {
		paused() {
			this.syncTimer()
		},

		/** Tazelemede liste kısalırsa gösterilen sıra dışarıda kalmasın. */
		slides(list) {
			if (this.index >= list.length) {
				this.index = 0
			}
			// İlk yüklemede slayt sayısı 0'dan çıkınca dönme ancak şimdi başlayabilir.
			this.syncTimer()
			this.preloadNext()
		},

		index() {
			this.preloadNext()
		},
	},

	mounted() {
		document.addEventListener('visibilitychange', this.syncTimer)
		this.syncTimer()
	},

	beforeUnmount() {
		document.removeEventListener('visibilitychange', this.syncTimer)
		this.stopTimer()
	},

	methods: {
		t,
		linkTo: announcementLink,

		syncTimer() {
			if (document.hidden || this.paused || !this.autoRotate) {
				this.stopTimer()
			} else {
				this.startTimer()
			}
		},

		startTimer() {
			this.stopTimer()
			this.timer = setInterval(() => this.step(1), this.settings.interval * 1000)
		},

		stopTimer() {
			if (this.timer !== null) {
				clearInterval(this.timer)
				this.timer = null
			}
		},

		/** @param {boolean} manual kullanıcı tıkladıysa sayaç baştan başlar, hemen kaymaz. */
		step(delta, manual = false) {
			const count = this.slides.length
			if (count === 0) {
				return
			}
			this.direction = delta < 0 ? 'prev' : 'next'
			// Negatif modülü JavaScript'te doğru dönmez; count ekleyip düzeltiyoruz.
			this.index = (this.index + delta + count) % count
			if (manual) {
				this.syncTimer()
			}
		},

		go(i) {
			if (i === this.index) {
				return
			}
			this.direction = i < this.index ? 'prev' : 'next'
			this.index = i
			this.syncTimer()
		},

		/** Sıradaki kapağı önceden indir: kayarken boş çerçeve görünmesin. */
		preloadNext() {
			const count = this.slides.length
			if (count < 2) {
				return
			}
			const next = this.slides[(this.index + 1) % count]
			if (next?.coverFileId) {
				new Image().src = coverUrl(next.id)
			}
		},

		onFocusOut(event) {
			// Odak vitrinin İÇİNDE başka bir öğeye geçtiyse hâlâ odaklı sayılır.
			if (!this.$el.contains(event.relatedTarget)) {
				this.focused = false
			}
		},

		onSwipeStart(event) {
			if (event.pointerType === 'mouse') {
				return
			}
			this.swipeStartX = event.clientX
		},

		onSwipeEnd(event) {
			if (this.swipeStartX === null) {
				return
			}
			const dx = event.clientX - this.swipeStartX
			this.swipeStartX = null
			if (Math.abs(dx) >= SWIPE_MIN_PX) {
				this.step(dx < 0 ? 1 : -1, true)
			}
		},
	},
}
</script>

<style scoped>
/*
 * Gezinme (oklar + noktalar) panelin ALT kenarına yapışır (kullanıcı isteği):
 * widget panel yüksekliğini doldurur, `margin-top: auto` boşluğu nav'ın üstüne
 * iter. Mobilde pano yüksekliği otomatik — orada doğal akışta kalır.
 */
.nb-widget {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.nb-spot {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	gap: 6px;
	min-height: 0;
}

/*
 * Geçişte eski ve yeni slayt AYNI hücrede üst üste durur (grid alanı 1/1);
 * biri dışarı kayarken diğeri içeri girer, kutu boyu zıplamaz.
 */
.nb-spot__viewport {
	display: grid;
	overflow: hidden;
	border-radius: var(--border-radius-large, 12px);
	touch-action: pan-y;
}

.nb-spot__slide {
	grid-area: 1 / 1;
}

.nb-slide-next-enter-active,
.nb-slide-next-leave-active,
.nb-slide-prev-enter-active,
.nb-slide-prev-leave-active {
	transition: transform 0.45s cubic-bezier(0.22, 0.61, 0.36, 1), opacity 0.45s ease;
}

.nb-slide-next-enter-from,
.nb-slide-prev-leave-to {
	transform: translateX(100%);
	opacity: 0.4;
}

.nb-slide-next-leave-to,
.nb-slide-prev-enter-from {
	transform: translateX(-100%);
	opacity: 0.4;
}

@media (prefers-reduced-motion: reduce) {
	.nb-slide-next-enter-active,
	.nb-slide-next-leave-active,
	.nb-slide-prev-enter-active,
	.nb-slide-prev-leave-active {
		transition: opacity 0.2s ease;
	}

	.nb-slide-next-enter-from,
	.nb-slide-next-leave-to,
	.nb-slide-prev-enter-from,
	.nb-slide-prev-leave-to {
		transform: none;
		opacity: 0;
	}
}

.nb-spot__nav {
	display: flex;
	gap: 4px;
	align-items: center;
	justify-content: center;
	margin-top: auto;
}

.nb-spot__dots {
	display: flex;
	align-items: center;
}

/*
 * ⚠️ Nextcloud çekirdeği çıplak <button>'a min-height/padding/margin/border/arka plan
 * veriyor; sıfırlanmazsa 8 px'lik nokta uzun bir hapa dönüşüyor (Hata #35).
 * Düğmenin kendisi görünmez tıklama alanı; görünen nokta içteki span.
 */
.nb-spot__dot,
.nb-spot__dot:hover,
.nb-spot__dot:focus,
.nb-spot__dot:active {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	min-width: 0;
	height: 24px;
	min-height: 0;
	margin: 0;
	padding: 0;
	border: none;
	border-radius: 0;
	background: transparent;
	box-shadow: none;
	cursor: pointer;
}

.nb-spot__dot:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
	border-radius: var(--border-radius, 8px);
}

.nb-spot__dot-mark {
	display: block;
	width: 7px;
	height: 7px;
	border-radius: 999px;
	background-color: var(--color-border-maxcontrast);
	transition: width 0.2s ease, background-color 0.2s ease;
}

.nb-spot__dot:hover .nb-spot__dot-mark {
	background-color: var(--color-text-maxcontrast);
}

.nb-spot__dot--on .nb-spot__dot-mark,
.nb-spot__dot--on:hover .nb-spot__dot-mark {
	width: 16px;
	background-color: var(--color-primary-element);
}

.nb-widget__center {
	display: block;
	margin: 24px auto;
	text-align: center;
}

.nb-widget__error {
	color: var(--color-error);
}
</style>
