<!--
  Duyuru detayı: kapak, başlık, üstveri, zengin metin gövde ve ekler.

  ⚠️ Gövde `v-html` ile basılır. Bu GÜVENLİ, çünkü içerik sunucuda yazılırken
  HtmlSanitizer'dan geçiyor (bkz. lib/Service/HtmlSanitizer.php ve testleri).
  Buraya sanitize edilmemiş bir kaynaktan HTML BAĞLAMA.
-->
<template>
	<article class="nb-detail">
		<img v-if="announcement.coverFileId"
			class="nb-detail__cover"
			:src="coverSrc || coverUrl(announcement.id)"
			:style="{ objectPosition: coverPosition(announcement) }"
			alt="">

		<header class="nb-detail__header">
			<div class="nb-detail__badges">
				<span v-if="announcement.pinned" class="nb-badge nb-badge--pin">
					{{ t('noticeboard', 'Sabitlenmiş') }}
				</span>
				<span v-if="priorityLabel(announcement.priority)"
					class="nb-badge"
					:class="`nb-badge--p${announcement.priority}`">
					{{ priorityLabel(announcement.priority) }}
				</span>
				<span v-if="announcement.category" class="nb-badge nb-badge--category">
					{{ announcement.category }}
				</span>
				<span v-if="announcement.status !== 'published'"
					class="nb-badge"
					:class="`nb-badge--status-${announcement.status}`">
					{{ statusLabel(announcement.status) }}
				</span>
			</div>

			<h2 class="nb-detail__title">{{ announcement.subject }}</h2>

			<!-- Yazar ETİKETLİ gösterilir: çıplak ad, alıcı listesi sanılıyordu (7 Eki 2026). -->
			<p class="nb-detail__meta">
				<span>{{ t('noticeboard', 'Yayınlayan') }}: {{ announcement.authorName || announcement.authorUid }}</span>
				<span aria-hidden="true">·</span>
				<time :datetime="isoDate">{{ formatDateTime(announcement.createdAt) }}</time>
			</p>

			<p v-if="scheduleNote" class="nb-detail__schedule">{{ scheduleNote }}</p>
		</header>

		<!-- eslint-disable-next-line vue/no-v-html -- sunucuda sanitize ediliyor -->
		<div v-if="announcement.message" class="nb-detail__body nb-rich" v-html="bodyHtml" />
		<p v-else class="nb-detail__empty">{{ t('noticeboard', 'Bu duyurunun metni yok.') }}</p>

		<section v-if="announcement.attachments.length" class="nb-detail__attachments">
			<h3>{{ t('noticeboard', 'Ekler') }}</h3>
			<ul>
				<li v-for="file in announcement.attachments" :key="file.id">
					<a :href="attachmentUrl(announcement.id, file.id)" target="_blank" rel="noopener noreferrer">
						<Paperclip :size="16" />
						{{ file.fileName || t('noticeboard', 'Dosya') }}
					</a>
				</li>
			</ul>
		</section>

		<!-- Hedef gruplar duyuruda GÖSTERİLMEZ (kullanıcı isteği, 7 Eki 2026): okuyucu
		     için anlamsız ve kurumun grup yapısını açığa çıkarıyordu. Sunucu da grup
		     adlarını yalnız duyuruyu düzenleyebilene gönderir (AnnouncementService::forViewer). -->

		<AnnouncementComments v-if="showComments" :announcement-id="announcement.id" />
	</article>
</template>

<script>
import Paperclip from 'vue-material-design-icons/Paperclip.vue'
import { t } from '@nextcloud/l10n'

import AnnouncementComments from './AnnouncementComments.vue'
import { attachmentUrl, coverUrl } from '../api.js'
import { inlineImageSrc } from '../inlineImages.js'
import { withImageSources } from '../inlineImageTools.js'
import '../richBody.css'
import { coverPosition, formatDateTime, priorityLabel, statusLabel } from '../format.js'

