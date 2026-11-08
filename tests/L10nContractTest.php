<?php

declare(strict_types=1);

/**
 * ÇEVİRİ SÖZLEŞMESİ (Faz 9 — KAPI 9: "arayüzde eksik metin yok").
 *
 * Kaynak dil Türkçe; Nextcloud İngilizceyken metinler l10n/en.json'dan gelir.
 * Çevrilmemiş bir metin HATA VERMEZ — kullanıcı sessizce karışık dil görür.
 * Bu test o sessiz kusurun geri gelmesini engeller:
 *   · `t()` dışında kalmış Türkçe metin
 *   · hata mesajının çevrilmeden (`getMessage()`) arayüze dönmesi
 *   · istisna şablonunun birleştirilerek yazılması (sözlüğe giremez)
 *   · çeviride `%s` yer tutucusunun kaybolması
 *
 * ⚠️ run-tests.sh çeviri dosyasını birim testlerden ÖNCE üretir; bu test
 * taze l10n/en.json'u okur.
 *
 * Çalıştırma:  php tests/L10nContractTest.php
 */

namespace {
    require __DIR__ . '/bootstrap.php';
    require __DIR__ . '/../lib/Exception/ValidationException.php';
    require __DIR__ . '/../lib/Exception/AccessDeniedException.php';
    require __DIR__ . '/../lib/Exception/CommentsClosedException.php';

    use OCA\NoticeBoard\Exception\CommentsClosedException;
    use OCA\NoticeBoard\Exception\ValidationException;

    $kok = __DIR__ . '/..';

    /** Yorumları atılmış kaynak (satır numaraları korunur). */
    function yorumsuz(string $s): string {
        $bosalt = static fn (array $m) => str_repeat("\n", substr_count($m[0], "\n"));
        $s = preg_replace_callback('/<!--.*?-->/s', $bosalt, $s) ?? $s;
        $s = preg_replace_callback('#/\*.*?\*/#s', $bosalt, $s) ?? $s;
        $s = preg_replace('#^\s*(//|\#).*$#m', '', $s) ?? $s;

        return $s;
    }

