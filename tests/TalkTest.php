<?php

declare(strict_types=1);

/**
 * Talk kanalı (K6 · opt_talk, 0.5.0) — TalkService + DispatchService + SendTalkJob.
 *
 * Talk'un kendisi burada YOK: tek temas noktası TalkGateway, ikiziyle değiştirilir.
 * Gateway'in Talk'a gerçekten bağlandığını yerel test kanıtlayamaz; onu deploy'daki
 * duman testi (TalkGateway::problems) ve canlı deneme yapar.
 *
 * Bu testin kilitlediği sorular:
 *   · Mesaj KİME gider? (hedefleme + yazara tek kopya; bot/kapalı/Talk'suz hesap atlanır)
 *   · NE ZAMAN gitmez? (anahtar kapalı, duyuru yayında değil, silinmiş, Talk yok)
 *   · Bot hesabı nasıl açılır? (sabit ad, parola saklanmaz, kota 0, bir kez)
 *   · Bir kişide hata diğerlerini durdurur mu? (durdurmaz; API kırılırsa durur)
 *
 * Çalıştırma:  php tests/TalkTest.php
 */

namespace {
    require __DIR__ . '/bootstrap.php';
    require __DIR__ . '/../lib/Db/Announcement.php';
    require __DIR__ . '/../lib/Service/HtmlSanitizer.php';
}

// ---------------------------------------------------------------------------
// OCP / PSR ikizleri (bootstrap'ta olmayanlar)
// ---------------------------------------------------------------------------

namespace OCP {

    interface IURLGenerator {
        public function linkToRoute(string $routeName, array $arguments = []): string;
        public function getAbsoluteURL(string $url): string;
    }

    interface IConfig {
        public function getUserValue($userId, $appName, $key, $default = '');
        public function setUserValue($userId, $appName, $key, $value, $preCondition = null);
    }
}

namespace OCP\L10N {

    interface IFactory {
        public function get($app, $lang = null, $locale = null);
        public function getUserLanguage(?\OCP\IUser $user = null): string;
    }
}

namespace OCP\Security {

    interface ISecureRandom {
        public const CHAR_UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        public const CHAR_LOWER = 'abcdefghijklmnopqrstuvwxyz';
        public const CHAR_DIGITS = '0123456789';
        public const CHAR_SYMBOLS = '!"#$%&\\\'()*+,-./:;<=>?@[\]^_`{|}~';

        public function generate(int $length, string $characters = ''): string;
    }
}

namespace Psr\Log {

    interface LoggerInterface {
        public function error($message, array $context = []): void;
        public function warning($message, array $context = []): void;
        public function info($message, array $context = []): void;
    }
}

namespace OCP\BackgroundJob {

    interface IJobList {
        public function add($job, $argument = null): void;
    }

    /** NC'deki gibi: start() işi koşturur, run() alt sınıfın gövdesidir. */
    abstract class QueuedJob {
        protected $argument;

        public function __construct(protected \OCP\AppFramework\Utility\ITimeFactory $time) {
        }

        public function setArgument($argument): void {
            $this->argument = $argument;
        }

        public function start(IJobList $jobList): void {
            $this->run($this->argument);
        }

        abstract protected function run($argument);
    }
}

// ---------------------------------------------------------------------------
// App ikizleri — gerçekleri yüklenmiyor
// ---------------------------------------------------------------------------

namespace OCA\NoticeBoard\Db {

    class AnnouncementMapper {
        /** @var Announcement[] */
        public array $rows = [];

        public function find(int $id): Announcement {
            if (!isset($this->rows[$id])) {
                throw new \OCP\AppFramework\Db\DoesNotExistException("yok: $id");
            }
            return $this->rows[$id];
        }
    }

    class AnnouncementGroupMapper {
        /** @var array<int, string[]> */
        public array $gids = [];

        public function findGids(int $announcementId): array {
            return $this->gids[$announcementId] ?? [];
        }
    }
}

namespace OCA\NoticeBoard\Service {

    /** Hedef çözümü ikizi: hangi gruplar ve kim hariç tutularak sorulduğunu kaydeder. */
    class RecipientService {
        public array $calls = [];

        /** @param array<string, string[]> $members gid ('' = herkes) => uid listesi */
        public function __construct(private array $members = []) {
        }

