/**
 * Kapak düzenleyicisinin saf yardımcıları. Çalıştırma: node --test tests/js
 * (run-tests.sh 5. adım).
 *
 * En kritik iddia: kayıtta piksellere uygulanan renk ayarı, düzenlerken ekranda
 * gösterilen CSS filtresiyle AYNI formül. Ayrışırsa yayıncı gördüğünden farklı
 * bir kapak kaydeder ve fark etmez.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'

import {
	ASPECT_PRESETS,
	DEFAULT_ASPECT,
	NEUTRAL_ADJUSTMENTS,
	applyAdjustments,
	coverFileName,
	cssFilter,
	fitOutputSize,
	outputType,
	parseOcFileId,
} from '../../src/cover/imageTools.js'

const pixel = (r, g, b, a = 255) => new Uint8ClampedArray([r, g, b, a])

test('nötr ayar pikselleri değiştirmez ve CSS filtresi yoktur', () => {
	const data = pixel(10, 120, 250, 77)
	applyAdjustments(data, NEUTRAL_ADJUSTMENTS)
	assert.deepEqual([...data], [10, 120, 250, 77])
	assert.equal(cssFilter(NEUTRAL_ADJUSTMENTS), 'none')
})

test('parlaklık CSS brightness() gibi çarpar ve 255 de kırpar', () => {
	const data = pixel(100, 200, 0)
	applyAdjustments(data, { brightness: 150, contrast: 100, saturation: 100 })
	assert.deepEqual([...data.slice(0, 3)], [150, 255, 0])
})

test('karşıtlık orta griyi (127.5) sabit tutar, uçları iter', () => {
	const data = new Uint8ClampedArray([128, 64, 192, 255])
	applyAdjustments(data, { brightness: 100, contrast: 150, saturation: 100 })
	// (v - 127.5) * 1.5 + 127.5 → 128.25, 31.75, 223.75
	assert.deepEqual([...data.slice(0, 3)], [128, 32, 224])
})

test('doygunluk 50% gri değere yaklaştırır; gri piksel değişmez', () => {
	const gray = pixel(90, 90, 90)
	applyAdjustments(gray, { brightness: 100, contrast: 100, saturation: 50 })
	assert.deepEqual([...gray.slice(0, 3)], [90, 90, 90])

	const red = pixel(255, 0, 0)
	applyAdjustments(red, { brightness: 100, contrast: 100, saturation: 50 })
	// saturate(0.5) matrisinin ilk sütunu: 0.6065, 0.1065, 0.1065
	assert.deepEqual([...red.slice(0, 3)], [155, 27, 27])
})

test('alfa kanalına dokunulmaz', () => {
	const data = pixel(10, 20, 30, 12)
	applyAdjustments(data, { brightness: 140, contrast: 60, saturation: 130 })
	assert.equal(data[3], 12)
})

test('CSS filtresi formülle AYNI sırada yazılır (brightness → contrast → saturate)', () => {
	assert.equal(
		cssFilter({ brightness: 110, contrast: 90, saturation: 120 }),
		'brightness(110%) contrast(90%) saturate(120%)',
	)
})

test('çıktı biçimi: JPEG JPEG kalır, saydamlık taşıyabilenler PNG olur', () => {
	assert.equal(outputType('image/jpeg').mime, 'image/jpeg')
	assert.equal(outputType('image/jpeg').extension, 'jpg')
	for (const mime of ['image/png', 'image/webp', 'image/gif']) {
		assert.equal(outputType(mime).mime, 'image/png', mime)
	}
})

test('çıktı boyutu en uzun kenarda sınırlanır, küçük görsel büyütülmez', () => {
	assert.deepEqual(fitOutputSize(4000, 2250, 1920), { width: 1920, height: 1080 })
	assert.deepEqual(fitOutputSize(1000, 3000, 1920), { width: 640, height: 1920 })
	assert.deepEqual(fitOutputSize(800, 450, 1920), { width: 800, height: 450 })
	// Sınır tam eşitlikte dokunulmaz.
	assert.deepEqual(fitOutputSize(1920, 1080, 1920), { width: 1920, height: 1080 })
	assert.deepEqual(fitOutputSize(0, 0, 1920), { width: 0, height: 0 })
})

test('dosya adı: uzantı değişir, zaman damgası eklenir, yolu bozan karakter atılır', () => {
	const date = new Date(2026, 9, 7, 14, 5, 9)
	assert.equal(coverFileName('Bahar şenliği.png', 'jpg', date), 'Bahar şenliği-kapak-20261007-140509.jpg')
	assert.equal(coverFileName('a/b\\c:d*?.jpeg', 'png', date), 'a b c d-kapak-20261007-140509.png')
	assert.equal(coverFileName('', 'jpg', date), 'kapak-kapak-20261007-140509.jpg')
	assert.equal(coverFileName(undefined, 'jpg', date), 'kapak-kapak-20261007-140509.jpg')
	assert.ok(!coverFileName('#%.png', 'jpg', date).includes('#'))
	assert.ok(coverFileName('x'.repeat(300), 'jpg', date).length < 90)
})

test('OC-FileId başlığından dosya kimliği okunur', () => {
	assert.equal(parseOcFileId('00000042oc1x2y3z'), 42)
	assert.equal(parseOcFileId('00012300ocabc'), 12300)
	assert.equal(parseOcFileId(undefined), null)
	assert.equal(parseOcFileId('ocabc'), null)
})

test('varsayılan oran vitrinin oranıdır (16:9)', () => {
	const preset = ASPECT_PRESETS.find(p => p.id === DEFAULT_ASPECT)
	assert.equal(preset.value, 16 / 9)
})
