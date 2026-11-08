<?php

declare(strict_types=1);

/**
 * AnnouncementService — doğrulama ve durum türetme kuralları.
 *
 * Bu testin koruduğu en önemli şey: **durum (status) istemciden GELMEZ, kurallardan
 * türetilir.** Aksi halde bir istemci `status=published` gönderip zamanlamayı
 * (K5) atlayabilir ve duyuru vaktinden önce herkese görünür.
 *
 * Çalıştırma:  php tests/AnnouncementServiceTest.php
 */

// ⚠️ Süslü parantezli namespace sözdizimi kullanıldığı anda dosyadaki HER İFADE
// bir namespace bloğunun içinde olmak zorunda — çıplak require bile. Bu yüzden
// yüklemeler de global namespace bloğuna alındı.
namespace {
    require __DIR__ . '/bootstrap.php';
    require __DIR__ . '/../lib/Exception/ValidationException.php';
    require __DIR__ . '/../lib/Exception/AccessDeniedException.php';
    require __DIR__ . '/../lib/Db/Announcement.php';
    require __DIR__ . '/../lib/Db/Attachment.php';
    require __DIR__ . '/../lib/Service/VisibilityService.php';
    require __DIR__ . '/../lib/Service/PublisherService.php';
    require __DIR__ . '/../lib/Service/HtmlSanitizer.php';
}

namespace OCA\NoticeBoard\Service {

    /**
     * Dagitim ikizi: gercek kanallari cagirmak yerine ne tetiklendigini kaydeder.
     * Testin sordugu soru "posta gitti mi" degil, "dagitim DOGRU ANDA mi tetiklendi".
     */
    class DispatchService {
        /** @var array<int, array{id: int, gids: string[]}> */
        public array $dispatched = [];
        /** @var int[] */
        public array $withdrawn = [];

        public function dispatch(\OCA\NoticeBoard\Db\Announcement $a, array $gids): void {
            $this->dispatched[] = ['id' => (int)$a->getId(), 'gids' => $gids];
        }

        public function withdraw(int $id): void {
            $this->withdrawn[] = $id;
        }
    }

    /** Yorum temizleyici ikizi: hangi duyurunun yorumlari silindi, onu kaydeder. */
    class CommentCleaner {
        /** @var int[] */
        public array $cleaned = [];

        public function deleteAllForAnnouncement(int $id): void {
            $this->cleaned[] = $id;
        }
    }
}

// ---------------------------------------------------------------------------
// Mapper ikizleri — gerçekleri hiç yüklenmiyor, bellekte çalışıyorlar.
// ---------------------------------------------------------------------------

namespace OCA\NoticeBoard\Db {

    class AnnouncementMapper {
        /** @var Announcement[] */
        public array $rows = [];
        private int $nextId = 1;
        /** Son INSERT'te gerçekten yazılacak alanlar (NC yalnız bunları yazar). */
        public array $lastInsertedUpdatedFields = [];

        public function insert(Announcement $a): Announcement {
            $this->lastInsertedUpdatedFields = array_keys($a->getUpdatedFields());
            $a->setId($this->nextId++);
            $this->rows[$a->getId()] = $a;
            return $a;
        }

        public function update(Announcement $a): Announcement {
            $this->rows[$a->getId()] = $a;
            return $a;
        }

        public function delete(Announcement $a): Announcement {
            unset($this->rows[$a->getId()]);
            return $a;
        }

        public function find(int $id): Announcement {
            if (!isset($this->rows[$id])) {
                throw new \OCP\AppFramework\Db\DoesNotExistException("yok: $id");
            }
            return $this->rows[$id];
        }

        public function findAll(int $limit = 50, int $offset = 0): array {
            return array_values($this->rows);
        }

        public function findVisible(int $now, int $limit = 50, int $offset = 0): array {
            return array_values(array_filter(
                $this->rows,
                static fn (Announcement $a) => $a->isVisibleAt($now)
            ));
        }

        public function findByAuthor(string $authorUid, int $limit = 50, int $offset = 0): array {
            return array_values(array_filter(
                $this->rows,
                static fn (Announcement $a) => $a->getAuthorUid() === $authorUid
            ));
        }

        public function findDueForPublishing(int $now): array {
            return array_values(array_filter($this->rows, static fn (Announcement $a) => $a->getStatus() === Announcement::STATUS_SCHEDULED
                && $a->getPublishAt() !== null
                && $a->getPublishAt() <= $now));
        }

        public function findExpired(int $now): array {
            return array_values(array_filter($this->rows, static fn (Announcement $a) => $a->getStatus() === Announcement::STATUS_PUBLISHED
                && $a->getDeleteAt() !== null
                && $a->getDeleteAt() <= $now));
        }
    }

    class AnnouncementGroupMapper {
        /** @var array<int, string[]> */
        public array $gids = [];

        public function findGids(int $announcementId): array {
            return $this->gids[$announcementId] ?? [];
        }

        public function setGids(int $announcementId, array $gids): void {
            $this->gids[$announcementId] = array_values(array_unique($gids));
        }

        public function deleteByAnnouncement(int $announcementId): void {
            unset($this->gids[$announcementId]);
        }
    }

    class AttachmentMapper {
        /** @var array<int, Attachment[]> */
        public array $rows = [];

        public function insert(Attachment $a): Attachment {
            $this->rows[$a->getAnnouncementId()][] = $a;
            return $a;
        }

