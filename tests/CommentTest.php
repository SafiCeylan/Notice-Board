<?php

declare(strict_types=1);

/**
 * CommentService — yorum yetkileri (K6 · opt_comments).
 *
 * Bu dosyadaki EN KRİTİK test şudur: **bir yorumu silerken, yorumun gerçekten o
 * duyuruya ait olduğu doğrulanmalı.** Doğrulanmazsa, görebildiği bir duyurunun
 * adresine göremediği bir duyurunun yorum kimliğini yazan kullanıcı o yorumu
 * silebilir (IDOR).
 *
 * Çalıştırma:  php tests/CommentTest.php
 */

namespace {
    require __DIR__ . '/bootstrap.php';
    require __DIR__ . '/../lib/Exception/ValidationException.php';
    require __DIR__ . '/../lib/Exception/AccessDeniedException.php';
    require __DIR__ . '/../lib/Exception/CommentsClosedException.php';
    require __DIR__ . '/../lib/Db/Announcement.php';
    require __DIR__ . '/../lib/Service/PublisherService.php';
}

namespace OCA\NoticeBoard\Tests {

    use OCP\Comments\IComment;

    /** Bellekte duran yorum. */
    class FakeComment implements IComment {
        public function __construct(
            private string $id,
            private string $actorId,
            private string $objectId,
            private string $message = '',
            private string $verb = '',
            private string $actorType = 'users',
            private string $objectType = 'noticeboard',
        ) {
        }

        public function getId(): string {
            return $this->id;
        }

        public function getActorType(): string {
            return $this->actorType;
        }

        public function getActorId(): string {
            return $this->actorId;
        }

        public function getObjectType(): string {
            return $this->objectType;
        }

        public function getObjectId(): string {
            return $this->objectId;
        }

        public function getMessage(): string {
            return $this->message;
        }

        public function setMessage(string $message, int $maxLength = 1000): IComment {
            $this->message = $message;
            return $this;
        }

        public function getVerb(): string {
            return $this->verb;
        }

        public function setVerb(string $verb): IComment {
            $this->verb = $verb;
            return $this;
        }

        public function getCreationDateTime(): \DateTime {
            return new \DateTime('@1700000000');
        }
    }

    class FakeCommentsManager implements \OCP\Comments\ICommentsManager {
        /** @var array<string, FakeComment> */
        public array $comments = [];
        public array $deletedObjects = [];
        private int $nextId = 100;

        public function create(string $actorType, string $actorId, string $objectType, string $objectId): IComment {
            return new FakeComment((string)$this->nextId++, $actorId, $objectId, '', '', $actorType, $objectType);
        }

        public function save(IComment $comment): void {
            $this->comments[$comment->getId()] = $comment;
        }

        public function get(string $id): IComment {
            if (!isset($this->comments[$id])) {
                throw new \OCP\Comments\NotFoundException("yok: $id");
            }
            return $this->comments[$id];
        }

        public function delete(string $id): bool {
            unset($this->comments[$id]);
            return true;
        }

        public function getForObject(string $objectType, string $objectId, int $limit = 0, int $offset = 0): array {
            return array_values(array_filter(
                $this->comments,
                static fn (FakeComment $c) => $c->getObjectType() === $objectType && $c->getObjectId() === $objectId
            ));
        }

        public function getNumberOfCommentsForObject(string $objectType, string $objectId): int {
            return count($this->getForObject($objectType, $objectId));
        }

        public function deleteCommentsAtObject(string $objectType, string $objectId): bool {
            $this->deletedObjects[] = $objectId;
            foreach ($this->comments as $id => $c) {
                if ($c->getObjectId() === $objectId) {
                    unset($this->comments[$id]);
                }
            }
            return true;
        }
    }

    class FakeUserManager implements \OCP\IUserManager {
        public function get(string $uid): ?\OCP\IUser {
            return new FakeUser($uid);
        }

        public function getDisplayName(string $uid): ?string {
            return $uid;
        }
    }
}

namespace OCA\NoticeBoard\Service {

    use OCA\NoticeBoard\Db\Announcement;
    use OCA\NoticeBoard\Exception\AccessDeniedException;

