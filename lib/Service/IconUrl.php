<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\AppInfo\Application;
use OCP\IURLGenerator;

/**
 * Uygulama ikonlarının adresi — içerik özetiyle (`…/app-dark.svg?v=1a2b3c4d`).
 *
 * NEDEN: Nextcloud ikonları tarayıcıya **6 ay** önbelleklettiriyor
 * (`Cache-Control: max-age=15778463`). Aynı adla düzeltilen ikon kullanıcıya
 * ulaşmıyor: 24 Eyl'de `app-dark.svg` beyazdan koyuya düzeltildi, ama
 * önbellekteki eski BEYAZ dosyayı Nextcloud karanlık temada ters çevirip SİYAH
 * gösterdi — 7 Eki'de yönetici menüsünde "siyah kare" olarak geri geldi (Hata #29,
 * #45). Adres dosyanın içeriğine bağlı olunca ikon değiştiği anda adres de
 * değişir; önbellek kendiliğinden kırılır, kimsenin tarayıcısını temizlemesi gerekmez.
 *
 * ⚠️ İkon adresi üreten HER yer buradan geçer; doğrudan `imagePath()` yazılmaz
 * (WidgetContractTest kilitler).
 */
class IconUrl {

    /** @var array<string, string> istek boyunca dosya başına bir kez okunur */
    private static array $versions = [];

    /** Göreli adres (arama sonucu, ayarlar menüsü). */
    public static function path(IURLGenerator $urlGenerator, string $file): string {
        $version = self::version($file);
        $path = $urlGenerator->imagePath(Application::APP_ID, $file);

        return $version === '' ? $path : $path . '?v=' . $version;
    }

    /** Mutlak adres (bildirim, etkinlik, pano — başka istemciler de okur). */
    public static function absolute(IURLGenerator $urlGenerator, string $file): string {
        return $urlGenerator->getAbsoluteURL(self::path($urlGenerator, $file));
    }

    private static function version(string $file): string {
        if (!isset(self::$versions[$file])) {
            $hash = @md5_file(__DIR__ . '/../../img/' . basename($file));
            self::$versions[$file] = $hash === false ? '' : substr($hash, 0, 8);
        }

        return self::$versions[$file];
    }
}