        public function findByAnnouncement(int $announcementId): array {
            return $this->rows[$announcementId] ?? [];
        }

        public function deleteByAnnouncement(int $announcementId): void {
            unset($this->rows[$announcementId]);
        }
    }
}

namespace {

    require __DIR__ . '/FakeUserFiles.php';
    require __DIR__ . '/../lib/Service/InlineImages.php';
    require __DIR__ . '/../lib/Service/AnnouncementService.php';

    use OCA\NoticeBoard\Db\Announcement;
    use OCA\NoticeBoard\Service\AnnouncementService;

    const NOW = 1_700_000_000;

    /**
     * Denetlenebilir saat — testler gerçek zamandan bağımsız olsun.
     * Zamanlama testleri `$clock->now` ile saati ileri alır.
     */
    class FrozenClock implements \OCP\AppFramework\Utility\ITimeFactory {
        public int $now = NOW;

        public function getTime(): int {
            return $this->now;
        }
    }

    /**
     * Testlerde okuyucu olarak kullanılan hesap. Hiçbir gruba üye değil, yani
     * yalnızca "herkese açık" duyuruları görür — hedefleme testleri
     * PermissionTest'te ayrıca yapılıyor.
     */
    function reader(): \OCP\IUser {
        return new \OCA\NoticeBoard\Tests\FakeUser('okuyucu');
    }

    /**
     * @param array<string, int[]>|null $owned kapak/ek testleri için uid => dosyalar (null = hepsi herkesin)
     * @param array<int, string> $names dosya kimliği => gerçek ad
     * @param int[]|null $images görsel olan dosyalar (null = hepsi)
     */
    function makeService(?array $owned = null, array $names = [], ?array $images = null, ?\OCA\NoticeBoard\Tests\FakeGroupManager $groupManager = null): array {
        $mapper = new \OCA\NoticeBoard\Db\AnnouncementMapper();
        $groups = new \OCA\NoticeBoard\Db\AnnouncementGroupMapper();
        $files = new \OCA\NoticeBoard\Db\AttachmentMapper();
        $groupManager ??= new \OCA\NoticeBoard\Tests\FakeGroupManager();
        $visibility = new \OCA\NoticeBoard\Service\VisibilityService($groupManager);
        $publisher = new \OCA\NoticeBoard\Service\PublisherService(
            new \OCA\NoticeBoard\Tests\FakeAppConfig(),
            $groupManager
        );
        $dispatch = new \OCA\NoticeBoard\Service\DispatchService();
        $cleaner = new \OCA\NoticeBoard\Service\CommentCleaner();
        $clock = new FrozenClock();
        $service = new AnnouncementService(
            $mapper,
            $groups,
            $files,
            $visibility,
            $publisher,
            new \OCA\NoticeBoard\Service\HtmlSanitizer(),
            $dispatch,
            $cleaner,
            $clock,
            $userFiles = new \OCA\NoticeBoard\Tests\FakeUserFiles($owned, $names, $images),
            new \OCA\NoticeBoard\Tests\FakeDisplayNames(['ali' => 'Ali Veli']),
            new \OCA\NoticeBoard\Service\InlineImages($userFiles)
        );

        return [$service, $mapper, $groups, $files, $dispatch, $clock, $cleaner];
    }

    echo "AnnouncementService\n";

    // --- Doğrulama --------------------------------------------------------
    [$service] = makeService();

    checkThrows('bos baslik reddedilir', fn () => $service->create('ali', ['subject' => '   ']));

    checkThrows(
        '255 karakterden uzun baslik reddedilir',
        fn () => $service->create('ali', ['subject' => str_repeat('a', 256)])
    );

    checkThrows(
        'gecersiz oncelik reddedilir',
        fn () => $service->create('ali', ['subject' => 'Test', 'priority' => 5])
    );

    checkThrows(
        'negatif oncelik reddedilir',
        fn () => $service->create('ali', ['subject' => 'Test', 'priority' => -1])
    );

    checkThrows(
        'silme zamani yayin zamanindan once olamaz',
        fn () => $service->create('ali', [
            'subject' => 'Test',
            'publishAt' => NOW + 1000,
            'deleteAt' => NOW + 500,
        ])
    );

    checkThrows(
        'silme zamani yayin zamaniyla ayni olamaz',
        fn () => $service->create('ali', [
            'subject' => 'Test',
            'publishAt' => NOW + 1000,
            'deleteAt' => NOW + 1000,
        ])
    );

    // --- Durum türetme (K5) ----------------------------------------------
    [$service] = makeService();

    $plain = $service->create('ali', ['subject' => 'Hemen yayinla']);
    check('tarihsiz duyuru dogrudan yayinlanir', $plain['status'] === Announcement::STATUS_PUBLISHED);

    $future = $service->create('ali', ['subject' => 'Ileri tarih', 'publishAt' => NOW + 3600]);
    check('ileri tarihli duyuru zamanlanmis olur', $future['status'] === Announcement::STATUS_SCHEDULED);

    $past = $service->create('ali', ['subject' => 'Gecmis tarih', 'publishAt' => NOW - 3600]);
    check('gecmis tarihli duyuru yayinlanmis olur', $past['status'] === Announcement::STATUS_PUBLISHED);

    $draft = $service->create('ali', ['subject' => 'Taslak', 'draft' => true]);
    check('draft bayragi taslak uretir', $draft['status'] === Announcement::STATUS_DRAFT);

