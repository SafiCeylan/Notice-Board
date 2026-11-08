<?php

declare(strict_types=1);

/**
 * Widget sınıfları Nextcloud'un GERÇEK arayüz zinciriyle yüklenebiliyor mu?
 *
 * NEDEN: 24 Eyl 2026'da widget'lardan `IAPIWidgetV2` çıkarıldı ama
 * `IReloadableWidget` bırakıldı. O arayüz V2'yi MİRAS ALIR; sınıf `getItemsV2`
 * eksik kaldı ve canlıda panoyu ölümcül hatayla düşürdü (Hata #33). Kaynak
 * tarayan testler (`implements` satırına bakan) bunu göremedi — miras bilgisi
 * kaynakta değil, Nextcloud'da.
 *
 * Bu test OCP arayüzlerinin ikizlerini NC 32.0.15'teki MİRAS ZİNCİRİYLE BİREBİR
 * tanımlar ve widget dosyalarını gerçekten yükler. Eksik metot varsa PHP burada
 * da canlıdaki gibi ölümcül hata verir → test kırmızı.
 *
 * ⚠️ NC yükseltmesinden sonra bu ikizleri sunucudaki
 * `lib/public/Dashboard/*.php` ile karşılaştır.
 *
 * Çalıştırma:  php tests/WidgetInterfaceTest.php
 */

namespace OCP\Dashboard\Model {

    class WidgetItem {
        public function __construct(
            public string $title = '',
            public string $subtitle = '',
            public string $link = '',
            public string $iconUrl = '',
            public string $sinceId = '',
            public string $overlayIconUrl = '',
        ) {
        }
    }

    class WidgetItems {
    }

    class WidgetButton {
        public const TYPE_NEW = 'new';
        public const TYPE_MORE = 'more';
        public const TYPE_SETUP = 'setup';

        public function __construct(public string $type, public string $link, public string $text) {
        }
    }
}

namespace OCP\Dashboard {

    use OCP\Dashboard\Model\WidgetItems;

    interface IWidget {
        public function getId(): string;
        public function getTitle(): string;
        public function getOrder(): int;
        public function getIconClass(): string;
        public function getUrl(): ?string;
        public function load(): void;
    }

    interface IIconWidget extends IWidget {
        public function getIconUrl(): string;
    }

    interface IButtonWidget extends IWidget {
        public function getWidgetButtons(string $userId): array;
    }

    interface IAPIWidget extends IWidget {
        public function getItems(string $userId, ?string $since = null, int $limit = 7): array;
    }

    interface IAPIWidgetV2 extends IWidget {
        public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems;
    }

    /** ⚠️ NC 32'de V2'yi MİRAS ALIR — bu testin var olma sebebi. */
    interface IReloadableWidget extends IAPIWidgetV2 {
        public function getReloadInterval(): int;
    }
}

namespace OCP\AppFramework\Services {

    interface IInitialState {
        public function provideInitialState(string $key, $data): void;
    }
}

namespace OCP {

    interface IURLGenerator {
        public function linkToRoute(string $routeName, array $arguments = []): string;
        public function linkToRouteAbsolute(string $routeName, array $arguments = []): string;
        public function imagePath(string $appName, string $file): string;
        public function getAbsoluteURL(string $url): string;
    }

    class Util {
        /** @var array<int, string> */
        public static array $scripts = [];
        /** @var array<int, string> */
        public static array $styles = [];

        public static function addScript(string $application, ?string $file = null, string $afterAppId = 'core', bool $prepend = false): void {
            self::$scripts[] = "$application/$file";
        }

        public static function addStyle(string $application, ?string $file = null, bool $prepend = false): void {
            self::$styles[] = "$application/$file";
        }
    }
}

namespace OCA\NoticeBoard\Service {

    /** Tür ipucu için; veri yolu WidgetContractTest'te ve SearchTest'te sınanıyor. */
    class AnnouncementService {
        public function findVisibleFor($user, int $limit = 50, int $offset = 0): array {
            return [[
                'id' => 7, 'subject' => 'Bakım', 'category' => 'Teknik',
                'message' => '<p>Sunucu kapalı.</p>', 'coverFileId' => null, 'createdAt' => 1700000000,
            ]];
        }
    }

    class HtmlSanitizer {
        public function toPlainText(string $html): string {
            return trim(strip_tags($html));
        }
    }

    class SpotlightSettings {
        public function get(): array {
            return ['interval' => 12, 'filter' => 'featured'];
        }
    }
}

