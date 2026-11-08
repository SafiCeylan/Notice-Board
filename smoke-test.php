<?php

declare(strict_types=1);

/**
 * DUMAN TESTİ — deploy sonrası sunucuda, GERÇEK Nextcloud içinde koşar.
 *
 * NEDEN: yerel testler OCP arayüzlerinin İKİZLERİYLE koşar. 24 Eyl 2026'da
 * ikizlerde olmayan bir miras (IReloadableWidget → IAPIWidgetV2) canlıda panoyu
 * ölümcül hatayla düşürdü ve deploy "başarılı" dedi (Hata #33). Bu betik app'in
 * sınıflarını sunucunun kendi OCP'siyle yükler; bir şey patlarsa çıkış kodu 1.
 *
 * deploy.sh ve install.sh tarafından /tmp'ye kopyalanıp web kullanıcısıyla
 * çalıştırılır; app klasörüne girmez (sürüm paketinde tools/ altında durur).
 */

// install.sh Nextcloud kökünü argüman olarak verir; deploy.sh vermez (test sunucusu yolu).
require rtrim($argv[1] ?? '/var/www/html/nextcloud', '/') . '/lib/base.php';

// Public API (NC 27+). `\OC_App::loadApp` özel ve 27'den beri kullanımdan kalkmış;
// silindiği sürümde duman testi — yani kurulumun kendini doğrulaması — bozulurdu.
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('noticeboard');

$hatalar = [];

try {
    $widgetlar = array_filter(
        \OCP\Server::get(\OCP\Dashboard\IManager::class)->getWidgets(),
        static fn ($id) => str_starts_with((string)$id, 'noticeboard'),
        ARRAY_FILTER_USE_KEY
    );
    if (count($widgetlar) !== 3) {
        $hatalar[] = '3 widget bekleniyordu, ' . count($widgetlar) . ' bulundu';
    }
    foreach ($widgetlar as $id => $w) {
        if ($w instanceof \OCP\Dashboard\IAPIWidgetV2) {
            $hatalar[] = "$id IAPIWidgetV2 — web panosu Vue tasarımını atlar (Hata #31)";
        }
    }
} catch (\Throwable $e) {
    $hatalar[] = 'widgetlar: ' . $e->getMessage();
}

$siniflar = [
    \OCA\NoticeBoard\Search\SearchProvider::class,
    \OCA\NoticeBoard\Notification\Notifier::class,
    \OCA\NoticeBoard\Activity\Provider::class,
    // Talk kurulu olmasa da kurulabilmeli: Talk sınıfları yalnız çalışma anında çözülür.
    \OCA\NoticeBoard\Service\TalkService::class,
    \OCA\NoticeBoard\BackgroundJob\SendTalkJob::class,
];
foreach (['Announcement', 'Comment', 'Picker', 'File', 'Admin', 'Page'] as $c) {
    $siniflar[] = 'OCA\\NoticeBoard\\Controller\\' . $c . 'Controller';
}
foreach ($siniflar as $sinif) {
    try {
        \OCP\Server::get($sinif);
    } catch (\Throwable $e) {
        $hatalar[] = "$sinif: " . $e->getMessage();
    }
}

// Yabancı bir etkinlik, NC'nin GERÇEK "bilmiyorum" istisnasıyla reddedilmeli.
// Yanlış sınıf adı yerelde ikizlerle geçti, canlıda her Dosyalar etkinliğinde
// "Class not found" verdi (Hata #39).
try {
    $olay = \OCP\Server::get(\OCP\Activity\IManager::class)->generateEvent()
        ->setApp('files')->setType('file_changed')->setSubject('changed_self');
    \OCP\Server::get(\OCA\NoticeBoard\Activity\Provider::class)->parse('en', $olay);
    $hatalar[] = 'Activity\\Provider yabanci etkinligi reddetmedi';
} catch (\Throwable $e) {
    // NC 30+ UnknownActivityException, NC 29 ve oncesi InvalidArgumentException.
    $beklenen = class_exists(\OCP\Activity\Exceptions\UnknownActivityException::class)
        ? \OCP\Activity\Exceptions\UnknownActivityException::class
        : \InvalidArgumentException::class;
    if (get_class($e) !== $beklenen) {
        $hatalar[] = 'Activity\\Provider: ' . get_class($e) . ' ' . $e->getMessage() . " (beklenen: $beklenen)";
    }
}

if ($hatalar !== []) {
    fwrite(STDERR, "DUMAN TESTI KIRMIZI:\n  - " . implode("\n  - ", $hatalar) . "\n");
    exit(1);
}

echo '    3 widget (V2 degil) + ' . count($siniflar) . " sinif gercek Nextcloud'da yuklendi\n";

// Talk kanalı isteğe bağlı: uyumsuzluk kurulumu geri aldırmaz, yalnız kanal kapanır.
// Ama Talk ETKİNKEN kapanmışsa bunu kurulumu yapan görmeli.
try {
    $talkSorunlari = \OCP\Server::get(\OCA\NoticeBoard\Service\TalkGateway::class)->problems();
    echo $talkSorunlari === []
        ? "    Talk kanali kullanilabilir\n"
        : '    Talk kanali KAPALI: ' . implode('; ', $talkSorunlari) . "\n";
    if ($talkSorunlari !== [] && !str_starts_with($talkSorunlari[0], 'Talk (spreed) etkin degil')) {
        echo "    UYARI: Talk etkin ama uyumsuz — duyurular Talk'a gitmeyecek\n";
    }
} catch (\Throwable $e) {
    echo '    UYARI: Talk denetimi yapilamadi: ' . $e->getMessage() . "\n";
}