    $draftFuture = $service->create('ali', [
        'subject' => 'Ileri tarihli taslak',
        'draft' => true,
        'publishAt' => NOW + 3600,
    ]);
    check(
        'taslak, ileri tarih verilse bile taslak kalir',
        $draftFuture['status'] === Announcement::STATUS_DRAFT
    );

    // 🔐 En kritik test: istemci durumu dayatamaz.
    $injected = $service->create('ali', [
        'subject' => 'Zorlama',
        'status' => Announcement::STATUS_PUBLISHED,
        'publishAt' => NOW + 99999,
    ]);
    check(
        'istemci status gondererek zamanlamayi ATLAYAMAZ',
        $injected['status'] === Announcement::STATUS_SCHEDULED
    );

    // --- Yazar ve zaman damgası -------------------------------------------
    [$service] = makeService();
    $owned = $service->create('veli', ['subject' => 'Sahiplik']);
    check('yazar oturumdan gelir', $owned['authorUid'] === 'veli');
    check('olusturma zamani saatten gelir', $owned['createdAt'] === NOW);

    // --- Hedefleme (K3) ---------------------------------------------------
    [$service] = makeService();

    $everyone = $service->create('ali', ['subject' => 'Herkese']);
    check('grup verilmezse hedef listesi bostur (= herkese acik)', $everyone['groups'] === []);

    $targeted = $service->create('ali', ['subject' => 'Gruba', 'groups' => ['ik', 'muhasebe', 'ik']]);
    check('hedef gruplar tekillestirilir', $targeted['groups'] === ['ik', 'muhasebe']);

    $dirty = $service->create('ali', ['subject' => 'Bosluklu', 'groups' => ['  ik  ', '', '   ']]);
    check('bos ve bosluklu grup adlari temizlenir', $dirty['groups'] === ['ik']);

    // --- Dağıtım anahtarları (K6) -----------------------------------------
    [$service] = makeService();

    $defaults = $service->create('ali', ['subject' => 'Varsayilan']);
    check(
        'varsayilan olarak bildirim ve etkinlik acik, e-posta kapali',
        $defaults['options']['notification'] === true
        && $defaults['options']['activity'] === true
        && $defaults['options']['email'] === false
    );

    $custom = $service->create('ali', [
        'subject' => 'Ozel',
        'options' => ['notification' => false, 'email' => true, 'comments' => false],
    ]);
    check(
        'dagitim anahtarlari tek tek ayarlanabilir',
        $custom['options']['notification'] === false
        && $custom['options']['email'] === true
        && $custom['options']['comments'] === false
        && $custom['options']['activity'] === true
    );

    // Talk (0.5.0): yeni duyuruda açık. NC yalnız SET EDİLEN alanları yazar ve
    // kolon varsayılanı kapalı — create() alanı açıkça işaretlemezse kayıt kapalı düşer.
    [$service, $mapper] = makeService();
    $talkVarsayilan = $service->create('ali', ['subject' => 'Talk varsayilan']);
    check('varsayilan olarak Talk mesaji acik', $talkVarsayilan['options']['talk'] === true);
    check('Talk anahtari INSERT oncesi set edilmis (kolon varsayilanina kalmaz)',
        in_array('optTalk', $mapper->lastInsertedUpdatedFields, true));
    $talkKapali = $service->create('ali', ['subject' => 'Talk kapali', 'options' => ['talk' => false]]);
    check('Talk anahtari kapatilabilir', $talkKapali['options']['talk'] === false);
    $talkDuzen = $service->update($talkKapali['id'], ['options' => ['talk' => true]]);
    check('Talk anahtari duzenlemede acilabilir', $talkDuzen['options']['talk'] === true);
    $talkDokunulmadi = $service->update($talkKapali['id'], ['options' => ['email' => true]]);
    check('baska anahtar degisince Talk anahtari korunur', $talkDokunulmadi['options']['talk'] === true);

    // --- Güncelleme -------------------------------------------------------
    [$service, $mapper] = makeService();

    $created = $service->create('ali', ['subject' => 'Ilk hali', 'groups' => ['ik']]);
    $updated = $service->update($created['id'], ['subject' => 'Ikinci hali']);
    check('guncelleme basligi degistirir', $updated['subject'] === 'Ikinci hali');
    check('gonderilmeyen alan (groups) korunur', $updated['groups'] === ['ik']);

    $regrouped = $service->update($created['id'], ['groups' => []]);
    check('bos grup listesi gonderilirse duyuru herkese acilir', $regrouped['groups'] === []);

    // Arşivlenmiş duyuru düzenlenince kendiliğinden yayına dönmemeli.
    $archived = $service->create('ali', ['subject' => 'Arsivlik']);
    $mapper->find($archived['id'])->setStatus(Announcement::STATUS_ARCHIVED);
    $touched = $service->update($archived['id'], ['subject' => 'Arsivde duzenlendi']);
    check('arsivlenmis duyuru duzenlenince arsivde kalir', $touched['status'] === Announcement::STATUS_ARCHIVED);

    // --- Duzenlemede alan BOSALTMA (5 Eki 2026) ---------------------------
    // Controller null'lari atiyordu: kapagi kaldirmak, zamanlamayi silmek ve
    // kategoriyi bosaltmak sessizce yok sayiliyordu.
    $tamDolu = [
        'subject' => null, 'message' => null, 'category' => null, 'priority' => null,
        'pinned' => null, 'coverFileId' => null, 'coverX' => null, 'coverY' => null,
        'publishAt' => null, 'deleteAt' => null, 'groups' => null,
        'attachments' => null, 'options' => null, 'draft' => null,
    ];

