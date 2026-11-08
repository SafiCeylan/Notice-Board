/**
 * Duyuru bağlantısı. Widget'lar panodan uygulamaya yönlendirir; kalıcı bağlantı
 * biçimi uygulamadaki hash yönlendirmesiyle aynı olmalı.
 */

import { generateUrl } from '@nextcloud/router'

export const announcementLink = id =>
	`${generateUrl('/apps/noticeboard/')}#/announcement/${id}`
