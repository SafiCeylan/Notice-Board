<!--
  Listedeki tek duyuru satırı.

  Sabitlenmiş ve acil duyurular burada görsel olarak öne çıkar; sıralamayı sunucu
  yapar (pinned DESC, created_at DESC), bu bileşen yalnız gösterir.
-->
<template>
	<li class="nb-item" :class="{ 'nb-item--active': active }">
		<!--
		  ⚠️ GERÇEK bir adres veriyoruz (`href="#"` değil). Böylece kullanıcı
		  orta tıkla yeni sekmede açabilir, bağlantıyı kopyalayabilir ve ekran
		  okuyucu nereye gittiğini söyleyebilir. Normal tıklamada uygulama
		  içinde kalmak için `.exact` ile yalnız düz sol tık yakalanır —
		  Ctrl/Cmd/Shift'li tıklamalar tarayıcıya bırakılır.
		-->
		<a class="nb-item__link"
			:href="permalink"
			@click.exact.prevent="$emit('select', announcement.id)">
			<img v-if="announcement.coverFileId"
				class="nb-item__thumb"
				:src="coverUrl(announcement.id)"
				:style="{ objectPosition: coverPosition(announcement) }"
				alt=""
				loading="lazy">
			<span v-else class="nb-item__thumb nb-item__thumb--empty">
				<AutoCover :announcement="announcement" compact :icon-size="20" />
			</span>

			<span class="nb-item__body">
				<span class="nb-item__top">
					<Pin v-if="announcement.pinned"
						class="nb-item__pin"
						:size="14"
						:title="t('noticeboard', 'Sabitlenmiş')" />
					<span class="nb-item__subject">{{ announcement.subject }}</span>
				</span>

				<span class="nb-item__meta">
					<!-- Yayında olmayan duyurunun durumu listede de görünsün; yayıncı tek tek açıp bakmasın. -->
					<span v-if="announcement.status && announcement.status !== 'published'"
						class="nb-badge"
						:class="`nb-badge--status-${announcement.status}`">
						{{ statusLabel(announcement.status) }}
					</span>
					<span v-if="priorityLabel(announcement.priority)"
						class="nb-badge"
						:class="`nb-badge--p${announcement.priority}`">
						{{ priorityLabel(announcement.priority) }}
					</span>
					<span v-if="announcement.category" class="nb-badge nb-badge--category">
						{{ announcement.category }}
					</span>
					<span class="nb-item__date">{{ formatRelative(announcement.createdAt) }}</span>
				</span>
			</span>
		</a>
	</li>
</template>

<script>
import Pin from 'vue-material-design-icons/Pin.vue'
import { t } from '@nextcloud/l10n'

import AutoCover from './AutoCover.vue'
import { coverUrl } from '../api.js'
import { coverPosition, formatRelative, priorityLabel, statusLabel } from '../format.js'

export default {
	name: 'AnnouncementListItem',

	components: { AutoCover, Pin },

	props: {
		announcement: {
			type: Object,
			required: true,
		},
		active: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['select'],

	computed: {
		permalink() {
			return `#/announcement/${this.announcement.id}`
		},
	},

	methods: {
		t,
		coverPosition,
		coverUrl,
		formatRelative,
		priorityLabel,
		statusLabel,
	},
}
</script>

<style scoped>
.nb-item__link {
	display: flex;
	gap: 12px;
	align-items: center;
	padding: 10px 12px;
	border-radius: var(--border-radius-large, 12px);
	color: var(--color-main-text);
	text-decoration: none;
}

.nb-item__link:hover,
.nb-item__link:focus-visible {
	background-color: var(--color-background-hover);
}

.nb-item--active .nb-item__link {
	background-color: var(--color-primary-element-light);
}

.nb-item__thumb {
	flex: 0 0 auto;
	width: 44px;
	height: 44px;
	border-radius: var(--border-radius, 8px);
	object-fit: cover;
	background-color: var(--color-background-dark);
}

/* Otomatik kapak küçük resmin köşelerinden taşmasın. */
.nb-item__thumb--empty {
	display: block;
	overflow: hidden;
}

.nb-item__body {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.nb-item__top {
	display: flex;
	align-items: center;
	gap: 6px;
	min-width: 0;
}

.nb-item__pin {
	flex: 0 0 auto;
	color: var(--color-primary-element);
}

.nb-item__subject {
	overflow: hidden;
	font-weight: 600;
	white-space: nowrap;
	text-overflow: ellipsis;
}

.nb-item__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.nb-item__date {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.nb-badge {
	padding: 1px 8px;
	border-radius: 999px;
	font-size: 0.8em;
	line-height: 1.6;
	white-space: nowrap;
}

.nb-badge--category {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

/* Renkler Nextcloud değişkenlerinden gelir ki tema ve karanlık modla uyumlu kalsın.
   "Önemli" sunucunun kendi ana rengini kullanır; kırmızı yalnızca "Acil" için
   ayrılmıştır — her şey kırmızı olursa acil olanın anlamı kalmaz. */
.nb-badge--p1 {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
}

.nb-badge--p2 {
	background-color: var(--color-error);
	color: var(--color-primary-element-text, #fff);
}

/* Durum rozetleri (AnnouncementDetail ile aynı renkler). Taslak NC'nin uyarı
   rengi: ne "Önemli"nin ana rengi ne "Acil"in kırmızısıyla karışır. */
.nb-badge--status-draft {
	/* Tema değişkeni (--color-warning) koyu temada soluk kaldı: sabit, canlı amber + koyu yazı. */
	background-color: #ffb300;
	color: #1a1a1a;
	font-weight: 600;
}

.nb-badge--status-scheduled {
	border: 1px solid var(--color-primary-element);
	color: var(--color-primary-element);
}

.nb-badge--status-archived {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
</style>