export default {
	name: 'AnnouncementDetail',

	components: { AnnouncementComments, Paperclip },

	props: {
		announcement: {
			type: Object,
			required: true,
		},
		/**
		 * Kapak adresini dışarıdan vermek için: oluşturma ekranının önizlemesinde
		 * kapak henüz kaydedilmemiş olabilir, duyurunun kendi ucundan çekilemez.
		 */
		coverSrc: {
			type: String,
			default: '',
		},
	},

	computed: {
		/** Gövdedeki görseller adressiz kaydedilir; adres burada eklenir (InlineImages). */
		bodyHtml() {
			return withImageSources(this.announcement.message, fileId => inlineImageSrc(this.announcement.id, fileId))
		},

		isoDate() {
			return new Date(this.announcement.createdAt * 1000).toISOString()
		},

		/**
		 * Yorum bölümü iki koşulda gizlenir:
		 *  · duyuruda yorumlar kapalıysa (K6)
		 *  · bu bileşen oluşturma ekranının ÖNİZLEMESİ olarak kullanılıyorsa
		 *    (henüz kaydedilmemiş duyurunun id'si 0'dır, yorum çekilemez)
		 */
		showComments() {
			return this.announcement.options?.comments === true && this.announcement.id > 0
		},

		/** Taslak/zamanlanmış duyurularda yayıncıya durumu açıkça söyler. */
		scheduleNote() {
			const { status, publishAt, deleteAt } = this.announcement

			if (status === 'scheduled' && publishAt) {
				return t('noticeboard', 'Bu duyuru {date} tarihinde yayınlanacak.', {
					date: formatDateTime(publishAt),
				})
			}

			if (deleteAt) {
				return t('noticeboard', 'Bu duyuru {date} tarihinde yayından kalkacak.', {
					date: formatDateTime(deleteAt),
				})
			}

			return ''
		},
	},

	methods: {
		t,
		coverUrl,
		coverPosition,
		attachmentUrl,
		formatDateTime,
		priorityLabel,
		statusLabel,
	},
}
</script>

<style scoped>
.nb-detail {
	max-width: 760px;
	margin: 0 auto;
	padding: 24px 16px 64px;
}

/*
 * Vitrinle AYNI 16:9 çerçeve: kapak düzenleyicisi bu orana kırpıyor, burada da
 * hiçbir kenarı kesilmeden görünür. Eskiden yükseklik 320 px'e sabitti ve geniş
 * bant olarak üstü/altı kırpılıyordu — yayıncının gördüğüyle okuyucunun gördüğü
 * ayrışıyordu.
 */
.nb-detail__cover {
	display: block;
	width: 100%;
	aspect-ratio: 16 / 9;
	margin-bottom: 20px;
	border-radius: var(--border-radius-large, 12px);
	object-fit: cover;
}

.nb-detail__badges {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 8px;
}

.nb-detail__title {
	margin: 0 0 6px;
	font-size: 1.6em;
	line-height: 1.25;
}

.nb-detail__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0;
	color: var(--color-text-maxcontrast);
}

/* Zamanlama notu, sunucunun ana rengiyle vurgulanır — yayıncının gözden
   kaçırmaması gereken bilgi. */
.nb-detail__schedule {
	margin: 12px 0 0;
	padding: 8px 12px;
	border-left: 3px solid var(--color-primary-element);
	border-radius: var(--border-radius, 8px);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text, var(--color-main-text));
}

.nb-detail__body {
	margin-top: 24px;
	line-height: 1.6;
	overflow-wrap: anywhere;
}

/* Gövde HTML'i bileşen dışından geldiği için :deep gerekiyor. */
.nb-detail__body :deep(h1),
.nb-detail__body :deep(h2),
.nb-detail__body :deep(h3),
.nb-detail__body :deep(h4) {
	margin: 20px 0 8px;
	line-height: 1.3;
}

.nb-detail__body :deep(p) {
	margin: 0 0 12px;
}

.nb-detail__body :deep(ul),
.nb-detail__body :deep(ol) {
	margin: 0 0 12px;
	padding-left: 24px;
	list-style: revert;
}

.nb-detail__body :deep(blockquote) {
	margin: 0 0 12px;
	padding-left: 14px;
	border-left: 4px solid var(--color-primary-element);
	color: var(--color-text-maxcontrast);
}

.nb-detail__body :deep(pre) {
	padding: 12px;
	overflow-x: auto;
	border-radius: var(--border-radius, 8px);
	background-color: var(--color-background-dark);
}

/* Gövdedeki görseller: src/richBody.css (editörle ortak). */

.nb-detail__body :deep(a) {
	color: var(--color-primary-element);
}

.nb-detail__empty {
	margin-top: 24px;
	color: var(--color-text-maxcontrast);
}

.nb-detail__attachments {
	margin-top: 32px;
}

.nb-detail__attachments h3 {
	margin: 0 0 8px;
	font-size: 1em;
}

.nb-detail__attachments ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.nb-detail__attachments a {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	padding: 6px 0;
	color: var(--color-primary-element);
}

.nb-badge {
	padding: 2px 10px;
	border-radius: 999px;
	font-size: 0.85em;
}

.nb-badge--category,
.nb-badge--status-archived {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

/* Taslak NC'nin uyarı rengi: "Önemli" (ana renk) ve "Acil" (kırmızı) ile karışmaz.
   Liste satırı (AnnouncementListItem) aynı renkleri kullanır. */
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

.nb-badge--pin {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text, var(--color-main-text));
}

.nb-badge--p1 {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
}

.nb-badge--p2 {
	background-color: var(--color-error);
	color: var(--color-primary-element-text, #fff);
}
</style>
