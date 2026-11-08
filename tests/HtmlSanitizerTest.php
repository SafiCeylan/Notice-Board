<?php

declare(strict_types=1);

/**
 * HtmlSanitizer — depolanmış XSS'e karşı tek savunma hattı.
 *
 * Duyuru gövdesi arayüzde `v-html` ile basılıyor. Bu testler kırmızıya dönerse
 * uygulama kullanıcıların tarayıcısında saldırganın kodunu çalıştırıyor demektir.
 * **Bu dosyadaki bir testi "geçsin diye" gevşetme.**
 *
 * Çalıştırma:  php tests/HtmlSanitizerTest.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../lib/Service/HtmlSanitizer.php';

use OCA\NoticeBoard\Service\HtmlSanitizer;

$s = new HtmlSanitizer();

/** Çıktıda tehlikeli bir iz kalmadığını doğrular. */
function temiz(string $name, string $input, array $olmamali): void {
    global $s;
    $out = strtolower($s->sanitize($input));
    foreach ($olmamali as $iz) {
        if (str_contains($out, strtolower($iz))) {
            check("$name (iz: $iz)", false);
            return;
        }
    }
    check($name, true);
}

echo "HtmlSanitizer — tehlikeli icerik\n";

temiz('script etiketi tamamen silinir',
    '<p>merhaba</p><script>alert(1)</script>',
    ['script', 'alert']);

temiz('olay ozniteligi (onclick) silinir',
    '<p onclick="alert(1)">tikla</p>',
    ['onclick', 'alert']);

temiz('onerror ile img enjeksiyonu silinir',
    '<img src=x onerror="alert(1)">',
    ['onerror', 'alert', '<img']);

temiz('javascript: baglantisi silinir',
    '<a href="javascript:alert(1)">tikla</a>',
    ['javascript:', 'alert']);

temiz('data: baglantisi silinir',
    '<a href="data:text/html,<script>alert(1)</script>">tikla</a>',
    ['data:', 'script']);

temiz('iframe silinir',
    '<iframe src="https://kotu.example"></iframe>',
    ['iframe']);

temiz('style etiketi silinir',
    '<style>body{display:none}</style><p>metin</p>',
    ['<style', 'display:none']);

temiz('style ozniteligindeki url() silinir',
    '<span style="background-image:url(javascript:alert(1))">x</span>',
    ['url(', 'javascript']);

temiz('svg icindeki script silinir',
    '<svg><script>alert(1)</script></svg>',
    ['script', 'alert']);

temiz('form ve input silinir (kimlik avi)',
    '<form action="https://kotu.example"><input name="parola"></form>',
    ['<form', '<input']);

temiz('HTML yorumu silinir',
    '<p>a</p><!--[if IE]><script>alert(1)</script><![endif]-->',
    ['<!--', 'script']);

temiz('bilinmeyen etikette olay ozniteligi de gider',
    '<marquee onstart="alert(1)">kay</marquee>',
    ['onstart', 'alert', 'marquee']);

echo "\nHtmlSanitizer — gomulu gorsel (20 Eki 2026)\n";

temiz('dis adresli gorsel tamamen silinir (izleme pikseli)',
    '<img src="https://izci.example/p.gif">',
    ['<img', 'izci']);

temiz('dosya kimligi rakam degilse gorsel silinir',
    '<img data-nb-file="1 onerror=alert(1)">',
    ['<img', 'onerror', 'alert']);

temiz('dosya kimligi javascript ise gorsel silinir',
    '<img data-nb-file="javascript:alert(1)">',
    ['<img', 'javascript']);

temiz('gecerli gorselde src ve olay ozniteligi atilir',
    '<img data-nb-file="42" src="https://izci.example/p.gif" onerror="alert(1)" onload="x()">',
    ['src', 'izci', 'onerror', 'alert', 'onload']);

temiz('istemcinin yazdigi sahip (data-nb-owner) atilir',
    '<img data-nb-file="42" data-nb-owner="baskasi">',
    ['data-nb-owner', 'baskasi']);

