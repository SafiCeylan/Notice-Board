/**
 * Metne gömülü görseller: adres üretimi ve yükleme.
 *
 * Görsel, yükleyenin KENDİ dosyalarına yazılır (kapakla aynı gerekçe, bkz.
 * cover/uploadCover.js) ve sunucu onu duyuru görünürlüğüyle servis eder.
 */

import { generateUrl } from '@nextcloud/router'
import { t } from '@nextcloud/l10n'

import { fitOutputSize, outputType } from './cover/imageTools.js'
import { uploadCover } from './cover/uploadCover.js'
import { extensionFor, inlineImageName } from './inlineImageTools.js'

/**
 * Bu oturumda yüklenen görsellerin yerel kopyası (dosya kimliği → blob adresi).
 * Henüz kaydedilmemiş duyurunun sunucu adresi yok (kimliği 0); editör ve
 * önizleme görseli buradan gösterir.
 */
const localUrls = new Map()

export const inlineImageFolder = () => t('noticeboard', 'Duyuru görselleri')

/**
 * @param {number} announcementId kaydedilmemişse 0/null
 * @param {number} fileId
 */
export const inlineImageSrc = (announcementId, fileId) => {
	if (localUrls.has(fileId)) {
		return localUrls.get(fileId)
	}
	if (announcementId > 0) {
		return generateUrl('/apps/noticeboard/announcements/{id}/images/{fileId}', { id: announcementId, fileId })
	}

	// Kaydedilmemiş taslakta yalnız yükleyenin kendi dosyası olabilir: önizleme ucu yeter.
	return generateUrl('/core/preview?fileId={fileId}&x=1600&y=1600&a=1', { fileId })
}

/** Büyük fotoğrafı en uzun kenarı 1920 px olacak şekilde küçültür; GIF'e dokunmaz (animasyon). */
const shrink = async file => {
	if (file.type === 'image/gif' || typeof createImageBitmap !== 'function') {
		return { blob: file, extension: extensionFor(file.type) }
	}

	let bitmap
	try {
		bitmap = await createImageBitmap(file)
	} catch (error) {
		// Tarayıcı çözemiyorsa (ör. eski tarayıcıda AVIF) olduğu gibi yükle.
		return { blob: file, extension: extensionFor(file.type) }
	}

	const size = fitOutputSize(bitmap.width, bitmap.height)
	if (size.width === bitmap.width && size.height === bitmap.height) {
		bitmap.close?.()
		return { blob: file, extension: extensionFor(file.type) }
	}

	const canvas = document.createElement('canvas')
	canvas.width = size.width
	canvas.height = size.height
	canvas.getContext('2d').drawImage(bitmap, 0, 0, size.width, size.height)
	bitmap.close?.()

	const type = outputType(file.type)
	const blob = await new Promise(resolve => canvas.toBlob(resolve, type.mime, type.quality))

	return blob ? { blob, extension: type.extension } : { blob: file, extension: extensionFor(file.type) }
}

/**
 * Görseli yükleyenin dosyalarına yükler ve dosya kimliğini döner.
 *
 * @param {string} uid oturumdaki kullanıcı
 * @param {File} file
 * @return {Promise<number>}
 */
export const uploadInlineImage = async (uid, file) => {
	const { blob, extension } = await shrink(file)
	const fileId = await uploadCover(uid, inlineImageFolder(), inlineImageName(file.name, extension), blob)
	localUrls.set(fileId, URL.createObjectURL(blob))

	return fileId
}
