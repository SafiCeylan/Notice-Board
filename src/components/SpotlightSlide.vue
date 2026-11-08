<!--
  Vitrin widget'ındaki TEK slayt.

  Ayrı bileşen olmasının sebebi önizleme: kapak düzenleyicisi ve oluşturma ekranı
  "panoda böyle görünecek" derken bu bileşenin KENDİSİNİ çizer. Önizleme ayrı
  yazılsaydı zamanla gerçek vitrinden ayrışır ve yalan söylerdi (aynı ilke:
  AnnouncementComposer önizlemesi AnnouncementDetail'in kendisidir).

  Kapak 16:9 çerçeveyi DOLDURUR (`cover`). Kapak düzenleyicisi varsayılan olarak
  tam bu oranda kırptığı için düzenlenmiş kapak hiç kesilmez; eski (düzenlenmemiş)
  kapaklarda kayıtlı odak noktası (coverX/coverY) korunur.
-->
<template>
	<component :is="href ? 'a' : 'div'"
		class="nb-slide"
		:class="{ 'nb-slide--link': href }"
		:href="href || undefined">
		<span class="nb-slide__cover" :class="{ 'nb-slide__cover--blank': !hasCover }">
			<img v-if="hasCover"
				class="nb-slide__cover-img"
				:src="coverSrc || coverUrl(announcement.id)"
				:style="{ objectPosition: coverPosition(announcement) }"
				alt=""
				draggable="false">
			<AutoCover v-else :announcement="announcement" />
		</span>

		<span class="nb-slide__body">
			<span v-if="announcement.pinned || priorityLabel(announcement.priority) || announcement.category"
				class="nb-slide__badges">
				<span v-if="announcement.pinned" class="nb-chip nb-chip--pin">{{ t('noticeboard', 'Sabit') }}</span>
				<span v-if="priorityLabel(announcement.priority)"
					class="nb-chip"
					:class="`nb-chip--p${announcement.priority}`">
					{{ priorityLabel(announcement.priority) }}
				</span>
				<span v-if="announcement.category" class="nb-chip nb-chip--category">{{ announcement.category }}</span>
			</span>

			<span class="nb-slide__subject">{{ announcement.subject }}</span>
			<span v-if="excerpt" class="nb-slide__excerpt">{{ excerpt }}</span>
			<span class="nb-slide__date">{{ formatRelative(announcement.createdAt) }}</span>
		</span>
	</component>
</template>

<script>
import { t } from '@nextcloud/l10n'

import AutoCover from './AutoCover.vue'

import { coverUrl } from '../api.js'
import { coverPosition, formatRelative, priorityLabel } from '../format.js'

const EXCERPT_LENGTH = 140

export default {
	name: 'SpotlightSlide',

	components: { AutoCover },

	props: {
		announcement: {
			type: Object,
			required: true,
		},
		/** Verilmezse duyurunun kendi kapak ucu kullanılır (önizlemede yerel görsel verilir). */
		coverSrc: {
			type: String,
			default: '',
		},
		/** Boşsa slayt tıklanamaz bir kutu olarak çizilir (önizleme). */
		href: {
			type: String,
			default: '',
		},
	},

	computed: {
		hasCover() {
			return Boolean(this.coverSrc || this.announcement.coverFileId)
		},

		/** Gövde HTML'i; vitrinde düz metin özet gösterilir. */
		excerpt() {
			// DOMParser betik çalıştırmaz ve görsel yüklemez; innerHTML'e atanan
			// bağımsız bir div'de <img onerror> tetiklenebilirdi.
			const doc = new DOMParser().parseFromString(this.announcement.message ?? '', 'text/html')
			const text = (doc.body.textContent ?? '').replace(/\s+/g, ' ').trim()

			return text.length > EXCERPT_LENGTH ? `${text.slice(0, EXCERPT_LENGTH)}…` : text
		},
	},

	methods: {
		t,
		coverUrl,
		coverPosition,
		formatRelative,
		priorityLabel,
	},
}
</script>

<style scoped>
.nb-slide {
	display: flex;
	flex-direction: column;
	overflow: hidden;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	text-decoration: none;
}

.nb-slide--link:hover,
.nb-slide--link:focus-visible {
	border-color: var(--color-primary-element);
}

.nb-slide--link:hover .nb-slide__cover-img {
	transform: scale(1.03);
}

.nb-slide__cover {
	position: relative;
	display: flex;
	flex: 0 0 auto;
	align-items: center;
	justify-content: center;
	width: 100%;
	/* Sabit oran: duyurular arasında geçerken widget boyu zıplamasın. */
	aspect-ratio: 16 / 9;
	overflow: hidden;
	background-color: var(--color-background-dark);
}

.nb-slide__cover-img {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	object-fit: cover;
	transition: transform 0.4s ease;
}

.nb-slide__body {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 10px 12px 12px;
}

.nb-slide__badges {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.nb-slide__subject {
	overflow: hidden;
	font-size: 1.05em;
	font-weight: 600;
	line-height: 1.3;
	display: -webkit-box;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
}

.nb-slide__excerpt {
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	font-size: 0.88em;
	line-height: 1.4;
	display: -webkit-box;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
}

.nb-slide__date {
	color: var(--color-text-maxcontrast);
	font-size: 0.8em;
}

.nb-chip {
	padding: 0 7px;
	border-radius: 999px;
	font-size: 0.75em;
	line-height: 1.7;
	white-space: nowrap;
}

.nb-chip--category {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.nb-chip--pin {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text, var(--color-main-text));
}

.nb-chip--p1 {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
}

.nb-chip--p2 {
	background-color: var(--color-error);
	color: var(--color-primary-element-text, #fff);
}

@media (prefers-reduced-motion: reduce) {
	.nb-slide__cover-img {
		transition: none;
	}

	.nb-slide--link:hover .nb-slide__cover-img {
		transform: none;
	}
}
</style>
