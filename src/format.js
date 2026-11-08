/**
 * Tarih ve rozet biçimlendirme.
 *
 * Tarihler sunucuda unix saniye olarak tutulur; JavaScript milisaniye bekler.
 * Bu dönüşümü tek yerde yapmak, bileşenlerde 1000 çarpanının unutulmasını önler.
 */

import { getCanonicalLocale } from '@nextcloud/l10n'
import { t } from '@nextcloud/l10n'

const locale = () => getCanonicalLocale().replace('_', '-')

/** "23 Eylül 2026 14:30" */
export const formatDateTime = seconds => {
	if (!seconds) {
		return ''
	}

	return new Intl.DateTimeFormat(locale(), {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		hour: '2-digit',
		minute: '2-digit',
	}).format(new Date(seconds * 1000))
}

/** "23 Eylül 2026" */
export const formatDate = seconds => {
	if (!seconds) {
		return ''
	}

	return new Intl.DateTimeFormat(locale(), {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	}).format(new Date(seconds * 1000))
}

/** "3 gün önce" — listede tarihin yanında kısa bağlam verir. */
export const formatRelative = seconds => {
	if (!seconds) {
		return ''
	}

	const diff = seconds - Math.floor(Date.now() / 1000)
	const rtf = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' })

	const units = [
		['year', 31536000],
		['month', 2592000],
		['day', 86400],
		['hour', 3600],
		['minute', 60],
	]

	for (const [unit, size] of units) {
		if (Math.abs(diff) >= size) {
			return rtf.format(Math.round(diff / size), unit)
		}
	}

	return rtf.format(0, 'minute')
}

export const PRIORITY = {
	NORMAL: 0,
	IMPORTANT: 1,
	URGENT: 2,
}

export const priorityLabel = priority => {
	if (priority === PRIORITY.URGENT) {
		return t('noticeboard', 'Acil')
	}
	if (priority === PRIORITY.IMPORTANT) {
		return t('noticeboard', 'Önemli')
	}

	return ''
}

export const statusLabel = status => {
	const labels = {
		draft: t('noticeboard', 'Taslak'),
		scheduled: t('noticeboard', 'Zamanlanmış'),
		published: t('noticeboard', 'Yayında'),
		archived: t('noticeboard', 'Arşivlenmiş'),
	}

	return labels[status] ?? status
}

/**
 * Kapağın kırpıldığında görünen bölgesi — CSS `object-position` değeri.
 * Yayıncı oluşturma ekranında görseli sürükleyerek seçer; seçmediyse orta.
 * Kapak gösteren her yer (detay, liste, kart) bunu kullanır ki aynı bölge görünsün.
 */
export const coverPosition = announcement =>
	`${announcement?.coverX ?? 50}% ${announcement?.coverY ?? 50}%`
