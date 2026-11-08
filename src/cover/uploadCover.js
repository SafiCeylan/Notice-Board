/**
 * Düzenlenmiş kapağı yayıncının KENDİ dosyalarına yükler ve dosya kimliğini döner.
 *
 * Neden yayıncının dosyaları: kapak her zaman yazarın alanından servis ediliyor
 * (FileController::openAuthorFile). Ayrı bir depolama icat edilseydi okuma
 * yetkisi, silme ve yedek kuralları yeniden yazılmak zorundaydı. Asıl görsele
 * dokunulmaz; düzenlenen kopya ayrı bir klasöre yeni adla yazılır.
 */

import axios from '@nextcloud/axios'
import { generateRemoteUrl } from '@nextcloud/router'

import { parseOcFileId } from './imageTools.js'

const encodePath = path => path.split('/').map(encodeURIComponent).join('/')

const userRoot = uid => `${generateRemoteUrl('dav')}/files/${encodeURIComponent(uid)}`

/** Klasörü oluşturur; zaten varsa (405) sorun değil. */
const ensureFolder = async (uid, folder) => {
	try {
		await axios.request({ method: 'MKCOL', url: `${userRoot(uid)}/${encodePath(folder)}` })
	} catch (error) {
		if (error.response?.status !== 405) {
			throw error
		}
	}
}

/** `OC-FileId` başlığı gelmezse (vekil sunucu silmiş olabilir) kimliği PROPFIND ile sorar. */
const fetchFileId = async url => {
	const response = await axios.request({
		method: 'PROPFIND',
		url,
		headers: { Depth: '0', 'Content-Type': 'application/xml' },
		data: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">'
			+ '<d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const match = /<oc:fileid>(\d+)<\/oc:fileid>/.exec(String(response.data))

	return match ? Number(match[1]) : null
}

/**
 * @param {string} uid       yükleyen (oturumdaki) kullanıcı
 * @param {string} folder    kullanıcının dosyalarında klasör adı
 * @param {string} fileName  coverFileName() çıktısı
 * @param {Blob} blob
 * @return {Promise<number>} yeni dosyanın kimliği
 */
export const uploadCover = async (uid, folder, fileName, blob) => {
	if (!uid) {
		throw new Error('uid gerekli')
	}

	await ensureFolder(uid, folder)

	const url = `${userRoot(uid)}/${encodePath(`${folder}/${fileName}`)}`
	const response = await axios.put(url, blob, {
		headers: { 'Content-Type': blob.type || 'application/octet-stream' },
	})

	const fileId = parseOcFileId(response.headers?.['oc-fileid']) ?? await fetchFileId(url)
	if (!fileId) {
		// Teknik iç hata; kullanıcıya oluşturma ekranının çevrilmiş mesajı gösterilir.
		throw new Error('uploaded cover has no file id')
	}

	return fileId
}