    $secilen = AnnouncementService::pickSentFields(
        array_merge($tamDolu, ['subject' => 'Yeni']),
        ['id' => 3, 'subject' => 'Yeni']
    );
    check('gonderilmeyen alanlar guncellemeye girmez', $secilen === ['subject' => 'Yeni']);

    $secilen = AnnouncementService::pickSentFields(
        $tamDolu,
        ['coverFileId' => null, 'publishAt' => null, 'deleteAt' => null, 'category' => null]
    );
    check('gonderilen null kapak "kaldir" demektir', array_key_exists('coverFileId', $secilen) && $secilen['coverFileId'] === null);
    check('gonderilen null yayin zamani "sil" demektir', array_key_exists('publishAt', $secilen));
    check('gonderilen null kalkma zamani "sil" demektir', array_key_exists('deleteAt', $secilen));
    check('gonderilen null kategori "bosalt" demektir', array_key_exists('category', $secilen));

    $secilen = AnnouncementService::pickSentFields($tamDolu, ['subject' => null, 'groups' => null, 'options' => null]);
    check('bosaltilamaz alanda gonderilen null yok sayilir', $secilen === []);

    [$service] = makeService();
    $kapakli = $service->create('ali', [
        'subject' => 'Kapakli', 'category' => 'IK', 'coverFileId' => 77,
        'coverX' => 20, 'coverY' => 0, 'publishAt' => NOW + 3600, 'deleteAt' => NOW + 7200,
    ]);
    check('kapak konumu kaydedilir', $kapakli['coverX'] === 20 && $kapakli['coverY'] === 0);

    $temiz = $service->update($kapakli['id'], AnnouncementService::pickSentFields(
        $tamDolu,
        ['coverFileId' => null, 'publishAt' => null, 'deleteAt' => null, 'category' => null]
    ));
    check('duzenlemede kapak kaldirilabilir', $temiz['coverFileId'] === null);
    check('kapak kaldirilinca konumu da sifirlanir', $temiz['coverX'] === null && $temiz['coverY'] === null);
    check('duzenlemede yayin zamani silinebilir', $temiz['publishAt'] === null);
    check('duzenlemede kalkma zamani silinebilir', $temiz['deleteAt'] === null);
    check('duzenlemede kategori bosaltilabilir', $temiz['category'] === null);
    check('yayin zamani silinen zamanlanmis duyuru hemen yayina girer', $temiz['status'] === Announcement::STATUS_PUBLISHED);

    $konumlu = $service->create('ali', ['subject' => 'Konum', 'coverFileId' => 5, 'coverX' => 50, 'coverY' => 50]);
    $kaydi = $service->update($konumlu['id'], ['coverY' => 100]);
    check('kapak konumu tek basina guncellenebilir', $kaydi['coverY'] === 100 && $kaydi['coverX'] === 50);
    check('sinir: konum 0 kabul edilir', $service->update($konumlu['id'], ['coverX' => 0])['coverX'] === 0);
    checkThrows('konum 100 ustu reddedilir', fn () => $service->update($konumlu['id'], ['coverY' => 101]));
    checkThrows('negatif konum reddedilir', fn () => $service->update($konumlu['id'], ['coverX' => -1]));

    // --- Kapak sahibi (7 Eki 2026) ----------------------------------------
    // Kapak onu SEÇENİN dosyalarından servis edilir. Yönetici başkasının
    // duyurusunda kapağı değiştirince görsel yazarın alanında aranıp 404 dönüyordu.
    [$service, $mapper] = makeService(['ali' => [10, 11], 'ozge' => [20]]);

    $kendi = $service->create('ali', ['subject' => 'Kapak', 'coverFileId' => 10]);
    check('yazarin kendi kapaginda sahip bos kalir', $mapper->rows[$kendi['id']]->getCoverOwner() === null);
    check('sahip bossa kapak yazarin alaninda aranir', $mapper->rows[$kendi['id']]->getCoverFileOwnerUid() === 'ali');
    check('kapak sahibi okuyucuya gonderilmez', !array_key_exists('coverOwner', $kendi));

    $service->update($kendi['id'], ['coverFileId' => 20], 'ozge');
    check('yonetici degistirdiginde sahip yonetici olur', $mapper->rows[$kendi['id']]->getCoverFileOwnerUid() === 'ozge');

    // Yazar başlığı düzeltiyor; form yöneticinin koyduğu kapağı aynen geri gönderiyor.
    try {
        $service->update($kendi['id'], ['subject' => 'Baslik duzeldi', 'coverFileId' => 20], 'ali');
        $reddedildi = false;
    } catch (\OCA\NoticeBoard\Exception\ValidationException) {
        $reddedildi = true;
    }
    check('yonetici kapak koyduktan sonra yazar duzenleyebilir (ayni kapak dogrulanmaz)', !$reddedildi);
    check('ayni kapak yeniden gonderilince sahibi degismez', $mapper->rows[$kendi['id']]->getCoverFileOwnerUid() === 'ozge');

    $service->update($kendi['id'], ['coverFileId' => 11], 'ali');
    check('yazar yeniden kendi kapagini koyunca sahip bosalir', $mapper->rows[$kendi['id']]->getCoverOwner() === null);

