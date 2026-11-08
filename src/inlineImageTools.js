/**
 * Metne gömülü görsellerin saf yardımcıları (Nextcloud'a bağımlı değil; node
 * testleri doğrudan yükler: tests/js/inlineImageTools.test.mjs).
 *
 * Gövdede görsel `<img data-nb-file="42" ...>` olarak durur, `src` taşımaz
 * (bkz. lib/Service/InlineImages.php). Adres gösterim anında eklenir.
 */

/** Sunucunun kabul ettiği türler — UserFileService::IMAGE_TYPES ile AYNI. */
export const INLINE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif']

/** Tarayıcıda işlenecek en büyük dosya; daha büyüğü belleği şişirir. */
export const MAX_INLINE_IMAGE_BYTES = 20 * 1024 * 1024

export const isInlineImageType = mime => INLINE_IMAGE_TYPES.includes(String(mime ?? '').toLowerCase())

/*
 * Görsel yerleşimi (27 Eki 2026). Gövdede `data-nb-align` + `data-nb-width`
 * olarak durur; sunucu (HtmlSanitizer) aynı listeyi uygular, biçimi
 * src/richBody.css verir. Değer yoksa görsel eskisi gibi kendi satırında.
 */

/** left/right = metin yanından akar; center = ortada, tek başına. */
export const IMAGE_ALIGNS = ['left', 'center', 'right']

export const IMAGE_WIDTH_MIN = 10
export const IMAGE_WIDTH_MAX = 100
export const IMAGE_WIDTH_STEP = 5

/** Yana alınan görselin genişliği seçilmemişse (asıl boyut çoğu zaman tüm satırı kaplar). */
export const FLOAT_DEFAULT_WIDTH = 40

/** HtmlSanitizer::MAX_IMAGE_ALT ile AYNI. */
export const MAX_IMAGE_ALT = 200

export const normalizeImageAlign = value => IMAGE_ALIGNS.includes(value) ? value : null

/** Yüzde genişlik: 5'in katına yuvarlanır, 10–100'e sıkıştırılır; boş/geçersiz → null (asıl boyut). */
export const normalizeImageWidth = value => {
	if (value === null || value === undefined || value === '') {
		return null
	}
	const number = Number(value)
	if (!Number.isFinite(number)) {
		return null
	}
	const snapped = Math.round(number / IMAGE_WIDTH_STEP) * IMAGE_WIDTH_STEP

	return Math.min(IMAGE_WIDTH_MAX, Math.max(IMAGE_WIDTH_MIN, snapped))
}

/**
 * Köşe tutamağı sürüklenince yeni genişlik (yüzde).
 * Sağa yaslı görselin tutamağı sol alttadır: sola çekmek büyütür.
 * Ortadaki görsel iki yana birden büyür: fare kayması iki kez sayılır.
 */
export const dragWidth = ({ startPx, deltaPx, containerPx, align }) => {
	if (!(containerPx > 0)) {
		return null
	}
	const factor = align === 'right' ? -1 : align === 'center' ? 2 : 1

	return normalizeImageWidth(((startPx + deltaPx * factor) / containerPx) * 100)
}

/**
 * Kayıtlı gövdedeki görsellere `src` ekler. Adres yalnız rakamlardan oluşan
 * kimlikten üretildiği için çıktıya yeni bir saldırı yüzeyi girmez.
 *
 * @param {string} html sunucuda temizlenmiş gövde
 * @param {(fileId: number) => string} srcFor
 */
export const withImageSources = (html, srcFor) => String(html ?? '').replace(
	/<img\b([^>]*?)\sdata-nb-file="(\d+)"([^>]*)>/gi,
	(whole, before, fileId, after) => {
		// Tırnak içleri (açıklama metni) "src=" geçse bile öznitelik sayılmaz.
		if (/\ssrc\s*=/i.test((before + after).replace(/"[^"]*"|'[^']*'/g, '""'))) {
			return whole
		}
		const src = String(srcFor(Number(fileId))).replace(/"/g, '&quot;')

		return `<img${before} data-nb-file="${fileId}"${after} src="${src}" loading="lazy">`
	},
)

const pad = n => String(n).padStart(2, '0')

/** "<asıl ad>-20261020-142530.<uzantı>": aynı adla iki yükleme birbirini ezmesin. */
export const inlineImageName = (originalName, extension, date = new Date()) => {
	const base = String(originalName ?? '')
		.replace(/\.[^./\\]*$/, '')
		.replace(/[\\/:*?"<>|#%\u0000-\u001f]+/g, ' ')
		.replace(/\s+/g, ' ')
		.trim()
		.slice(0, 60)
		.trim() || 'gorsel'
	const stamp = `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}`
		+ `-${pad(date.getHours())}${pad(date.getMinutes())}${pad(date.getSeconds())}`

	return `${base}-${stamp}.${extension}`
}

/** Dosya türünden uzantı (ad uzantısız yapıştırılan ekran görüntüleri için). */
export const extensionFor = mime => ({
	'image/jpeg': 'jpg',
	'image/png': 'png',
	'image/gif': 'gif',
	'image/webp': 'webp',
	'image/avif': 'avif',
})[String(mime ?? '').toLowerCase()] ?? 'png'
