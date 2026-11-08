<?php

declare(strict_types=1);

/**
 * Unified search (Faz 9) — üst arama kutusundaki duyuru sonuçları.
 *
 * Bu testin koruduğu en önemli şey: **arama, kullanıcının GÖREMEYECEĞİ duyurunun
 * başlığını sızdırmaz.** Arama sonucu da bir okuma yüzeyidir; liste ve widget'larla
 * aynı hedefleme (K3) ve zamanlama (K5) kurallarına uymak zorundadır.
 *
 * Çalıştırma:  php tests/SearchTest.php
 */

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

// ---------------------------------------------------------------------------
// Arama için gereken OCP ikizleri (imzalar NC 32.0.15 sunucusundan alındı)
// ---------------------------------------------------------------------------

namespace OCP {

    // IL10N ikizi bootstrap.php'de (L10nContractTest de kullanıyor).

    interface IURLGenerator {
        public function linkToRoute(string $routeName, array $arguments = []): string;
        public function imagePath(string $appName, string $file): string;
    }
}

namespace OCP\Search {

    interface IProvider {
        public function getId(): string;
        public function getName(): string;
        public function getOrder(string $route, array $routeParameters): ?int;
        public function search(\OCP\IUser $user, ISearchQuery $query): SearchResult;
    }

    interface ISearchQuery {
        public function getTerm(): string;
        public function getLimit(): int;
        public function getCursor();
    }

    final class SearchResult {
        private function __construct(
            public string $name,
            public bool $isPaginated,
            public array $entries,
            public $cursor = null,
        ) {
        }

        public static function paginated(string $name, array $entries, $cursor): self {
            return new self($name, true, $entries, $cursor);
        }
    }

    class SearchResultEntry {
        public function __construct(
            public string $thumbnailUrl,
            public string $title,
            public string $subline,
            public string $resourceUrl,
            public string $icon = '',
            public bool $rounded = false,
        ) {
        }
    }
}

namespace OCA\NoticeBoard\Service {

    /** Dağıtım bu testin konusu değil; hiçbir şey yapmayan ikiz. */
    class DispatchService {
        public function dispatch(\OCA\NoticeBoard\Db\Announcement $a, array $gids): void {
        }

        public function withdraw(int $id): void {
        }
    }

    class CommentCleaner {
        public function deleteAllForAnnouncement(int $id): void {
        }
    }
}

namespace OCA\NoticeBoard\Db {

    /**
     * Veritabanı ikizi.
     *
     * ⚠️ `searchVisible()` BİLEREK durum/zaman süzmesi YAPMAZ ve eşleşmeyi HAM
     * HTML üzerinde arar — yani gerçek sorgunun en kötü halini taklit eder.
     * Böylece servisin kendi kilitleri (görünürlük, hedefleme, düz metin
     * doğrulaması) sorguya güvenmeden sınanır.
     */
    class AnnouncementMapper {
        /** @var Announcement[] */
        public array $rows = [];
        private int $nextId = 1;
        /** @var array<int, array{offset: int, limit: int}> */
        public array $calls = [];

        public function insert(Announcement $a): Announcement {
            $a->setId($this->nextId++);
            $this->rows[$a->getId()] = $a;
            return $a;
        }

        public function update(Announcement $a): Announcement {
            $this->rows[$a->getId()] = $a;
            return $a;
        }

        public function find(int $id): Announcement {
            if (!isset($this->rows[$id])) {
                throw new \OCP\AppFramework\Db\DoesNotExistException("yok: $id");
            }
            return $this->rows[$id];
        }

        public function searchVisible(int $now, string $term, int $limit, int $offset): array {
            $this->calls[] = ['offset' => $offset, 'limit' => $limit];

            // general_ci gibi: büyük/küçük ve I/İ/ı farkı gözetmez.
            $fold = static fn (string $s) => mb_strtolower(strtr($s, ['İ' => 'i', 'I' => 'i', 'ı' => 'i']));
            $needle = $fold($term);

            $matches = array_filter($this->rows, static fn (Announcement $a) =>
                str_contains($fold((string)$a->getSubject()), $needle)
                || str_contains($fold((string)$a->getMessage()), $needle)
                || str_contains($fold((string)$a->getCategory()), $needle));

            // created_at DESC, id DESC
            usort($matches, static fn (Announcement $x, Announcement $y) =>
                [$y->getCreatedAt(), $y->getId()] <=> [$x->getCreatedAt(), $x->getId()]);

            return array_slice($matches, $offset, $limit);
        }
    }

