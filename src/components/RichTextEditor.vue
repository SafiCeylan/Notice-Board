<!--
  Zengin metin editörü (K4).

  Çıktısı HTML'dir ve doğrudan duyuru gövdesi olur. Buradaki araç çubuğu, sunucudaki
  HtmlSanitizer'ın izin listesiyle BİLİNÇLİ olarak aynı tutulmuştur: kullanıcıya
  yazdırıp sonra sunucuda sessizce silmek kötü bir deneyim olurdu.
  ⚠️ Yeni bir biçimlendirme eklersen HtmlSanitizer'a da eklemen gerekir.
-->
<template>
	<div class="nb-editor">
		<div class="nb-editor__toolbar" role="toolbar" :aria-label="t('noticeboard', 'Biçimlendirme')">
			<button v-for="tool in tools"
				:key="tool.key"
				type="button"
				class="nb-editor__tool"
				:class="{ 'nb-editor__tool--active': tool.isActive && tool.isActive() }"
				:title="tool.label"
				:aria-label="tool.label"
				@click="tool.run">
				<component :is="tool.icon" :size="18" />
			</button>

			<span class="nb-editor__sep" />

			<label class="nb-editor__color" :title="t('noticeboard', 'Yazı rengi')">
				<FormatColorText :size="18" />
				<input type="color" :value="currentColor" @input="setColor($event.target.value)">
			</label>

			<button type="button"
				class="nb-editor__tool"
				:class="{ 'nb-editor__tool--active': isActive('highlight') }"
				:title="t('noticeboard', 'Vurgula')"
				@click="toggle('toggleHighlight')">
				<Marker :size="18" />
			</button>

			<button type="button"
				class="nb-editor__tool"
				:title="t('noticeboard', 'Biçimi temizle')"
				@click="clearFormatting">
				<FormatClear :size="18" />
			</button>

			<span class="nb-editor__sep" />

			<button type="button"
				class="nb-editor__tool"
				:title="t('noticeboard', 'Görsel ekle')"
				:aria-label="t('noticeboard', 'Görsel ekle')"
				:disabled="uploading > 0"
				@click="$refs.imageInput.click()">
				<ImagePlusOutline :size="18" />
			</button>
			<input ref="imageInput"
				type="file"
				class="nb-editor__file"
				:accept="imageAccept"
				multiple
				@change="onImagesPicked">

			<span v-if="uploading > 0" class="nb-editor__status">
				{{ t('noticeboard', 'Görsel yükleniyor…') }}
			</span>
		</div>

		<p v-if="imageError" class="nb-editor__error" role="alert">
			{{ imageError }}
		</p>

		<!--
		  Bağlantı adresi satır içi alınır. Önce `window.prompt` kullanılıyordu:
		  tarayıcının kendi kutusu tema dışı görünüyor, bazı bağlamlarda hiç
		  açılmıyor ve klavye gezinmesini bozuyor.
		-->
		<div v-if="linkOpen" class="nb-editor__linkbar">
			<input ref="linkInput"
				v-model="linkDraft"
				type="url"
				class="nb-editor__linkinput"
				:placeholder="t('noticeboard', 'https://ornek.com')"
				@keydown.enter.prevent="applyLink"
				@keydown.esc.prevent="closeLink">
			<NcButton variant="primary" @click="applyLink">{{ t('noticeboard', 'Ekle') }}</NcButton>
			<NcButton v-if="isActive('link')" variant="tertiary" @click="removeLink">
				{{ t('noticeboard', 'Kaldır') }}
			</NcButton>
			<NcButton variant="tertiary" @click="closeLink">{{ t('noticeboard', 'Vazgeç') }}</NcButton>
		</div>

		<!--
		  Görsel seçiliyken (üstüne tıklanınca) açılan çubuk: yerleşim, boyut,
		  açıklama. Yalnız görsel seçiliyken görünür ki araç çubuğu kalabalıklaşmasın.
		-->
		<div v-if="selectedImage"
			class="nb-editor__imagebar"
			role="toolbar"
			:aria-label="t('noticeboard', 'Görsel yerleşimi')">
			<div class="nb-editor__group">
				<button v-for="option in alignOptions"
					:key="option.key"
					type="button"
					class="nb-editor__chip"
					:class="{ 'nb-editor__chip--on': selectedImage.align === option.value }"
					:aria-pressed="selectedImage.align === option.value ? 'true' : 'false'"
					:title="option.hint"
					@click="setImageAlign(option.value)">
					<component :is="option.icon" :size="18" />
					<span>{{ option.label }}</span>
				</button>
			</div>

			<div class="nb-editor__group">
				<span class="nb-editor__grouplabel">{{ t('noticeboard', 'Boyut') }}</span>
				<button v-for="option in sizeOptions"
					:key="option.key"
					type="button"
					class="nb-editor__chip"
					:class="{ 'nb-editor__chip--on': selectedImage.width === option.value }"
					:aria-pressed="selectedImage.width === option.value ? 'true' : 'false'"
					@click="setImageWidth(option.value)">
					{{ option.label }}
				</button>
				<span v-if="selectedImage.width" class="nb-editor__grouplabel">%{{ selectedImage.width }}</span>
			</div>

			<div class="nb-editor__group nb-editor__group--grow">
				<input class="nb-editor__alt"
					type="text"
					:value="selectedImage.alt"
					:maxlength="maxImageAlt"
					:placeholder="t('noticeboard', 'Görsel açıklaması (ekran okuyucular için)')"
					:aria-label="t('noticeboard', 'Görsel açıklaması (ekran okuyucular için)')"
					@input="setImageAlt($event.target.value)">
				<button type="button"
					class="nb-editor__tool"
					:title="t('noticeboard', 'Görseli kaldır')"
					:aria-label="t('noticeboard', 'Görseli kaldır')"
					@click="removeImage">
					<DeleteOutline :size="18" />
				</button>
			</div>

			<p class="nb-editor__hint">
				{{ t('noticeboard', 'Köşedeki tutamağı sürükleyerek de boyutlandırabilirsiniz. Görseli tutup başka bir yere taşıyabilirsiniz.') }}
			</p>
		</div>

		<EditorContent class="nb-editor__content nb-rich" :editor="editor" />
	</div>