        public function resolveUids(array $targetGids, string $excludeUid = ''): array {
            $this->calls[] = ['gids' => $targetGids, 'exclude' => $excludeUid];
            $uids = [];
            foreach ($targetGids === [] ? [''] : $targetGids as $gid) {
                foreach ($this->members[$gid] ?? [] as $uid) {
                    $uids[$uid] = true;
                }
            }
            unset($uids[$excludeUid]);
            return array_keys($uids);
        }
    }

    /** Talk ikizi: gönderilen mesajları kaydeder; kullanılabilirlik ve hatalar elle verilir. */
    class TalkGateway {
        /** @var array<int, array{from: string, to: string, message: string}> */
        public array $sent = [];
        /** @var string[] */
        public array $problems = [];
        /** @var string[] Talk'u kullanamayan kişiler */
        public array $talkDisabled = [];
        /** @var array<string, \Throwable> kişiye gönderirken fırlatılacak hata */
        public array $throwFor = [];
        public ?\Throwable $availabilityError = null;

        public function isAvailable(): bool {
            if ($this->availabilityError !== null) {
                throw $this->availabilityError;
            }
            return $this->problems === [];
        }

        public function problems(): array {
            return $this->problems;
        }

        public function isDisabledFor(\OCP\IUser $user): bool {
            return in_array($user->getUID(), $this->talkDisabled, true);
        }

        /** @var string[] Talk'ta artık olmayan sohbet anahtarları (silinmiş sohbet gibi) */
        public array $deadTokens = [];
        /** @var array<int, array{uid: string, name: string, description: string}> açılan sohbetler */
        public array $created = [];

        /** Gerçeği gibi: anahtar geçerliyse aynısını döndürür, değilse yeni sohbet açar. */
        public function sendNotice(\OCP\IUser $bot, \OCP\IUser $recipient, string $message, string $token, array $roomInfo): string {
            if (isset($this->throwFor[$recipient->getUID()])) {
                throw $this->throwFor[$recipient->getUID()];
            }
            if ($token === '' || in_array($token, $this->deadTokens, true)) {
                $token = 'oda-' . $recipient->getUID() . '-' . (count($this->created) + 1);
                $this->created[] = ['uid' => $recipient->getUID()] + $roomInfo;
            }
            $this->sent[] = ['from' => $bot->getUID(), 'to' => $recipient->getUID(), 'message' => $message, 'token' => $token];
            return $token;
        }
    }

    /** DispatchService'in diğer kanalları — bu testte yalnız çağrıldıkları önemli. */
    class NotificationService {
        public int $calls = 0;

        public function notify(\OCA\NoticeBoard\Db\Announcement $a, array $gids): void {
            $this->calls++;
        }

        public function withdraw(int $id): void {
        }
    }

    class ActivityService {
        public int $calls = 0;

        public function publish(\OCA\NoticeBoard\Db\Announcement $a, array $gids): void {
            $this->calls++;
        }
    }
}

namespace OCA\NoticeBoard\Tests {

    /** Talk testlerinin kullanıcısı: kapatılabilir, görünen ad ve kota tutar. */
    class TalkUser implements \OCP\IUser {
        public ?string $quota = null;

        public function __construct(
            private string $uid,
            public string $displayName = '',
            public bool $enabled = true,
        ) {
            $this->displayName = $displayName !== '' ? $displayName : $uid;
        }

        public function getUID(): string {
            return $this->uid;
        }

        public function getDisplayName(): string {
            return $this->displayName;
        }

        public function setDisplayName(string $name): bool {
            $this->displayName = $name;
            return true;
        }

        public function setQuota(string $quota): void {
            $this->quota = $quota;
        }

        public function isEnabled(): bool {
            return $this->enabled;
        }
    }

    class TalkUserManager implements \OCP\IUserManager {
        /** @var array<string, TalkUser> */
        public array $users = [];
        /** @var array<int, array{uid: string, password: string}> */
        public array $created = [];
        public ?\Exception $createError = null;

        public function add(TalkUser ...$users): void {
            foreach ($users as $u) {
                $this->users[$u->getUID()] = $u;
            }
        }

        public function get(string $uid): ?\OCP\IUser {
            return $this->users[$uid] ?? null;
        }

        public function getDisplayName(string $uid): ?string {
            return isset($this->users[$uid]) ? $this->users[$uid]->getDisplayName() : null;
        }