    class AnnouncementGroupMapper {
        public array $gids = [];

        public function findGids(int $announcementId): array {
            return $this->gids[$announcementId] ?? [];
        }

        public function setGids(int $announcementId, array $gids): void {
            $this->gids[$announcementId] = array_values(array_unique($gids));
        }
    }

    class AttachmentMapper {
        public function insert(Attachment $a): Attachment {
            return $a;
        }

        public function findByAnnouncement(int $announcementId): array {
            return [];
        }
    }
}

namespace {

    require __DIR__ . '/FakeUserFiles.php';
    require __DIR__ . '/../lib/Service/InlineImages.php';
    require __DIR__ . '/../lib/Service/AnnouncementService.php';
    require __DIR__ . '/../lib/Service/IconUrl.php';
    require __DIR__ . '/../lib/Search/SearchProvider.php';

    use OCA\NoticeBoard\Search\SearchProvider;
    use OCA\NoticeBoard\Service\AnnouncementService;
    use OCA\NoticeBoard\Tests\FakeUser;

    const NOW = 1_700_000_000;

    class FrozenClock implements \OCP\AppFramework\Utility\ITimeFactory {
        public int $now = NOW;

        public function getTime(): int {
            return $this->now;
        }
    }

    class FakeL10N implements \OCP\IL10N {
        public function t(string $text, $parameters = []): string {
            return $text;
        }
    }

    class FakeUrlGenerator implements \OCP\IURLGenerator {
        public function linkToRoute(string $routeName, array $arguments = []): string {
            return match ($routeName) {
                'noticeboard.page.index' => '/apps/noticeboard/',
                'noticeboard.file.cover' => '/apps/noticeboard/cover/' . $arguments['id'],
                default => throw new \RuntimeException("bilinmeyen rota: $routeName"),
            };
        }

        public function imagePath(string $appName, string $file): string {
            return "/apps/$appName/img/$file";
        }
    }

    class FakeQuery implements \OCP\Search\ISearchQuery {
        public function __construct(private string $term, private int $limit = 5, private $cursor = null) {
        }

        public function getTerm(): string {
            return $this->term;
        }

        public function getLimit(): int {
            return $this->limit;
        }

        public function getCursor() {
            return $this->cursor;
        }
    }

    /**
     * İK grubunda "ayse", hiçbir grupta olmayan "okuyucu".
     *
     * @return array{0: AnnouncementService, 1: \OCA\NoticeBoard\Db\AnnouncementMapper, 2: FrozenClock}
     */
    function makeService(): array {
        $mapper = new \OCA\NoticeBoard\Db\AnnouncementMapper();
        $groupManager = new \OCA\NoticeBoard\Tests\FakeGroupManager([], ['ayse' => ['ik']]);
        $clock = new FrozenClock();
        $service = new AnnouncementService(
            $mapper,
            new \OCA\NoticeBoard\Db\AnnouncementGroupMapper(),
            new \OCA\NoticeBoard\Db\AttachmentMapper(),
            new \OCA\NoticeBoard\Service\VisibilityService($groupManager),
            new \OCA\NoticeBoard\Service\PublisherService(new \OCA\NoticeBoard\Tests\FakeAppConfig(), $groupManager),
            new \OCA\NoticeBoard\Service\HtmlSanitizer(),
            new \OCA\NoticeBoard\Service\DispatchService(),
            new \OCA\NoticeBoard\Service\CommentCleaner(),
            $clock,
            $userFiles = new \OCA\NoticeBoard\Tests\FakeUserFiles(),
            new \OCA\NoticeBoard\Tests\FakeDisplayNames(),
            new \OCA\NoticeBoard\Service\InlineImages($userFiles)
        );

        return [$service, $mapper, $clock];
    }

    function subjects(array $page): array {
        return array_map(static fn ($a) => $a['subject'], $page['items']);
    }

    $okuyucu = new FakeUser('okuyucu');
    $ayse = new FakeUser('ayse');

    echo "SearchTest\n";

    // --- Temel eşleşme ----------------------------------------------------
    [$service] = makeService();
    $service->create('admin', ['subject' => 'Yıllık izin talepleri', 'message' => '<p>Portal üzerinden iletin.</p>']);
    $service->create('admin', ['subject' => 'Bakım duyurusu', 'message' => '<p>Sunucu <strong>cumartesi</strong> kapalı.</p>']);
    $service->create('admin', ['subject' => 'Kahve', 'message' => '<p>Yeni makine.</p>', 'category' => 'Etkinlik']);

