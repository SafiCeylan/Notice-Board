<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Exception\ValidationException;
use OCP\IAppConfig;

/**
 * Vitrin widget'ının kurum geneli ayarları (yönetici seçer, herkese uygulanır).
 *
 * Kullanıcı başına değil kurum geneli: NC panosunda widget başına ayar ekranı yok
 * ve "son kullanıcı hiçbir şey ayarlamaz" ürün ilkesi (yönetici bir kez kurar).
 */
class SpotlightSettings {

    public const FILTER_ALL = 'all';
    /** Yalnız sabitlenmiş, önemli ya da acil duyurular. */
    public const FILTER_FEATURED = 'featured';

    public const DEFAULT_INTERVAL = 8;
    /** 0 = otomatik dönme kapalı (yalnız elle gezinme). */
    public const MIN_INTERVAL = 4;
    public const MAX_INTERVAL = 60;

    private const KEY_INTERVAL = 'spotlight_interval';
    private const KEY_FILTER = 'spotlight_filter';

    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }

    /**
     * @return array{interval: int, filter: string}
     */
    public function get(): array {
        $interval = $this->appConfig->getValueInt(Application::APP_ID, self::KEY_INTERVAL, self::DEFAULT_INTERVAL);
        $filter = $this->appConfig->getValueString(Application::APP_ID, self::KEY_FILTER, self::FILTER_ALL);

        // Elle (occ) bozuk değer yazılmışsa widget çökmesin; varsayılana düşer.
        return [
            'interval' => self::isValidInterval($interval) ? $interval : self::DEFAULT_INTERVAL,
            'filter' => in_array($filter, [self::FILTER_ALL, self::FILTER_FEATURED], true) ? $filter : self::FILTER_ALL,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function set(int $interval, string $filter): void {
        if (!self::isValidInterval($interval)) {
            throw new ValidationException('Dönme süresi 0 (kapalı) ya da %s–%s saniye olmalı.', [self::MIN_INTERVAL, self::MAX_INTERVAL]);
        }
        if (!in_array($filter, [self::FILTER_ALL, self::FILTER_FEATURED], true)) {
            throw new ValidationException('Geçersiz vitrin seçimi.');
        }

        $this->appConfig->setValueInt(Application::APP_ID, self::KEY_INTERVAL, $interval);
        $this->appConfig->setValueString(Application::APP_ID, self::KEY_FILTER, $filter);
    }

    private static function isValidInterval(int $interval): bool {
        return $interval === 0 || ($interval >= self::MIN_INTERVAL && $interval <= self::MAX_INTERVAL);
    }
}