        public function createUser(string $uid, string $password) {
            if ($this->createError !== null) {
                throw $this->createError;
            }
            $this->created[] = ['uid' => $uid, 'password' => $password];
            return $this->users[$uid] = new TalkUser($uid);
        }
    }

    /** NC gibi çeviren IL10N: 'en' l10n/en.json'dan, diğer diller kaynak (Türkçe). */
    class DictL10N implements \OCP\IL10N {
        public function __construct(private array $dict) {
        }

        public function t(string $text, $parameters = []): string {
            return vsprintf($this->dict[$text] ?? $text, (array)$parameters);
        }
    }

    class TalkL10nFactory implements \OCP\L10N\IFactory {
        /** @var array<string, string> uid => dil */
        public array $languages = [];
        private array $en;

        public function __construct() {
            $this->en = json_decode((string)file_get_contents(__DIR__ . '/../l10n/en.json'), true)['translations'] ?? [];
        }

        public function get($app, $lang = null, $locale = null) {
            return new DictL10N($lang === 'en' ? $this->en : []);
        }

        public function getUserLanguage(?\OCP\IUser $user = null): string {
            return $this->languages[$user?->getUID() ?? ''] ?? 'tr';
        }
    }

    class TalkUrls implements \OCP\IURLGenerator {
        public function linkToRoute(string $routeName, array $arguments = []): string {
            return $routeName === 'noticeboard.page.index' ? '/apps/noticeboard/' : '/??';
        }

        public function getAbsoluteURL(string $url): string {
            return 'https://bulut.example' . $url;
        }
    }

    /** Hangi karakter sınıfının istendiğini kaydeden rastgele üreteç. */
    class RecordingRandom implements \OCP\Security\ISecureRandom {
        public array $asked = [];

        public function generate(int $length, string $characters = ''): string {
            $this->asked[] = $characters;
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $characters[random_int(0, strlen($characters) - 1)];
            }
            return $out;
        }
    }

    class TalkLogger implements \Psr\Log\LoggerInterface {
        /** @var array<int, array{level: string, message: string}> */
        public array $lines = [];

        public function error($message, array $context = []): void {
            $this->lines[] = ['level' => 'error', 'message' => (string)$message];
        }

        public function warning($message, array $context = []): void {
            $this->lines[] = ['level' => 'warning', 'message' => (string)$message];
        }

        public function info($message, array $context = []): void {
            $this->lines[] = ['level' => 'info', 'message' => (string)$message];
        }

        public function count(string $level): int {
            return count(array_filter($this->lines, static fn ($l) => $l['level'] === $level));
        }
    }

    /** Kullanıcı tercihleri: [uid][app][key] => değer; yazma sayısını da tutar. */
    class TalkConfig implements \OCP\IConfig {
        public array $values = [];
        public int $writes = 0;

        public function getUserValue($userId, $appName, $key, $default = '') {
            return $this->values[$userId][$appName][$key] ?? $default;
        }

        public function setUserValue($userId, $appName, $key, $value, $preCondition = null) {
            $this->values[$userId][$appName][$key] = (string)$value;
            $this->writes++;
        }
    }

    class TalkClock implements \OCP\AppFramework\Utility\ITimeFactory {
        public int $now = 1_700_000_000;

        public function getTime(): int {
            return $this->now;
        }
    }

    class JobRecorder implements \OCP\BackgroundJob\IJobList {
        public array $jobs = [];

        public function add($job, $argument = null): void {
            $this->jobs[] = ['class' => $job, 'argument' => $argument];
        }
    }
}

namespace {

    require __DIR__ . '/../lib/Service/TalkService.php';
    require __DIR__ . '/../lib/Service/DispatchService.php';
    require __DIR__ . '/../lib/BackgroundJob/SendTalkJob.php';

    use OCA\NoticeBoard\Db\Announcement;
    use OCA\NoticeBoard\Service\TalkService;
    use OCA\NoticeBoard\Tests\TalkUser;

