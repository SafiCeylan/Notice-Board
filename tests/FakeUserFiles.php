<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Tests;

require_once __DIR__ . '/../lib/Service/UserFileService.php';

use OCA\NoticeBoard\Service\UserFileService;

/**
 * Kullanıcı dosyaları ikizi. `$owned` verilmezse her dosya her kullanıcınındır
 * (dosyayla ilgisi olmayan testler için); verilirse yalnız listedekiler.
 * Görsel sayılanlar `$images` (verilmezse hepsi görsel).
 */
class FakeUserFiles extends UserFileService {
    /**
     * @param array<string, int[]>|null $owned uid => dosya kimlikleri
     * @param array<int, string> $names dosya kimliği => gerçek ad
     * @param int[]|null $images görsel olan dosya kimlikleri
     */
    public function __construct(
        private ?array $owned = null,
        private array $names = [],
        private ?array $images = null,
    ) {
        // Üst sınıfın IRootFolder bağımlılığı bilerek kurulmaz.
    }

    private function owns(string $uid, int $fileId): bool {
        return $this->owned === null || in_array($fileId, $this->owned[$uid] ?? [], true);
    }

    public function isUserImage(string $uid, int $fileId): bool {
        return $this->owns($uid, $fileId) && ($this->images === null || in_array($fileId, $this->images, true));
    }

    public function userFileName(string $uid, int $fileId): ?string {
        return $this->owns($uid, $fileId) ? ($this->names[$fileId] ?? "dosya-$fileId") : null;
    }
}
