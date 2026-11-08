<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\Db\Announcement;
use OCA\NoticeBoard\Db\AnnouncementGroupMapper;
use OCA\NoticeBoard\Db\AnnouncementMapper;
use OCA\NoticeBoard\Db\Attachment;
use OCA\NoticeBoard\Db\AttachmentMapper;
use OCA\NoticeBoard\Exception\AccessDeniedException;
use OCA\NoticeBoard\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Duyuru iş mantığı.
 *
 * ⚠️ "Kim YAYINLAYABİLİR" (K1) sorusu buraya sorulmaz — o PublisherService'te ve
 * controller'da bu servisten önce kontrol edilir. Ama "kim GÖREBİLİR" (K3+K5)
 * kuralı okuma uçlarında burada uygulanır ki hiçbir uç onu atlamasın.
 */
class AnnouncementService {

    private const MAX_SUBJECT_LENGTH = 255;
    private const MAX_CATEGORY_LENGTH = 64;

    /**
     * Ek sayısı tavanı. Sınırsız bırakılsaydı tek bir istek binlerce satır
     * yazabilir, detay sayfası da binlerce dosya sorgusu çalıştırırdı.
     */
    private const MAX_ATTACHMENTS = 20;

    /**
     * Gövde uzunluğu tavanı. `message` kolonu TEXT olduğu için veritabanı
     * kendiliğinden sınırlamaz; sanitize maliyeti de girdiyle birlikte büyür.
     */
    private const MAX_MESSAGE_LENGTH = 100000;

    /**
     * Tek harfli arama neredeyse her duyuruyu eşleştirir ve `%a%` sorgusu
     * indeks kullanamaz. İki harf "İK" gibi kısaltmalara yetiyor.
     */
    private const MIN_SEARCH_LENGTH = 2;

    /** Bir arama isteğinde taranacak en fazla aday partisi. */
    private const MAX_SEARCH_BATCHES = 5;

    /**
     * Güncellemede `null` göndererek BOŞALTILABİLEN alanlar: kapağı kaldırmak,
     * zamanlamayı silmek, kategoriyi boşaltmak "değer null" demektir.
     * Diğer alanlarda null "gönderilmedi" anlamına gelir ve dokunulmaz.
     */
    public const CLEARABLE_FIELDS = ['category', 'coverFileId', 'coverX', 'coverY', 'publishAt', 'deleteAt'];

    public function __construct(
        private AnnouncementMapper $mapper,
        private AnnouncementGroupMapper $groupMapper,
        private AttachmentMapper $attachmentMapper,
        private VisibilityService $visibilityService,
        private PublisherService $publisherService,
        private HtmlSanitizer $sanitizer,
        private DispatchService $dispatchService,
        private CommentCleaner $commentCleaner,
        private ITimeFactory $timeFactory,
        private UserFileService $userFiles,
        private IUserManager $userManager,
        private InlineImages $inlineImages,
    ) {
    }

    /** İstek boyunca aynı yazarın adı tekrar tekrar sorulmasın (liste 50 duyuru). */
    private array $displayNames = [];