    /**
     * Kurulu bir Talk ortamı: ali yazar; ayse, mehmet (ik), john (en, ik), kapali (kapalı hesap),
     * misafir (Talk'u kullanamaz). Herkes = hepsi + bot (yanlışlıkla listeye girmiş gibi).
     */
    function talkEnv(): object {
        $env = new \stdClass();
        $env->users = new \OCA\NoticeBoard\Tests\TalkUserManager();
        $env->users->add(
            new TalkUser('ali', 'Ali Veli'),
            new TalkUser('ayse'),
            new TalkUser('mehmet'),
            new TalkUser('john'),
            new TalkUser('kapali', '', false),
            new TalkUser('misafir'),
        );
        $env->recipients = new \OCA\NoticeBoard\Service\RecipientService([
            '' => ['ali', 'ayse', 'mehmet', 'john', 'kapali', 'misafir', TalkService::BOT_UID, 'silinmis'],
            // Yazar bilerek SONDA: "kopyası önce gider" kuralı ancak böyle sınanır.
            'ik' => ['mehmet', 'john', 'ali'],
            'muhasebe' => ['ayse'],
        ]);
        $env->gateway = new \OCA\NoticeBoard\Service\TalkGateway();
        $env->gateway->talkDisabled = ['misafir'];
        $env->mapper = new \OCA\NoticeBoard\Db\AnnouncementMapper();
        $env->groups = new \OCA\NoticeBoard\Db\AnnouncementGroupMapper();
        $env->l10n = new \OCA\NoticeBoard\Tests\TalkL10nFactory();
        $env->l10n->languages = ['john' => 'en'];
        $env->random = new \OCA\NoticeBoard\Tests\RecordingRandom();
        $env->logger = new \OCA\NoticeBoard\Tests\TalkLogger();
        $env->clock = new \OCA\NoticeBoard\Tests\TalkClock();
        $env->config = new \OCA\NoticeBoard\Tests\TalkConfig();
        $env->service = new TalkService(
            $env->gateway,
            $env->config,
            $env->mapper,
            $env->groups,
            $env->recipients,
            $env->users,
            $env->random,
            new \OCA\NoticeBoard\Tests\TalkUrls(),
            $env->l10n,
            new \OCA\NoticeBoard\Service\HtmlSanitizer(),
            $env->clock,
            $env->logger,
        );
        return $env;
    }

    function talkAnnouncement(object $env, int $id, array $set = [], array $gids = []): Announcement {
        $a = new Announcement();
        $a->setId($id);
        $a->setAuthorUid('ali');
        $a->setSubject('Yangın tatbikatı');
        $a->setMessage('<p>Yarın <strong>10:00</strong>\'da bahçede toplanıyoruz.</p>');
        $a->setStatus(Announcement::STATUS_PUBLISHED);
        $a->setOptTalk(true);
        foreach ($set as $alan => $deger) {
            $a->{'set' . ucfirst($alan)}($deger);
        }
        $env->mapper->rows[$id] = $a;
        $env->groups->gids[$id] = $gids;
        return $a;
    }

    /** @return string[] */
    function alicilar(object $env): array {
        $to = array_column($env->gateway->sent, 'to');
        sort($to);
        return $to;
    }

    echo "TalkTest\n";

    // =====================================================================
    echo "1) Mesaj metni\n";
    // =====================================================================
    $env = talkEnv();
    $a = talkAnnouncement($env, 1);
    $tr = $env->service->composeMessage($a, 'tr', 'Ali Veli', 'https://bulut.example/apps/noticeboard/#/announcement/1');
    check('baslik kalin ve duyuru simgesiyle', str_starts_with($tr, '📢 **Yangın tatbikatı**'));
    check('yazar cumlesi var', str_contains($tr, 'Ali Veli bir duyuru yayınladı.'));
    check('govde duz metin (HTML etiketi yok)', str_contains($tr, "Yarın 10:00'da bahçede toplanıyoruz.") && !str_contains($tr, '<'));
    check('baglanti son satirda', str_ends_with($tr, "\nDuyuruyu aç: https://bulut.example/apps/noticeboard/#/announcement/1"));
    check('"yanitlar okunmaz" notu yok (sohbet kilitli, 0.5.2)', !str_contains($tr, 'okunmaz'));
    check('oncelik/kategori yoksa etiket satiri yok', !str_contains($tr, ' · ') && !str_contains($tr, 'Acil'));