    /** @return string[] */
    function tara(string $dizin, array $uzantilar): array {
        $sonuc = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dizin));
        foreach ($it as $f) {
            foreach ($uzantilar as $u) {
                if ($f->isFile() && str_ends_with($f->getFilename(), $u)) {
                    $sonuc[] = $f->getPathname();
                }
            }
        }
        sort($sonuc);
        return $sonuc;
    }

    $sozluk = json_decode((string)file_get_contents("$kok/l10n/en.json"), true)['translations'] ?? [];

    echo "L10nContractTest\n";

    // =================================================================
    echo "1) t() disinda kalmis Turkce metin\n";
    // =================================================================

    /*
     * Türkçe harf içeren bir satır ya çeviri çağrısında, ya istisna şablonunda
     * (controller'da çevrilir), ya da günlük kaydında olmalı. Günlükler yöneticiye
     * yöneliktir ve çevrilmez — Nextcloud'un kendi uygulamaları da böyle yapar.
     */
    $izinli = '/\bt\(\s*\'noticeboard\'|->t\(|\bn\(\s*\'noticeboard\'|Exception\(\s*\'|logger->|->safely\(|->guard\(/';
    $cevrilmemis = [];

    foreach (array_merge(tara("$kok/lib", ['.php']), tara("$kok/src", ['.vue', '.js']), tara("$kok/templates", ['.php'])) as $f) {
        foreach (explode("\n", yorumsuz((string)file_get_contents($f))) as $no => $satir) {
            // Satır sonu yorumu: kodun kendisi değil.
            $kod = preg_replace('#\s//\s.*$#', '', $satir) ?? $satir;
            if (!preg_match('/[çğıöşüÇĞİÖŞÜ]/u', $kod) || preg_match($izinli, $kod)) {
                continue;
            }
            // Arama katlaması (I/İ/ı → i) bir çeviri değil, harf eşlemesidir.
            if (str_contains($kod, "'İ' => 'i'")) {
                continue;
            }
            // Otomatik kapak: kategori adında ARANAN kelimeler; ekrana yazılmaz.
            if (basename($f) === 'autoCover.js' && str_contains($kod, 'words: [')) {
                continue;
            }
            $cevrilmemis[] = basename($f) . ':' . ($no + 1) . ' ' . trim($kod);
        }
    }
    check('t() disinda Turkce metin yok: ' . implode(' | ', $cevrilmemis), $cevrilmemis === []);

    // =================================================================
    echo "\n2) Istisna mesajlari cevrilebilir\n";
    // =================================================================

    $sablonlar = [];
    $birlestirilmis = [];
    foreach (tara("$kok/lib", ['.php']) as $f) {
        $s = yorumsuz((string)file_get_contents($f));
        preg_match_all('/new \w+Exception\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $s, $m);
        foreach ($m[1] as $sablon) {
            $sablonlar[] = $sablon;
        }
        if (preg_match('/new \w+Exception\(\s*\'[^\']*\'\s*\./', $s)) {
            $birlestirilmis[] = basename($f);
        }
    }

    check('taranacak istisna sablonu bulundu (tarayici calisiyor)', count($sablonlar) >= 15);
    check(
        'istisna sablonu birlestirilerek yazilmamis (%s kullan): ' . implode(', ', $birlestirilmis),
        $birlestirilmis === []
    );

    $eksik = array_values(array_filter(array_unique($sablonlar), static fn ($s) => !isset($sozluk[$s])));
    check('her istisna sablonunun Ingilizcesi var: ' . implode(' | ', $eksik), $eksik === []);

    // =================================================================
    echo "\n3) Controller'lar mesaji kullanicinin dilinde donduruyor\n";
    // =================================================================

    foreach (tara("$kok/lib/Controller", ['.php']) as $f) {
        $s = yorumsuz((string)file_get_contents($f));
        $ad = basename($f);
        check("$ad hata mesajini getMessage() ile donmuyor", !preg_match("/'message'\s*=>\s*\\\$e->getMessage\(\)/", $s));
        check("$ad 'message' degerini ham metin olarak yazmiyor", !preg_match("/'message'\s*=>\s*'/", $s));
    }

    // =================================================================
    echo "\n4) Ceviri sozlugu butunlugu\n";
    // =================================================================

    $yerTutucuBozuk = [];
    foreach ($sozluk as $kaynak => $ceviri) {
        foreach (['%s', '%1$s', '%2$s', '{'] as $yt) {
            if (substr_count($kaynak, $yt) !== substr_count($ceviri, $yt)) {
                $yerTutucuBozuk[] = $kaynak;
                break;
            }
        }
        preg_match_all('/\{(\w+)\}/', $kaynak, $a);
        preg_match_all('/\{(\w+)\}/', $ceviri, $b);
        $ak = $a[1];
        $bk = $b[1];
        sort($ak);
        sort($bk);
        if ($ak !== $bk) {
            $yerTutucuBozuk[] = $kaynak;
        }
    }
    check('ceviride yer tutucular korunuyor: ' . implode(' | ', array_unique($yerTutucuBozuk)), $yerTutucuBozuk === []);

    $turkceKalan = array_keys(array_filter($sozluk, static fn ($c) => (bool)preg_match('/[ğışĞİŞ]/u', $c)));
    check('Ingilizce cevirilerde Turkce harf kalmamis: ' . implode(' | ', $turkceKalan), $turkceKalan === []);

    $bos = array_keys(array_filter($sozluk, static fn ($c) => trim((string)$c) === ''));
    check('bos ceviri yok', $bos === []);

    // Tarayıcı en.js'i, sunucu en.json'u okur; biri eksikse iki taraf ayrı dil konuşur.
    $enJs = (string)file_get_contents("$kok/l10n/en.js");
    $jsEksik = array_filter(
        array_keys($sozluk),
        static fn ($k) => !str_contains($enJs, json_encode((string)$k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
    );
    check('en.js her metni tasiyor: ' . implode(' | ', $jsEksik), $jsEksik === []);

    // =================================================================
    echo "\n5) TranslatableException davranisi\n";
    // =================================================================

    /** Sözlükten çeviren, NC gibi vsprintf uygulayan IL10N ikizi. */
    $ingilizce = new class($sozluk) implements \OCP\IL10N {
        public function __construct(private array $sozluk) {
        }

        public function t(string $text, $parameters = []): string {
            return vsprintf($this->sozluk[$text] ?? $text, (array)$parameters);
        }
    };

    $e = new ValidationException('Başlık en fazla %s karakter olabilir.', [255]);
    check('getMessage okunur Turkce kalir', $e->getMessage() === 'Başlık en fazla 255 karakter olabilir.');
    check('sablon ham saklanir', $e->getTemplate() === 'Başlık en fazla %s karakter olabilir.');
    check('parametreler saklanir', $e->getParameters() === [255]);
    check('Ingilizceye parametresiyle cevrilir', $e->translate($ingilizce) === 'The title can be at most 255 characters long.');

    $k = new CommentsClosedException('Bu duyuruda yorumlar kapalı.');
    check('alt sinif da cevrilir', $k->translate($ingilizce) === 'Comments are closed for this announcement.');
    check('parametresiz istisna bos parametre tasir', $k->getParameters() === []);

    // =================================================================
    echo "\n6) Turkce dosyasi (kimlik eslemesi)\n";
    // =================================================================

    /*
     * tr.json yoksa Nextcloud PHP tarafında Türkçeyi "yok" sayıp İngilizceye
     * düşer (24 Eyl 2026'da canlıda ölçüldü, Hata #26). Değerler kaynağın
     * AYNISI olmalı; biri İngilizce kalırsa Türkçe kullanıcı onu İngilizce görür.
     */
    $tr = json_decode((string)@file_get_contents("$kok/l10n/tr.json"), true)['translations'] ?? null;
    check('l10n/tr.json var', is_array($tr));
    check('l10n/tr.js var', is_file("$kok/l10n/tr.js"));

    $trEksik = array_values(array_filter(array_keys($sozluk), static fn ($k) => !isset(($tr ?? [])[$k])));
    check('en.json\'daki her metin tr.json\'da da var: ' . implode(' | ', $trEksik), $trEksik === []);

    $trFarkli = array_keys(array_filter($tr ?? [], static fn ($v, $k) => $v !== $k, ARRAY_FILTER_USE_BOTH));
    check('tr.json kimlik eslemesi (deger == kaynak): ' . implode(' | ', $trFarkli), $trFarkli === []);

    finish('L10nContractTest');
}