    /**
     * Okuyucu listesi: yayında, zamanı uygun (K5) VE kullanıcıya hedeflenmiş (K3)
     * duyurular.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findVisibleFor(?IUser $user, int $limit = 50, int $offset = 0): array {
        $now = $this->timeFactory->getTime();

        $result = [];
        foreach ($this->mapper->findVisible($now, $limit, $offset) as $announcement) {
            $gids = $this->groupMapper->findGids((int)$announcement->getId());
            if (!$this->visibilityService->isTargeted($user, $gids)) {
                continue;
            }
            $result[] = $this->forViewer($this->expand($announcement, $gids), $announcement, $user);
        }

        return $result;
    }

    /**
     * Unified search (üst arama kutusu) — okuyucu gözüyle arama.
     *
     * Yalnız KULLANICININ GÖREBİLECEĞİ duyurular döner: `findVisibleFor()` ile
     * aynı K3+K5 kuralları. Taslaklar yazarlarına bile aramada çıkmaz; arama
     * okuyucu yüzeyidir, yönetim listesi değil.
     *
     * Sayfalama imleci VERİTABANI ofsetidir, sonuç sırası değil. Hedeflemeye
     * takılan satırlar atlandığı için "kaçıncı sonuç" ile "kaçıncı satır"
     * ayrışır; sonuç sırasını imleç yapmak sonraki sayfada kayıt atlatırdı.
     *
     * @return array{items: array<int, array<string, mixed>>, cursor: int}
     */
    public function searchVisibleFor(IUser $user, string $term, int $limit, int $cursor = 0): array {
        $term = trim($term);
        $cursor = max(0, $cursor);
        if (mb_strlen($term) < self::MIN_SEARCH_LENGTH || $limit <= 0) {
            return ['items' => [], 'cursor' => $cursor];
        }

        $now = $this->timeFactory->getTime();
        $needle = self::foldForSearch($term);
        $batch = max($limit * 2, 20);
        $items = [];

        // Hedeflenmemiş aday çoksa bir sayfa doldurmak için birkaç parti gerekir;
        // tavan, tek bir aramanın tüm tabloyu taramasını önler.
        for ($round = 0; $round < self::MAX_SEARCH_BATCHES; $round++) {
            $rows = $this->mapper->searchVisible($now, $term, $batch, $cursor);

            foreach ($rows as $announcement) {
                $cursor++;

                // Sorgu zaten süzüyor; bu satır sorgu bozulursa diye ikinci kilit.
                if (!$announcement->isVisibleAt($now)) {
                    continue;
                }

                $gids = $this->groupMapper->findGids((int)$announcement->getId());
                if (!$this->visibilityService->isTargeted($user, $gids)) {
                    continue;
                }

                if (!$this->matchesPlainText($announcement, $needle)) {
                    continue;
                }

                $items[] = $this->expand($announcement, $gids);
                if (count($items) >= $limit) {
                    return ['items' => $items, 'cursor' => $cursor];
                }
            }

            if (count($rows) < $batch) {
                break; // aday kalmadı
            }
        }

        return ['items' => $items, 'cursor' => $cursor];
    }

    /**
     * Eşleşme, kullanıcının GÖRDÜĞÜ metin üzerinden doğrulanır: gövde HTML
     * olarak saklandığı için veritabanı "strong" ya da "color" aramasında
     * etiket adlarını eşleştirir.
     */
    private function matchesPlainText(Announcement $announcement, string $needle): bool {
        $haystack = implode("\n", [
            (string)$announcement->getSubject(),
            (string)$announcement->getCategory(),
            $this->sanitizer->toPlainText((string)$announcement->getMessage()),
        ]);

        return str_contains(self::foldForSearch($haystack), $needle);
    }