temiz('gorselde style atilir',
    '<img data-nb-file="42" style="background:url(javascript:alert(1))">',
    ['style', 'javascript']);

check('dosya kimlikli gorsel korunur',
    $s->sanitize('<p>a</p><img data-nb-file="42"><p>b</p>') === '<p>a</p><img data-nb-file="42"><p>b</p>');

check('gorsel duz metne bosluk olarak duser',
    $s->toPlainText($s->sanitize('<p>a</p><img data-nb-file="42"><p>b</p>')) === 'a b');

echo "\nHtmlSanitizer — gorsel yerlesimi (27 Eki 2026)\n";

check('yerlesim, genislik ve aciklama korunur',
    $s->sanitize('<img data-nb-file="42" data-nb-align="left" data-nb-width="40" alt="Toplanti salonu">')
        === '<img data-nb-file="42" data-nb-align="left" data-nb-width="40" alt="Toplanti salonu">');

foreach (['left', 'center', 'right'] as $align) {
    check("yerlesim '$align' korunur",
        str_contains($s->sanitize('<img data-nb-file="42" data-nb-align="' . $align . '">'), 'data-nb-align="' . $align . '"'));
}

foreach (['10', '25', '50', '75', '95', '100'] as $width) {
    check("genislik $width korunur",
        str_contains($s->sanitize('<img data-nb-file="42" data-nb-width="' . $width . '">'), 'data-nb-width="' . $width . '"'));
}

// CSS'te kurali olmayan her deger atilir: 5'in kati olmayan, aralik disi, birimli, ifade.
foreach (['5', '0', '105', '110', '42', '33', '050', '50%', '50px', '-50', ' 1e2', 'calc(100%)', '50;position:fixed'] as $width) {
    check("gecersiz genislik '$width' atilir",
        !str_contains($s->sanitize('<img data-nb-file="42" data-nb-width="' . $width . '">'), 'data-nb-width'));
}

foreach (['LEFT', 'float', 'left;position:fixed', 'justify', ''] as $align) {
    check("gecersiz yerlesim '$align' atilir",
        !str_contains($s->sanitize('<img data-nb-file="42" data-nb-align="' . $align . '">'), 'data-nb-align'));
}

/** Çıktıyı tarayıcı gibi ayrıştırıp görselin öznitelikleri: ad => değer. */
function gorselOznitelikleri(string $html): array {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $out = [];
    foreach ($doc->getElementsByTagName('img')->item(0)?->attributes ?? [] as $a) {
        $out[$a->name] = $a->value;
    }
    return $out;
}

// libxml, değerde " varsa özniteliği tek tırnakla yazar; önemli olan çıktının
// AYRIŞTIRILINCA yeni öznitelik açmaması.
$kacis = gorselOznitelikleri($s->sanitize('<img data-nb-file="42" alt="&quot; onerror=&quot;alert(1)">'));
check('aciklamadan oznitelik kacisi yapilamaz (onerror acilmaz)',
    array_keys($kacis) === ['data-nb-file', 'alt'] && $kacis['alt'] === '" onerror="alert(1)');

$tirnak = gorselOznitelikleri($s->sanitize('<img data-nb-file="42" alt="it&#39;s &quot;q&quot; &lt;b&gt;">'));
check('aciklamada iki tur tirnak ve acili parantez ayni metin olarak kalir',
    array_keys($tirnak) === ['data-nb-file', 'alt'] && $tirnak['alt'] === 'it\'s "q" <b>');

check('aciklamadaki acili parantez kacislanir',
    !str_contains($s->sanitize('<img data-nb-file="42" alt="&lt;script&gt;">'), '<script'));

check('aciklama 200 karakterle sinirlanir',
    mb_strlen((string)preg_replace('/^.*alt="([^"]*)".*$/s', '$1',
        $s->sanitize('<img data-nb-file="42" alt="' . str_repeat('ş', 300) . '">'))) === 200);

check('aciklamadaki kontrol karakterleri bosluk olur',
    str_contains($s->sanitize("<img data-nb-file=\"42\" alt=\"a\tb\">"), 'alt="a b"'));