    $acil = talkAnnouncement($env, 2, ['priority' => Announcement::PRIORITY_URGENT, 'category' => 'İK']);
    $acilMetin = $env->service->composeMessage($acil, 'tr', 'Ali Veli', 'x');
    check('acil duyuru siren simgesiyle baslar', str_starts_with($acilMetin, '🚨 **'));
    check('acil + kategori etiket satirinda', str_contains($acilMetin, "\nAcil · İK\n"));
    $onemli = talkAnnouncement($env, 3, ['priority' => Announcement::PRIORITY_IMPORTANT]);
    $onemliMetin = $env->service->composeMessage($onemli, 'tr', 'Ali Veli', 'x');
    check('onemli duyuru unlemle baslar ve etiketlenir', str_starts_with($onemliMetin, '❗ **') && str_contains($onemliMetin, "\nÖnemli\n"));

    $uzun = talkAnnouncement($env, 4, ['message' => '<p>' . str_repeat('kelime ', 100) . '</p>']);
    $uzunMetin = $env->service->composeMessage($uzun, 'tr', 'Ali Veli', 'x');
    preg_match("/\n\n(kelime[^\n]*)\n/u", $uzunMetin, $m);
    check('uzun govde 300 karakterde kesilir ve uc nokta alir',
        isset($m[1]) && str_ends_with($m[1], '…') && mb_strlen($m[1]) <= 301);
    $sinir = talkAnnouncement($env, 5, ['message' => str_repeat('a', 300)]);
    check('sinir: tam 300 karakter kesilmez', !str_contains($env->service->composeMessage($sinir, 'tr', 'A', 'x'), '…'));
    $bos = talkAnnouncement($env, 6, ['message' => '<p></p>']);
    check('bos govde bos paragraf birakmaz', !str_contains($env->service->composeMessage($bos, 'tr', 'A', 'x'), "\n\n\n"));

    $en = $env->service->composeMessage($a, 'en', 'Ali Veli', 'L');
    check('Ingilizce: yazar cumlesi cevrilir', str_contains($en, 'Ali Veli published an announcement.'));
    check('Ingilizce: baglanti cevrilir', str_ends_with($en, "\nOpen the announcement: L"));
    check('Ingilizce metinde Turkce kaynak kalmaz', !str_contains($en, 'yayınladı') && !str_contains($en, 'Duyuruyu'));

    $odaTr = $env->service->roomInfo('tr');
    $odaEn = $env->service->roomInfo('en');
    check('sohbet adi Duyuru Panosu', $odaTr['name'] === 'Duyuru Panosu');
    check('sohbet aciklamasi yanit ve aramanin olmadigini soyler',
        str_contains($odaTr['description'], 'yanıt yazılamaz') && str_contains($odaTr['description'], 'arama başlatılamaz'));
    check('Ingilizce: sohbet adi ve aciklamasi cevrilir',
        $odaEn['name'] === 'Notice Board' && str_contains($odaEn['description'], 'calls are not possible'));
    $acilEn = $env->service->composeMessage($acil, 'en', 'A', 'L');
    check('Ingilizce: oncelik etiketi cevrilir', str_contains($acilEn, "\nUrgent · İK\n"));

    // =====================================================================
    echo "\n2) Bot hesabi\n";
    // =====================================================================
    $env = talkEnv();
    $bot = $env->service->botUser();
    check('hesap yoksa acilir', $bot !== null && count($env->users->created) === 1);
    check('hesap adi sabit: ' . TalkService::BOT_UID, $env->users->created[0]['uid'] === 'noticeboard-bot');
    check('gorunen ad Duyuru Panosu', $bot->getDisplayName() === 'Duyuru Panosu');
    check('kotasi 0 (dosya tutamaz)', $bot->quota === '0 B');
    $parola = $env->users->created[0]['password'];
    check('parola uzun (>= 64)', strlen($parola) >= 64);
    check('parola her karakter sinifini ister (parola politikasi reddetmesin)',
        in_array(\OCP\Security\ISecureRandom::CHAR_UPPER, $env->random->asked, true)
        && in_array(\OCP\Security\ISecureRandom::CHAR_LOWER, $env->random->asked, true)
        && in_array(\OCP\Security\ISecureRandom::CHAR_DIGITS, $env->random->asked, true)
        && in_array(\OCP\Security\ISecureRandom::CHAR_SYMBOLS, $env->random->asked, true));
    $tekrar = $env->service->botUser();
    check('ikinci cagrida yeni hesap ACILMAZ, aynisi doner', $tekrar === $bot && count($env->users->created) === 1);

