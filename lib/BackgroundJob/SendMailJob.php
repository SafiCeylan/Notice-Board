<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\BackgroundJob;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Db\AnnouncementGroupMapper;
use OCA\NoticeBoard\Db\AnnouncementMapper;
use OCA\NoticeBoard\Service\MailService;
use OCA\NoticeBoard\Service\RecipientService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Duyuru e-postalarını web isteğinin dışında gönderir.
 *
 * Kuyruğa yalnızca duyuru kimliği konur, alıcı listesi konmaz: iş çalıştığında
 * grup üyelikleri değişmiş olabilir ve doğru olan, gönderim ANINDAKİ üyeliktir.
 */
class SendMailJob extends QueuedJob {

    public function __construct(
        ITimeFactory $time,
        private AnnouncementMapper $mapper,
        private AnnouncementGroupMapper $groupMapper,
        private RecipientService $recipientService,
        private MailService $mailService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    /**
     * @param array{announcementId?: int} $argument
     */
    protected function run($argument): void {
        $id = (int)($argument['announcementId'] ?? 0);
        if ($id === 0) {
            return;
        }

        try {
            $announcement = $this->mapper->find($id);
        } catch (DoesNotExistException) {
            // Duyuru iş çalışmadan önce silinmiş; gönderilecek bir şey yok.
            return;
        }

        // Anahtar sonradan kapatılmış olabilir — iş kuyruktayken duyuru
        // düzenlenmişse kullanıcının son kararına uy.
        if (!$announcement->getOptEmail()) {
            $this->logger->info('E-posta anahtarı kapatılmış, gönderim atlandı', [
                'app' => Application::APP_ID,
                'announcement' => $id,
            ]);
            return;
        }

        $gids = $this->groupMapper->findGids($id);
        $uids = $this->recipientService->resolveUids($gids, $announcement->getAuthorUid());

        $this->mailService->sendTo($announcement, $uids);
    }
}
