<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Dashboard;

use OCA\NoticeBoard\Service\AnnouncementService;
use OCA\NoticeBoard\Service\HtmlSanitizer;
use OCA\NoticeBoard\Service\SpotlightSettings;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * "Duyuru vitrini" pano bileşeni (K8).
 *
 * Veri ve davranış ortak taban sınıftan gelir; burada kimlik, başlık, sıra ve
 * yöneticinin seçtiği vitrin ayarları (dönme süresi, hangi duyurular) tanımlanır.
 * Görsel tasarım src/widgets/SpotlightWidget.vue içindedir.
 */
class SpotlightWidget extends AbstractAnnouncementWidget {

    public const WIDGET_ID = 'noticeboard-spotlight';

    /** Vue tarafı bu adla okur (`loadState('noticeboard', 'spotlight')`). */
    public const STATE_KEY = 'spotlight';

    public function __construct(
        IL10N $l,
        IURLGenerator $urlGenerator,
        AnnouncementService $service,
        IUserManager $userManager,
        HtmlSanitizer $sanitizer,
        private IInitialState $initialState,
        private SpotlightSettings $spotlightSettings,
    ) {
        parent::__construct($l, $urlGenerator, $service, $userManager, $sanitizer);
    }

    public function getId(): string {
        return self::WIDGET_ID;
    }

    public function getTitle(): string {
        return $this->l->t('Duyuru vitrini');
    }

    protected function getVariant(): string {
        return 'slider';
    }

    public function getOrder(): int {
        return 12;
    }

    public function load(): void {
        parent::load();
        $this->initialState->provideInitialState(self::STATE_KEY, $this->spotlightSettings->get());
    }
}