    $env = talkEnv();
    $varolan = new TalkUser(TalkService::BOT_UID, 'Kurumsal Duyurular');
    $env->users->add($varolan);
    check('var olan hesap yeniden kullanilir, adi ezilmez',
        $env->service->botUser() === $varolan && $env->users->created === [] && $varolan->getDisplayName() === 'Kurumsal Duyurular');

    $env = talkEnv();
    $env->users->createError = new \InvalidArgumentException('parola politikasi');
    check('hesap acilamazsa null', $env->service->botUser() === null);
    check('hesap acilamazsa hata gunluge yazilir', $env->logger->count('error') === 1);
    $sonuc = $env->service->sendTo(talkAnnouncement($env, 1), ['ayse', 'mehmet']);
    check('hesap yoksa kimseye gonderilmez, hepsi basarisiz sayilir',
        $env->gateway->sent === [] && $sonuc === ['sent' => 0, 'skipped' => 0, 'failed' => 2]);

    // =====================================================================
    echo "\n3) Gonderim (sendTo)\n";
    // =====================================================================
    $env = talkEnv();
    $a = talkAnnouncement($env, 1);
    $sonuc = $env->service->sendTo($a, ['ayse', 'mehmet', 'john']);
    check('her aliciya bir mesaj', alicilar($env) === ['ayse', 'john', 'mehmet']);
    check('mesajlar bot hesabindan', array_unique(array_column($env->gateway->sent, 'from')) === [TalkService::BOT_UID]);
    check('sonuc sayilari', $sonuc === ['sent' => 3, 'skipped' => 0, 'failed' => 0]);
    $mesajlar = array_column($env->gateway->sent, 'message', 'to');
    check('mesajda duyuru baglantisi', str_contains($mesajlar['ayse'], 'https://bulut.example/apps/noticeboard/#/announcement/1'));
    check('yazarin GORUNEN adi kullanilir', str_contains($mesajlar['ayse'], 'Ali Veli bir duyuru'));
    check('her kisi kendi dilinde alir (john Ingilizce)',
        str_contains($mesajlar['john'], 'published an announcement') && str_contains($mesajlar['mehmet'], 'yayınladı'));

    $env = talkEnv();
    $sonuc = $env->service->sendTo(talkAnnouncement($env, 1), [TalkService::BOT_UID, 'silinmis', 'kapali', 'misafir', 'ayse']);
    check('bot kendine, silinmis/kapali hesaba ve Talk kullanamayana gonderilmez', alicilar($env) === ['ayse']);
    check('atlananlar sayilir', $sonuc === ['sent' => 1, 'skipped' => 4, 'failed' => 0]);

    $env = talkEnv();
    $env->gateway->throwFor['ayse'] = new \RuntimeException('hesap arama kisiti');
    $sonuc = $env->service->sendTo(talkAnnouncement($env, 1), ['ayse', 'mehmet', 'john']);
    check('bir kisideki hata digerlerini durdurmaz', alicilar($env) === ['john', 'mehmet']);
    check('basarisiz sayilir ve gunluge uyari duser', $sonuc['failed'] === 1 && $env->logger->count('warning') === 1);

    $env = talkEnv();
    $herkes = ['ayse', 'mehmet', 'john'];
    for ($i = 0; $i < 6; $i++) {
        $uid = "kisi$i";
        $env->users->add(new TalkUser($uid));
        $env->gateway->throwFor[$uid] = new \RuntimeException('olmadi');
        $herkes[] = $uid;
    }
    $sonuc = $env->service->sendTo(talkAnnouncement($env, 1), $herkes);
    check('cok hata: hepsi sayilir ama gunluk tasmaz (en fazla 3 uyari)', $sonuc['failed'] === 6 && $env->logger->count('warning') === 3);

    $env = talkEnv();
    $env->gateway->throwFor['mehmet'] = new \TypeError('Talk API degismis');
    $durdu = false;
    try {
        $env->service->sendTo(talkAnnouncement($env, 1), ['ayse', 'mehmet', 'john']);
    } catch (\TypeError) {
        $durdu = true;
    }
    check('Talk API kirilirsa (\Error) is DURUR, her kisi icin tekrarlanmaz', $durdu && alicilar($env) === ['ayse']);

