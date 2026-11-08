/**
 * Duyuru API'si — tüm sunucu çağrıları buradan geçer.
 *
 * OCS yanıtı `{ ocs: { meta, data } }` sarmalayıcısıyla gelir; bileşenlerin bunu
 * bilmesi gerekmesin diye burada açılır.
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

const API = generateOcsUrl('apps/noticeboard/api/v1')

const unwrap = response => response.data.ocs.data

export const fetchAnnouncements = async (params = {}) =>
	unwrap(await axios.get(`${API}/announcements`, { params }))

export const fetchAnnouncement = async id =>
	unwrap(await axios.get(`${API}/announcements/${id}`))

/** Yonetim listesi: yonetici hepsini, yayinci yalnizca kendi duyurularini gorur. */
export const fetchAllAnnouncements = async (params = {}) =>
	unwrap(await axios.get(`${API}/announcements/all`, { params }))

export const createAnnouncement = async payload =>
	unwrap(await axios.post(`${API}/announcements`, payload))

export const updateAnnouncement = async (id, payload) =>
	unwrap(await axios.put(`${API}/announcements/${id}`, payload))

export const deleteAnnouncement = async id =>
	unwrap(await axios.delete(`${API}/announcements/${id}`))

/** Hedef grup seçicisi — yalnızca yayıncılara açık. */
export const fetchGroups = async (search = '') =>
	unwrap(await axios.get(`${API}/groups`, { params: { search } }))

// --- Yorumlar (K6) ---------------------------------------------------------

export const fetchComments = async id =>
	unwrap(await axios.get(`${API}/announcements/${id}/comments`))

export const createComment = async (id, message) =>
	unwrap(await axios.post(`${API}/announcements/${id}/comments`, { message }))

export const deleteComment = async (id, commentId) =>
	unwrap(await axios.delete(`${API}/announcements/${id}/comments/${commentId}`))

/** Kapak görseli ve ekler OCS değil, normal rotadan servis edilir. */
export const coverUrl = id =>
	generateUrl('/apps/noticeboard/announcements/{id}/cover', { id })

export const attachmentUrl = (id, attachmentId) =>
	generateUrl('/apps/noticeboard/announcements/{id}/attachments/{attachmentId}', { id, attachmentId })
