<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Activity;

use OCA\NoticeBoard\AppInfo\Application;
use OCP\Activity\ISetting;
use OCP\IL10N;

/**
 * Kişisel ayarlardaki "Etkinlik" bölümünde duyuru satırının açık/kapalı
 * yapılabilmesini sağlar.
 */
class Setting implements ISetting {

    public function __construct(
        private IL10N $l,
    ) {
    }

    public function getIdentifier(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return $this->l->t('Yeni bir duyuru yayınlandığında');
    }

    public function getPriority(): int {
        return 60;
    }

    public function canChangeStream(): bool {
        return true;
    }

    public function isDefaultEnabledStream(): bool {
        return true;
    }

    public function canChangeMail(): bool {
        return true;
    }

    /**
     * Etkinlik e-postası varsayılan olarak KAPALI: duyurunun kendi e-posta
     * anahtarı (K6) zaten var, ikisi birden açık olursa aynı duyuru için iki
     * ayrı posta gider.
     */
    public function isDefaultEnabledMail(): bool {
        return false;
    }
}