    check('baslikta arar', subjects($service->searchVisibleFor($okuyucu, 'izin', 10)) === ['Yıllık izin talepleri']);
    check('govdede arar', subjects($service->searchVisibleFor($okuyucu, 'cumartesi', 10)) === ['Bakım duyurusu']);
    check('kategoride arar', subjects($service->searchVisibleFor($okuyucu, 'etkinlik', 10)) === ['Kahve']);
    check('eslesmeyen terim bos doner', $service->searchVisibleFor($okuyucu, 'tatil', 10)['items'] === []);

    // --- Türkçe büyük/küçük harf ------------------------------------------
    [$service] = makeService();
    $service->create('admin', ['subject' => 'İK duyurusu', 'message' => '<p>İzin formu yenilendi.</p>']);

    check('"ik" araması "İK"yı bulur', count($service->searchVisibleFor($okuyucu, 'ik', 10)['items']) === 1);
    check('"IZIN" araması "İzin"i bulur', count($service->searchVisibleFor($okuyucu, 'IZIN', 10)['items']) === 1);
    check('foldForSearch I/İ/ı farkini siler', AnnouncementService::foldForSearch('İIı') === 'iii');

    // --- HTML etiketi eşleşmesi (sahte pozitif) ---------------------------
    [$service] = makeService();
    $service->create('admin', ['subject' => 'Kalın yazı', 'message' => '<p><strong>Önemli</strong> not.</p>']);
    $service->create('admin', ['subject' => 'Renkli', 'message' => '<p><span style="color: #c9302c">kırmızı</span></p>']);

    check('etiket adi ("strong") eslesme sayilmaz', $service->searchVisibleFor($okuyucu, 'strong', 10)['items'] === []);
    check('oznitelik ("color") eslesme sayilmaz', $service->searchVisibleFor($okuyucu, 'color', 10)['items'] === []);
    check('etiketin icindeki metin bulunur', count($service->searchVisibleFor($okuyucu, 'önemli', 10)['items']) === 1);

    // --- 🔐 Hedefleme (K3) ------------------------------------------------
    [$service] = makeService();
    $service->create('admin', ['subject' => 'Maaş zammı', 'message' => '<p>gizli</p>', 'groups' => ['ik']]);
    $service->create('admin', ['subject' => 'Maaş günü', 'message' => '<p>herkese</p>']);

    check(
        'hedeflenmemis kullanici grup duyurusunu aramada GORMEZ',
        subjects($service->searchVisibleFor($okuyucu, 'maaş', 10)) === ['Maaş günü']
    );
    check(
        'grup uyesi kendi grubunun duyurusunu bulur',
        subjects($service->searchVisibleFor($ayse, 'maaş', 10)) === ['Maaş günü', 'Maaş zammı']
    );

    // --- 🔐 Durum ve zaman (K5) -------------------------------------------
    [$service, , $clock] = makeService();
    $service->create('admin', ['subject' => 'Taslak rapor', 'draft' => true]);
    $service->create('admin', ['subject' => 'Gelecek rapor', 'publishAt' => NOW + 3600]);
    $service->create('admin', ['subject' => 'Süreli rapor', 'deleteAt' => NOW + 60]);

    check('taslak aramada cikmaz', !in_array('Taslak rapor', subjects($service->searchVisibleFor($okuyucu, 'rapor', 10)), true));
    check('zamanlanmis aramada cikmaz', !in_array('Gelecek rapor', subjects($service->searchVisibleFor($okuyucu, 'rapor', 10)), true));
    check('suresi dolmamis duyuru cikar', in_array('Süreli rapor', subjects($service->searchVisibleFor($okuyucu, 'rapor', 10)), true));

    $clock->now = NOW + 60; // tam silme anı
    check('silme aninda aramadan duser (sinir: esitlik)', $service->searchVisibleFor($okuyucu, 'rapor', 10)['items'] === []);

    // Taslak yazarına bile aramada çıkmaz — arama okuyucu yüzeyidir.
    [$service] = makeService();
    $service->create('ayse', ['subject' => 'Ayşenin taslağı', 'draft' => true]);
    check('yazar kendi taslagini aramada gormez', $service->searchVisibleFor($ayse, 'taslağı', 10)['items'] === []);

