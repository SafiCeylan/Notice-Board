<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Settings;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Service\IconUrl;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Yönetici ayarlarındaki sol menü girişi.
 */
class AdminSection implements IIconSection {

    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return $this->l->t('Duyuru Panosu');
    }

    public function getPriority(): int {
        return 75;
    }

    public function getIcon(): string {
        // Ayarlar kenar çubuğu açık zemin: koyu ikon (karanlık temada NC kendisi çevirir).
        // app.svg BEYAZDIR (üst menü için) ve burada görünmez olurdu.
        // İçerik özetli adres: önbellekteki eski beyaz dosya karanlık temada SİYAH
        // kare gibi görünüyordu (Hata #45).
        return IconUrl::path($this->urlGenerator, 'app-dark.svg');
    }
}