    /**
     * AnnouncementService ikizi: yalnızca CommentService'in kullandığı tek metodu
     * taklit eder. Görünürlük kuralı zaten kendi testlerinde kilitli.
     */
    class AnnouncementService {
        /** @var array<int, Announcement> */
        public array $readable = [];

        public function findEntityForReader(int $id, ?\OCP\IUser $user): Announcement {
            if ($user === null || !isset($this->readable[$id])) {
                throw new AccessDeniedException('Bu duyuruya erişiminiz yok.');
            }
            return $this->readable[$id];
        }
    }
}

namespace {

    require __DIR__ . '/../lib/Service/CommentService.php';

    use OCA\NoticeBoard\Db\Announcement;
    use OCA\NoticeBoard\Exception\AccessDeniedException;
    use OCA\NoticeBoard\Exception\ValidationException;
    use OCA\NoticeBoard\Service\CommentService;
    use OCA\NoticeBoard\Tests\FakeAppConfig;
    use OCA\NoticeBoard\Tests\FakeComment;
    use OCA\NoticeBoard\Tests\FakeCommentsManager;
    use OCA\NoticeBoard\Tests\FakeGroupManager;
    use OCA\NoticeBoard\Tests\FakeUser;
    use OCA\NoticeBoard\Tests\FakeUserManager;

    function duyuru(string $author, bool $commentsOpen): Announcement {
        $a = new Announcement();
        $a->setAuthorUid($author);
        $a->setSubject('Duyuru');
        $a->setStatus(Announcement::STATUS_PUBLISHED);
        $a->setOptComments($commentsOpen);
        return $a;
    }

    /** @return array{0: CommentService, 1: FakeCommentsManager, 2: \OCA\NoticeBoard\Service\AnnouncementService} */
    function makeCommentService(array $admins = []): array {
        $comments = new FakeCommentsManager();
        $announcements = new \OCA\NoticeBoard\Service\AnnouncementService();
        $publisher = new \OCA\NoticeBoard\Service\PublisherService(
            new FakeAppConfig(),
            new FakeGroupManager($admins)
        );

        $service = new CommentService($comments, $announcements, $publisher, new FakeUserManager());

        return [$service, $comments, $announcements];
    }

    function checkDenied(string $name, callable $fn): void {
        try {
            $fn();
            check($name, false);
        } catch (AccessDeniedException) {
            check($name, true);
        }
    }

    $ali = new FakeUser('ali');
    $veli = new FakeUser('veli');
    $admin = new FakeUser('admin');

    // =====================================================================
    echo "CommentService — okuma\n";
    // =====================================================================

    [$service, $comments, $announcements] = makeCommentService();
    $announcements->readable[1] = duyuru('ali', true);

    checkDenied(
        'goremedigi duyurunun yorumlarini okuyamaz',
        fn () => $service->findForAnnouncement(99, $ali)
    );

    checkDenied('oturum yoksa yorum okunamaz', fn () => $service->findForAnnouncement(1, null));

    check('gorebildigi duyuruda yorum listesi bos baslar', $service->findForAnnouncement(1, $ali) === []);

    // =====================================================================
    echo "\nCommentService — yazma\n";
    // =====================================================================

    [$service, $comments, $announcements] = makeCommentService();
    $announcements->readable[1] = duyuru('ali', true);
    $announcements->readable[2] = duyuru('ali', false);

    checkDenied('oturum yoksa yorum yazilamaz', fn () => $service->create(1, null, 'merhaba'));

    checkDenied(
        'goremedigi duyuruya yorum yazamaz',
        fn () => $service->create(99, $ali, 'merhaba')
    );

    // 🔐 Anahtar kapaliyken arayuz gizler; API de reddetmeli.
    checkDenied(
        'yorumlar KAPALI duyuruya API uzerinden bile yorum yazilamaz',
        fn () => $service->create(2, $veli, 'merhaba')
    );

    try {
        $service->create(1, $ali, '   ');
        check('bos yorum reddedilir', false);
    } catch (ValidationException) {
        check('bos yorum reddedilir', true);
    }

