<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\BackgroundJob\SendMailJob;
use OCA\NoticeBoard\BackgroundJob\SendTalkJob;
use OCA\NoticeBoard\Db\Announcement;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Duyuru yayına çıktığında dağıtım kanallarını tetikler (K6).
 *
 * ⚠️ NE ZAMAN ÇAĞRILIR: yalnızca duyuru yayına GEÇTİĞİ anda. Yayındaki bir
 * duyurunun her düzenlenmesinde değil — aksi halde bir yazım hatası düzeltmek
 * herkese yeniden bildirim gönderirdi. Geçişin tespiti AnnouncementService'te
 * eski/yeni durum karşılaştırmasıyla yapılır.
 *
 * Kapalı anahtar hiçbir yan etki üretmez; her kanal kendi anahtarına bakar.
 */
class DispatchService {

    public function __construct(
        private NotificationService $notificationService,
        private ActivityService $activityService,
        private IJobList $jobList,
        private TalkGateway $talkGateway,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string[] $targetGids
     */
    public function dispatch(Announcement $announcement, array $targetGids): void {
        // Bir kanalın patlaması diğerlerini engellememeli: duyuru zaten
        // kaydedildi, dağıtım en iyi çaba ile yapılır.
        if ($announcement->getOptNotification()) {
            $this->guard('bildirim', fn () => $this->notificationService->notify($announcement, $targetGids));
        }

        if ($announcement->getOptActivity()) {
            $this->guard('etkinlik', fn () => $this->activityService->publish($announcement, $targetGids));
        }

        if ($announcement->getOptEmail()) {
            // E-posta web isteğinde gönderilmez; kuyruğa alınır (bkz. SendMailJob).
            $this->guard('e-posta kuyruğu', fn () => $this->jobList->add(
                SendMailJob::class,
                ['announcementId' => (int)$announcement->getId()]
            ));
        }

        // Talk kurulu değilse iş kuyruğa hiç girmez; Talk mesajı da kuyrukta
        // gönderilir (bkz. SendTalkJob) — binlerce sohbet web isteğine sığmaz.
        if ($announcement->getOptTalk()) {
            $this->guard('Talk kuyruğu', function () use ($announcement): void {
                if ($this->talkGateway->isAvailable()) {
                    $this->jobList->add(SendTalkJob::class, ['announcementId' => (int)$announcement->getId()]);
                }
            });
        }
    }

    /**
     * Duyuru silinince bekleyen bildirimleri de topla.
     */
    public function withdraw(int $announcementId): void {
        $this->guard('bildirim geri çekme', fn () => $this->notificationService->withdraw($announcementId));
    }

    private function guard(string $channel, callable $action): void {
        try {
            $action();
        } catch (\Throwable $e) {
            $this->logger->error('Duyuru dağıtımı başarısız: ' . $channel, [
                'app' => Application::APP_ID,
                'exception' => $e,
            ]);
        }
    }
}
