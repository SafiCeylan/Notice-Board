<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\Server;

/**
 * Nextcloud Talk'a (spreed) TEK temas noktası.
 *
 * Talk'un mesaj göndermek için genel (OCP) bir API'si yok; bu sınıf Talk'un kendi
 * servislerini, Talk'un kendi sohbet ucunun (ChatController::sendMessage) yaptığı
 * sırayla çağırır. Bu yüzden:
 *   · Talk sınıfları YALNIZ burada anılır ve çalışma anında çözülür. Tip ipucu
 *     olarak kullanılsalardı Talk kurulu olmayan sunucuda DI bu sınıfı kuramaz,
 *     duyuru kaydetmek bile patlardı.
 *   · Kullanılan her metodun imzası önce yansıma ile denetlenir (`problems()`).
 *     Talk bir sürümde imzayı değiştirirse kanal kapanır, oluşturma ekranında
 *     Talk anahtarı gizlenir ve duman testi bunu UYARI olarak yazar — duyuru
 *     yayınlamak etkilenmez.
 *
 * Duyuru sohbeti (0.5.2, 27 Eki 2026): Talk'un kendi "Talk updates" sohbetine
 * benzer. Kişi başına bir GRUP sohbeti; sahibi bot, tek katılımcısı kişi.
 * Kilitli (READ_ONLY: yanıt kutusu yerine "sohbet kilitli"), kişinin izinleri
 * "özel, hiçbiri" (arama başlatamaz/katılamaz), simgesi 📢. Bire bir sohbet
 * kilitlenemez ve simge alamaz (Talk izin vermez), bu yüzden grup.
 * Talk 25'in "announcement" hazır ayarı daha da uygun ama Talk 22'de yok.
 *
 * Doğrulanan sürümler: Talk 22.0.18 (NC 32.0.15) ve Talk 25.0.6 (NC 35.0.1).
 */
class TalkGateway {

    public const TALK_APP = 'spreed';

    /** sınıf => [metot => bizim verdiğimiz argüman sayısı] */
    private const API = [
        'OCA\Talk\Manager' => ['getRoomByToken' => 1],
        'OCA\Talk\Service\RoomService' => ['createConversation' => 2],
        'OCA\Talk\Service\ParticipantService' => ['getParticipant' => 3, 'addUsers' => 2, 'updateNotificationLevel' => 2],
        'OCA\Talk\Chat\ChatManager' => ['sendMessage' => 6],
        'OCA\Talk\Config' => ['isDisabledForUser' => 1],
    ];

    /** Adıyla verilen argümanlar: Talk adı değiştirirse kanal kapanır, yanlış yere gitmez. */
    private const NAMED_ARGUMENTS = [
        'OCA\Talk\Service\RoomService::createConversation' => ['type', 'name', 'owner', 'readOnly', 'permissions', 'description', 'emoji'],
    ];

    /** Kullanılan Talk sabitleri; değerleri çalışma anında Talk'tan okunur. */
    private const CONSTANTS = [
        'OCA\Talk\Room::TYPE_GROUP',
        'OCA\Talk\Room::READ_ONLY',
        'OCA\Talk\Model\Attendee::PERMISSIONS_CUSTOM',
        'OCA\Talk\Participant::NOTIFY_ALWAYS',
    ];

    private const ROOM_NOT_FOUND = 'OCA\Talk\Exceptions\RoomNotFoundException';
    private const PARTICIPANT_NOT_FOUND = 'OCA\Talk\Exceptions\ParticipantNotFoundException';

    private const ROOM_EMOJI = '📢';

    /** @var string[]|null istek boyunca bir kez hesaplanır */
    private ?array $problems = null;

    public function __construct(
        private IAppManager $appManager,
        private ITimeFactory $timeFactory,
    ) {
    }

    public function isAvailable(): bool {
        return $this->problems() === [];
    }

    /**
     * Talk kanalını engelleyen sebepler; boşsa kanal kullanılabilir.
     *
     * @return string[]
     */
    public function problems(): array {
        if ($this->problems !== null) {
            return $this->problems;
        }

        if (!$this->isTalkEnabled()) {
            return $this->problems = ['Talk (spreed) etkin degil'];
        }

        try {
            // Cron ve web isteğinde zaten yüklüdür; tekrar çağrı zararsız.
            $this->appManager->loadApp(self::TALK_APP);
        } catch (\Throwable $e) {
            return $this->problems = ['Talk yuklenemedi: ' . $e->getMessage()];
        }

        $problems = [];
        foreach (self::API as $class => $methods) {
            if (!class_exists($class)) {
                $problems[] = "$class yok";
                continue;
            }
            foreach ($methods as $method => $argCount) {
                if (!method_exists($class, $method)) {
                    $problems[] = "$class::$method yok";
                    continue;
                }
                $reflection = new \ReflectionMethod($class, $method);
                if ($reflection->getNumberOfRequiredParameters() > $argCount
                    || $reflection->getNumberOfParameters() < $argCount) {
                    $problems[] = "$class::$method imzasi degismis";
                    continue;
                }
                $names = array_map(static fn (\ReflectionParameter $p) => $p->getName(), $reflection->getParameters());
                foreach (self::NAMED_ARGUMENTS["$class::$method"] ?? [] as $name) {
                    if (!in_array($name, $names, true)) {
                        $problems[] = "$class::$method '$name' argumani yok";
                    }
                }
            }
        }

        foreach (self::CONSTANTS as $constant) {
            if (!defined($constant)) {
                $problems[] = "$constant yok";
            }
        }
        foreach ([self::ROOM_NOT_FOUND, self::PARTICIPANT_NOT_FOUND] as $class) {
            if (!class_exists($class)) {
                $problems[] = "$class yok";
            }
        }

        return $this->problems = $problems;
    }