</template>

<script>
import { Editor, EditorContent, Node } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { Highlight } from '@tiptap/extension-highlight'
import { Color, TextStyle } from '@tiptap/extension-text-style'
import NcButton from '@nextcloud/vue/components/NcButton'
import { getCurrentUser } from '@nextcloud/auth'

import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import FormatBold from 'vue-material-design-icons/FormatBold.vue'
import FormatClear from 'vue-material-design-icons/FormatClear.vue'
import FormatColorText from 'vue-material-design-icons/FormatColorText.vue'
import FormatFloatCenter from 'vue-material-design-icons/FormatFloatCenter.vue'
import FormatFloatLeft from 'vue-material-design-icons/FormatFloatLeft.vue'
import FormatFloatNone from 'vue-material-design-icons/FormatFloatNone.vue'
import FormatFloatRight from 'vue-material-design-icons/FormatFloatRight.vue'
import FormatHeader2 from 'vue-material-design-icons/FormatHeader2.vue'
import FormatHeader3 from 'vue-material-design-icons/FormatHeader3.vue'
import FormatItalic from 'vue-material-design-icons/FormatItalic.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import FormatQuoteClose from 'vue-material-design-icons/FormatQuoteClose.vue'
import FormatStrikethroughVariant from 'vue-material-design-icons/FormatStrikethroughVariant.vue'
import FormatUnderline from 'vue-material-design-icons/FormatUnderline.vue'
import ImagePlusOutline from 'vue-material-design-icons/ImagePlusOutline.vue'
import LinkIcon from 'vue-material-design-icons/Link.vue'
import Marker from 'vue-material-design-icons/Marker.vue'
import { t } from '@nextcloud/l10n'