    checkThrows(
        'baskasinin dosyasi kapak yapilamaz',
        fn () => $service->update($kendi['id'], ['coverFileId' => 20], 'ali')
    );
    check('reddedilen kapak eskisini bozmaz', $mapper->rows[$kendi['id']]->getCoverFileId() === 11);

    $once = count($mapper->rows);
    checkThrows('olmayan dosyayla duyuru olusturulamaz', fn () => $service->create('ali', ['subject' => 'X', 'coverFileId' => 999]));
    check('reddedilen olusturma yetim kayit birakmaz', count($mapper->rows) === $once);

    $service->update($kendi['id'], ['coverFileId' => 20], 'ozge');
    $service->update($kendi['id'], ['coverFileId' => null], 'ozge');
    check('kapak kaldirilinca sahip de temizlenir', $mapper->rows[$kendi['id']]->getCoverOwner() === null);

    // --- Hedef gruplar yalnız düzenleyebilene gider (7 Eki 2026) -----------
    // Okuyucu için anlamsız ve kurumun grup yapısını açığa çıkarıyordu; ama
    // düzenleme ekranı gruplarla açılmalı, yoksa kayıt hedeflemeyi silerdi.
    // Yönetici de `ik` üyesi: okuyucu listesi yalnız hedeflenenleri gösterir; burada
    // sorulan "listede gruplar düzenleyene gidiyor mu", hedefleme değil.
    $gm = new \OCA\NoticeBoard\Tests\FakeGroupManager(['yonetici'], ['ayse' => ['ik'], 'ali' => ['ik'], 'yonetici' => ['ik']]);
    [$service] = makeService(null, [], null, $gm);
    $hedefli = $service->create('ali', ['subject' => 'Hedefli', 'groups' => ['ik']]);
    $okur = new \OCA\NoticeBoard\Tests\FakeUser('ayse');
    $yonetici = new \OCA\NoticeBoard\Tests\FakeUser('yonetici');
    $yazar = new \OCA\NoticeBoard\Tests\FakeUser('ali');

    check('okuyucu detayinda hedef gruplar yok', !array_key_exists('groups', $service->findFor($hedefli['id'], $okur)));
    $okurListesi = $service->findVisibleFor($okur);
    check('okuyucu listesinde duyuru var', count($okurListesi) === 1);
    check('okuyucu listesinde hedef gruplar yok', !array_key_exists('groups', $okurListesi[0]));
    check('yonetici detayinda hedef gruplar var (duzenleme ekrani icin)', ($service->findFor($hedefli['id'], $yonetici)['groups'] ?? null) === ['ik']);
    check('yonetici listesinde hedef gruplar var', ($service->findVisibleFor($yonetici)[0]['groups'] ?? null) === ['ik']);
    // Yazar yayıncı değilse (yetkisi alınmışsa) düzenleyemez → o da okuyucudur.
    check('yayinci olmayan yazar okuyucu sayilir', !array_key_exists('groups', $service->findFor($hedefli['id'], $yazar)));
    check('olusturma yaniti (yayinciya) gruplari tasir', $hedefli['groups'] === ['ik']);

    // --- Yazar görünen adıyla -----------------------------------------------
    check('yazarin gorunen adi gider', $hedefli['authorName'] === 'Ali Veli');
    [$service] = makeService();
    check('hesabi silinmis yazarda kimlige duser', $service->create('silindi', ['subject' => 'Eski'])['authorName'] === 'silindi');

    // --- Görsel olmayan dosya kapak yapılamaz -------------------------------
    [$service] = makeService(['ali' => [30, 31]], [], [30]);
    check('gorsel kapak kabul edilir', $service->create('ali', ['subject' => 'G', 'coverFileId' => 30])['coverFileId'] === 30);
    checkThrows('gorsel olmayan dosya kapak yapilamaz', fn () => $service->create('ali', ['subject' => 'P', 'coverFileId' => 31]));

    // --- Ek sahibi (7 Eki 2026) -------------------------------------------
    // 🔴 Önceden ek kimliği hiç doğrulanmıyordu: başkasının duyurusunu düzenleyen
    // biri yazarın ÖZEL dosyasını ek yazıp herkese servis ettirebilirdi.
    [$service, $mapper, , $files] = makeService(
        ['ali' => [40, 41, 99], 'ozge' => [50]],
        [40 => 'izin-formu.pdf', 41 => 'takvim.xlsx', 50 => 'yonetici-notu.pdf', 99 => 'ali-ozel-maas.pdf']
    );
    $ekli = $service->create('ali', ['subject' => 'Ekli', 'attachments' => [['fileId' => 40, 'fileName' => 'sahte-ad.exe']]]);
    check('ek adi istemciden degil dosyadan gelir', $ekli['attachments'][0]['fileName'] === 'izin-formu.pdf');
    check('yazarin kendi ekinde sahip bos', $files->findByAnnouncement($ekli['id'])[0]->getOwnerUid() === null);
    check('ek sahibi okuyucuya gonderilmez', !array_key_exists('ownerUid', $ekli['attachments'][0]));

    checkThrows(
        'yonetici yazarin OZEL dosyasini ek yapamaz',
        fn () => $service->update($ekli['id'], ['attachments' => [['fileId' => 40], ['fileId' => 99]]], 'ozge')
    );
    check('reddedilen ek guncellemesi mevcut ekleri silmez', count($files->findByAnnouncement($ekli['id'])) === 1);