check('bos aciklama atilir',
    $s->sanitize('<img data-nb-file="42" alt="   ">') === '<img data-nb-file="42">');

check('yerlesim oznitelikleri baska etikette atilir',
    $s->sanitize('<p data-nb-align="left" data-nb-width="50">a</p>') === '<p>a</p>');

check('yerlesim varken dis adresli gorsel yine atilir',
    !str_contains($s->sanitize('<img src="https://izci.example/p.gif" data-nb-align="left">'), '<img'));

echo "\nHtmlSanitizer — mesru icerik korunur\n";

check('kalin ve italik korunur',
    str_contains($s->sanitize('<p><strong>kalin</strong> ve <em>italik</em></p>'), '<strong>kalin</strong>'));

check('liste korunur',
    str_contains($s->sanitize('<ul><li>bir</li><li>iki</li></ul>'), '<li>bir</li>'));

check('baslik korunur',
    str_contains($s->sanitize('<h2>Baslik</h2>'), '<h2>Baslik</h2>'));

check('alinti korunur',
    str_contains($s->sanitize('<blockquote>soz</blockquote>'), '<blockquote>'));

$link = $s->sanitize('<a href="https://nextcloud.com">site</a>');
check('https baglantisi korunur', str_contains($link, 'href="https://nextcloud.com"'));
check('dis baglantiya rel="noopener noreferrer" eklenir', str_contains($link, 'noopener noreferrer'));
check('dis baglanti yeni sekmede acilir', str_contains($link, 'target="_blank"'));

check('mailto baglantisi korunur',
    str_contains($s->sanitize('<a href="mailto:a@b.com">yaz</a>'), 'mailto:a@b.com'));

check('gecerli renk korunur',
    str_contains($s->sanitize('<span style="color: #ff0000">kirmizi</span>'), 'color: #ff0000'));

check('arka plan rengi (vurgu) korunur',
    str_contains($s->sanitize('<span style="background-color: yellow">vurgu</span>'), 'background-color: yellow'));

check('izin verilmeyen css ozelligi atilir',
    !str_contains($s->sanitize('<span style="color: red; position: fixed">x</span>'), 'position'));

check('renk korunurken yasak ozellik atilinca renk kalir',
    str_contains($s->sanitize('<span style="color: red; position: fixed">x</span>'), 'color: red'));

check('bilinmeyen etiketin ICERIGI korunur (unwrap)',
    str_contains($s->sanitize('<article>onemli metin</article>'), 'onemli metin'));

check('turkce karakterler bozulmaz',
    str_contains($s->sanitize('<p>Şğüöçİ ısırgan</p>'), 'Şğüöçİ ısırgan'));

check('bos girdi bos doner', $s->sanitize('   ') === '');

echo "\nHtmlSanitizer — duz metin cikarma\n";

check('duz metin etiketsiz doner',
    $s->toPlainText('<p>Birinci</p><p>Ikinci</p>') === 'Birinci Ikinci');

/*
 * ⚠️ ÖNEMLİ AYRIM: toPlainText() bir TEMİZLEYİCİ DEĞİLDİR. `strip_tags` etiketi
 * atar ama içindeki metni bırakır — yani ham `<script>alert(1)</script>` verilirse
 * "alert(1)" geri döner. Güvenli olması, kendisine ZATEN sanitize edilmiş gövdenin
 * verilmesine bağlı (gövde veritabanına temizlenmiş yazılıyor).
 * Aşağıdaki iki test bu sözleşmeyi açıkça kilitler.
 */
check('toPlainText TEK BASINA temizleyici degildir (ham girdide metin kalir)',
    str_contains($s->toPlainText('<script>alert(1)</script>merhaba'), 'alert'));

$temizlenmis = $s->toPlainText($s->sanitize('<script>alert(1)</script>merhaba'));
check('sanitize sonrasi duz metinde script icerigi KALMAZ',
    !str_contains($temizlenmis, 'alert'));
check('sanitize sonrasi mesru metin korunur',
    str_contains($temizlenmis, 'merhaba'));

finish('HtmlSanitizerTest');
