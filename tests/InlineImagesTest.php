<?php

declare(strict_types=1);

/**
 * InlineImages — metne gömülü görselin sahibi ve "bu görsel bu duyurunun mu?".
 *
 * Bu testler kırmızıya dönerse: duyuruyu düzenleyebilen biri başkasının özel
 * dosyasının kimliğini gövdeye yazıp tüm okuyuculara servis ettirebilir, ya da
 * yönetici yazım hatası düzeltince yazarın görselleri kırılır.
 *
 * Çalıştırma:  php tests/InlineImagesTest.php
 */

namespace OCA\NoticeBoard\Tests {
    require __DIR__ . '/bootstrap.php';
    require __DIR__ . '/../lib/Exception/ValidationException.php';
    require __DIR__ . '/../lib/Service/HtmlSanitizer.php';
    require __DIR__ . '/FakeUserFiles.php';
    require __DIR__ . '/../lib/Service/InlineImages.php';
}

namespace {
    use OCA\NoticeBoard\Exception\ValidationException;
    use OCA\NoticeBoard\Service\HtmlSanitizer;
    use OCA\NoticeBoard\Service\InlineImages;
    use OCA\NoticeBoard\Tests\FakeUserFiles;

    // ali: 10, 11 (görsel), 12 (pdf) · admin: 20 (görsel)
    $images = new InlineImages(new FakeUserFiles(
        ['ali' => [10, 11, 12], 'admin' => [20]],
        [],
        [10, 11, 20],
    ));
    $s = new HtmlSanitizer();

    /** İstemciden gelen gövdenin servisteki yolu: önce temizle, sonra bağla. */
    $kaydet = fn (string $html, string $onceki, string $kaydeden, string $yazar = 'ali')
        => $images->bind($s->sanitize($html), $onceki, $kaydeden, $yazar);

    $reddedilir = function (callable $fn): bool {
        try {
            $fn();
        } catch (ValidationException) {
            return true;
        }
        return false;
    };

    echo "InlineImages — yeni gorsel\n";

    $ilk = $kaydet('<p>a</p><img data-nb-file="10">', '', 'ali');
    check('kaydedenin kendi gorseline sahip yazilir', $images->owners($ilk, 'ali') === [10 => 'ali']);

    check('baskasinin dosyasi reddedilir',
        $reddedilir(fn () => $kaydet('<img data-nb-file="20">', '', 'ali')));

    check('gorsel olmayan dosya (pdf) reddedilir',
        $reddedilir(fn () => $kaydet('<img data-nb-file="12">', '', 'ali')));

    check('istemcinin yazdigi sahip DIKKATE ALINMAZ',
        $reddedilir(fn () => $kaydet('<img data-nb-file="20" data-nb-owner="admin">', '', 'ali')));

    $cok = str_repeat('<img data-nb-file="10">', InlineImages::MAX_IMAGES + 1);
    check('gorsel sayisi tavani asilirsa reddedilir', $reddedilir(fn () => $kaydet($cok, '', 'ali')));

    $tavan = str_repeat('<img data-nb-file="10">', InlineImages::MAX_IMAGES);
    check('tavana kadar gorsel kabul edilir', !$reddedilir(fn () => $kaydet($tavan, '', 'ali')));

    echo "\nInlineImages — duzenleme\n";

    // Yönetici, yazarın görselini barındıran duyurunun yalnız metnini düzeltiyor.
    // İstemci kayıtlı sahibi geri gönderse de göndermese de sonuç aynı olmalı.
    $duzelt = $kaydet('<p>duzeltildi</p><img data-nb-file="10">', $ilk, 'admin');
    check('mevcut gorsel onceki sahibini korur', $images->owners($duzelt, 'ali') === [10 => 'ali']);

    $ekle = $kaydet('<img data-nb-file="10"><img data-nb-file="20">', $ilk, 'admin');
    check('yoneticinin ekledigi gorsel yoneticiye yazilir',
        $images->owners($ekle, 'ali') === [10 => 'ali', 20 => 'admin']);

    check('silinip geri eklenen YABANCI gorsel reddedilir (onceki govdede yoksa)',
        $reddedilir(fn () => $kaydet('<img data-nb-file="20">', $ilk, 'ali')));

    echo "\nInlineImages — okuma\n";

    check('sahibi yazilmamis eski gorsel yazarindir',
        $images->owners('<img data-nb-file="11">', 'ali') === [11 => 'ali']);
    check('gorselsiz govde bos liste', $images->owners('<p>metin</p>', 'ali') === []);
    check('gorselsiz govde bind ile degismez', $kaydet('<p>metin</p>', '', 'ali') === '<p>metin</p>');
    check('turkce metin bind sonrasi bozulmaz',
        str_contains($kaydet('<p>Şğüöçİ</p><img data-nb-file="10">', '', 'ali'), 'Şğüöçİ'));

    $yerlesik = $kaydet('<img data-nb-file="10" data-nb-align="right" data-nb-width="35" alt="Çay ocağı">', '', 'ali');
    check('yerlesim ve aciklama bind sonrasi korunur',
        str_contains($yerlesik, 'data-nb-align="right"')
        && str_contains($yerlesik, 'data-nb-width="35"')
        && str_contains($yerlesik, 'alt="Çay ocağı"')
        && str_contains($yerlesik, 'data-nb-owner="ali"'));
    check('yerlesimi degisen eski gorsel sahibini korur',
        str_contains($kaydet('<img data-nb-file="10" data-nb-align="left">', '<img data-nb-file="10" data-nb-owner="ali">', 'admin'),
            'data-nb-owner="ali"'));

    finish('InlineImagesTest');
}