    $service->update($ekli['id'], ['attachments' => [['fileId' => 40], ['fileId' => 50]]], 'ozge');
    $ekler = $files->findByAnnouncement($ekli['id']);
    check('yonetici kendi dosyasini ekleyebilir', count($ekler) === 2);
    check('yoneticinin eki yonetici alaninda aranir', $ekler[1]->getFileOwnerUid('ali') === 'ozge');
    check('mevcut ekin sahibi degismez', $ekler[0]->getFileOwnerUid('ali') === 'ali');

    try {
        $service->update($ekli['id'], ['subject' => 'Yazar duzeltti', 'attachments' => [['fileId' => 40], ['fileId' => 50], ['fileId' => 41]]], 'ali');
        $yazarReddedildi = false;
    } catch (\OCA\NoticeBoard\Exception\ValidationException) {
        $yazarReddedildi = true;
    }
    check('yonetici ek ekledikten sonra yazar duzenleyebilir', !$yazarReddedildi);
    $ekler = $files->findByAnnouncement($ekli['id']);
    check('yazar duzenleyince yoneticinin eki yonetici alaninda kalir', $ekler[1]->getFileOwnerUid('ali') === 'ozge');
    check('yazarin yeni eki eklenir', count($ekler) === 3 && $ekler[2]->getFileName() === 'takvim.xlsx');

    $once = count($mapper->rows);
    checkThrows('olmayan dosyayla ekli duyuru olusturulamaz', fn () => $service->create('ali', ['subject' => 'X', 'attachments' => [['fileId' => 777]]]));
    check('reddedilen ekli olusturma yetim kayit birakmaz', count($mapper->rows) === $once);

    $service->update($ekli['id'], ['subject' => 'Eklere dokunulmadi']);
    check('ek listesi gonderilmezse eklere dokunulmaz', count($files->findByAnnouncement($ekli['id'])) === 3);

    // --- Silme ------------------------------------------------------------
    [$service, $mapper, $groups, $files] = makeService();

    $doomed = $service->create('ali', [
        'subject' => 'Silinecek',
        'groups' => ['ik'],
        'attachments' => [['fileId' => 42, 'fileName' => 'yonetmelik.pdf']],
    ]);
    check('ek kaydedilir', count($doomed['attachments']) === 1);
    check('ek adi dosyanin kendisinden okunur', $doomed['attachments'][0]['fileName'] === 'dosya-42');

    $service->delete($doomed['id']);
    check('silinen duyuru kalmaz', $mapper->rows === []);
    check('duyuruyla birlikte hedef gruplari da silinir', $groups->findGids($doomed['id']) === []);
    check('duyuruyla birlikte ekleri de silinir', $files->findByAnnouncement($doomed['id']) === []);

    // --- Okuyucu listesi zamanlamaya uyar ---------------------------------
    [$service] = makeService();
    $service->create('ali', ['subject' => 'Yayinda']);
    $service->create('ali', ['subject' => 'Ileride', 'publishAt' => NOW + 3600]);
    $service->create('ali', ['subject' => 'Taslak', 'draft' => true]);

    $visible = $service->findVisibleFor(reader());
    check('okuyucu listesinde yalniz yayindakiler var', count($visible) === 1);
    check('gorunen duyuru dogru olan', $visible[0]['subject'] === 'Yayinda');

    check('yonetim listesi hepsini gosterir', count($service->findAll()) === 3);

    // --- Okuyucu listesi hedeflemeye de uyar (K3) -------------------------
    [$service] = makeService();
    $service->create('ali', ['subject' => 'Herkese acik']);
    $service->create('ali', ['subject' => 'Sadece IK', 'groups' => ['ik']]);

    $forReader = $service->findVisibleFor(reader());
    check(
        'gruba hedeflenmis duyuru, o grupta olmayana listelenmez',
        count($forReader) === 1 && $forReader[0]['subject'] === 'Herkese acik'
    );

    // --- Tek duyuru okuma yetkisi ----------------------------------------
    [$service] = makeService();
    $acik = $service->create('ali', ['subject' => 'Acik']);
    $kapali = $service->create('ali', ['subject' => 'Kapali', 'groups' => ['ik']]);

    check('herkese acik duyuru okunabilir', $service->findFor($acik['id'], reader())['id'] === $acik['id']);

    $reddedildi = false;
    try {
        $service->findFor($kapali['id'], reader());
    } catch (\OCA\NoticeBoard\Exception\AccessDeniedException) {
        $reddedildi = true;
    }
    check('hedeflenmemis duyuru tek tek de okunamaz', $reddedildi);

    // --- Gövde YAZILIRKEN temizlenir (XSS) --------------------------------
    [$service] = makeService();
    $kirli = $service->create('ali', [
        'subject' => 'Zararli icerik',
        'message' => '<p>merhaba</p><script>alert(1)</script><a href="javascript:alert(2)">tikla</a>',
    ]);
    check(
        'govde veritabanina temizlenmis yazilir (script yok)',
        !str_contains(strtolower($kirli['message']), 'script')
    );
    check(
        'javascript: baglantisi veritabanina yazilmaz',
        !str_contains(strtolower($kirli['message']), 'javascript:')
    );
    check(
        'mesru icerik korunur',
        str_contains($kirli['message'], 'merhaba')
    );