    // Duyuru sohbeti (0.5.2): kişi başına bir sohbet, anahtarı tercihlerde.
    $env = talkEnv();
    $a = talkAnnouncement($env, 1);
    $env->service->sendTo($a, ['ayse', 'john']);
    $anahtar = fn (string $uid) => $env->config->values[$uid]['noticeboard'][TalkService::ROOM_KEY] ?? null;
    check('ilk mesajda sohbet acilir ve anahtari kisiye kaydedilir',
        count($env->gateway->created) === 2 && $anahtar('ayse') === 'oda-ayse-1' && $anahtar('john') === 'oda-john-2');
    check('yeni sohbet kisinin dilinde adlandirilir',
        array_column($env->gateway->created, 'name', 'uid') === ['ayse' => 'Duyuru Panosu', 'john' => 'Notice Board']);
    $yazma = $env->config->writes;
    $env->service->sendTo($a, ['ayse', 'john']);
    check('ikinci duyuru AYNI sohbete gider, yeni sohbet acilmaz',
        count($env->gateway->created) === 2 && array_column(array_slice($env->gateway->sent, 2), 'token') === ['oda-ayse-1', 'oda-john-2']);
    check('anahtar degismediyse tercih yeniden yazilmaz', $env->config->writes === $yazma);
    $env->gateway->deadTokens[] = 'oda-ayse-1';
    $env->service->sendTo($a, ['ayse']);
    check('sohbet silinmisse yenisi acilir ve yeni anahtar kaydedilir', $anahtar('ayse') === 'oda-ayse-3');

    $env = talkEnv();
    $env->gateway->throwFor['ayse'] = new \RuntimeException('olmadi');
    $env->service->sendTo(talkAnnouncement($env, 1), ['ayse']);
    check('gonderim basarisizsa anahtar yazilmaz', $env->config->writes === 0);

    // =====================================================================
    echo "\n4) Kuyruk isi govdesi (deliver)\n";
    // =====================================================================
    $env = talkEnv();
    talkAnnouncement($env, 1);
    $env->service->deliver(1);
    check('herkese acik duyuru: herkes alir, yazar da kopya alir', alicilar($env) === ['ali', 'ayse', 'john', 'mehmet']);
    check('hedef cozumunde kimse haric tutulmaz', $env->recipients->calls[0]['exclude'] === '');

    $env = talkEnv();
    talkAnnouncement($env, 1, [], ['ik']);
    $env->service->deliver(1);
    check('grup hedefli: grup uyeleri + yazar', alicilar($env) === ['ali', 'john', 'mehmet']);
    check('yazar grupta da olsa TEK mesaj alir', count(array_keys(array_column($env->gateway->sent, 'to'), 'ali')) === 1);
    check('yazarin kopyasi ILK gider (grupta sonda olsa bile)', $env->gateway->sent[0]['to'] === 'ali');
    check('hedef gruplar is aninda okunur', $env->recipients->calls[0]['gids'] === ['ik']);

    $env = talkEnv();
    talkAnnouncement($env, 1, [], ['muhasebe']);
    $env->service->deliver(1);
    check('yazar hedef grupta OLMASA da kopya alir', alicilar($env) === ['ali', 'ayse']);

    $env = talkEnv();
    $env->gateway->talkDisabled = ['ali'];
    talkAnnouncement($env, 1, [], ['ik']);
    $env->service->deliver(1);
    check('Talk kullanamayan yazara kopya gitmez (diger kurallar gecerli)', alicilar($env) === ['john', 'mehmet']);

    $env = talkEnv();
    $env->service->deliver(99);
    check('silinmis duyuru: hicbir sey gonderilmez, hata yok', $env->gateway->sent === [] && $env->users->created === []);

    $env = talkEnv();
    talkAnnouncement($env, 1, ['optTalk' => false]);
    $env->service->deliver(1);
    check('anahtar kuyruktayken kapatildiysa gonderilmez', $env->gateway->sent === []);

    $env = talkEnv();
    talkAnnouncement($env, 1, ['optTalk' => null]);
    $env->service->deliver(1);
    check('eski satir (opt_talk null) gonderilmez', $env->gateway->sent === []);

    $env = talkEnv();
    talkAnnouncement($env, 1, ['status' => Announcement::STATUS_DRAFT]);
    $env->service->deliver(1);
    check('taslaga cekilmis duyuru gonderilmez', $env->gateway->sent === []);

    $env = talkEnv();
    talkAnnouncement($env, 1, ['deleteAt' => $env->clock->now]);
    $env->service->deliver(1);
    check('sinir: silme zamani TAM su an ise gonderilmez', $env->gateway->sent === []);

