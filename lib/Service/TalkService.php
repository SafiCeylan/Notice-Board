<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Db\Announcement;
use OCA\NoticeBoard\Db\AnnouncementGroupMapper;
use OCA\NoticeBoard\Db\AnnouncementMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Talk özel mesajı (K6 · `opt_talk`, 0.5.0).
 *
 * Duyuru yayına çıkınca hedeflenen her kişiye (ve yazara bir kopya), yalnız bu iş için açılmış
 * "Duyuru Panosu" hesabından (BOT_UID) kişinin Talk'taki duyuru sohbetine mesaj gider
 * (0.5.2'den beri kilitli, aramasız grup sohbeti — bkz. TalkGateway). Mesajı Talk
 * kendi bildirimiyle telefona/masaüstüne iletir. Sohbetin anahtarı kişinin
 * tercihlerinde (ROOM_KEY) durur; purge.php app tercihleriyle birlikte siler.
 *
 * Bot hesabı ilk gönderimde kendiliğinden açılır; yöneticinin bir şey yapması
 * gerekmez. Parolası hiçbir yerde saklanmaz (o hesapla oturum açılamaz), kotası
 * 0'dır (dosya tutamaz), hiçbir gruba eklenmez.
 *
 * ⚠️ Bu sınıf ARKA PLAN İŞİNDEN çağrılır (`SendTalkJob`): binlerce kişiye sohbet
 * açıp mesaj yazmak web isteğine sığmaz.
 */
class TalkService {

    /** Mesajları gönderen hesap. purge.php aynı adı taşır — PurgeContractTest kilitler. */
    public const BOT_UID = 'noticeboard-bot';

    /** Kişinin duyuru sohbetinin anahtarı (kullanıcı tercihi, app = noticeboard). */
    public const ROOM_KEY = 'talk_room';

    private const EXCERPT_LENGTH = 300;

    public function __construct(
        private TalkGateway $gateway,
        private IConfig $config,
        private AnnouncementMapper $mapper,
        private AnnouncementGroupMapper $groupMapper,
        private RecipientService $recipientService,
        private IUserManager $userManager,
        private ISecureRandom $random,
        private IURLGenerator $urlGenerator,
        private IFactory $l10nFactory,
        private HtmlSanitizer $sanitizer,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool {
        return $this->gateway->isAvailable();
    }

    /**
     * Kuyruktaki işin gövdesi. Alıcılar ŞİMDİ çözülür (e-postadaki gibi): doğru
     * olan gönderim anındaki grup üyeliğidir.
     */
    public function deliver(int $announcementId): void {
        try {
            $announcement = $this->mapper->find($announcementId);
        } catch (DoesNotExistException) {
            // İş çalışmadan duyuru silinmiş; gönderilecek bir şey yok.
            return;
        }

        // İş kuyruktayken anahtar kapatılmış ya da duyuru geri çekilmiş olabilir:
        // okuyucunun açamayacağı bir duyurunun bağlantısı gönderilmez.
        if (!$announcement->getOptTalk() || !$announcement->isVisibleAt($this->timeFactory->getTime())) {
            $this->logger->info('Talk mesajı atlandı: anahtar kapalı ya da duyuru artık yayında değil', [
                'app' => Application::APP_ID,
                'announcement' => $announcementId,
            ]);
            return;
        }

        if (!$this->gateway->isAvailable()) {
            $this->logger->warning('Talk kullanılamıyor, duyuru mesajı gönderilmedi: ' . implode('; ', $this->gateway->problems()), [
                'app' => Application::APP_ID,
                'announcement' => $announcementId,
            ]);
            return;
        }

        $gids = $this->groupMapper->findGids($announcementId);
        // Yazar da bir KOPYA alır (kullanıcı isteği, 12 Eki) — hedef gruplarında olmasa
        // bile; kişilere ne gittiğini görür. Bildirimden farklı: orada yazar hariçtir.
        // İlk sırada: binlerce alıcılı gönderimde kopyası hemen düşer. Grupta da varsa tek mesaj.
        $uids = $this->recipientService->resolveUids($gids);
        $uids = array_values(array_unique([$announcement->getAuthorUid(), ...$uids]));

        $this->sendTo($announcement, $uids);
    }

    /**
     * @param string[] $uids
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function sendTo(Announcement $announcement, array $uids): array {
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        $bot = $this->botUser();
        if ($bot === null) {
            $result['failed'] = count($uids);
            return $result;
        }

        $link = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('noticeboard.page.index')
            . '#/announcement/' . $announcement->getId()
        );
        $authorName = $this->userManager->get($announcement->getAuthorUid())?->getDisplayName()
            ?? $announcement->getAuthorUid();

        // Metin dile göre bir kez kurulur; aynı dili konuşan binlerce kişi aynı metni alır.
        $messages = [];
        $roomInfos = [];

        foreach ($uids as $uid) {
            $user = $uid === self::BOT_UID ? null : $this->userManager->get($uid);

            // Kapatılmış hesap ya da Talk'u kullanamayan kişi (Talk "izinli gruplar")
            // sohbeti zaten göremez; ona sohbet açmak yalnız çöp bırakır.
            if ($user === null || !$user->isEnabled() || $this->gateway->isDisabledFor($user)) {
                $result['skipped']++;
                continue;
            }

            $language = $this->l10nFactory->getUserLanguage($user);
            $messages[$language] ??= $this->composeMessage($announcement, $language, $authorName, $link);
            $roomInfos[$language] ??= $this->roomInfo($language);

            try {
                $token = $this->config->getUserValue($uid, Application::APP_ID, self::ROOM_KEY, '');
                $newToken = $this->gateway->sendNotice($bot, $user, $messages[$language], $token, $roomInfos[$language]);
                if ($newToken !== $token) {
                    $this->config->setUserValue($uid, Application::APP_ID, self::ROOM_KEY, $newToken);
                }
                $result['sent']++;
            } catch (\Exception $e) {
                // Kişiye özgü sorun: diğerleri yine alsın. Günlük taşmasın diye
                // yalnız ilk birkaçı ayrıntılı yazılır.
                // \Error (TypeError...) BİLEREK yakalanmaz: Talk API'si değişmiş demektir,
                // her kişide aynısı tekrarlanırdı. İş durur, hata bir kez günlüğe düşer.
                $result['failed']++;
                if ($result['failed'] <= 3) {
                    $this->logger->warning('Duyuru Talk mesajı gönderilemedi', [
                        'app' => Application::APP_ID,
                        'uid' => $uid,
                        'exception' => $e,
                    ]);
                }
            }
        }

        $this->logger->info('Duyuru Talk mesajları tamamlandı', [
            'app' => Application::APP_ID,
            'announcement' => $announcement->getId(),
        ] + $result);

        return $result;
    }

    /**
     * Talk mesajının metni (Talk Markdown'ı çizer). Adres düz satırda durur:
     * Talk onu kendiliğinden bağlantıya çevirir.
     */
    public function composeMessage(Announcement $announcement, string $language, string $authorName, string $link): string {
        $l = $this->l10nFactory->get(Application::APP_ID, $language);

        $priority = (int)$announcement->getPriority();
        $icon = match ($priority) {
            Announcement::PRIORITY_URGENT => '🚨',
            Announcement::PRIORITY_IMPORTANT => '❗',
            default => '📢',
        };

        $lines = [$icon . ' **' . $announcement->getSubject() . '**'];

        $tags = [];
        if ($priority === Announcement::PRIORITY_URGENT) {
            $tags[] = $l->t('Acil');
        } elseif ($priority === Announcement::PRIORITY_IMPORTANT) {
            $tags[] = $l->t('Önemli');
        }
        $category = trim((string)$announcement->getCategory());
        if ($category !== '') {
            $tags[] = $category;
        }
        if ($tags !== []) {
            $lines[] = implode(' · ', $tags);
        }

        $lines[] = $l->t('%s bir duyuru yayınladı.', [$authorName]);

        $plain = $this->sanitizer->toPlainText($announcement->getMessage());
        if ($plain !== '') {
            $excerpt = mb_substr($plain, 0, self::EXCERPT_LENGTH);
            $lines[] = '';
            $lines[] = mb_strlen($plain) > self::EXCERPT_LENGTH ? rtrim($excerpt) . '…' : $excerpt;
        }

        $lines[] = '';
        $lines[] = $l->t('Duyuruyu aç') . ': ' . $link;
        // "Yanıtlar okunmaz" notu 0.5.2'de kalktı: sohbet kilitli, yanıt yazılamıyor.

        return implode("\n", $lines);
    }

    /**
     * Duyuru sohbetinin adı ve açıklaması, kişinin dilinde. Yalnız sohbet AÇILIRKEN
     * kullanılır; yönetici sonradan Talk'tan değiştirirse ezilmez.
     *
     * @return array{name: string, description: string}
     */
    public function roomInfo(string $language): array {
        $l = $this->l10nFactory->get(Application::APP_ID, $language);

        return [
            'name' => $l->t('Duyuru Panosu'),
            'description' => $l->t('Size gönderilen duyurular burada görünür. Bu sohbet yalnız bilgilendirme içindir: yanıt yazılamaz, sesli ya da görüntülü arama başlatılamaz.'),
        ];
    }

    /**
     * Mesajları gönderen hesap; yoksa açılır. Açılamazsa null (günlüğe yazılır).
     */
    public function botUser(): ?IUser {
        $bot = $this->userManager->get(self::BOT_UID);
        if ($bot !== null) {
            return $bot;
        }

        try {
            $bot = $this->userManager->createUser(self::BOT_UID, $this->password());
        } catch (\Exception $e) {
            // Ör. parola politikası ya da kullanıcı arka ucu yeni hesaba izin vermiyor.
            $this->logger->error('Talk duyuru hesabı oluşturulamadı', ['app' => Application::APP_ID, 'exception' => $e]);
            return null;
        }
        if (!$bot instanceof IUser) {
            $this->logger->error('Talk duyuru hesabı oluşturulamadı', ['app' => Application::APP_ID]);
            return null;
        }

        // Görünen ad sunucunun varsayılan dilinde; yönetici Kullanıcılar sayfasından değiştirebilir.
        $bot->setDisplayName($this->l10nFactory->get(Application::APP_ID)->t('Duyuru Panosu'));
        // Yalnız Talk mesajı yazar; dosya tutması için bir sebep yok.
        $bot->setQuota('0 B');

        $this->logger->info('Talk duyuru hesabı oluşturuldu: ' . self::BOT_UID, ['app' => Application::APP_ID]);

        return $bot;
    }

    /**
     * Rastgele, hiçbir yerde saklanmayan parola. Her karakter sınıfından en az
     * biri bulunur ki parola politikası uygulaması hesabı reddetmesin (Talk'un
     * kendi köprü hesabı da böyle açılır).
     */
    private function password(): string {
        $all = ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS . ISecureRandom::CHAR_SYMBOLS;

        return $this->random->generate(1, ISecureRandom::CHAR_UPPER)
            . $this->random->generate(1, ISecureRandom::CHAR_LOWER)
            . $this->random->generate(1, ISecureRandom::CHAR_DIGITS)
            . $this->random->generate(1, ISecureRandom::CHAR_SYMBOLS)
            . $this->random->generate(60, $all);
    }
}
