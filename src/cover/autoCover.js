/**
 * Kapaksız duyurular için otomatik kapak: renk + ikon (DOM'suz, düğümde test edilir).
 *
 * Kural, kategori ADINDA geçen kelimedir. Oluşturma ekranındaki hazır kategoriler
 * (categories.js) bu kurallara birebir bağlıdır; listede olmayan, elle yazılmış
 * bir kategori de kelimesi tutarsa aynı ikonu/rengi alır ("ik" → İnsan Kaynakları).
 *
 * Renk: tanınan kategoride KURALIN SABİT rengi (okuyucu panoda "mavi olanlar İK"
 * diye tanımayı öğrenir); tanınmayanda kategori adından türeyen kararlı renk;
 * kategori yoksa başlıktan. Kırmızı yalnız ACİL duyurulara ayrılmıştır — hiçbir
 * kuralda ve paletde yok, yoksa sıradan bir kategori acil gibi görünürdü.
 */

/** Tanınmayan kategorinin rengi bu paletten seçilir. [açık, koyu] */
export const PALETTE = [
	['#3b82f6', '#1e40af'],
	['#06b6d4', '#155e75'],
	['#10b981', '#065f46'],
	['#f59e0b', '#92400e'],
	['#ec4899', '#9d174d'],
	['#8b5cf6', '#5b21b6'],
	['#6366f1', '#3730a3'],
	['#14b8a6', '#115e59'],
]

export const URGENT_COLORS = ['#ef4444', '#991b1b']

/**
 * İlk eşleşen kazanır; sıra önemli:
 *   · sağlık, güvenlikten ÖNCE — "İş Güvenliği" İSG'dir, bilgi güvenliği değil
 *   · güvenlik, eğitimden önce — "Güvenlik eğitimi" güvenliktir
 * Kelimeler Türkçe küçük harfe çevrilmiş metinde aranır; İngilizce karşılıklar da
 * var (İngilizce arayüzdeki hazır kategoriler İngilizce kaydedilir).
 */
export const ICON_RULES = [
	{ key: 'health', icon: 'health', colors: ['#06b6d4', '#155e75'], words: ['sağlı', 'sagli', 'health', 'iş güvenli', 'isg'] },
	{ key: 'security', icon: 'shield', colors: ['#64748b', '#1e293b'], words: ['güvenli', 'guvenli', 'security', 'kvkk', 'siber'] },
	{ key: 'hr', icon: 'people', colors: ['#3b82f6', '#1e40af'], words: ['insan kaynak', 'human resource', 'ik', 'ık', 'hr', 'personel', 'izin', 'izni', 'işe alım'] },
	{ key: 'maintenance', icon: 'wrench', colors: ['#f59e0b', '#92400e'], words: ['tekni', 'bakım', 'bakim', 'arıza', 'maintenance', 'altyapı'] },
	{ key: 'it', icon: 'laptop', colors: ['#6366f1', '#3730a3'], words: ['bilgi işlem', 'bt', 'it', 'ıt', 'yazılım', 'sistem', 'software'] },
	{ key: 'events', icon: 'party', colors: ['#ec4899', '#9d174d'], words: ['etkinli', 'kutlama', 'event', 'parti', 'doğum gün', 'yılbaşı'] },
	{ key: 'meetings', icon: 'calendar', colors: ['#14b8a6', '#115e59'], words: ['toplantı', 'takvim', 'meeting', 'tarih'] },
	{ key: 'training', icon: 'school', colors: ['#8b5cf6', '#5b21b6'], words: ['eğitim', 'egitim', 'training', 'seminer', 'kurs'] },
	{ key: 'finance', icon: 'money', colors: ['#10b981', '#065f46'], words: ['finans', 'muhasebe', 'bordro', 'maaş', 'finance', 'bütçe'] },
	{ key: 'cafeteria', icon: 'food', colors: ['#84cc16', '#3f6212'], words: ['yemek', 'yemeğ', 'kafeterya', 'menü', 'food', 'cafeteria'] },
	{ key: 'transport', icon: 'bus', colors: ['#f97316', '#9a3412'], words: ['servis', 'ulaşım', 'ulasim', 'shuttle', 'otopark', 'transport'] },
	{ key: 'general', icon: 'bullhorn', colors: ['#0082c9', '#00426b'], words: ['genel', 'general', 'duyuru'] },
]

/** FNV-1a 32 bit: küçük, kararlı, her tarayıcıda aynı sonucu veren karma. */
export const hashString = text => {
	let hash = 0x811c9dc5
	for (const char of String(text)) {
		hash ^= char.codePointAt(0)
		hash = Math.imul(hash, 0x01000193) >>> 0
	}

	return hash
}

const normalize = text => String(text ?? '').toLocaleLowerCase('tr').trim()

/**
 * Kelime BAŞINDAN arar; Türkçe ekler serbest ("bakımı", "eğitimleri" eşleşir).
 * ⚠️ k ile biten kelimeler ek alınca yumuşar (güvenlik → güvenliği, teknik →
 * tekniği): kurallarda bu yüzden KÖK yazılır ('güvenli', 'tekni', 'sağlı').
 * 3 harf ve kısası kısaltmadır (İK, IT, BT, HR): onlar TAM kelime olmalı, yoksa
 * "ik" kuralı "ikinci"de, "it" kuralı "itfaiye"de eşleşirdi.
 */
const containsWord = (haystack, word) => {
	const escaped = word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const end = word.length <= 3 ? '([^\\p{L}\\p{N}]|$)' : ''

	return new RegExp(`(^|[^\\p{L}\\p{N}])${escaped}${end}`, 'u').test(haystack)
}

/** Kategori adının uyduğu kural; yoksa null. */
export const ruleFor = category => {
	const text = normalize(category)
	if (!text) {
		return null
	}

	return ICON_RULES.find(r => r.words.some(word => containsWord(text, word))) ?? null
}

export const ruleByKey = key => ICON_RULES.find(r => r.key === key) ?? null

export const iconFor = category => ruleFor(category)?.icon ?? 'bullhorn'

/**
 * @param {{category?: string, subject?: string, priority?: number}} announcement
 * @return {{from: string, to: string, icon: string, label: string}}
 */
export const autoCover = announcement => {
	const category = String(announcement?.category ?? '').trim()
	const urgent = announcement?.priority === 2
	const rule = ruleFor(category)

	let colors
	if (urgent) {
		colors = URGENT_COLORS
	} else if (rule) {
		colors = rule.colors
	} else {
		colors = PALETTE[hashString(normalize(category || announcement?.subject || '')) % PALETTE.length]
	}

	return {
		from: colors[0],
		to: colors[1],
		icon: urgent && !category ? 'alert' : (rule?.icon ?? 'bullhorn'),
		label: category,
	}
}
