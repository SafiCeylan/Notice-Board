/**
 * Kapak düzenleyicinin saf yardımcıları (DOM'suz, düğümde test edilir:
 * tests/js/imageTools.test.mjs).
 *
 * ⚠️ Renk ayarı İKİ yerde uygulanır: düzenlerken ekranda CSS `filter` ile,
 * kaydederken piksellere `applyAdjustments` ile. İkisi aynı formülü kullanmazsa
 * yayıncı ekranda gördüğünden farklı bir kapak kaydeder. Formüller CSS Filter
 * Effects spesifikasyonundaki brightness → contrast → saturate sırasıyla birebir
 * aynıdır; `cssFilter` de aynı sırayı yazar (sıra değişirse sonuç değişir).
 */

/** Ayar kaydırıcılarının sınırları (yüzde). 100 = değişiklik yok. */
export const ADJUST_LIMITS = { min: 50, max: 150 }

export const NEUTRAL_ADJUSTMENTS = Object.freeze({ brightness: 100, contrast: 100, saturation: 100 })

/** Kaydedilen kapağın en uzun kenarı. Panoda 270 px, detayda 760 px gösteriliyor. */
export const MAX_OUTPUT_SIZE = 1920

/**
 * Oran ön ayarları. `value` cropper'ın `aspectRatio` değeridir; NaN = serbest,
 * null = görselin kendi oranı (düzenleyici açılınca hesaplanır).
 * Vitrin 16:9 çizdiği için varsayılan odur.
 */
export const ASPECT_PRESETS = [
	{ id: 'wide', value: 16 / 9 },
	{ id: 'classic', value: 4 / 3 },
	{ id: 'square', value: 1 },
	{ id: 'original', value: null },
	{ id: 'free', value: NaN },
]

export const DEFAULT_ASPECT = 'wide'

const clampByte = value => (value < 0 ? 0 : value > 255 ? 255 : value)

export const isNeutral = adjustments =>
	adjustments.brightness === 100 && adjustments.contrast === 100 && adjustments.saturation === 100

/** Ekrandaki önizleme için CSS filter değeri. */
export const cssFilter = adjustments => isNeutral(adjustments)
	? 'none'
	: `brightness(${adjustments.brightness}%) contrast(${adjustments.contrast}%) saturate(${adjustments.saturation}%)`

/**
 * Parlaklık / karşıtlık / doygunluk ayarını piksel dizisine YERİNDE uygular.
 * `data`, ImageData.data biçiminde RGBA dizisidir; alfa kanalına dokunulmaz.
 */
export const applyAdjustments = (data, adjustments) => {
	if (isNeutral(adjustments)) {
		return data
	}

	const b = adjustments.brightness / 100
	const c = adjustments.contrast / 100
	const s = adjustments.saturation / 100
	const intercept = 255 * (0.5 - 0.5 * c)

	// saturate() matrisi (Filter Effects 1, feColorMatrix type="saturate").
	const m = [
		0.213 + 0.787 * s, 0.715 - 0.715 * s, 0.072 - 0.072 * s,
		0.213 - 0.213 * s, 0.715 + 0.285 * s, 0.072 - 0.072 * s,
		0.213 - 0.213 * s, 0.715 - 0.715 * s, 0.072 + 0.928 * s,
	]

	for (let i = 0; i < data.length; i += 4) {
		// Her filtre kendi çıktısını [0, 255] aralığına kırpar; tarayıcı da öyle yapar.
		const r = clampByte(clampByte(data[i] * b) * c + intercept)
		const g = clampByte(clampByte(data[i + 1] * b) * c + intercept)
		const bl = clampByte(clampByte(data[i + 2] * b) * c + intercept)

		data[i] = Math.round(clampByte(m[0] * r + m[1] * g + m[2] * bl))
		data[i + 1] = Math.round(clampByte(m[3] * r + m[4] * g + m[5] * bl))
		data[i + 2] = Math.round(clampByte(m[6] * r + m[7] * g + m[8] * bl))
	}

	return data
}

/**
 * Kayıt biçimi. Fotoğraf JPEG kalır (PNG'si megabaytlarca tutar); saydamlık
 * taşıyabilen kaynaklar (logo, ekran görüntüsü) PNG olur ki saydam alan siyaha
 * dönmesin ve yazılar JPEG kusuruyla bulanmasın.
 */
export const outputType = sourceMime => sourceMime === 'image/jpeg'
	? { mime: 'image/jpeg', extension: 'jpg', quality: 0.9 }
	: { mime: 'image/png', extension: 'png', quality: undefined }

/** Kırpılan alanı en uzun kenarı MAX_OUTPUT_SIZE olacak şekilde küçültür (büyütmez). */
export const fitOutputSize = (width, height, max = MAX_OUTPUT_SIZE) => {
	const longest = Math.max(width, height)
	if (longest <= max || longest === 0) {
		return { width: Math.round(width), height: Math.round(height) }
	}
	const scale = max / longest

	return { width: Math.round(width * scale), height: Math.round(height * scale) }
}

const pad = n => String(n).padStart(2, '0')

/**
 * Düzenlenmiş kapağın dosya adı: "<asıl ad>-kapak-20261007-142530.jpg".
 * Zaman damgası aynı görselin iki düzenlemesinin birbirini ezmesini önler;
 * WebDAV yolunu bozacak karakterler atılır.
 */
export const coverFileName = (originalName, extension, date = new Date()) => {
	const base = String(originalName ?? '')
		.replace(/\.[^./\\]*$/, '')
		.replace(/[\\/:*?"<>|#%\u0000-\u001f]+/g, ' ')
		.replace(/\s+/g, ' ')
		.trim()
		.slice(0, 60)
		.trim() || 'kapak'
	const stamp = `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}`
		+ `-${pad(date.getHours())}${pad(date.getMinutes())}${pad(date.getSeconds())}`

	return `${base}-kapak-${stamp}.${extension}`
}

/**
 * WebDAV PUT yanıtındaki `OC-FileId` başlığı "00000042ocabc123" biçimindedir:
 * baştaki sayı dosya kimliği, kalanı kurulum kimliği.
 */
export const parseOcFileId = header => {
	const match = /^0*(\d+)/.exec(String(header ?? ''))

	return match ? Number(match[1]) : null
}