import { inlineImageSrc, uploadInlineImage } from '../inlineImages.js'
import {
	FLOAT_DEFAULT_WIDTH,
	INLINE_IMAGE_TYPES,
	MAX_IMAGE_ALT,
	MAX_INLINE_IMAGE_BYTES,
	dragWidth,
	isInlineImageType,
	normalizeImageAlign,
	normalizeImageWidth,
} from '../inlineImageTools.js'
import '../richBody.css'

/**
 * Metne gömülü görsel. HTML'de `data-nb-file` + yerleşim (`data-nb-align`,
 * `data-nb-width`) + `alt` taşır — sunucudaki HtmlSanitizer da yalnız bunları,
 * değerlerini doğrulayarak geçirir; `src` gösterim anında eklenir. Dış adresli
 * `<img>` (başka siteden yapıştırılan) bu kurala uymadığı için editöre hiç girmez.
 */
const NbImage = Node.create({
	name: 'nbImage',
	group: 'block',
	atom: true,
	draggable: true,
	selectable: true,

	addOptions() {
		return { resolveSrc: () => '' }
	},

	addAttributes() {
		return {
			fileId: {
				default: null,
				parseHTML: element => Number(element.getAttribute('data-nb-file')) || null,
				renderHTML: attributes => ({ 'data-nb-file': attributes.fileId }),
			},
			align: {
				default: null,
				parseHTML: element => normalizeImageAlign(element.getAttribute('data-nb-align')),
				renderHTML: attributes => attributes.align ? { 'data-nb-align': attributes.align } : {},
			},
			width: {
				default: null,
				parseHTML: element => normalizeImageWidth(element.getAttribute('data-nb-width')),
				renderHTML: attributes => attributes.width ? { 'data-nb-width': attributes.width } : {},
			},
			alt: {
				default: '',
				parseHTML: element => element.getAttribute('alt') ?? '',
				renderHTML: attributes => attributes.alt?.trim() ? { alt: attributes.alt.trim() } : {},
			},
		}
	},

	parseHTML() {
		return [{ tag: 'img[data-nb-file]' }]
	},

	renderHTML({ HTMLAttributes }) {
		return ['img', HTMLAttributes]
	},

	/*
	 * Kutu (`.nb-box`) yerleşim özniteliklerini taşır, böylece okuma görünümüyle
	 * aynı CSS (richBody.css) uygulanır. Köşedeki tutamak sürüklenince genişlik
	 * canlı değişir, bırakınca belgeye tek adımda yazılır (geri al tek adım).
	 */
	addNodeView() {
		return ({ node, getPos, editor }) => {
			let current = node

			const box = document.createElement('div')
			box.className = 'nb-box nb-editor__box'
			const img = document.createElement('img')
			img.src = this.options.resolveSrc(node.attrs.fileId)
			img.draggable = false
			const handle = document.createElement('span')
			handle.className = 'nb-editor__handle'
			handle.title = t('noticeboard', 'Boyutlandırmak için sürükleyin')
			box.append(img, handle)

			const show = (align, width) => {
				align ? box.setAttribute('data-nb-align', align) : box.removeAttribute('data-nb-align')
				width ? box.setAttribute('data-nb-width', width) : box.removeAttribute('data-nb-width')
				box.classList.toggle('nb-editor__box--right', align === 'right')
			}
			const apply = next => {
				current = next
				show(next.attrs.align, next.attrs.width)
				img.alt = next.attrs.alt ?? ''
			}
			apply(node)

			handle.addEventListener('pointerdown', event => {
				event.preventDefault()
				event.stopPropagation()
				handle.setPointerCapture(event.pointerId)

				const startX = event.clientX
				const startPx = box.getBoundingClientRect().width
				const containerPx = editor.view.dom.clientWidth
				const { align } = current.attrs
				let width = current.attrs.width

				const move = moveEvent => {
					width = dragWidth({ startPx, deltaPx: moveEvent.clientX - startX, containerPx, align }) ?? width
					show(align, width)
				}
				const up = () => {
					handle.removeEventListener('pointermove', move)
					handle.removeEventListener('pointerup', up)
					handle.removeEventListener('pointercancel', up)
					const pos = getPos()
					if (typeof pos === 'number' && width !== current.attrs.width) {
						editor.chain().setNodeSelection(pos).updateAttributes('nbImage', { width }).run()
					} else {
						show(current.attrs.align, current.attrs.width)
					}
				}
				handle.addEventListener('pointermove', move)
				handle.addEventListener('pointerup', up)
				handle.addEventListener('pointercancel', up)
			})

			return {
				dom: box,
				update: next => {
					if (next.type !== current.type || next.attrs.fileId !== current.attrs.fileId) {
						return false
					}
					apply(next)
					return true
				},
				// Tutamak olayları editöre gitmesin (seçim/sürükle-taşı başlamasın).
				stopEvent: event => event.target === handle,
				ignoreMutation: () => true,
			}
		}
	},
})

