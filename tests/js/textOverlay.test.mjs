/**
 * Kapak yazısı yerleşimi. Sahte ölçü: her harf yazı boyutunun yarısı genişlikte.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'

import { EMPTY_OVERLAY, hasOverlay, layoutOverlay, wrapLines } from '../../src/cover/textOverlay.js'

/** 10 birim/harf — satır genişliği hesabı elle doğrulanabilsin. */
const tenPerChar = s => s.length * 10

test('boş ya da yalnız boşluk metin yazı sayılmaz', () => {
	assert.equal(hasOverlay(EMPTY_OVERLAY), false)
	assert.equal(hasOverlay({ ...EMPTY_OVERLAY, text: '   ' }), false)
	assert.equal(layoutOverlay({ width: 1600, height: 900 }, EMPTY_OVERLAY, () => 0), null)
})

test('sığan metin tek satır kalır', () => {
	assert.deepEqual(wrapLines('SON GÜN 15 EKİM', 200, tenPerChar), ['SON GÜN 15 EKİM'])
})

test('sığmayan metin kelime sınırından bölünür', () => {
	assert.deepEqual(wrapLines('aaa bbb ccc', 70, tenPerChar), ['aaa bbb', 'ccc'])
})

test('sınır: tam sığan satır bölünmez', () => {
	assert.deepEqual(wrapLines('aaa bbb', 70, tenPerChar), ['aaa bbb'])
})

test('en fazla 3 satır; fazlası üç noktayla biter', () => {
	const lines = wrapLines('aa bb cc dd ee', 20, tenPerChar)
	assert.equal(lines.length, 3)
	assert.ok(lines[2].endsWith('…'), lines[2])
	assert.ok(lines.every(l => tenPerChar(l) <= 20 || l.endsWith('…')))
})

test('satıra sığmayan tek uzun kelime harf harf kesilir', () => {
	assert.deepEqual(wrapLines('abcdefgh', 30, tenPerChar), ['abc', 'def', 'gh'])
})

test('fazla boşluklar tek boşluğa iner', () => {
	assert.deepEqual(wrapLines('  a    b  ', 100, tenPerChar), ['a b'])
})

const measure = (s, fontSize) => s.length * fontSize * 0.5

test('yazı boyutu görselle ORANTILI: aynı oran, aynı yerleşim', () => {
	const small = layoutOverlay({ width: 320, height: 180 }, { ...EMPTY_OVERLAY, text: 'Duyuru' }, measure)
	const big = layoutOverlay({ width: 1920, height: 1080 }, { ...EMPTY_OVERLAY, text: 'Duyuru' }, measure)
	assert.equal(big.fontSize / small.fontSize, 6)
	assert.equal(big.band.y / 1080, small.band.y / 180)
})

test('kare kapakta yazı 16:9 eşdeğerine göre ölçülür (dev olmaz)', () => {
	const wide = layoutOverlay({ width: 1000, height: 562.5 }, { ...EMPTY_OVERLAY, text: 'A' }, measure)
	const square = layoutOverlay({ width: 1000, height: 1000 }, { ...EMPTY_OVERLAY, text: 'A' }, measure)
	assert.equal(square.fontSize, wide.fontSize)
})

test('konum: alt şerit alt kenara, üst şerit üst kenara yapışır, orta ortalanır', () => {
	const size = { width: 1600, height: 900 }
	const bottom = layoutOverlay(size, { ...EMPTY_OVERLAY, text: 'X', position: 'bottom' }, measure)
	const top = layoutOverlay(size, { ...EMPTY_OVERLAY, text: 'X', position: 'top' }, measure)
	const center = layoutOverlay(size, { ...EMPTY_OVERLAY, text: 'X', position: 'center' }, measure)
	assert.equal(bottom.band.y + bottom.band.height, 900)
	assert.equal(top.band.y, 0)
	assert.ok(Math.abs(center.band.y + center.band.height / 2 - 450) <= 1)
})

test('gölgeli stilde şerit yok, açık stilde koyu yazı', () => {
	const size = { width: 1600, height: 900 }
	assert.equal(layoutOverlay(size, { ...EMPTY_OVERLAY, text: 'X', style: 'shadow' }, measure).band, null)
	assert.equal(layoutOverlay(size, { ...EMPTY_OVERLAY, text: 'X', style: 'light' }, measure).colors.text, '#1a1a1a')
})

test('boyut seçimi yazıyı büyütür', () => {
	const size = { width: 1600, height: 900 }
	const s = layoutOverlay(size, { ...EMPTY_OVERLAY, text: 'X', size: 'small' }, measure).fontSize
	const l = layoutOverlay(size, { ...EMPTY_OVERLAY, text: 'X', size: 'large' }, measure).fontSize
	assert.ok(l > s * 1.8)
})

test('satır merkezleri şeridin içinde kalır', () => {
	const layout = layoutOverlay({ width: 400, height: 225 }, { ...EMPTY_OVERLAY, text: 'çok uzun bir duyuru başlığı burada duruyor' }, measure)
	assert.ok(layout.lines.length > 1)
	for (const y of layout.lineCenters) {
		assert.ok(y > layout.band.y && y < layout.band.y + layout.band.height)
	}
})
