<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Listener;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Db\AnnouncementMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Comments\CommentsEntityEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Nextcloud'un yorum altyapısına "noticeboard" diye bir nesne türü tanıtır.
 *
 * Bu kayıt olmadan çekirdek, yorumun bağlı olduğu duyurunun var olup olmadığını
 * doğrulayamaz ve yorumlar yetim kalır.
 *
 * @template-implements IEventListener<CommentsEntityEvent>
 */
class CommentsEntityListener implements IEventListener {

    public function __construct(
        private AnnouncementMapper $mapper,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof CommentsEntityEvent) {
            return;
        }

        $event->addEntityCollection(Application::APP_ID, function (string $id): bool {
            try {
                $this->mapper->find((int)$id);
                return true;
            } catch (DoesNotExistException) {
                return false;
            }
        });
    }
}