export default {
	name: 'RichTextEditor',

	components: {
		DeleteOutline,
		EditorContent,
		FormatClear,
		FormatColorText,
		ImagePlusOutline,
		Marker,
		NcButton,
	},

	props: {
		modelValue: {
			type: String,
			default: '',
		},
		/** Düzenlenen duyurunun kimliği; yeni duyuruda null. Görsel adresi için. */
		announcementId: {
			type: Number,
			default: null,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			editor: null,
			linkOpen: false,
			linkDraft: '',
			uploading: 0,
			imageError: '',
			imageAccept: INLINE_IMAGE_TYPES.join(','),
			maxImageAlt: MAX_IMAGE_ALT,
		}
	},

	computed: {
		/** Seçili görselin öznitelikleri; görsel seçili değilse null. */
		selectedImage() {
			const node = this.editor?.state.selection.node
			return node?.type.name === 'nbImage' ? node.attrs : null
		},

		alignOptions() {
			return [
				{ key: 'left', value: 'left', icon: FormatFloatLeft, label: t('noticeboard', 'Solda'), hint: t('noticeboard', 'Görsel solda, metin sağından akar') },
				{ key: 'center', value: 'center', icon: FormatFloatCenter, label: t('noticeboard', 'Ortada'), hint: t('noticeboard', 'Görsel ortada, kendi satırında') },
				{ key: 'right', value: 'right', icon: FormatFloatRight, label: t('noticeboard', 'Sağda'), hint: t('noticeboard', 'Görsel sağda, metin solundan akar') },
				{ key: 'none', value: null, icon: FormatFloatNone, label: t('noticeboard', 'Satırda'), hint: t('noticeboard', 'Görsel kendi satırında, sola yaslı') },
			]
		},

		sizeOptions() {
			return [
				{ key: 's', value: 25, label: t('noticeboard', 'Küçük') },
				{ key: 'm', value: 50, label: t('noticeboard', 'Orta boy') },
				{ key: 'l', value: 75, label: t('noticeboard', 'Büyük') },
				{ key: 'full', value: 100, label: t('noticeboard', 'Tam') },
				{ key: 'auto', value: null, label: t('noticeboard', 'Asıl boyut') },
			]
		},

		currentColor() {
			return this.editor?.getAttributes('textStyle')?.color || '#000000'
		},

		tools() {
			return [
				{ key: 'bold', icon: FormatBold, label: t('noticeboard', 'Kalın'), run: () => this.toggle('toggleBold'), isActive: () => this.isActive('bold') },
				{ key: 'italic', icon: FormatItalic, label: t('noticeboard', 'İtalik'), run: () => this.toggle('toggleItalic'), isActive: () => this.isActive('italic') },
				{ key: 'underline', icon: FormatUnderline, label: t('noticeboard', 'Altı çizili'), run: () => this.toggle('toggleUnderline'), isActive: () => this.isActive('underline') },
				{ key: 'strike', icon: FormatStrikethroughVariant, label: t('noticeboard', 'Üstü çizili'), run: () => this.toggle('toggleStrike'), isActive: () => this.isActive('strike') },
				{ key: 'h2', icon: FormatHeader2, label: t('noticeboard', 'Başlık'), run: () => this.heading(2), isActive: () => this.isActive('heading', { level: 2 }) },
				{ key: 'h3', icon: FormatHeader3, label: t('noticeboard', 'Alt başlık'), run: () => this.heading(3), isActive: () => this.isActive('heading', { level: 3 }) },
				{ key: 'bullet', icon: FormatListBulleted, label: t('noticeboard', 'Madde listesi'), run: () => this.toggle('toggleBulletList'), isActive: () => this.isActive('bulletList') },
				{ key: 'ordered', icon: FormatListNumbered, label: t('noticeboard', 'Numaralı liste'), run: () => this.toggle('toggleOrderedList'), isActive: () => this.isActive('orderedList') },
				{ key: 'quote', icon: FormatQuoteClose, label: t('noticeboard', 'Alıntı'), run: () => this.toggle('toggleBlockquote'), isActive: () => this.isActive('blockquote') },
				{ key: 'code', icon: CodeTags, label: t('noticeboard', 'Kod bloğu'), run: () => this.toggle('toggleCodeBlock'), isActive: () => this.isActive('codeBlock') },
				{ key: 'link', icon: LinkIcon, label: t('noticeboard', 'Bağlantı'), run: this.openLink, isActive: () => this.isActive('link') },
			]
		},
	},

	watch: {
		modelValue(value) {
			// Dışarıdan gelen değişikliği yansıt, ama kullanıcı yazarken imleci
			// bozmamak için içerik zaten aynıysa dokunma.
			if (this.editor && value !== this.editor.getHTML()) {
				this.editor.commands.setContent(value, false)
			}
		},
	},

	mounted() {
		this.editor = new Editor({
			content: this.modelValue,
			extensions: [
				// Link ve Underline StarterKit'in içinde geliyor (Tiptap 3).
				StarterKit.configure({
					link: {
						openOnClick: false,
						// Sunucudaki izin listesiyle aynı şemalar.
						protocols: ['http', 'https', 'mailto'],
					},
				}),
				TextStyle,
				Color,
				Highlight,
				NbImage.configure({
					resolveSrc: fileId => inlineImageSrc(this.announcementId, fileId),
				}),
			],
			editorProps: {
				// Panodan yapıştırılan ya da sürüklenen görsel dosyası yüklenir.
				handlePaste: (view, event) => this.takeImages(event.clipboardData?.files),
				handleDrop: (view, event, slice, moved) => !moved && this.takeImages(event.dataTransfer?.files),
			},
			onUpdate: ({ editor }) => {
				const html = editor.getHTML()
				// Boş editör '<p></p>' döndürür; bunu boş kabul et ki
				// "metin yok" durumu doğru görünsün.
				this.$emit('update:modelValue', html === '<p></p>' ? '' : html)
			},
		})
	},

	beforeUnmount() {
		this.editor?.destroy()
	},

	methods: {
		t,

		isActive(name, attrs) {
			return this.editor?.isActive(name, attrs) ?? false
		},

		toggle(command) {
			this.editor?.chain().focus()[command]().run()
		},

		heading(level) {
			this.editor?.chain().focus().toggleHeading({ level }).run()
		},

		setColor(color) {
			this.editor?.chain().focus().setColor(color).run()
		},

		clearFormatting() {
			this.editor?.chain().focus().unsetAllMarks().clearNodes().run()
		},

		openLink() {
			this.linkDraft = this.editor?.getAttributes('link')?.href ?? ''
			this.linkOpen = true
			this.$nextTick(() => this.$refs.linkInput?.focus())
		},

		closeLink() {
			this.linkOpen = false
			this.linkDraft = ''
			this.editor?.commands.focus()
		},

		applyLink() {
			const url = this.linkDraft.trim()

			if (url === '') {
				this.removeLink()
				return
			}

			this.editor?.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
			this.closeLink()
		},

		removeLink() {
			this.editor?.chain().focus().extendMarkRange('link').unsetLink().run()
			this.closeLink()
		},

		setImageAlign(align) {
			const attrs = { align }
			// Asıl boyutundaki görsel yana alınınca çoğu zaman tüm satırı kaplar,
			// metne yer kalmaz; makul bir genişlikle başlasın. Durum `selectedImage`'dan
			// değil editörden okunur: Tiptap'in tepkisel kopyası bir kare geriden gelir,
			// art arda tıklamada bir önceki genişliği görürdü.
			const current = this.editor?.view.state.selection.node
			if ((align === 'left' || align === 'right') && !current?.attrs.width) {
				attrs.width = FLOAT_DEFAULT_WIDTH
			}
			this.editor?.chain().focus().updateAttributes('nbImage', attrs).run()
		},

		setImageWidth(width) {
			this.editor?.chain().focus().updateAttributes('nbImage', { width: normalizeImageWidth(width) }).run()
		},

		/** Yazarken odak açıklama kutusunda kalsın: focus() yok. */
		setImageAlt(alt) {
			this.editor?.commands.updateAttributes('nbImage', { alt: alt.slice(0, MAX_IMAGE_ALT) })
		},

		removeImage() {
			this.editor?.chain().focus().deleteSelection().run()
		},

		/** Yeni yüklenen görseli seç: yerleşim çubuğu hemen açılsın. */
		selectImage(fileId) {
			let found = null
			this.editor?.state.doc.descendants((node, pos) => {
				if (found === null && node.type.name === 'nbImage' && node.attrs.fileId === fileId) {
					found = pos
				}
				return found === null
			})
			if (found !== null) {
				this.editor.chain().focus().setNodeSelection(found).run()
			}
		},

		onImagesPicked(event) {
			this.takeImages(event.target.files)
			// Aynı dosya yeniden seçilebilsin.
			event.target.value = ''
		},

		/** @return {boolean} olayı biz mi karşıladık (yapıştır/bırak için) */
		takeImages(fileList) {
			const files = Array.from(fileList ?? []).filter(file => file.type.startsWith('image/'))
			if (files.length === 0) {
				return false
			}
			this.uploadImages(files)
			return true
		},

		async uploadImages(files) {
			this.imageError = ''
			const uid = getCurrentUser()?.uid

			for (const file of files) {
				if (!isInlineImageType(file.type)) {
					this.imageError = t('noticeboard', 'Bu görsel türü desteklenmiyor: {name}', { name: file.name })
					continue
				}
				if (file.size > MAX_INLINE_IMAGE_BYTES) {
					this.imageError = t('noticeboard', 'Görsel çok büyük (en fazla 20 MB): {name}', { name: file.name })
					continue
				}

				this.uploading++
				try {
					const fileId = await uploadInlineImage(uid, file)
					this.editor?.chain().focus().insertContent({ type: 'nbImage', attrs: { fileId } }).run()
					this.selectImage(fileId)
				} catch (error) {
					console.error('noticeboard: inline image upload failed', error)
					this.imageError = t('noticeboard', 'Görsel yüklenemedi: {name}', { name: file.name })
				} finally {
					this.uploading--
				}
			}
		},
	},
}
</script>