    // --- Dağıtım NE ZAMAN tetiklenir (K6) ---------------------------------
    // Bu bloğun koruduğu şey: yayındaki bir duyuruda yazım hatası düzeltmek
    // herkese YENIDEN bildirim göndermemeli. Kural bozulursa kimse fark etmez,
    // sadece kullanıcılar aynı duyuru için defalarca bildirim alır.

    [$service, $mapper, , , $dispatch] = makeService();

    $service->create('ali', ['subject' => 'Hemen yayinda']);
    check('yayinlanan duyuru dagitimi tetikler', count($dispatch->dispatched) === 1);

    $service->create('ali', ['subject' => 'Taslak', 'draft' => true]);
    check('taslak dagitim TETIKLEMEZ', count($dispatch->dispatched) === 1);

    $ileri = $service->create('ali', ['subject' => 'Ileri tarihli', 'publishAt' => NOW + 3600]);
    check('zamanlanmis duyuru dagitim TETIKLEMEZ', count($dispatch->dispatched) === 1);

    // Yayındaki duyuruyu düzenle → tekrar dağıtılmamalı.
    $yayinda = $service->create('ali', ['subject' => 'Yayindaki']);
    $oncekiSayi = count($dispatch->dispatched);
    $service->update($yayinda['id'], ['subject' => 'Yayindaki (duzeltildi)']);
    check(
        'yayindaki duyuruyu duzenlemek YENIDEN dagitmaz',
        count($dispatch->dispatched) === $oncekiSayi
    );

    // Taslak → yayın geçişi dağıtmalı.
    $taslak = $service->create('ali', ['subject' => 'Once taslak', 'draft' => true]);
    $oncekiSayi = count($dispatch->dispatched);
    $service->update($taslak['id'], ['draft' => false]);
    check(
        'taslaktan yayina gecis dagitimi tetikler',
        count($dispatch->dispatched) === $oncekiSayi + 1
    );

    // Hedef gruplar dağıtıma doğru geçmeli.
    [$service, , , , $dispatch] = makeService();
    $service->create('ali', ['subject' => 'Gruba', 'groups' => ['ik', 'muhasebe']]);
    check(
        'hedef gruplar dagitima aktarilir',
        $dispatch->dispatched[0]['gids'] === ['ik', 'muhasebe']
    );

    // Silme, bekleyen bildirimleri geri çekmeli.
    [$service, , , , $dispatch] = makeService();
    $silinecek = $service->create('ali', ['subject' => 'Silinecek']);
    $service->delete($silinecek['id']);
    check('silme bildirimleri geri ceker', $dispatch->withdrawn === [$silinecek['id']]);

    // Duyuru silinince yorumlari da gitmeli, yoksa yetim kalirlar.
    [$service, , , , , , $cleaner] = makeService();
    $yorumlu = $service->create('ali', ['subject' => 'Yorumlu']);
    $service->delete($yorumlu['id']);
    check('silme duyurunun yorumlarini da temizler', $cleaner->cleaned === [$yorumlu['id']]);

    // --- Zamanlama: arka plan işi (K5) ------------------------------------
    [$service, $mapper, , , $dispatch, $clock] = makeService();

    $zamanli = $service->create('ali', ['subject' => 'Ileri tarihli', 'publishAt' => NOW + 3600]);
    check('zamanlanmis duyuru olusunca dagitilmaz', $dispatch->dispatched === []);
    check('vakti gelmeden yayina alinmaz', $service->publishDue() === 0);
    check('vakti gelmeden okuyucu listesinde yok', $service->findVisibleFor(reader()) === []);

    // Saati yayın vaktinin bir saniye sonrasına al.
    $clock->now = NOW + 3601;

    check('vakti gelince yayina alinir', $service->publishDue() === 1);
    check(
        'yayina alinan duyurunun durumu published olur',
        $mapper->find($zamanli['id'])->getStatus() === Announcement::STATUS_PUBLISHED
    );
    check('yayina alinca dagitim tetiklenir', count($dispatch->dispatched) === 1);
    check('artik okuyucu listesinde gorunur', count($service->findVisibleFor(reader())) === 1);

    // 🔐 En kritik zamanlama testi: iş her 5 dakikada bir çalışıyor. İkinci
    // çalıştırmada aynı duyuru tekrar yayına alınıp tekrar dağıtılırsa
    // kullanıcılar aynı duyuru için sürekli bildirim alır.
    check('is tekrar calisinca ayni duyuru YENIDEN yayinlanmaz', $service->publishDue() === 0);
    check('is tekrar calisinca YENIDEN dagitilmaz', count($dispatch->dispatched) === 1);

    // --- Zamanlama: süresi dolanlar ---------------------------------------
    [$service, $mapper, , , $dispatch, $clock] = makeService();

    $sureli = $service->create('ali', ['subject' => 'Sureli', 'deleteAt' => NOW + 100]);
    check('suresi dolmamis duyuru arsivlenmez', $service->archiveExpired() === 0);
    check('suresi dolmamis duyuru gorunur', count($service->findVisibleFor(reader())) === 1);

    $clock->now = NOW + 101;

    // Cron bozulsa bile süresi dolan duyuru GÖRÜNMEMELİ — görünürlük kuralı
    // arka plan işine bağlı değil. Bu, işi çalıştırmadan önce doğrulanıyor.
    check(
        'suresi dolan duyuru, ARSIVLENMEDEN once bile gorunmez',
        $service->findVisibleFor(reader()) === []
    );

