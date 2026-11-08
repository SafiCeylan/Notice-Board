/**
 * Otomatik kapak kuralları. Çalıştırma: node --test "tests/js/*.test.mjs"
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'

import { PALETTE, URGENT_COLORS, autoCover, hashString, iconFor } from '../../src/cover/autoCover.js'

test('aynı kategori her zaman aynı renk (kararlı)', () => {
	const a = autoCover({ category: 'İnsan Kaynakları', subject: 'Bir' })
	const b = autoCover({ category: 'İnsan Kaynakları', subject: 'Tamamen başka' })
	assert.deepEqual([a.from, a.to], [b.from, b.to])
})

test('kategori büyük/küçük harf ve Türkçe I/İ farkını yok sayar', () => {
	assert.deepEqual(autoCover({ category: 'TEKNİK' }).from, autoCover({ category: 'teknik' }).from)
})

test('kategorisiz duyuru başlıktan kararlı renk alır', () => {
	assert.equal(autoCover({ subject: 'Kahve makinesi' }).from, autoCover({ subject: 'Kahve makinesi' }).from)
})

test('kırmızı yalnız acile ayrılmış: palette yok, acilde var', () => {
	assert.ok(!PALETTE.some(([from]) => from === URGENT_COLORS[0]))
	assert.equal(autoCover({ category: 'Teknik', priority: 2 }).from, URGENT_COLORS[0])
	assert.notEqual(autoCover({ category: 'Teknik', priority: 1 }).from, URGENT_COLORS[0])
})

test('farklı kategoriler paleti kullanır (hepsi tek renge düşmez)', () => {
	const renkler = new Set(['İK', 'Teknik', 'Etkinlik', 'Finans', 'Yemek', 'Güvenlik', 'Eğitim', 'Servis', 'Satış', 'Hukuk']
		.map(category => autoCover({ category }).from))
	assert.ok(renkler.size >= 4, `yalnız ${renkler.size} renk`)
})

test('ikon kategoriden seçilir; Türkçe ekler sorun değil', () => {
	assert.equal(iconFor('İnsan Kaynakları'), 'people')
	assert.equal(iconFor('İK'), 'people')
	assert.equal(iconFor('IK'), 'people')
	assert.equal(iconFor('Teknik Bakım'), 'wrench')
	assert.equal(iconFor('Sunucu bakımı'), 'wrench')
	assert.equal(iconFor('Etkinlikler'), 'party')
	assert.equal(iconFor('Eğitimler'), 'school')
	assert.equal(iconFor('IT'), 'laptop')
	assert.equal(iconFor('Bilgi İşlem'), 'laptop')
})

test('kısaltmalar başka kelimenin içinde eşleşmez', () => {
	assert.equal(iconFor('İkinci kat'), 'bullhorn')
	assert.equal(iconFor('İtfaiye tatbikatı'), 'bullhorn')
	assert.equal(iconFor('Kitaplık'), 'bullhorn')
})

test('ilk eşleşen kural kazanır: "Güvenlik eğitimi" güvenliktir', () => {
	assert.equal(iconFor('Güvenlik eğitimi'), 'shield')
})

test('bilinmeyen ya da boş kategori megafon', () => {
	assert.equal(iconFor('Hukuk'), 'bullhorn')
	assert.equal(iconFor(''), 'bullhorn')
	assert.equal(iconFor(null), 'bullhorn')
})

test('kategorisiz acil duyuru uyarı ikonu alır', () => {
	assert.equal(autoCover({ subject: 'Yangın tatbikatı', priority: 2 }).icon, 'alert')
	assert.equal(autoCover({ category: 'İK', priority: 2 }).icon, 'people')
})

test('karma 32 bit işaretsiz ve kararlı', () => {
	assert.equal(hashString('abc'), hashString('abc'))
	assert.ok(hashString('abc') >= 0 && hashString('abc') < 2 ** 32)
	assert.notEqual(hashString('abc'), hashString('abd'))
})