    // --- Girdi sınırları --------------------------------------------------
    [$service, $mapper] = makeService();
    $service->create('admin', ['subject' => 'a harfi']);

    check('tek harf aranmaz', $service->searchVisibleFor($okuyucu, 'a', 10)['items'] === []);
    check('bosluk kirpildiktan sonra tek harf aranmaz', $service->searchVisibleFor($okuyucu, '  a  ', 10)['items'] === []);
    check('kisa terimde veritabanina gidilmez', $mapper->calls === []);
    check('iki harf aranir', count($service->searchVisibleFor($okuyucu, 'ha', 10)['items']) === 1);
    check('limit 0 bos doner', $service->searchVisibleFor($okuyucu, 'ha', 0)['items'] === []);
    check('negatif imlec sifira cekilir', count($service->searchVisibleFor($okuyucu, 'ha', 10, -5)['items']) === 1);

    // --- Sayfalama --------------------------------------------------------
    // 6 eşleşme; aralarına hedeflenmemiş 3 duyuru serpiştirilmiş.
    [$service] = makeService();
    for ($i = 1; $i <= 9; $i++) {
        $gizli = $i % 3 === 0;
        $service->create('admin', [
            'subject' => "Toplanti $i",
            'groups' => $gizli ? ['ik'] : [],
        ]);
    }

    $seen = [];
    $cursor = 0;
    $pages = 0;
    do {
        $page = $service->searchVisibleFor($okuyucu, 'toplanti', 2, $cursor);
        $seen = array_merge($seen, subjects($page));
        $cursor = $page['cursor'];
        $pages++;
    } while ($page['items'] !== [] && $pages < 10);

    check('sayfalar tum gorulebilir sonuclari verir (6)', count($seen) === 6);
    check('sayfalar arasinda tekrar yok', count(array_unique($seen)) === count($seen));
    check('gizli duyuru hicbir sayfada yok', array_filter($seen, static fn ($s) => in_array($s, ['Toplanti 3', 'Toplanti 6', 'Toplanti 9'], true)) === []);
    check('en yeni once gelir', $seen[0] === 'Toplanti 8');
    check('dongu sonsuza girmeden bitti', $pages < 10);

    // Sonuç sırası değil VERİTABANI ofseti: ilk sayfa iki sonuç verdiyse imleç
    // atlanan gizli satırı da saymalı ("Toplanti 9" gizli, 8 ve 7 görünür → 3).
    $first = $service->searchVisibleFor($okuyucu, 'toplanti', 2, 0);
    check('imlec atlanan satirlari da sayar', $first['cursor'] === 3);

    // --- Parti tavanı -----------------------------------------------------
    // 200 gizli aday + en sonda 1 görünür: tek istek tabloyu sonuna kadar taramaz.
    [$service, $mapper] = makeService();
    $service->create('admin', ['subject' => 'Eski ortak']);
    for ($i = 0; $i < 200; $i++) {
        $service->create('admin', ['subject' => "Gizli ortak $i", 'groups' => ['ik']]);
    }
    $capped = $service->searchVisibleFor($okuyucu, 'ortak', 5);
    check('bir istekte en fazla 5 parti taranir', count($mapper->calls) === 5);
    check('tavana takilinca imlec kaldigi yeri verir', $capped['cursor'] === 100);
    $rest = $service->searchVisibleFor($okuyucu, 'ortak', 5, $capped['cursor']);
    $rest = $service->searchVisibleFor($okuyucu, 'ortak', 5, $rest['cursor']);
    check('sonraki sayfalarda eski duyuru bulunur', subjects($rest) === ['Eski ortak']);

    // --- Sağlayıcı ---------------------------------------------------------
    [$service] = makeService();
    $service->create('admin', [
        'subject' => 'Bakım',
        'category' => 'Teknik',
        'message' => '<p>' . str_repeat('Giriş metni. ', 10) . 'Sunucu cumartesi kapanacak. ' . str_repeat('Son. ', 30) . '</p>',
        'coverFileId' => 42,
    ]);
    $service->create('admin', ['subject' => 'Kısa bakım notu', 'message' => '<p>Kısa.</p>']);

    $provider = new SearchProvider(new FakeL10N(), new FakeUrlGenerator(), $service, new \OCA\NoticeBoard\Service\HtmlSanitizer());

    check('saglayici kimligi app id', $provider->getId() === 'noticeboard');
    check('uygulama icinde en ustte', $provider->getOrder('noticeboard.page.index', []) === -1);
    check('baska sayfada sonlarda', $provider->getOrder('files.view.index', []) === 35);

