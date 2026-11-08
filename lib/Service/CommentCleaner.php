<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\AppInfo\Application;
use OCP\Comments\ICommentsManager;

/**
 * Duyuru silinince yorumlarını da siler.
 *
 * NEDEN AYRI SINIF: `CommentService` yetki için `AnnouncementService`'e bağlı.
 * Temizliği oraya koyup `AnnouncementService`'ten çağırsaydık iki servis
 * birbirini isteyecek ve DI konteyneri döngüye girecekti. Bu sınıfın tek bağımlılığı
 * çekirdeğin yorum yöneticisi; kimseyle döngü kurmaz.
 */
class CommentCleaner {

    public function __construct(
        private ICommentsManager $commentsManager,
    ) {
    }

    public function deleteAllForAnnouncement(int $announcementId): void {
        $this->commentsManager->deleteCommentsAtObject(Application::APP_ID, (string)$announcementId);
    }
}
