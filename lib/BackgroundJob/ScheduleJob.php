<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\BackgroundJob;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Service\AnnouncementService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Zamanlanmış duyuruları yayına alır, süresi dolanları arşivler (K5).
 *
 * Yol haritasında iki ayrı iş olarak planlanmıştı; tek işte birleştirildi çünkü
 * ikisi de aynı sıklıkta çalışıyor ve aynı tabloyu tarıyor. İki ayrı zamanlanmış
 * iş, hiçbir kazanç sağlamadan iki kat tarama demekti.
 */
class ScheduleJob extends TimedJob {

    /**
     * 5 dakika. Daha sık çalışmak duyuru zamanlaması için bir şey kazandırmaz;
     * daha seyrek çalışmak "saat 09:00'da yayınlansın" beklentisini bozar.
     */
    private const INTERVAL_SECONDS = 300;

    public function __construct(
        ITimeFactory $time,
        private AnnouncementService $service,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);

        $this->setInterval(self::INTERVAL_SECONDS);
        // Zaman duyarlı: sunucu yoğunken bile ertelenmemeli, yoksa duyuru
        // saatler sonra yayınlanır.
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
    }

    protected function run($argument): void {
        // İki adım birbirinden bağımsız; biri patlarsa diğeri yine çalışmalı.
        $published = $this->safely('yayına alma', fn () => $this->service->publishDue());
        $archived = $this->safely('arşivleme', fn () => $this->service->archiveExpired());

        if ($published > 0 || $archived > 0) {
            $this->logger->info('Duyuru zamanlaması çalıştı', [
                'app' => Application::APP_ID,
                'published' => $published,
                'archived' => $archived,
            ]);
        }
    }

    private function safely(string $step, callable $action): int {
        try {
            return (int)$action();
        } catch (\Throwable $e) {
            $this->logger->error('Duyuru zamanlaması başarısız: ' . $step, [
                'app' => Application::APP_ID,
                'exception' => $e,
            ]);

            return 0;
        }
    }
}