namespace {

    require __DIR__ . '/bootstrap.php';

    // ⚠️ Bu require'lar ölümcül hata verirse (eksik arayüz metodu) test süreci
    // kırmızı çıkış koduyla biter — tam da istenen davranış.
    require __DIR__ . '/../lib/Service/IconUrl.php';
    require __DIR__ . '/../lib/Dashboard/AbstractAnnouncementWidget.php';
    require __DIR__ . '/../lib/Dashboard/ListWidget.php';
    require __DIR__ . '/../lib/Dashboard/CardWidget.php';
    require __DIR__ . '/../lib/Dashboard/SpotlightWidget.php';

    use OCA\NoticeBoard\Dashboard\CardWidget;
    use OCA\NoticeBoard\Dashboard\ListWidget;
    use OCA\NoticeBoard\Dashboard\SpotlightWidget;
    use OCP\Dashboard\IAPIWidget;
    use OCP\Dashboard\IAPIWidgetV2;

    echo "WidgetInterfaceTest\n";

    check('uc widget sinifi NC arayuz zinciriyle YUKLENDI (eksik metot yok)', true);

    $l = new class implements \OCP\IL10N {
        public function t(string $text, $parameters = []): string {
            return $text;
        }
    };
    $url = new class implements \OCP\IURLGenerator {
        public function linkToRoute(string $routeName, array $arguments = []): string {
            return "/r/$routeName";
        }
        public function linkToRouteAbsolute(string $routeName, array $arguments = []): string {
            return "https://x/r/$routeName";
        }
        public function imagePath(string $appName, string $file): string {
            return "/apps/$appName/img/$file";
        }
        public function getAbsoluteURL(string $url): string {
            return "https://x$url";
        }
    };
    $users = new class implements \OCP\IUserManager {
        public function get(string $uid): ?\OCP\IUser {
            return $uid === 'yok' ? null : new \OCA\NoticeBoard\Tests\FakeUser($uid);
        }
        public function getDisplayName(string $uid): ?string {
            return $uid === 'yok' ? null : $uid;
        }
    };

    $initialState = new class implements \OCP\AppFramework\Services\IInitialState {
        public array $provided = [];
        public function provideInitialState(string $key, $data): void {
            $this->provided[$key] = $data;
        }
    };

    foreach ([ListWidget::class, CardWidget::class, SpotlightWidget::class] as $sinif) {
        $ortak = [$l, $url, new \OCA\NoticeBoard\Service\AnnouncementService(), $users, new \OCA\NoticeBoard\Service\HtmlSanitizer()];
        // Vitrin ek olarak yöneticinin vitrin ayarlarını sayfaya verir.
        $w = $sinif === SpotlightWidget::class
            ? new $sinif(...[...$ortak, $initialState, new \OCA\NoticeBoard\Service\SpotlightSettings()])
            : new $sinif(...$ortak);
        $ad = substr($sinif, strrpos($sinif, '\\') + 1);

        // 🔴 V2 olursa NC 32 web panosu Vue tasarımını atlar (Hata #31) —
        // doğrudan ya da IReloadableWidget gibi bir MİRAS yoluyla (Hata #33).
        check("$ad IAPIWidgetV2 DEGIL (dogrudan ya da miras yoluyla)", !($w instanceof IAPIWidgetV2));
        check("$ad IAPIWidget (mobil istemciler icin)", $w instanceof IAPIWidget);

        $items = $w->getItems('ayse');
        check("$ad getItems dizi donuyor", is_array($items) && count($items) === 1);
        check("$ad oge baglantisi duyuruya gidiyor", str_ends_with($items[0]->link ?? '', '#/announcement/7'));
        check("$ad bilinmeyen kullanicida bos dizi (istisna degil)", $w->getItems('yok') === []);

        \OCP\Util::$scripts = [];
        \OCP\Util::$styles = [];
        $w->load();
        check("$ad load() Vue girisini yukluyor", \OCP\Util::$scripts === ['noticeboard/noticeboard-dashboard']);
        check("$ad load() baslik ikonu stilini yukluyor", \OCP\Util::$styles === ['noticeboard/dashboard']);
    }

    check(
        'vitrin load() yonetici ayarlarini ilk durum olarak veriyor',
        ($initialState->provided['spotlight'] ?? null) === ['interval' => 12, 'filter' => 'featured']
    );

    finish('WidgetInterfaceTest');
}