    try {
        $service->create(1, $ali, str_repeat('a', 1001));
        check('1000 karakterden uzun yorum reddedilir', false);
    } catch (ValidationException) {
        check('1000 karakterden uzun yorum reddedilir', true);
    }

    $olusan = $service->create(1, $ali, '  merhaba dunya  ');
    check('yorum olusturulur', $olusan['message'] === 'merhaba dunya');
    check('yorumun yazari oturumdan gelir', $olusan['actorId'] === 'ali');
    check('kendi yorumunu silebilecegi isaretlenir', $olusan['canDelete'] === true);
    check('yorum listede gorunur', count($service->findForAnnouncement(1, $ali)) === 1);
    check(
        'baskasinin gozunde silme isareti kapali',
        $service->findForAnnouncement(1, $veli)[0]['canDelete'] === false
    );

    // Tam sınırdaki uzunluk kabul edilmeli (1000 karakter).
    $sinir = $service->create(1, $ali, str_repeat('b', 1000));
    check('tam 1000 karakterlik yorum kabul edilir', mb_strlen($sinir['message']) === 1000);

    // =====================================================================
    echo "\nCommentService — silme\n";
    // =====================================================================

    [$service, $comments, $announcements] = makeCommentService();
    $announcements->readable[1] = duyuru('ali', true);
    $announcements->readable[2] = duyuru('ali', true);

    $comments->save(new FakeComment('500', 'ali', '1', 'alinin yorumu'));
    $comments->save(new FakeComment('501', 'veli', '1', 'velinin yorumu'));
    $comments->save(new FakeComment('600', 'veli', '2', 'baska duyurudaki yorum'));

    checkDenied('oturum yoksa yorum silinemez', fn () => $service->delete(1, '500', null));

    checkDenied(
        'baskasinin yorumunu siradan kullanici silemez',
        fn () => $service->delete(1, '500', $veli)
    );

    checkDenied('olmayan yorum silinemez', fn () => $service->delete(1, '9999', $ali));

    // 🔐 EN KRİTİK: 600 numaralı yorum 2 numaralı duyuruya ait. Kullanıcı 1
    // numaralı duyurunun adresinden onu silmeye çalışırsa reddedilmeli.
    checkDenied(
        'BASKA duyuruya ait yorum, bu duyurunun adresinden silinemez (IDOR)',
        fn () => $service->delete(1, '600', $veli)
    );
    check('IDOR denemesi sonrasi yorum hala duruyor', isset($comments->comments['600']));

    $service->delete(1, '501', $veli);
    check('kullanici kendi yorumunu silebilir', !isset($comments->comments['501']));

    // Duyuru sahibi (yayıncı) kendi duyurusunun altını temizleyebilmeli.
    [$service, $comments, $announcements] = makeCommentService();
    $announcements->readable[1] = duyuru('ali', true);
    $comments->save(new FakeComment('700', 'veli', '1', 'velinin yorumu'));

    // ali yayinci degilse baskasinin yorumunu silemez.
    checkDenied(
        'yayinci olmayan duyuru sahibi baskasinin yorumunu silemez',
        fn () => $service->delete(1, '700', $ali)
    );

    // Yonetici her yorumu silebilir.
    [$service, $comments, $announcements] = makeCommentService(['admin']);
    $announcements->readable[1] = duyuru('ali', true);
    $comments->save(new FakeComment('800', 'veli', '1', 'velinin yorumu'));

    $service->delete(1, '800', $admin);
    check('yonetici baskasinin yorumunu silebilir', !isset($comments->comments['800']));

    // =====================================================================
    echo "\nCommentService — sayim\n";
    // =====================================================================

    [$service, $comments, $announcements] = makeCommentService();
    $announcements->readable[1] = duyuru('ali', true);
    $comments->save(new FakeComment('900', 'ali', '1'));
    $comments->save(new FakeComment('901', 'veli', '1'));
    $comments->save(new FakeComment('902', 'veli', '2'));

    check('sayim yalniz o duyurunun yorumlarini sayar', $service->countForAnnouncement(1) === 2);
    check('yorumu olmayan duyuruda sayim sifirdir', $service->countForAnnouncement(3) === 0);

    finish('CommentTest');
}