    /**
     * Türkçe duyarlı gevşek küçültme. `mb_strtolower('İ')` "i̇" (i + birleşik
     * nokta) verir ve "izin" araması "İzin"i bulamaz; I/İ/ı hepsi "i"ye indirilir.
     */
    public static function foldForSearch(string $text): string {
        return mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'i', 'ı' => 'i']));
    }

    /**
     * Yönetim listesi — çağıranın rolüne göre daraltılır.
     *
     * 🔐 Yönetici her duyuruyu yönetir; yayıncı YALNIZCA KENDİ duyurularını.
     * Aksi halde bir departmanın yayıncısı, başka departmanın henüz
     * yayınlanmamış taslağını okuyabilirdi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findManageable(?IUser $user, int $limit = 50, int $offset = 0): array {
        if ($user === null) {
            return [];
        }

        $rows = $this->publisherService->isAdministrator($user)
            ? $this->mapper->findAll($limit, $offset)
            : $this->mapper->findByAuthor($user->getUID(), $limit, $offset);

        return array_map(fn (Announcement $a) => $this->expand($a), $rows);
    }

    /**
     * Süzmesiz tam liste. YALNIZCA testler ve yönetici yolu içindir;
     * controller'dan doğrudan çağrılmaz — findManageable() kullan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(int $limit = 50, int $offset = 0): array {
        return array_map(
            fn (Announcement $a) => $this->expand($a),
            $this->mapper->findAll($limit, $offset)
        );
    }

    /**
     * Tek duyuru — okuyucu gözüyle. Göremeyeceği bir duyuruyu isterse reddedilir.
     *
     * @return array<string, mixed>
     * @throws DoesNotExistException
     * @throws AccessDeniedException
     */
    public function findFor(int $id, ?IUser $user): array {
        $announcement = $this->findEntityForReader($id, $user);

        return $this->forViewer($this->expand($announcement), $announcement, $user);
    }

    /**
     * Okuyucuya giden çıktıdan hedef grupları çıkarır (7 Eki 2026, kullanıcı isteği).
     *
     * Grup adları ("destek-ekibi_209583") kurumun iç yapısını gösterir ve okuyucu
     * için anlamsızdır: duyuruyu zaten görüyorsa hedeftedir. Yalnız duyuruyu
     * DÜZENLEYEBİLEN görür — düzenleme ekranı gruplarla açılır; okuyucu
     * çıktısıyla açılsaydı "herkes" sanıp kaydederken hedeflemeyi silerdi.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function forViewer(array $payload, Announcement $announcement, ?IUser $user): array {
        if (!$this->publisherService->canEdit($user, $announcement)) {
            unset($payload['groups']);
        }

        return $payload;
    }

    /**
     * Bir duyuruyu okumaya yetkisi var mı — TEK karar noktası.
     *
     * İki yol açık:
     *   · okuyucu olarak görebiliyorsa (K3 + K5)
     *   · ya da o duyuruyu düzenleme yetkisi varsa (yayıncı kendi taslağını,
     *     yönetici her şeyi görebilmeli)
     *
     * Duyuru gövdesini servis eden her uç (detay, kapak görseli, ek dosya)
     * buradan geçer; yeni bir uç eklenirken kural kopyalanmaz.
     *
     * @throws DoesNotExistException
     * @throws AccessDeniedException
     */
    public function findEntityForReader(int $id, ?IUser $user): Announcement {
        $announcement = $this->mapper->find($id);
        $gids = $this->groupMapper->findGids($id);

        if ($this->visibilityService->canSee($user, $announcement, $gids, $this->timeFactory->getTime())) {
            return $announcement;
        }

        if ($this->publisherService->canEdit($user, $announcement)) {
            return $announcement;
        }

        throw new AccessDeniedException('Bu duyuruya erişiminiz yok.');
    }

    /**
     * Tek duyuru — yönetim gözüyle (görünürlük süzmesi YOK).
     * Çağıran, PublisherService ile yetkiyi kontrol etmiş olmalıdır.
     *
     * @return array<string, mixed>
     * @throws DoesNotExistException
     */
    public function find(int $id): array {
        return $this->expand($this->mapper->find($id));
    }

    /**
     * @throws DoesNotExistException
     */
    public function findEntity(int $id): Announcement {
        return $this->mapper->find($id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function create(string $authorUid, array $data): array {
        $now = $this->timeFactory->getTime();

        $announcement = new Announcement();
        $announcement->setAuthorUid($authorUid);
        $announcement->setCreatedAt($now);
        // NC yalnız set edilen alanları INSERT eder. opt_talk kolonunun varsayılanı
        // eski satırlar için KAPALI; yeni duyuruda açık olsun diye açıkça yazılır.
        $announcement->setOptTalk(true);

        $this->applyData($announcement, $data, $now, $authorUid);
        // ⚠️ İlk yazmadan ÖNCE: reddedilen ek yetim duyuru satırı bırakmasın (Hata #21).
        $attachments = $this->planAttachments(null, $data, $authorUid, $authorUid) ?? [];

        $announcement = $this->mapper->insert($announcement);

        $gids = $this->readGroups($data);
        $this->groupMapper->setGids($announcement->getId(), $gids);
        $this->writeAttachments($announcement->getId(), $attachments);

        // Taslak ya da ileri tarihli duyuru için dağıtım YAPILMAZ; zamanı gelince
        // arka plan işi (Faz 6) yayına alıp o an tetikler.
        if ($announcement->getStatus() === Announcement::STATUS_PUBLISHED) {
            $this->dispatchService->dispatch($announcement, $gids);
        }

        return $this->expand($announcement, $gids);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws DoesNotExistException
     * @throws ValidationException
     */
    public function update(int $id, array $data, ?string $actorUid = null): array {
        $announcement = $this->mapper->find($id);
        $now = $this->timeFactory->getTime();

        // Dağıtımın tek tetikleyicisi YAYINA GEÇİŞTİR. Bu yüzden eski durum,
        // değişiklik uygulanmadan önce saklanır.
        $wasPublished = $announcement->getStatus() === Announcement::STATUS_PUBLISHED;

        $actorUid ??= $announcement->getAuthorUid();
        $this->applyData($announcement, $data, $now, $actorUid);
        // İlk yazmadan ÖNCE doğrulanır; mevcut eklerin sahibi silinmeden okunur.
        $attachments = $this->planAttachments($id, $data, $actorUid, $announcement->getAuthorUid());

        $announcement = $this->mapper->update($announcement);

        if (array_key_exists('groups', $data)) {
            $this->groupMapper->setGids($id, $this->readGroups($data));
        }
        if ($attachments !== null) {
            $this->attachmentMapper->deleteByAnnouncement($id);
            $this->writeAttachments($id, $attachments);
        }

        $gids = $this->groupMapper->findGids($id);

        // Yayındaki bir duyuruda yazım hatası düzeltmek herkese yeniden bildirim
        // göndermemeli; yalnızca taslak/zamanlanmış → yayında geçişi dağıtılır.
        if (!$wasPublished && $announcement->getStatus() === Announcement::STATUS_PUBLISHED) {
            $this->dispatchService->dispatch($announcement, $gids);
        }

        return $this->expand($announcement, $gids);
    }

    /**
     * Güncelleme isteğinden servise gidecek alanları seçer.
     *
     * ⚠️ Önceden bütün null değerler atılıyordu. Sonuç: düzenlemede kapağı
     * kaldırmak, yayın/kalkma zamanını silmek ya da kategoriyi boşaltmak
     * sessizce yok sayılıyor, kullanıcı "kaydedildi" görüp eski hali buluyordu.
     * Şimdi boşaltılabilir alanlarda null, istemci o alanı GERÇEKTEN
     * gönderdiyse korunur.
     *
     * @param array<string, mixed> $values controller parametreleri (gönderilmeyen = null)
     * @param array<string, mixed> $sent istemcinin gönderdiği ham parametreler
     * @return array<string, mixed>
     */
    public static function pickSentFields(array $values, array $sent): array {
        $picked = [];
        foreach ($values as $key => $value) {
            if ($value !== null) {
                $picked[$key] = $value;
            } elseif (in_array($key, self::CLEARABLE_FIELDS, true) && array_key_exists($key, $sent)) {
                $picked[$key] = null;
            }
        }

        return $picked;
    }

    // ------------------------------------------------------------------
    // Zamanlama (K5) — arka plan işinden çağrılır
    // ------------------------------------------------------------------

    /**
     * Yayın vakti gelmiş duyuruları yayına alır ve dağıtımlarını tetikler.
     *
     * ⚠️ Bu adım ZORUNLUDUR: `findVisible()` yalnız `published` durumundakileri
     * döndürdüğü için, iş çalışmazsa zamanlanmış duyuru hiç görünmez. Bilinçli
     * bir tercih — duyurunun görünmesiyle bildiriminin gitmesi aynı anda olmalı,
     * yoksa kullanıcı listede gördüğü duyuru için bildirim almaz.
     *
     * @return int yayına alınan duyuru sayısı
     */
    public function publishDue(): int {
        $now = $this->timeFactory->getTime();
        $count = 0;

        foreach ($this->mapper->findDueForPublishing($now) as $announcement) {
            $announcement->setStatus(Announcement::STATUS_PUBLISHED);
            $this->mapper->update($announcement);

            $gids = $this->groupMapper->findGids((int)$announcement->getId());
            $this->dispatchService->dispatch($announcement, $gids);

            $count++;
        }

        return $count;
    }

    /**
     * Süresi dolmuş duyuruları arşivler.
     *
     * Görünürlük açısından bu adım zorunlu DEĞİL: `Announcement::isVisibleAt()`
     * zaten `delete_at` geçmiş duyuruyu gizliyor. Yani cron bozulsa bile süresi
     * dolan duyuru kullanıcıya görünmez. İş, durumu kalıcı hale getirir ve
     * bekleyen bildirimleri toplar.
     *
     * @return int arşivlenen duyuru sayısı
     */
    public function archiveExpired(): int {
        $now = $this->timeFactory->getTime();
        $count = 0;

        foreach ($this->mapper->findExpired($now) as $announcement) {
            $announcement->setStatus(Announcement::STATUS_ARCHIVED);
            $this->mapper->update($announcement);

            $this->dispatchService->withdraw((int)$announcement->getId());

            $count++;
        }

        return $count;
    }

    /**
     * @throws DoesNotExistException
     */
    public function delete(int $id): void {
        $announcement = $this->mapper->find($id);

        // Önce bağlı satırlar, sonra duyurunun kendisi — yetim satır kalmasın.
        $this->groupMapper->deleteByAnnouncement($id);
        $this->attachmentMapper->deleteByAnnouncement($id);
        $this->mapper->delete($announcement);

        // Silinen duyurunun bildirimi çan ikonunda kalırsa tıklayan kişi
        // "bulunamadı" ekranına düşer. Yorumlar da yetim kalmamalı.
        $this->dispatchService->withdraw($id);
        $this->commentCleaner->deleteAllForAnnouncement($id);
    }

    // ------------------------------------------------------------------
    // İç yardımcılar
    // ------------------------------------------------------------------

    /**
     * Girdiyi doğrular ve varlığa yazar. Durum (status) buradan çıkar:
     * taslak istendiyse draft, ileri tarihliyse scheduled, değilse published.
     *
     * @param array<string, mixed> $data
     * @throws ValidationException
     */
    private function applyData(Announcement $announcement, array $data, int $now, string $actorUid): void {
        if (array_key_exists('subject', $data)) {
            $subject = trim((string)$data['subject']);
            if ($subject === '') {
                throw new ValidationException('Başlık boş olamaz.');
            }
            if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
                throw new ValidationException('Başlık en fazla %s karakter olabilir.', [self::MAX_SUBJECT_LENGTH]);
            }
            $announcement->setSubject($subject);
        }

        if (array_key_exists('message', $data)) {
            $message = (string)$data['message'];
            if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
                throw new ValidationException('Duyuru metni çok uzun.');
            }

            // ⚠️ Temizleme YAZMA anında yapılır. Okuma anında yapılsaydı yeni bir
            // okuma yolu eklendiğinde (widget, arama, e-posta) unutulabilirdi.
            // Gömülü görsellerin sahibi temizlemeden SONRA, kaydedenin alanında
            // doğrulanarak yazılır (InlineImages).
            $announcement->setMessage($this->inlineImages->bind(
                $this->sanitizer->sanitize($message),
                $announcement->getMessage(),
                $actorUid,
                $announcement->getAuthorUid(),
            ));
        }

        if (array_key_exists('category', $data)) {
            $category = $data['category'] === null ? null : trim((string)$data['category']);
            if ($category !== null && mb_strlen($category) > self::MAX_CATEGORY_LENGTH) {
                // Kolon varchar(64); kesmek yerine reddetmek, kullanıcının
                // yazdığından farklı bir şeyin sessizce kaydedilmesini önler.
                throw new ValidationException('Kategori en fazla %s karakter olabilir.', [self::MAX_CATEGORY_LENGTH]);
            }
            $announcement->setCategory($category === '' ? null : $category);
        }

        if (array_key_exists('priority', $data)) {
            $priority = (int)$data['priority'];
            if ($priority < Announcement::PRIORITY_NORMAL || $priority > Announcement::PRIORITY_URGENT) {
                throw new ValidationException('Geçersiz öncelik değeri.');
            }
            $announcement->setPriority($priority);
        }

        if (array_key_exists('pinned', $data)) {
            $announcement->setPinned((bool)$data['pinned']);
        }

        if (array_key_exists('coverFileId', $data)) {
            $coverFileId = $data['coverFileId'] === null ? null : (int)$data['coverFileId'];

            // Kapak DEĞİŞTİYSE dosya, değiştirenin alanında bir görsel olmalı ve
            // sahibi o kişi olarak kaydedilir. Aynı kapak yeniden gönderildiyse
            // (formun geri kalanı düzenlendi) sahibine dokunulmaz — yoksa yönetici
            // başlık düzelttiğinde yazarın kapağı yönetici alanında aranırdı.
            if ($coverFileId !== null && $coverFileId !== $announcement->getCoverFileId()) {
                if (!$this->userFiles->isUserImage($actorUid, $coverFileId)) {
                    throw new ValidationException('Kapak görseli dosyalarınızda bulunamadı ya da bir görsel değil.');
                }
                $announcement->setCoverOwner($actorUid === $announcement->getAuthorUid() ? null : $actorUid);
            }
            if ($coverFileId === null) {
                $announcement->setCoverOwner(null);
            }
            $announcement->setCoverFileId($coverFileId);
        }

        foreach (['coverX' => 'setCoverX', 'coverY' => 'setCoverY'] as $key => $setter) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $position = $data[$key] === null ? null : (int)$data[$key];
            if ($position !== null && ($position < 0 || $position > 100)) {
                throw new ValidationException('Kapak konumu 0 ile 100 arasında olmalı.');
            }
            $announcement->$setter($position);
        }

        // Kapak kaldırıldıysa konumu da sıfırlanır; sonra eklenen yeni görsel
        // eskisinin konumunu miras almasın.
        if ($announcement->getCoverFileId() === null) {
            $announcement->setCoverX(null);
            $announcement->setCoverY(null);
        }

        if (array_key_exists('publishAt', $data)) {
            $announcement->setPublishAt($data['publishAt'] === null ? null : (int)$data['publishAt']);
        }
        if (array_key_exists('deleteAt', $data)) {
            $announcement->setDeleteAt($data['deleteAt'] === null ? null : (int)$data['deleteAt']);
        }

        $publishAt = $announcement->getPublishAt();
        $deleteAt = $announcement->getDeleteAt();
        if ($publishAt !== null && $deleteAt !== null && $deleteAt <= $publishAt) {
            throw new ValidationException('Silme zamanı, yayın zamanından sonra olmalı.');
        }

        // Ek doğrulaması create()/update() içinde planAttachments() ile, yine
        // İLK YAZMADAN ÖNCE yapılır (Hata #21) — eklerin sahibi için duyuru
        // kimliği ve yazar gerekiyor.

        if (isset($data['options']) && is_array($data['options'])) {
            $options = $data['options'];
            if (array_key_exists('activity', $options)) {
                $announcement->setOptActivity((bool)$options['activity']);
            }
            if (array_key_exists('notification', $options)) {
                $announcement->setOptNotification((bool)$options['notification']);
            }
            if (array_key_exists('email', $options)) {
                $announcement->setOptEmail((bool)$options['email']);
            }
            if (array_key_exists('comments', $options)) {
                $announcement->setOptComments((bool)$options['comments']);
            }
            if (array_key_exists('talk', $options)) {
                $announcement->setOptTalk((bool)$options['talk']);
            }
        }

        // Durum, istemcinin gönderdiği ham string'den DEĞİL kurallardan türetilir;
        // böylece API'den "published" yazıp zamanlamayı atlamak mümkün olmaz.
        if (!empty($data['draft'])) {
            $announcement->setStatus(Announcement::STATUS_DRAFT);
        } elseif ($announcement->getStatus() === Announcement::STATUS_ARCHIVED) {
            // Arşivlenmiş duyuru düzenlenince kendiliğinden yayına dönmemeli.
            $announcement->setStatus(Announcement::STATUS_ARCHIVED);
        } else {
            $announcement->setStatus(
                ($publishAt !== null && $publishAt > $now)
                    ? Announcement::STATUS_SCHEDULED
                    : Announcement::STATUS_PUBLISHED
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function readGroups(array $data): array {
        if (!isset($data['groups']) || !is_array($data['groups'])) {
            return [];
        }

        $gids = [];
        foreach ($data['groups'] as $gid) {
            $gid = trim((string)$gid);
            if ($gid !== '') {
                $gids[] = $gid;
            }
        }

        // Tekilleştirme BURADA yapılır çünkü bu listeyi hem veritabanı hem de
        // dağıtım kullanıyor; yalnız kayıt tarafında tekilleştirilseydi aynı grup
        // iki kez hedeflenen bir duyuruda üyeler iki bildirim alırdı.
        return array_values(array_unique($gids));
    }

    /**
     * Yazılacak ek satırlarını hazırlar ve DOĞRULAR — hiçbir şey yazmadan önce
     * çağrılır (Hata #21: önce insert edip sonra patlamak yetim satır bırakıyordu).
     *
     * 🔐 Daha önce duyuruda olan ek (aynı dosya kimliği) olduğu gibi korunur:
     * sahibi ve adı değişmez, yeniden doğrulanmaz — yönetici ek eklediyse yazar
     * duyuruyu yine düzenleyebilsin. YENİ ek, ekleyenin alanında bulunmalıdır;
     * adı istemciden değil dosyanın kendisinden alınır.
     *
     * @param array<string, mixed> $data
     * @return list<array{fileId: int, fileName: string, ownerUid: ?string}>|null
     *         null = istemci ek listesi göndermedi (güncellemede dokunulmaz)
     * @throws ValidationException
     */
    private function planAttachments(?int $announcementId, array $data, string $actorUid, string $authorUid): ?array {
        if (!isset($data['attachments']) || !is_array($data['attachments'])) {
            return null;
        }

        if (count($data['attachments']) > self::MAX_ATTACHMENTS) {
            throw new ValidationException('En fazla %s dosya eklenebilir.', [self::MAX_ATTACHMENTS]);
        }

        $existing = [];
        if ($announcementId !== null) {
            foreach ($this->attachmentMapper->findByAnnouncement($announcementId) as $attachment) {
                $existing[(int)$attachment->getFileId()] = $attachment;
            }
        }

        // Aynı dosya iki kez eklenirse detayda iki kez görünür ve iki satır yazılır.
        $seen = [];
        $rows = [];

        foreach ($data['attachments'] as $item) {
            if (!is_array($item) || !isset($item['fileId'])) {
                continue;
            }

            $fileId = (int)$item['fileId'];
            if ($fileId <= 0 || isset($seen[$fileId])) {
                continue;
            }
            $seen[$fileId] = true;

            if (isset($existing[$fileId])) {
                $rows[] = [
                    'fileId' => $fileId,
                    'fileName' => $existing[$fileId]->getFileName(),
                    'ownerUid' => $existing[$fileId]->getOwnerUid(),
                ];
                continue;
            }

            $name = $this->userFiles->userFileName($actorUid, $fileId);
            if ($name === null) {
                throw new ValidationException('Eklenen dosya dosyalarınızda bulunamadı.');
            }
            $rows[] = [
                'fileId' => $fileId,
                'fileName' => mb_substr($name, 0, 255),
                'ownerUid' => $actorUid === $authorUid ? null : $actorUid,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{fileId: int, fileName: string, ownerUid: ?string}> $rows planAttachments() çıktısı
     */
    private function writeAttachments(int $announcementId, array $rows): void {
        foreach ($rows as $row) {
            $attachment = new Attachment();
            $attachment->setAnnouncementId($announcementId);
            $attachment->setFileId($row['fileId']);
            $attachment->setFileName($row['fileName']);
            $attachment->setOwnerUid($row['ownerUid']);
            $this->attachmentMapper->insert($attachment);
        }
    }

    /**
     * Varlığı, hedef grupları ve ekleriyle birlikte API çıktısına çevirir.
     *
     * @param string[]|null $gids zaten okunmuşsa tekrar sorgulamamak için verilir
     * @return array<string, mixed>
     */
    private function expand(Announcement $announcement, ?array $gids = null): array {
        $id = (int)$announcement->getId();
        $payload = $announcement->jsonSerialize();
        // Yazar kimliğiyle değil adıyla gösterilir ("Saficeylan" değil "Safi Ceylan");
        // hesap silinmişse kimliğe düşer.
        $authorUid = $announcement->getAuthorUid();
        $payload['authorName'] = $this->displayNames[$authorUid]
            ??= ($this->userManager->getDisplayName($authorUid) ?? $authorUid);
        $payload['groups'] = $gids ?? $this->groupMapper->findGids($id);
        $payload['attachments'] = array_map(
            fn ($a) => $a->jsonSerialize(),
            $this->attachmentMapper->findByAnnouncement($id)
        );

        return $payload;
    }
}
