<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Settings;

use OCA\NoticeBoard\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Yönetici ayarları ekranı: yayıncı atama (K1).
 * appinfo/info.xml içindeki <settings><admin> ile kaydedilir.
 */
class Admin implements ISettings {

    public function getForm(): TemplateResponse {
        Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');

        return new TemplateResponse(Application::APP_ID, 'admin');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    /**
     * Bölüm içindeki sıra. Tek ayarımız var, orta değer yeterli.
     */
    public function getPriority(): int {
        return 50;
    }
}