    $env = talkEnv();
    talkAnnouncement($env, 1, ['deleteAt' => $env->clock->now + 1]);
    $env->service->deliver(1);
    check('sinir: silme zamanina bir saniye varsa gonderilir', count($env->gateway->sent) === 4);

    $env = talkEnv();
    $env->gateway->problems = ['OCA\Talk\Chat\ChatManager::sendMessage imzasi degismis'];
    talkAnnouncement($env, 1);
    $env->service->deliver(1);
    check('Talk kullanilamiyorsa gonderilmez ve bot ACILMAZ', $env->gateway->sent === [] && $env->users->created === []);
    check('Talk kullanilamiyorsa sebep uyari olarak yazilir',
        $env->logger->count('warning') === 1 && str_contains($env->logger->lines[0]['message'], 'imzasi degismis'));

    // =====================================================================
    echo "\n5) Dagitim (DispatchService) ve kuyruk isi\n";
    // =====================================================================
    $dagitim = static function (object $env, ?\OCA\NoticeBoard\Tests\JobRecorder &$jobs = null): \OCA\NoticeBoard\Service\DispatchService {
        $jobs = new \OCA\NoticeBoard\Tests\JobRecorder();
        return new \OCA\NoticeBoard\Service\DispatchService(
            new \OCA\NoticeBoard\Service\NotificationService(),
            new \OCA\NoticeBoard\Service\ActivityService(),
            $jobs,
            $env->gateway,
            $env->logger,
        );
    };
    $talkIsleri = static fn (\OCA\NoticeBoard\Tests\JobRecorder $j) => array_values(array_filter(
        $j->jobs,
        static fn ($job) => $job['class'] === \OCA\NoticeBoard\BackgroundJob\SendTalkJob::class
    ));

    $env = talkEnv();
    $dagitim($env, $jobs)->dispatch(talkAnnouncement($env, 7), []);
    $isler = $talkIsleri($jobs);
    check('Talk anahtari acik: Talk isi kuyruga girer', count($isler) === 1 && $isler[0]['argument'] === ['announcementId' => 7]);
    check('Talk mesaji web isteginde GONDERILMEZ (yalniz kuyruk)', $env->gateway->sent === []);

    $env = talkEnv();
    $dagitim($env, $jobs)->dispatch(talkAnnouncement($env, 7, ['optTalk' => false]), []);
    check('Talk anahtari kapali: is kuyruga girmez', $talkIsleri($jobs) === []);

    $env = talkEnv();
    $env->gateway->problems = ['Talk (spreed) etkin degil'];
    $dagitim($env, $jobs)->dispatch(talkAnnouncement($env, 7), []);
    check('Talk kurulu degil: is kuyruga girmez', $talkIsleri($jobs) === []);

    $env = talkEnv();
    $env->gateway->availabilityError = new \RuntimeException('Talk patladi');
    $hataYok = true;
    try {
        $dagitim($env, $jobs)->dispatch(talkAnnouncement($env, 7, ['optEmail' => true]), []);
    } catch (\Throwable) {
        $hataYok = false;
    }
    check('Talk denetimi patlarsa duyuru kaydi patlamaz, hata gunluge duser', $hataYok && $env->logger->count('error') === 1);
    check('Talk patlasa da e-posta isi kuyrukta', count($jobs->jobs) === 1 && $jobs->jobs[0]['class'] === \OCA\NoticeBoard\BackgroundJob\SendMailJob::class);

    $env = talkEnv();
    talkAnnouncement($env, 8);
    $is = new \OCA\NoticeBoard\BackgroundJob\SendTalkJob($env->clock, $env->service);
    $is->setArgument(['announcementId' => 8]);
    $is->start(new \OCA\NoticeBoard\Tests\JobRecorder());
    check('SendTalkJob duyuruyu gonderir', alicilar($env) === ['ali', 'ayse', 'john', 'mehmet']);

    $env = talkEnv();
    $is = new \OCA\NoticeBoard\BackgroundJob\SendTalkJob($env->clock, $env->service);
    $is->setArgument([]);
    $is->start(new \OCA\NoticeBoard\Tests\JobRecorder());
    check('kimliksiz is hicbir sey yapmaz', $env->gateway->sent === [] && $env->users->created === []);

    finish('TalkTest');
}
