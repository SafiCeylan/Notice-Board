<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Db\Announcement;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\Notification\IManager;

/**
 * Çan ikonu bildirimleri (K6 · `opt_notification`).
 */
class NotificationService {

    private const EXCERPT_LENGTH = 160;

    public function __construct(
        private IManager $notificationManager,
        private RecipientService $recipientService,
        private HtmlSanitizer $sanitizer,
        private IURLGenerator $urlGenerator,
        private ITimeFactory $timeFactory,
    ) {
    }

    /**
     * @param string[] $targetGids
     */
    public function notify(Announcement $announcement, array $targetGids): void {
        $id = (string)$announcement->getId();
        $author = $announcement->getAuthorUid();

        $excerpt = mb_substr(
            $this->sanitizer->toPlainText($announcement->getMessage()),
            0,
            self::EXCERPT_LENGTH
        );

        $link = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('noticeboard.page.index') . '#/announcement/' . $id
        );

        // Yazarın kendine bildirim göndermesi gürültüden ibaret.
        foreach ($this->recipientService->resolveUids($targetGids, $author) as $uid) {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp(Application::APP_ID)
                ->setUser($uid)
                ->setDateTime($this->timeFactory->getDateTime())
                ->setObject('announcement', $id)
                ->setSubject('announcement', [
                    'author' => $author,
                    'subject' => $announcement->getSubject(),
                ])
                ->setMessage('excerpt', ['excerpt' => $excerpt])
                ->setLink($link);

            $this->notificationManager->notify($notification);
        }
    }

    /**
     * Duyuru silinince ona ait bildirimler de gitmeli; aksi halde kullanıcı
     * tıklayınca "bulunamadı" ekranına düşer.
     */
    public function withdraw(int $announcementId): void {
        $notification = $this->notificationManager->createNotification();
        $notification
            ->setApp(Application::APP_ID)
            ->setObject('announcement', (string)$announcementId);

        $this->notificationManager->markProcessed($notification);
    }
}
