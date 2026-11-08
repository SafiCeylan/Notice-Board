/**
 * Metne gömülü görsel yardımcıları. Çalıştırma: node --test tests/js
 *
 * En kritik iddia: gösterim anında eklenen `src` yalnız dosya kimliğinden
 * üretilir; gövdeye başka bir şey sızmaz.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'

import { readFileSync } from 'node:fs'

import {
	IMAGE_ALIGNS,
	IMAGE_WIDTH_MAX,
	IMAGE_WIDTH_MIN,
	IMAGE_WIDTH_STEP,
	MAX_IMAGE_ALT,
	dragWidth,
	extensionFor,
	inlineImageName,
	isInlineImageType,
	normalizeImageAlign,
	normalizeImageWidth,
	withImageSources,
} from '../../src/inlineImageTools.js'

const url = id => `/apps/noticeboard/announcements/7/images/${id}`

test('kayitli gorsele adres ve tembel yukleme eklenir', () => {
	assert.equal(
		withImageSources('<p>a</p><img data-nb-file="42" data-nb-owner="ali"><p>b</p>', url),
		'<p>a</p><img data-nb-file="42" data-nb-owner="ali" src="/apps/noticeboard/announcements/7/images/42" loading="lazy"><p>b</p>',
	)
})

test('birden fazla gorselin her biri kendi adresini alir', () => {
	const out = withImageSources('<img data-nb-file="1"><img data-nb-file="2">', url)
	assert.match(out, /images\/1" loading/)
	assert.match(out, /images\/2" loading/)
})

test('adres uretecine yalniz sayi gider', () => {
	const seen = []
	withImageSources('<img data-nb-file="0042">', id => {
		seen.push(id)
		return ''
	})
	assert.deepEqual(seen, [42])
})

test('adresteki tirnak kacirilir', () => {
	assert.match(withImageSources('<img data-nb-file="1">', () => 'a"b'), /src="a&quot;b"/)
})

test('zaten src tasiyan gorsele dokunulmaz', () => {
	const html = '<img data-nb-file="1" src="/x">'
	assert.equal(withImageSources(html, url), html)
})

test('kimliksiz gorsel ve gorselsiz metin degismez', () => {
	assert.equal(withImageSources('<img src="/x">', url), '<img src="/x">')
	assert.equal(withImageSources('<p>metin</p>', url), '<p>metin</p>')
	assert.equal(withImageSources(null, url), '')
})

test('desteklenen turler sunucu listesiyle ayni', () => {
	for (const mime of ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'IMAGE/PNG']) {
		assert.ok(isInlineImageType(mime), mime)
	}
	for (const mime of ['image/svg+xml', 'application/pdf', '', undefined]) {
		assert.ok(!isInlineImageType(mime), String(mime))
	}
})

test('dosya adi zaman damgali ve WebDAV icin guvenli', () => {
	const date = new Date(2026, 9, 20, 14, 5, 9)
	assert.equal(inlineImageName('Toplanti: foto?.jpeg', 'jpg', date), 'Toplanti foto-20261020-140509.jpg')
	assert.equal(inlineImageName('', 'png', date), 'gorsel-20261020-140509.png')
})

test('uzantisiz yapistirmada turden uzanti', () => {
	assert.equal(extensionFor('image/jpeg'), 'jpg')
	assert.equal(extensionFor('image/webp'), 'webp')
	assert.equal(extensionFor('bilinmeyen'), 'png')
})

// ---- Görsel yerleşimi (27 Eki 2026) -------------------------------------------

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')

test('yerlesimli gorsele de adres eklenir, oznitelikler korunur', () => {
	assert.equal(
		withImageSources('<img data-nb-file="42" data-nb-align="left" data-nb-width="40" alt="Salon" data-nb-owner="ali">', url),
		'<img data-nb-file="42" data-nb-align="left" data-nb-width="40" alt="Salon" data-nb-owner="ali" src="/apps/noticeboard/announcements/7/images/42" loading="lazy">',
	)
})

test('aciklamada "src=" gecmesi adresi engellemez', () => {
	assert.match(withImageSources('<img data-nb-file="42" alt="kaynak src=foto">', url), /images\/42" loading/)
	assert.match(withImageSources(`<img data-nb-file="42" alt='a " src="x'>`, url), /images\/42" loading/)
})

test('genislik 5in katina yuvarlanir ve 10-100e sikisir', () => {
	assert.equal(normalizeImageWidth(42), 40)
	assert.equal(normalizeImageWidth(43), 45)
	assert.equal(normalizeImageWidth('75'), 75)
	assert.equal(normalizeImageWidth(3), 10)
	assert.equal(normalizeImageWidth(140), 100)
	for (const empty of [null, undefined, '', 'abc', NaN, Infinity]) {
		assert.equal(normalizeImageWidth(empty), null, String(empty))
	}
})

test('yerlesim yalniz sabit listeden', () => {
	assert.deepEqual(IMAGE_ALIGNS, ['left', 'center', 'right'])
	assert.equal(normalizeImageAlign('right'), 'right')
	for (const bad of ['LEFT', 'float', '', null, 'left;x']) {
		assert.equal(normalizeImageAlign(bad), null, String(bad))
	}
})

test('surukleme: sola yasli saga cekince buyur, saga yasli sola cekince buyur', () => {
	assert.equal(dragWidth({ startPx: 400, deltaPx: 100, containerPx: 1000, align: 'left' }), 50)
	assert.equal(dragWidth({ startPx: 400, deltaPx: -100, containerPx: 1000, align: 'right' }), 50)
	assert.equal(dragWidth({ startPx: 400, deltaPx: 100, containerPx: 1000, align: 'right' }), 30)
	assert.equal(dragWidth({ startPx: 400, deltaPx: 100, containerPx: 1000, align: null }), 50)
})

test('surukleme: ortadaki gorsel iki yana birden buyur', () => {
	assert.equal(dragWidth({ startPx: 400, deltaPx: 50, containerPx: 1000, align: 'center' }), 50)
})

test('surukleme: sinirlar ve olculemeyen kapsayici', () => {
	assert.equal(dragWidth({ startPx: 400, deltaPx: -1000, containerPx: 1000, align: 'left' }), 10)
	assert.equal(dragWidth({ startPx: 400, deltaPx: 5000, containerPx: 1000, align: 'left' }), 100)
	assert.equal(dragWidth({ startPx: 400, deltaPx: 10, containerPx: 0, align: 'left' }), null)
})

test('CSS sunucunun kabul ettigi HER genislik icin kural tasir, fazlasini degil', () => {
	const css = read('../../src/richBody.css')
	const inCss = [...css.matchAll(/\[data-nb-width="(\d+)"\]\s*\{\s*width:\s*(\d+)%/g)]
	for (const [, attr, percent] of inCss) {
		assert.equal(attr, percent, `data-nb-width="${attr}" kurali ${percent}% veriyor`)
	}
	const expected = []
	for (let w = IMAGE_WIDTH_MIN; w <= IMAGE_WIDTH_MAX; w += IMAGE_WIDTH_STEP) {
		expected.push(String(w))
	}
	assert.deepEqual(inCss.map(m => m[1]), expected)

	// Sunucudaki desen aynı kümeyi kabul ediyor mu (PHP'deki düzenli ifadeyle).
	const php = read('../../lib/Service/HtmlSanitizer.php')
	const pattern = php.match(/IMAGE_WIDTH = '\/(.+?)\/';/)[1]
	const regex = new RegExp(pattern)
	for (let w = 0; w <= 120; w++) {
		assert.equal(regex.test(String(w)), expected.includes(String(w)), `sunucu ${w} icin farkli karar veriyor`)
	}
})

test('yerlesim ve aciklama siniri sunucuyla ayni', () => {
	const php = read('../../lib/Service/HtmlSanitizer.php')
	assert.ok(php.includes(`IMAGE_ALIGNS = [${IMAGE_ALIGNS.map(a => `'${a}'`).join(', ')}];`), 'IMAGE_ALIGNS farkli')
	assert.ok(php.includes(`MAX_IMAGE_ALT = ${MAX_IMAGE_ALT};`), 'MAX_IMAGE_ALT farkli')
})

test('yerlesim kurallari okuma gorunumunde ve editorde ayni dosyadan', () => {
	assert.match(read('../../src/components/AnnouncementDetail.vue'), /import '\.\.\/richBody\.css'/)
	assert.match(read('../../src/components/RichTextEditor.vue'), /import '\.\.\/richBody\.css'/)
	assert.match(read('../../src/components/AnnouncementDetail.vue'), /class="nb-detail__body nb-rich"/)
	assert.match(read('../../src/components/RichTextEditor.vue'), /class="nb-editor__content nb-rich"/)
})
