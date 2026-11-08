<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Search;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Service\IconUrl;
use OCA\NoticeBoard\Service\AnnouncementService;
use OCA\NoticeBoard\Service\HtmlSanitizer;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Nextcloud'un üst arama kutusu (unified search) için duyuru sağlayıcısı.
 *
 * 🔐 Veri YALNIZCA `AnnouncementService::searchVisibleFor()`'dan gelir — liste
 * ve widget'larla aynı hedefleme (K3) ve zamanlama (K5) kuralları. Burada kendi
 * sorgusunu yazmak, kullanıcının göremeyeceği duyurunun başlığını arama
 * sonucunda sızdırmak demektir ve hiçbir hata alınmaz.
 *
 * İmza NC 32.0.15 sunucusundaki `lib/public/Search/IProvider.php`'den alındı
 * (Hata #15 dersi).
 */
class SearchProvider implements IProvider {

    /** Alt satırdaki alıntının uzunluğu (karakter). */
    private const EXCERPT_LENGTH = 100;

    /** Alıntıda eşleşmeden önce bırakılan bağlam. */
    private const EXCERPT_LEAD = 30;

    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
        private AnnouncementService $service,
        private HtmlSanitizer $sanitizer,
    ) {
    }

    public function getId(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return $this->l->t('Duyurular');
    }

    /**
     * Uygulamanın kendi sayfasındayken sonuçlar en üstte; başka yerde
     * dosyalar ve kişilerden sonra gelir.
     */
    public function getOrder(string $route, array $routeParameters): ?int {
        if (str_starts_with($route, Application::APP_ID . '.')) {
            return -1;
        }

        return 35;
    }

    public function search(IUser $user, ISearchQuery $query): SearchResult {
        $term = $query->getTerm();

        $page = $this->service->searchVisibleFor(
            $user,
            $term,
            $query->getLimit(),
            max(0, (int)$query->getCursor())
        );

        $entries = array_map(
            fn (array $announcement) => $this->toEntry($announcement, $term),
            $page['items']
        );

        return SearchResult::paginated($this->getName(), $entries, $page['cursor']);
    }

    /**
     * @param array<string, mixed> $announcement
     */
    private function toEntry(array $announcement, string $term): SearchResultEntry {
        $id = (int)$announcement['id'];

        $thumbnail = empty($announcement['coverFileId'])
            ? ''
            : $this->urlGenerator->linkToRoute('noticeboard.file.cover', ['id' => $id]);

        return new SearchResultEntry(
            $thumbnail,
            (string)$announcement['subject'],
            $this->sublineFor($announcement, $term),
            $this->urlGenerator->linkToRoute('noticeboard.page.index') . '#/announcement/' . $id,
            IconUrl::path($this->urlGenerator, 'app-dark.svg')
        );
    }

    /**
     * Kategori + gövdeden eşleşmenin çevresi. Eşleşme gövdede değilse (başlık
     * ya da kategoride) gövdenin başı gösterilir.
     *
     * @param array<string, mixed> $announcement
     */
    private function sublineFor(array $announcement, string $term): string {
        $parts = [];

        if (!empty($announcement['category'])) {
            $parts[] = (string)$announcement['category'];
        }

        $text = $this->sanitizer->toPlainText((string)$announcement['message']);
        if ($text !== '') {
            $parts[] = $this->excerpt($text, $term);
        }

        return implode(' · ', $parts);
    }

    private function excerpt(string $text, string $term): string {
        // Konum katlanmış metinde bulunur; katlama harf sayısını değiştirmediği
        // için (I/İ/ı → i hep tek harf) aynı konum özgün metinde de geçerlidir.
        $position = mb_strpos(
            AnnouncementService::foldForSearch($text),
            AnnouncementService::foldForSearch(trim($term))
        );

        $start = ($position === false) ? 0 : max(0, $position - self::EXCERPT_LEAD);

        // Kelime ortasından başlamasın ("…l sonu planlaması" değil "…sonu planlaması").
        if ($start > 0) {
            $space = mb_strpos(mb_substr($text, $start, $position - $start), ' ');
            if ($space !== false) {
                $start += $space + 1;
            }
        }

        $excerpt = mb_substr($text, $start, self::EXCERPT_LENGTH);

        return ($start > 0 ? '…' : '')
            . $excerpt
            . ($start + self::EXCERPT_LENGTH < mb_strlen($text) ? '…' : '');
    }
}
