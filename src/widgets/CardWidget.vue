<!--
  Pano bileşeni 2/3 — KART.
  Kapak görselli kutular; daha az duyuru gösterir ama görsel olarak öne çıkar.
  Kapağı olmayan duyuru için renkli bir başlık şeridi çizilir, böylece kartlar
  boş görünmez.
-->
<template>
	<div class="nb-widget">
		<NcLoadingIcon v-if="loading" :size="24" class="nb-widget__center" />

		<p v-else-if="failed" class="nb-widget__center nb-widget__error">
			{{ t('noticeboard', 'Duyurular yüklenemedi.') }}
		</p>

		<NcEmptyContent v-else-if="!announcements.length"
			:name="t('noticeboard', 'Duyuru yok')"
			:description="t('noticeboard', 'Size yönelik bir duyuru yok.')">
			<template #icon><Bullhorn :size="32" /></template>
		</NcEmptyContent>

		<div v-else class="nb-cards">
			<a v-for="item in announcements"
				:key="item.id"
				class="nb-card"
				:href="linkTo(item.id)">
				<img v-if="item.coverFileId"
					class="nb-card__cover"
					:src="coverUrl(item.id)"
					:style="{ objectPosition: coverPosition(item) }"
					alt=""
					loading="lazy">
				<span v-else class="nb-card__cover nb-card__cover--blank">
					<AutoCover :announcement="item" compact />
				</span>

				<span class="nb-card__body">
					<span class="nb-card__badges">
						<span v-if="item.pinned" class="nb-chip nb-chip--pin">
							{{ t('noticeboard', 'Sabit') }}
						</span>
						<span v-if="priorityLabel(item.priority)"
							class="nb-chip"
							:class="`nb-chip--p${item.priority}`">
							{{ priorityLabel(item.priority) }}
						</span>
						<span v-if="item.category" class="nb-chip nb-chip--category">
							{{ item.category }}
						</span>
					</span>

					<span class="nb-card__subject">{{ item.subject }}</span>
					<span class="nb-card__date">{{ formatRelative(item.createdAt) }}</span>
				</span>
			</a>
		</div>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Bullhorn from 'vue-material-design-icons/Bullhorn.vue'
import { t } from '@nextcloud/l10n'

import AutoCover from '../components/AutoCover.vue'
import { widgetData } from './useAnnouncements.js'
import { announcementLink } from './link.js'
import { coverUrl } from '../api.js'
import { coverPosition, formatRelative, priorityLabel } from '../format.js'

export default {
	name: 'CardWidget',

	components: { AutoCover, Bullhorn, NcEmptyContent, NcLoadingIcon },

	// Kart daha çok yer kapladığı için daha az duyuru gösterilir.
	mixins: [widgetData(4)],

	methods: {
		t,
		coverPosition,
		coverUrl,
		formatRelative,
		priorityLabel,
		linkTo: announcementLink,
	},
}
</script>

<style scoped>
.nb-cards {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.nb-card {
	display: flex;
	gap: 10px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	color: var(--color-main-text);
	text-decoration: none;
}

.nb-card:hover,
.nb-card:focus-visible {
	border-color: var(--color-primary-element);
	background-color: var(--color-background-hover);
}

.nb-card__cover {
	flex: 0 0 auto;
	width: 64px;
	height: 64px;
	border-radius: var(--border-radius, 8px);
	object-fit: cover;
	background-color: var(--color-background-dark);
}

/* Otomatik kapak (kategori rengi + ikon) kutunun köşelerinden taşmasın. */
.nb-card__cover--blank {
	display: block;
	overflow: hidden;
}

.nb-card__body {
	display: flex;
	flex-direction: column;
	gap: 3px;
	min-width: 0;
}

.nb-card__badges {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.nb-card__subject {
	overflow: hidden;
	font-weight: 600;
	line-height: 1.25;
	/* İki satırdan sonra kes: kartlar eşit yükseklikte kalsın. */
	display: -webkit-box;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
}

.nb-card__date {
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

.nb-widget__center {
	display: block;
	margin: 24px auto;
	text-align: center;
}

.nb-widget__error {
	color: var(--color-error);
}
</style>
