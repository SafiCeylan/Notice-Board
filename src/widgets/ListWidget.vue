<!--
  Pano bileşeni 1/3 — LİSTE.
  Kompakt satırlar; en çok duyuruyu en az yerde gösterir. Kapak görseli yok,
  yalnız başlık + rozet + tarih.
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

		<ul v-else class="nb-widget__list">
			<li v-for="item in announcements" :key="item.id">
				<a class="nb-row" :href="linkTo(item.id)">
					<Pin v-if="item.pinned" class="nb-row__pin" :size="14" />
					<span class="nb-row__subject">{{ item.subject }}</span>
					<span v-if="priorityLabel(item.priority)"
						class="nb-dot"
						:class="`nb-dot--p${item.priority}`"
						:title="priorityLabel(item.priority)" />
					<span class="nb-row__date">{{ formatRelative(item.createdAt) }}</span>
				</a>
			</li>
		</ul>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import Bullhorn from 'vue-material-design-icons/Bullhorn.vue'
import Pin from 'vue-material-design-icons/Pin.vue'
import { t } from '@nextcloud/l10n'

import { widgetData } from './useAnnouncements.js'
import { announcementLink } from './link.js'
import { formatRelative, priorityLabel } from '../format.js'

export default {
	name: 'ListWidget',

	components: { Bullhorn, NcEmptyContent, NcLoadingIcon, Pin },

	mixins: [widgetData(7)],

	methods: {
		t,
		formatRelative,
		priorityLabel,
		linkTo: announcementLink,
	},
}
</script>

<style scoped>
.nb-widget__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.nb-row {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 8px 10px;
	border-radius: var(--border-radius-large, 12px);
	color: var(--color-main-text);
	text-decoration: none;
}

.nb-row:hover,
.nb-row:focus-visible {
	background-color: var(--color-background-hover);
}

.nb-row__pin {
	flex: 0 0 auto;
	color: var(--color-primary-element);
}

.nb-row__subject {
	flex: 1 1 auto;
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
}

.nb-row__date {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	font-size: 0.82em;
}

/* Dar widget'ta rozet yerine renkli nokta — metin sığmıyor. */
.nb-dot {
	flex: 0 0 auto;
	width: 8px;
	height: 8px;
	border-radius: 50%;
}

.nb-dot--p1 {
	background-color: var(--color-primary-element);
}

.nb-dot--p2 {
	background-color: var(--color-error);
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