    check('suresi dolan duyuru arsivlenir', $service->archiveExpired() === 1);
    check(
        'arsivlenen duyurunun durumu archived olur',
        $mapper->find($sureli['id'])->getStatus() === Announcement::STATUS_ARCHIVED
    );
    check('arsivlenince bildirimler geri cekilir', $dispatch->withdrawn === [$sureli['id']]);
    check('is tekrar calisinca ayni duyuru YENIDEN arsivlenmez', $service->archiveExpired() === 0);

    // =====================================================================
    // GUVENLIK DENETIMI (23 Eyl 2026) — kapatilan aciklarin testleri
    // =====================================================================
    echo "\nGuvenlik duzeltmeleri\n";

    // --- Yonetim listesi baska yayincinin TASLAGINI sizdirmamali -----------
    // Denetimde bulunan gercek bir bilgi sizintisi: "Tumu (yonetim)" sekmesi
    // butun duyurulari donduruyordu, yani IK yayincisi Muhasebe'nin henuz
    // yayinlanmamis taslagini okuyabiliyordu.
    [$service] = makeService();
    $service->create('ali', ['subject' => 'Alinin taslagi', 'draft' => true]);
    $service->create('veli', ['subject' => 'Velinin taslagi', 'draft' => true]);

    $aliUser = new \OCA\NoticeBoard\Tests\FakeUser('ali');
    $aliList = $service->findManageable($aliUser);

    check('yayinci yonetim listesinde YALNIZ kendi duyurularini gorur', count($aliList) === 1);
    check('gordugu duyuru kendi duyurusu', $aliList[0]['subject'] === 'Alinin taslagi');
    check(
        'baska yayincinin taslagi listede YOK',
        !in_array('Velinin taslagi', array_column($aliList, 'subject'), true)
    );
    check('oturum yoksa yonetim listesi bos', $service->findManageable(null) === []);

    // --- Ek dosya tavani ---------------------------------------------------
    [$service] = makeService();
    $cokEk = [];
    for ($i = 1; $i <= 21; $i++) {
        $cokEk[] = ['fileId' => $i, 'fileName' => "dosya$i.pdf"];
    }
    checkThrows(
        '20den fazla ek reddedilir',
        fn () => $service->create('ali', ['subject' => 'Cok ekli', 'attachments' => $cokEk])
    );

    // 🔐 Reddedilen istek GERIYE KAYIT BIRAKMAMALI. Once insert edilip sonra
    // dogrulamada patliyordu; istek 400 donse bile yetim bir duyuru satiri
    // kaliyordu (canli guvenlik sinamasinda bulundu).
    [$service, $mapperEk] = makeService();
    try {
        $service->create('ali', ['subject' => 'Reddedilecek', 'attachments' => $cokEk]);
    } catch (\OCA\NoticeBoard\Exception\ValidationException) {
        // beklenen
    }
    check('reddedilen olusturma geriye YETIM KAYIT birakmaz', $mapperEk->rows === []);

    [$service] = makeService();

    $sinirda = $service->create('ali', ['subject' => 'Tam sinir', 'attachments' => array_slice($cokEk, 0, 20)]);
    check('tam 20 ek kabul edilir', count($sinirda['attachments']) === 20);

    $tekrarli = $service->create('ali', ['subject' => 'Tekrarli', 'attachments' => [
        ['fileId' => 7, 'fileName' => 'a.pdf'],
        ['fileId' => 7, 'fileName' => 'a.pdf'],
    ]]);
    check('ayni dosya iki kez eklenmez', count($tekrarli['attachments']) === 1);

    $gecersiz = $service->create('ali', ['subject' => 'Gecersiz id', 'attachments' => [
        ['fileId' => 0], ['fileId' => -5], ['fileId' => 9],
    ]]);
    check('gecersiz dosya kimlikleri atilir', count($gecersiz['attachments']) === 1);

    // --- Uzunluk tavanlari -------------------------------------------------
    [$service] = makeService();
    checkThrows(
        'cok uzun govde reddedilir',
        fn () => $service->create('ali', ['subject' => 'Uzun', 'message' => str_repeat('a', 100001)])
    );
    checkThrows(
        '64 karakterden uzun kategori reddedilir',
        fn () => $service->create('ali', ['subject' => 'Kategori', 'category' => str_repeat('k', 65)])
    );
    $kategoriSinir = $service->create('ali', ['subject' => 'Sinir', 'category' => str_repeat('k', 64)]);
    check('tam 64 karakterlik kategori kabul edilir', mb_strlen($kategoriSinir['category']) === 64);

    // --- Kitlesel atama (mass assignment) korumasi ------------------------
    // Istemci authorUid gonderse bile yok sayilmali; yoksa baskasinin adina
    // duyuru yayinlanabilirdi.
    [$service] = makeService();
    $sahte = $service->create('ali', ['subject' => 'Sahiplik', 'authorUid' => 'yonetici']);
    check('istemci authorUid gondererek baskasinin adina yazamaz', $sahte['authorUid'] === 'ali');

    $guncel = $service->update($sahte['id'], ['authorUid' => 'yonetici', 'subject' => 'Degisti']);
    check('guncellemede de authorUid degistirilemez', $guncel['authorUid'] === 'ali');

    $zaman = $service->create('ali', ['subject' => 'Zaman', 'createdAt' => 1]);
    check('istemci createdAt dayatamaz', $zaman['createdAt'] === NOW);

    finish('AnnouncementServiceTest');
}
