/**
 * Hazır kategori listesi ↔ otomatik kapak kuralları.
 *
 * Korunan şey: listeden seçilen her kategori, kapaksız duyuruda KENDİ ikonunu ve
 * rengini alır — Türkçe arayüzde de İngilizce arayüzde de (kayda etiketin kendisi
 * yazıldığı için İngilizce kullanıcının seçtiği "Training" da eğitim kuralına
 * düşmeli). Etiket ya da kural değişip eşleşme bozulursa burada kırmızı olur.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

import { ICON_RULES, URGENT_COLORS, autoCover, ruleByKey, ruleFor } from '../../src/cover/autoCover.js'
import { OTHER_CATEGORY, categoryPresets, findPreset } from '../../src/cover/categories.js'

const turkish = (_app, text) => text
// run-tests.sh çeviri dosyasını JS testlerinden ÖNCE üretir.
const en = JSON.parse(readFileSync(new URL('../../l10n/en.json', import.meta.url), 'utf-8')).translations
const english = (_app, text) => en[text] ?? text

for (const [lang, t] of [['tr', turkish], ['en', english]]) {
	test(`${lang}: her hazır kategori kendi kuralına düşer`, () => {
		for (const preset of categoryPresets(t)) {
			assert.equal(ruleFor(preset.label)?.key, preset.key, `${lang} "${preset.label}"`)
		}
	})
}

test('İngilizce etiketlerin hepsi gerçekten çevrilmiş', () => {
	for (const preset of categoryPresets(turkish)) {
		assert.ok(en[preset.label], `çevirisi yok: ${preset.label}`)
	}
})

test('her hazır kategorinin bir kuralı var; anahtarlar tekil; "Diğer" kural değil', () => {
	const keys = categoryPresets(turkish).map(p => p.key)
	assert.equal(new Set(keys).size, keys.length)
	for (const key of keys) {
		assert.ok(ruleByKey(key), key)
	}
	assert.ok(!keys.includes(OTHER_CATEGORY))
})

test('kuralların renkleri birbirinden farklı ve hiçbiri acil kırmızısı değil', () => {
	const froms = ICON_RULES.map(r => r.colors[0])
	assert.equal(new Set(froms).size, froms.length)
	assert.ok(!froms.includes(URGENT_COLORS[0]))
})

test('tanınan kategori kuralın SABİT rengini alır (adı farklı yazılsa da)', () => {
	const hr = ruleByKey('hr').colors[0]
	assert.equal(autoCover({ category: 'İnsan Kaynakları' }).from, hr)
	assert.equal(autoCover({ category: 'ik' }).from, hr)
	assert.equal(autoCover({ category: 'Human Resources' }).from, hr)
})

test('"İş Güvenliği" İSG\'dir, bilgi güvenliği değil; "Bilgi Güvenliği" güvenliktir', () => {
	assert.equal(ruleFor('İş Güvenliği')?.key, 'health')
	assert.equal(ruleFor('Bilgi Güvenliği')?.key, 'security')
	assert.equal(ruleFor('Güvenlik eğitimi')?.key, 'security')
})

test('kayıtlı metinden hazır seçenek bulunur; büyük/küçük harf ve Türkçe I/İ fark etmez', () => {
	const presets = categoryPresets(turkish)
	assert.equal(findPreset(presets, 'İNSAN KAYNAKLARI')?.key, 'hr')
	assert.equal(findPreset(presets, '  eğitim ')?.key, 'training')
	assert.equal(findPreset(presets, 'ik'), null)
	assert.equal(findPreset(presets, ''), null)
	assert.equal(findPreset(presets, null), null)
})

test('Türkçe ünsüz yumuşaması: ek almış hâller de tanınır', () => {
	assert.equal(ruleFor('Sistem güvenliği')?.key, 'security')
	assert.equal(ruleFor('Tekniği')?.key, 'maintenance')
	assert.equal(ruleFor('Etkinliği')?.key, 'events')
	assert.equal(ruleFor('Sağlığınız')?.key, 'health')
	assert.equal(ruleFor('Yemeği')?.key, 'cafeteria')
	assert.equal(ruleFor('Yıllık izni')?.key, 'hr')
})
