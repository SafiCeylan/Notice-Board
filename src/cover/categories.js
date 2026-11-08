/**
 * Oluşturma ekranındaki hazır kategori listesi (7 Eki 2026, kullanıcı isteği:
 * "yazmak zorunda kalmayalım").
 *
 * Her hazır kategori autoCover.js'teki bir kurala (`key`) bağlıdır: seçilen
 * kategori, kapaksız duyuruda o kuralın ikonunu ve SABİT rengini alır.
 * tests/js/categories.test.mjs her etiketin (Türkçe VE İngilizce) gerçekten
 * kendi kuralına düştüğünü denetler — etiket değişip kural tutmazsa kırmızı olur.
 *
 * Kayda ETİKETİN KENDİSİ yazılır (anahtar değil): sunucu kategoriyi metin olarak
 * gösterir (widget alt satırı, arama) ve elle yazılmış eski kategorilerle aynı
 * alanı paylaşır. Etiket, yazarın arayüz dilindedir — başlık ve metin gibi.
 *
 * `t` dışarıdan verilir: düğüm testinde @nextcloud/l10n yüklenmeden çalışsın,
 * build-l10n.py de aşağıdaki çeviri çağrılarını burada da bulsun. (⚠️ Bu
 * açıklamaya örnek çeviri çağrısı YAZMA: toplayıcı onu gerçek metin sanar — Hata #27.)
 */

/** "Diğer" seçeneğinin anahtarı: seçilince elle yazma alanı açılır. */
export const OTHER_CATEGORY = 'other'

export const categoryPresets = t => [
	{ key: 'general', label: t('noticeboard', 'Genel') },
	{ key: 'hr', label: t('noticeboard', 'İnsan Kaynakları') },
	{ key: 'maintenance', label: t('noticeboard', 'Teknik Bakım') },
	{ key: 'it', label: t('noticeboard', 'Bilgi İşlem') },
	{ key: 'security', label: t('noticeboard', 'Bilgi Güvenliği') },
	{ key: 'events', label: t('noticeboard', 'Etkinlik') },
	{ key: 'meetings', label: t('noticeboard', 'Toplantı') },
	{ key: 'training', label: t('noticeboard', 'Eğitim') },
	{ key: 'finance', label: t('noticeboard', 'Finans') },
	{ key: 'health', label: t('noticeboard', 'Sağlık ve İSG') },
	{ key: 'cafeteria', label: t('noticeboard', 'Yemekhane') },
	{ key: 'transport', label: t('noticeboard', 'Servis ve Ulaşım') },
]

const normalize = text => String(text ?? '').toLocaleLowerCase('tr').trim()

/** Kayıtlı kategori metni bir hazır kategorinin etiketiyse onu döndürür, değilse null. */
export const findPreset = (presets, category) => {
	const text = normalize(category)

	return text ? (presets.find(p => normalize(p.label) === text) ?? null) : null
}
