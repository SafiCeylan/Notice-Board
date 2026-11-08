<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Dashboard;

/**
 * "Duyuru kartları" pano bileşeni (K8).
 *
 * Veri ve davranış ortak taban sınıftan gelir; burada yalnızca kimlik, başlık ve
 * sıra tanımlanır. Görsel tasarım src/widgets/CardWidget.vue içindedir.
 */
class CardWidget extends AbstractAnnouncementWidget {

    public const WIDGET_ID = 'noticeboard-card';

    public function getId(): string {
        return self::WIDGET_ID;
    }

    public function getTitle(): string {
        return $this->l->t('Duyuru kartları');
    }

    protected function getVariant(): string {
        return 'cards';
    }

    public function getOrder(): int {
        return 11;
    }
}
