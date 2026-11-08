<?php

declare(strict_types=1);

/**
 * SpotlightSettings — vitrin widget'ının kurum geneli ayarları.
 *
 * Korunan şey: widget'a HİÇBİR ZAMAN geçersiz ayar gitmez. Ya kayıtta reddedilir,
 * ya da (occ ile elle bozulmuşsa) okumada varsayılana düşer — aksi halde
 * `setInterval(..., 0)` gibi bir değer tarayıcıyı kilitlerdi.
 *
 * Çalıştırma:  php tests/SpotlightSettingsTest.php
 */

namespace {
    require __DIR__ . '/bootstrap.php';
    require __DIR__ . '/../lib/Exception/ValidationException.php';
    require __DIR__ . '/../lib/Service/SpotlightSettings.php';

    use OCA\NoticeBoard\Service\SpotlightSettings;
    use OCA\NoticeBoard\Tests\FakeAppConfig;

    echo "SpotlightSettings\n";

    $config = new FakeAppConfig();
    $ayar = new SpotlightSettings($config);

    check('varsayilan: 8 sn, tum duyurular', $ayar->get() === ['interval' => 8, 'filter' => 'all']);

    $ayar->set(12, 'featured');
    check('kaydedilen ayar okunur', $ayar->get() === ['interval' => 12, 'filter' => 'featured']);

    $ayar->set(0, 'all');
    check('0 = otomatik donme kapali kabul edilir', $ayar->get()['interval'] === 0);

    $ayar->set(4, 'all');
    check('sinir: en kisa sure (4) kabul edilir', $ayar->get()['interval'] === 4);
    $ayar->set(60, 'all');
    check('sinir: en uzun sure (60) kabul edilir', $ayar->get()['interval'] === 60);

    checkThrows('sinir: 3 sn reddedilir (cok hizli, okunamaz)', fn () => $ayar->set(3, 'all'));
    checkThrows('sinir: 61 sn reddedilir', fn () => $ayar->set(61, 'all'));
    checkThrows('negatif sure reddedilir', fn () => $ayar->set(-5, 'all'));
    checkThrows('bilinmeyen secim reddedilir', fn () => $ayar->set(8, 'hepsi'));
    check('reddedilen kayit eski ayari bozmaz', $ayar->get() === ['interval' => 60, 'filter' => 'all']);

    // occ ile elle bozuk değer yazılmış: widget yine çalışmalı.
    $config->setValueInt('noticeboard', 'spotlight_interval', 1);
    $config->setValueString('noticeboard', 'spotlight_filter', '<script>');
    check('bozuk kayitli deger varsayilana duser', $ayar->get() === ['interval' => 8, 'filter' => 'all']);

    finish('SpotlightSettingsTest');
}
