<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Dashboard;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Service\IconUrl;
use OCA\NoticeBoard\Service\AnnouncementService;
use OCA\NoticeBoard\Service\HtmlSanitizer;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Util;

/**
 * Üç widget varyantının ortak iskeleti (K8).
 *
 * Varyantlar yalnızca KİMLİK ve GÖRÜNÜM bakımından ayrışır; veri kaynağı üçünde
 * de aynıdır — `AnnouncementService::findVisibleFor()`. Böylece panodaki widget,
 * uygulamadaki listeyle aynı hedefleme ve zamanlama kurallarına uyar; bir
 * kullanıcının listede göremediği duyuru widget'ta da görünmez.
 *
 * Her varyant iki yolla çalışır:
 *  · `load()` → tarayıcıda kendi Vue bileşenini yükler (asıl tasarım burada)
 *  · `getItems()` → mobil/masaüstü istemcilerin okuduğu standart API (v1)
 *
 * 🔴 `IAPIWidgetV2` UYGULANMAZ. NC 32 web panosu bir widget V2 ise
 * (`isApiWidgetV2(id) ? standart liste : uygulamanın tasarımı`) kendi genel
 * listesini çizer ve `load()` ile yüklenen Vue tasarımını HİÇ kullanmaz. Faz 8'de
 * V2 uygulanmıştı; üç widget panoda birebir aynı görünüyordu ve kart/vitrin
 * tasarımı hiç çizilmedi (Hata #31, kullanıcı ekran görüntüsüyle fark etti).
 * `WidgetContractTest` bunu kilitler.
 *
 * ⚠️ `IReloadableWidget` de UYGULANMAZ: o arayüz `IAPIWidgetV2`'yi MİRAS ALIR
 * (NC 32 `lib/public/Dashboard/IReloadableWidget.php`). V2'yi kaldırıp onu
 * bırakmak sınıfı eksik bıraktı ve canlıda panoyu ölümcül hatayla düşürdü
 * (Hata #33). Tazeleme Vue tarafında yapılır (`useAnnouncements.js`).
 */
abstract class AbstractAnnouncementWidget implements IAPIWidget, IIconWidget, IButtonWidget {

    private const SUBTITLE_LENGTH = 100;

    public function __construct(
        protected IL10N $l,
        protected IURLGenerator $urlGenerator,
        protected AnnouncementService $service,
        protected IUserManager $userManager,
        protected HtmlSanitizer $sanitizer,
    ) {
    }

    /**
     * Varyantın kısa adı: ikon dosyası (`img/widget-{ad}.svg`) ve CSS sınıfı
     * (`icon-noticeboard-{ad}`) bundan türer.
     *
     * NEDEN her varyantın kendi ikonu var: üçü de aynı megafonu kullanınca
     * widget seçicide birbirinden ayırt edilemiyordu (kullanıcı geri bildirimi,
     * 24 Eyl 2026). İkon türü anlatır (liste / kart / slayt), başlık uygulamayı.
     */
    abstract protected function getVariant(): string;

    public function getIconClass(): string {
        return 'icon-noticeboard-' . $this->getVariant();
    }

    /**
     * ⚠️ Dosya KOYU olmalı: pano ve seçici bu görsele karanlık temada
     * `--background-invert-if-dark` uygular. Beyaz dosya karanlık temada SİYAH
     * görünür (Hata #29).
     */
    public function getIconUrl(): string {
        return IconUrl::absolute($this->urlGenerator, 'widget-' . $this->getVariant() . '.svg');
    }

    public function getUrl(): ?string {
        return $this->urlGenerator->linkToRouteAbsolute('noticeboard.page.index');
    }

    /**
     * @return WidgetButton[]
     */
    public function getWidgetButtons(string $userId): array {
        return [
            new WidgetButton(
                WidgetButton::TYPE_MORE,
                $this->urlGenerator->linkToRouteAbsolute('noticeboard.page.index'),
                $this->l->t('Tüm duyurular')
            ),
        ];
    }

    public function load(): void {
        Util::addScript(Application::APP_ID, Application::APP_ID . '-dashboard');
        // Başlık ikonu (`icon-noticeboard`) — yoksa panoda başlıkta ikon görünmez.
        Util::addStyle(Application::APP_ID, 'dashboard');
    }

    /**
     * @return WidgetItem[]
     */
    public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
        $user = $this->userManager->get($userId);

        // Kullanıcı yoksa boş dön; widget'ın hata vermesi panonun tamamını bozar.
        if ($user === null) {
            return [];
        }

        $items = [];
        foreach ($this->service->findVisibleFor($user, $limit) as $announcement) {
            $items[] = new WidgetItem(
                $announcement['subject'],
                $this->subtitleFor($announcement),
                $this->urlGenerator->getAbsoluteURL(
                    $this->urlGenerator->linkToRoute('noticeboard.page.index')
                    . '#/announcement/' . $announcement['id']
                ),
                $this->iconFor($announcement),
                (string)$announcement['createdAt']
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $announcement
     */
    private function subtitleFor(array $announcement): string {
        $parts = [];

        if (!empty($announcement['category'])) {
            $parts[] = (string)$announcement['category'];
        }

        $excerpt = $this->sanitizer->toPlainText((string)$announcement['message']);
        if ($excerpt !== '') {
            $parts[] = mb_substr($excerpt, 0, self::SUBTITLE_LENGTH);
        }

        return implode(' · ', $parts);
    }

    /**
     * Kapağı olan duyuru kendi görselini gösterir; olmayan app ikonunu.
     *
     * @param array<string, mixed> $announcement
     */
    private function iconFor(array $announcement): string {
        if (!empty($announcement['coverFileId'])) {
            return $this->urlGenerator->getAbsoluteURL(
                $this->urlGenerator->linkToRoute('noticeboard.file.cover', ['id' => $announcement['id']])
            );
        }

        // Öğe ikonu uygulamanın kendisini temsil eder (varyant ikonu değil).
        return IconUrl::absolute($this->urlGenerator, 'app-dark.svg');
    }
}
