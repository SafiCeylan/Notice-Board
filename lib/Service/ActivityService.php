<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\Activity\Provider;
use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Db\Announcement;
use OCP\Activity\IManager;
use Psr\Log\LoggerInterface;

/**
 * Etkinlik akışı kayıtları (K6 · `opt_activity`).
 */
class ActivityService {

    public function __construct(
        private IManager $activityManager,
        private RecipientService $recipientService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string[] $targetGids
     */
    public function publish(Announcement $announcement, array $targetGids): void {
        $author = $announcement->getAuthorUid();

        $event = $this->activityManager->generateEvent();
        $event->setApp(Application::APP_ID)
            ->setType(Application::APP_ID)
            ->setAuthor($author)
            ->setObject('announcement', (int)$announcement->getId(), $announcement->getSubject())
            ->setSubject(Provider::SUBJECT_PUBLISHED, [
                'author' => $author,
                'subject' => $announcement->getSubject(),
            ]);

        // Etkinlik, her alıcının kendi akışına ayrı ayrı yazılır. Yazarın kendi
        // akışına da yazılır — "ne yayınladım" dökümü onun için anlamlı.
        $uids = $this->recipientService->resolveUids($targetGids);
        if (!in_array($author, $uids, true)) {
            $uids[] = $author;
        }

        foreach ($uids as $uid) {
            try {
                $event->setAffectedUser($uid);
                $this->activityManager->publish($event);
            } catch (\Throwable $e) {
                // Tek bir kullanıcının akışı yazılamadı diye duyurunun tamamı
                // başarısız sayılmamalı.
                $this->logger->warning('Duyuru etkinliği yazılamadı', [
                    'app' => Application::APP_ID,
                    'uid' => $uid,
                    'exception' => $e,
                ]);
            }
        }
    }
}