    $result = $provider->search($okuyucu, new FakeQuery('bakım'));
    check('sonuc sayfali', $result->isPaginated === true);
    check('iki sonuc', count($result->entries) === 2);

    $byTitle = [];
    foreach ($result->entries as $e) {
        $byTitle[$e->title] = $e;
    }
    $bakim = $byTitle['Bakım'] ?? null;
    $kisa = $byTitle['Kısa bakım notu'] ?? null;

    check('baglanti duyurunun kalici adresi', $bakim !== null && $bakim->resourceUrl === '/apps/noticeboard/#/announcement/1');
    check('kapakli duyuruda kucuk resim kapak ucu', $bakim !== null && $bakim->thumbnailUrl === '/apps/noticeboard/cover/1');
    check('kapaksiz duyuruda kucuk resim yok', $kisa !== null && $kisa->thumbnailUrl === '');
    // İçerik özetli adres (önbellek kırıcı, Hata #45): dosya + ?v=<md5'in ilk 8'i>.
    $beklenenIkon = '/apps/noticeboard/img/app-dark.svg?v=' . substr(md5_file(__DIR__ . '/../img/app-dark.svg'), 0, 8);
    check('ikon uygulama ikonu, icerik ozetli', $kisa !== null && $kisa->icon === $beklenenIkon);
    check('alt satir kategoriyle baslar', $bakim !== null && str_starts_with($bakim->subline, 'Teknik · '));
    check('alt satirda HTML yok', $bakim !== null && !str_contains($bakim->subline, '<'));

    // Eşleşme gövdenin 137. karakterinde: alıntı başı ve sonu kesilmiş olmalı.
    $govdede = $provider->search($okuyucu, new FakeQuery('cumartesi'));
    $sub = $govdede->entries[0]->subline ?? '';
    check('alinti eslesmenin cevresini gosterir', str_contains($sub, 'cumartesi'));
    check('bastan kesilen alinti ucla baslar', str_starts_with($sub, 'Teknik · …'));
    // Ham kesim 107. karakterde "iş metni." ortasına düşer; kelime başına kaymalı.
    check('alinti kelime ortasindan baslamaz', str_starts_with($sub, 'Teknik · …metni. Giriş'));
    check('sondan kesilen alinti ucla biter', str_ends_with($sub, '…'));
    check('eslesme yoksa (baslikta) govdenin basi gosterilir', str_starts_with($bakim?->subline ?? '', 'Teknik · Giriş metni.'));

    // İmleç sağlayıcıdan servise geçer, ikinci sayfa ilkini tekrarlamaz.
    $p1 = $provider->search($okuyucu, new FakeQuery('bakım', 1));
    $p2 = $provider->search($okuyucu, new FakeQuery('bakım', 1, $p1->cursor));
    check('saglayici imleci tasiyor', $p1->entries[0]->title !== ($p2->entries[0]->title ?? null) && count($p2->entries) === 1);

    // --- 🔐 Kaynak sözleşmesi ---------------------------------------------
    $kok = __DIR__ . '/..';
    $prov = (string)file_get_contents("$kok/lib/Search/SearchProvider.php");
    $app = (string)file_get_contents("$kok/lib/AppInfo/Application.php");
    $map = (string)file_get_contents("$kok/lib/Db/AnnouncementMapper.php");
    $search = substr($map, (int)strpos($map, 'function searchVisible('), 2000);

    check('Application arama saglayicisini kaydediyor', str_contains($app, 'registerSearchProvider(SearchProvider::class)'));
    check('saglayici veriyi searchVisibleFor ile aliyor', str_contains($prov, '$this->service->searchVisibleFor('));
    check('saglayici suzmesiz liste cagirmiyor', !preg_match('/->(findAll|findManageable|find|findEntity)\(/', $prov));
    check('saglayici mapper kullanmiyor', !str_contains($prov, 'Mapper'));
    check('arama sorgusu LIKE jokerlerini kaciriyor', str_contains($search, 'escapeLikeParameter($term)'));
    check('arama sorgusu buyuk/kucuk harf duyarsiz (iLike)', substr_count($search, '->iLike(') === 3);
    check('arama sorgusu yalniz yayindakileri aliyor', str_contains($search, 'Announcement::STATUS_PUBLISHED'));

    finish('SearchTest');
}