    /** Talk yöneticinin "Talk'u kullanabilecek gruplar" ayarıyla bu kişiye kapalı mı? */
    public function isDisabledFor(IUser $user): bool {
        return Server::get('OCA\Talk\Config')->isDisabledForUser($user);
    }

    /**
     * Kişinin duyuru sohbetine `$bot` adına mesaj yazar ve sohbetin anahtarını döndürür.
     *
     * `$token` önceki gönderimin döndürdüğü anahtardır; sohbet hâlâ varsa ve bot
     * içindeyse kullanılır, yoksa (ilk mesaj, silinmiş sohbet) yenisi açılır.
     * Kişi sohbetten çıkmışsa geri eklenir — bire bir sohbetteki davranışın aynısı.
     *
     * @param array{name: string, description: string} $roomInfo yeni sohbet açılırsa adı ve açıklaması
     * @throws \Exception kişiye özgü sorunlar — çağıran diğer kişilerle sürdürür
     */
    public function sendNotice(IUser $bot, IUser $recipient, string $message, string $token, array $roomInfo): string {
        $rooms = Server::get('OCA\Talk\Service\RoomService');
        $participants = Server::get('OCA\Talk\Service\ParticipantService');
        $chat = Server::get('OCA\Talk\Chat\ChatManager');

        $room = $token !== '' ? $this->findNoticeRoom($token, $bot) : null;
        if ($room === null) {
            $room = $rooms->createConversation(
                type: constant('OCA\Talk\Room::TYPE_GROUP'),
                name: $roomInfo['name'],
                owner: $bot,
                readOnly: constant('OCA\Talk\Room::READ_ONLY'),
                // "Özel izin, hiçbiri": arama başlatamaz, katılamaz, yazamaz. Sahip (bot)
                // moderatör olduğu için etkilenmez.
                permissions: constant('OCA\Talk\Model\Attendee::PERMISSIONS_CUSTOM'),
                description: $roomInfo['description'],
                emoji: self::ROOM_EMOJI,
            );
        }

        if ($this->participantOrNull($room, $recipient->getUID()) === null) {
            $participants->addUsers($room, [[
                'actorType' => 'users',
                'actorId' => $recipient->getUID(),
                'displayName' => $recipient->getDisplayName(),
            ]]);
            // Grup sohbeti varsayılan olarak yalnız anılınca bildirir; duyuru her mesajda
            // bildirsin (bire bir sohbette de öyleydi). Yalnız eklenirken: kişi sonra
            // kısarsa tercihine dokunulmaz.
            $participants->updateNotificationLevel(
                $participants->getParticipant($room, $recipient->getUID(), false),
                constant('OCA\Talk\Participant::NOTIFY_ALWAYS'),
            );
        }

        $chat->sendMessage(
            $room,
            $participants->getParticipant($room, $bot->getUID(), false),
            'users',
            $bot->getUID(),
            $message,
            $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC')),
        );

        return $room->getToken();
    }

    /** Kayıtlı anahtarın sohbeti; silinmiş, grup değil ya da bot içinde değilse null. */
    private function findNoticeRoom(string $token, IUser $bot): ?object {
        try {
            $room = Server::get('OCA\Talk\Manager')->getRoomByToken($token);
        } catch (\Exception $e) {
            if (is_a($e, self::ROOM_NOT_FOUND)) {
                return null;
            }
            throw $e;
        }

        if ($room->getType() !== constant('OCA\Talk\Room::TYPE_GROUP')
            || $this->participantOrNull($room, $bot->getUID()) === null) {
            return null;
        }

        return $room;
    }

    private function participantOrNull(object $room, string $uid): ?object {
        try {
            return Server::get('OCA\Talk\Service\ParticipantService')->getParticipant($room, $uid, false);
        } catch (\Exception $e) {
            if (is_a($e, self::PARTICIPANT_NOT_FOUND)) {
                return null;
            }
            throw $e;
        }
    }

    private function isTalkEnabled(): bool {
        // isEnabledForAnyone NC 32'de geldi; isInstalled aynı soruyu NC 30/31'de cevaplar.
        return method_exists($this->appManager, 'isEnabledForAnyone')
            ? $this->appManager->isEnabledForAnyone(self::TALK_APP)
            : $this->appManager->isInstalled(self::TALK_APP);
    }
}