<style scoped>
.nb-editor {
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
}

.nb-editor:focus-within {
	border-color: var(--color-primary-element);
}

.nb-editor__toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
	align-items: center;
	padding: 4px 6px;
	border-bottom: 1px solid var(--color-border);
}

.nb-editor__tool {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	padding: 0;
	border: none;
	border-radius: var(--border-radius, 8px);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.nb-editor__tool:hover {
	background-color: var(--color-background-hover);
}

.nb-editor__tool--active {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.nb-editor__sep {
	width: 1px;
	height: 20px;
	margin: 0 4px;
	background-color: var(--color-border);
}

.nb-editor__color {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: var(--border-radius, 8px);
	cursor: pointer;
}

.nb-editor__color:hover {
	background-color: var(--color-background-hover);
}

/* Renk girdisi görünmez; tıklama alanı ikonun kendisi. */
.nb-editor__color input {
	position: absolute;
	width: 32px;
	height: 32px;
	opacity: 0;
	cursor: pointer;
}

.nb-editor__tool:disabled {
	opacity: .5;
	cursor: default;
}

.nb-editor__file {
	display: none;
}

.nb-editor__status {
	margin-left: 4px;
	color: var(--color-text-maxcontrast);
	font-size: .9em;
}

.nb-editor__error {
	margin: 0;
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border);
	color: var(--color-error);
}

/* Görsel kutusunun yerleşimi: src/richBody.css (okuma görünümüyle ortak). */
.nb-editor__content :deep(.nb-editor__box) {
	cursor: grab;
}

.nb-editor__content :deep(.ProseMirror-selectednode) {
	outline: 3px solid var(--color-primary-element);
	outline-offset: 2px;
}

/* Köşe tutamağı yalnız seçili görselde; sağa yaslı görselde sol altta. */
.nb-editor__content :deep(.nb-editor__handle) {
	position: absolute;
	right: -7px;
	bottom: -7px;
	display: none;
	width: 16px;
	height: 16px;
	border: 2px solid var(--color-main-background);
	border-radius: 50%;
	background-color: var(--color-primary-element);
	cursor: nwse-resize;
	touch-action: none;
}

.nb-editor__content :deep(.nb-editor__box--right .nb-editor__handle) {
	right: auto;
	left: -7px;
	cursor: nesw-resize;
}

.nb-editor__content :deep(.ProseMirror-selectednode .nb-editor__handle) {
	display: block;
}

.nb-editor__imagebar {
	display: flex;
	flex-wrap: wrap;
	gap: 6px 14px;
	align-items: center;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.nb-editor__group {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	align-items: center;
}

.nb-editor__group--grow {
	flex: 1 1 220px;
	min-width: 0;
}

.nb-editor__grouplabel {
	color: var(--color-text-maxcontrast);
	font-size: .9em;
}

.nb-editor__chip {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	min-height: 30px;
	padding: 0 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-pill, 16px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: .9em;
	cursor: pointer;
}

.nb-editor__chip:hover {
	border-color: var(--color-primary-element);
}

.nb-editor__chip--on {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.nb-editor__alt {
	flex: 1 1 auto;
	min-width: 0;
	min-height: 30px;
	margin: 0;
	padding: 2px 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.nb-editor__hint {
	flex-basis: 100%;
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: .85em;
}

.nb-editor__linkbar {
	display: flex;
	gap: 6px;
	align-items: center;
	padding: 6px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.nb-editor__linkinput {
	flex: 1 1 auto;
	min-width: 0;
	min-height: 34px;
	padding: 4px 10px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.nb-editor__linkinput:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.nb-editor__content {
	min-height: 240px;
	max-height: 50vh;
	overflow-y: auto;
	padding: 12px;
}

/*
 * ⚠️ Nextcloud çekirdek CSS'i `div[contenteditable=true]` öğelerine metin kutusu
 * biçimi veriyor: sabit `width: 130px`, çerçeve, dolgu ve gölge. ProseMirror'ın
 * yazma alanı tam olarak öyle bir öğe olduğu için editör küçük bir kutuya sıkışıyordu
 * (23 Eyl 2026'da kullanıcı bildirdi). Bu kurallar burada geri alınıyor.
 */
.nb-editor__content :deep(.ProseMirror) {
	width: 100%;
	min-width: 0;
	min-height: 220px;
	margin: 0;
	padding: 0;
	border: none;
	border-radius: 0;
	background-color: transparent;
	box-shadow: none;
	outline: none;
}

.nb-editor__content :deep(.ProseMirror:focus),
.nb-editor__content :deep(.ProseMirror:focus-visible),
.nb-editor__content :deep(.ProseMirror:hover) {
	border: none;
	box-shadow: none;
	outline: none;
}

.nb-editor__content :deep(.ProseMirror p) {
	margin: 0 0 10px;
}

.nb-editor__content :deep(.ProseMirror ul),
.nb-editor__content :deep(.ProseMirror ol) {
	margin: 0 0 10px;
	padding-left: 24px;
	list-style: revert;
}

.nb-editor__content :deep(.ProseMirror blockquote) {
	margin: 0 0 10px;
	padding-left: 12px;
	border-left: 4px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.nb-editor__content :deep(.ProseMirror pre) {
	padding: 10px;
	border-radius: var(--border-radius, 8px);
	background-color: var(--color-background-dark);
}

.nb-editor__content :deep(.ProseMirror a) {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
