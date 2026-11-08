<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\BackgroundJob;

use OCA\NoticeBoard\Service\TalkService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Duyurunun Talk mesajlarını web isteğinin dışında gönderir (K6 · `opt_talk`).
 *
 * SendMailJob gibi kuyruğa yalnız duyuru kimliği konur; alıcılar ve son
 * kontroller (anahtar hâlâ açık mı, duyuru hâlâ yayında mı) iş çalışırken
 * TalkService::deliver() içinde yapılır.
 */
class SendTalkJob extends QueuedJob {

    public function __construct(
        ITimeFactory $time,
        private TalkService $talkService,
    ) {
        parent::__construct($time);
    }

    /**
     * @param array{announcementId?: int} $argument
     */
    protected function run($argument): void {
        $id = (int)($argument['announcementId'] ?? 0);
        if ($id > 0) {
            $this->talkService->deliver($id);
        }
    }
}
