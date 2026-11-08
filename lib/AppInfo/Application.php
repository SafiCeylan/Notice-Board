<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\AppInfo;

use OCA\NoticeBoard\Dashboard\CardWidget;
use OCA\NoticeBoard\Dashboard\ListWidget;
use OCA\NoticeBoard\Dashboard\SpotlightWidget;
use OCA\NoticeBoard\Listener\CommentsEntityListener;
use OCA\NoticeBoard\Notification\Notifier;
use OCA\NoticeBoard\Search\SearchProvider;
use OCP\AppFramework\App;
use OCP\Comments\CommentsEntityEvent;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Duyuru Panosu uygulamasının giriş noktası (DI / bootstrap).
 *
 * Nextcloud app'i yüklerken bu sınıfı örnekler:
 *   register() → servisler container'a bağlanır, widget/notifier/listener kaydedilir
 *   boot()     → çalışma zamanı kancaları takılır
 */
class Application extends App implements IBootstrap {

    public const APP_ID = 'noticeboard';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Servislerin çoğu yalnızca OCP arayüzlerine bağlı olduğundan Nextcloud
        // DI konteyneri onları kendiliğinden çözer; burada yalnızca çekirdeğe
        // "bunlar var" demek gereken kancalar kaydedilir.

        // Çan ikonundaki bildirimi metne çeviren sınıf (K6).
        $context->registerNotifierService(Notifier::class);

        // ⚠️ Etkinlik sağlayıcısı ve ayarı BURADA DEĞİL, appinfo/info.xml'deki
        // <activity> bölümünde kaydedilir. (registerActivityFilter bambaşka bir
        // şey: akıştaki süzme sekmeleri.)

        // Yorum altyapısına "noticeboard" nesne türünü tanıtır (K6).
        $context->registerEventListener(CommentsEntityEvent::class, CommentsEntityListener::class);

        // Üç pano bileşeni (K8). Üçü de aynı veriyi kullanır, yalnız tasarımları
        // farklıdır; kullanıcı panosuna istediğini ekler.
        $context->registerDashboardWidget(ListWidget::class);
        $context->registerDashboardWidget(CardWidget::class);
        $context->registerDashboardWidget(SpotlightWidget::class);

        // Üst arama kutusu (Faz 9). Widget'lar gibi yalnız görülebilir duyuruları döner.
        $context->registerSearchProvider(SearchProvider::class);
    }

    public function boot(IBootContext $context): void {
        // Çalışma zamanı kancası yok.
    }
}
