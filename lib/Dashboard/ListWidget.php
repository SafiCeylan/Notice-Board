<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Dashboard;

/**
 * "Duyuru listesi" pano bileşeni (K8).
 *
 * Veri ve davranış ortak taban sınıftan gelir; burada yalnızca kimlik, başlık ve
 * sıra tanımlanır. Görsel tasarım src/widgets/ListWidget.vue içindedir.
 */
class ListWidget extends AbstractAnnouncementWidget {

    public const WIDGET_ID = 'noticeboard-list';

    public function getId(): string {
        return self::WIDGET_ID;
    }

    public function getTitle(): string {
        return $this->l->t('Duyuru listesi');
    }

    protected function getVariant(): string {
        return 'list';
    }

    public function getOrder(): int {
        return 10;
    }
}
