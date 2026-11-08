<!--
  Duyuru yorumları (K6 · opt_comments).

  Yorumlar Nextcloud'un kendi altyapısında tutulur. Bu bileşen yalnızca
  `options.comments` açıkken gösterilir; kapalıyken sunucu da yazmayı reddeder,
  yani gizlemek bir kolaylıktır, güvenlik değil.
-->
<template>
	<section class="nb-comments">
		<h3 class="nb-comments__title">
			{{ t('noticeboard', 'Yorumlar') }}
			<span v-if="comments.length" class="nb-comments__count">{{ comments.length }}</span>
		</h3>

		<div class="nb-comments__compose">
			<textarea v-model="draft"
				class="nb-comments__input"
				rows="2"
				:maxlength="1000"
				:placeholder="t('noticeboard', 'Bir yorum yazın…')"
				:disabled="sending"
				@keydown.ctrl.enter="submit" />
			<div class="nb-comments__compose-actions">
				<span class="nb-comments__hint">{{ t('noticeboard', 'Göndermek için Ctrl+Enter') }}</span>
				<NcButton variant="primary" :disabled="!canSend" @click="submit">
					<template #icon><Send :size="18" /></template>
					{{ t('noticeboard', 'Gönder') }}
				</NcButton>
			</div>
		</div>

		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

		<NcLoadingIcon v-if="loading" class="nb-comments__loading" :size="28" />

		<p v-else-if="!comments.length" class="nb-comments__empty">
			{{ t('noticeboard', 'Henüz yorum yok. İlk yazan siz olun.') }}
		</p>

		<ul v-else class="nb-comments__list">
			<li v-for="comment in comments" :key="comment.id" class="nb-comment">
				<NcAvatar :user="comment.actorId" :display-name="comment.actorDisplayName" :size="32" />
				<div class="nb-comment__body">
					<div class="nb-comment__head">
						<span class="nb-comment__author">{{ comment.actorDisplayName }}</span>
						<span class="nb-comment__date">{{ formatRelative(comment.createdAt) }}</span>
						<NcButton v-if="comment.canDelete"
							variant="tertiary"
							class="nb-comment__delete"
							:aria-label="t('noticeboard', 'Yorumu sil')"
							@click="remove(comment)">
							<template #icon><Delete :size="16" /></template>
						</NcButton>
					</div>
					<p class="nb-comment__message">{{ comment.message }}</p>
				</div>
			</li>
		</ul>
	</section>
</template>

<script>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Delete from 'vue-material-design-icons/Delete.vue'
import Send from 'vue-material-design-icons/Send.vue'
import { t } from '@nextcloud/l10n'

import { createComment, deleteComment, fetchComments } from '../api.js'
import { formatRelative } from '../format.js'

export default {
	name: 'AnnouncementComments',

	components: { Delete, NcAvatar, NcButton, NcLoadingIcon, NcNoteCard, Send },

	props: {
		announcementId: {
			type: Number,
			required: true,
		},
	},

	data() {
		return {
			comments: [],
			draft: '',
			loading: true,
			sending: false,
			error: '',
		}
	},

	computed: {
		canSend() {
			return !this.sending && this.draft.trim().length > 0
		},
	},

	watch: {
		// Kullanıcı başka bir duyuruya geçtiğinde eski yorumlar ekranda kalmamalı.
		announcementId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,
		formatRelative,

		async load() {
			this.loading = true
			this.error = ''
			this.comments = []

			try {
				this.comments = await fetchComments(this.announcementId)
			} catch (e) {
				this.error = t('noticeboard', 'Yorumlar yüklenemedi.')
			} finally {
				this.loading = false
			}
		},

		async submit() {
			if (!this.canSend) {
				return
			}

			this.sending = true
			this.error = ''

			try {
				const created = await createComment(this.announcementId, this.draft.trim())
				// En yeni yorum üstte: sunucu da en yeniden eskiye sıralıyor.
				this.comments.unshift(created)
				this.draft = ''
			} catch (e) {
				this.error = e.response?.data?.ocs?.data?.message
					|| t('noticeboard', 'Yorum gönderilemedi.')
			} finally {
				this.sending = false
			}
		},

		async remove(comment) {
			const { showConfirmation } = await import('@nextcloud/dialogs')

			const onaylandi = await showConfirmation({
				name: t('noticeboard', 'Yorumu sil'),
				text: t('noticeboard', 'Bu yorum silinsin mi?'),
				labelConfirm: t('noticeboard', 'Sil'),
				labelReject: t('noticeboard', 'Vazgeç'),
				severity: 'error',
			})

			if (!onaylandi) {
				return
			}

			try {
				await deleteComment(this.announcementId, comment.id)
				this.comments = this.comments.filter(c => c.id !== comment.id)
			} catch (e) {
				this.error = t('noticeboard', 'Yorum silinemedi.')
			}
		},
	},
}
</script>

<style scoped>
.nb-comments {
	margin-top: 40px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}

.nb-comments__title {
	display: flex;
	gap: 8px;
	align-items: center;
	margin: 0 0 12px;
	font-size: 1.05em;
}

.nb-comments__count {
	padding: 0 8px;
	border-radius: 999px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text, var(--color-main-text));
	font-size: 0.85em;
}

.nb-comments__compose {
	margin-bottom: 20px;
}

.nb-comments__input {
	width: 100%;
	min-height: 60px;
	padding: 10px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
	resize: vertical;
}

.nb-comments__input:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.nb-comments__compose-actions {
	display: flex;
	gap: 12px;
	align-items: center;
	justify-content: flex-end;
	margin-top: 6px;
}

.nb-comments__hint,
.nb-comments__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.nb-comments__loading {
	margin: 20px auto;
}

.nb-comments__list {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.nb-comment {
	display: flex;
	gap: 10px;
}

.nb-comment__body {
	flex: 1 1 auto;
	min-width: 0;
}

.nb-comment__head {
	display: flex;
	gap: 8px;
	align-items: center;
}

.nb-comment__author {
	font-weight: 600;
}

.nb-comment__date {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.nb-comment__delete {
	margin-left: auto;
}

/* Yorum düz metindir; girilen satır sonları korunur ama HTML yorumlanmaz. */
.nb-comment__message {
	margin: 2px 0 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}
</style>
