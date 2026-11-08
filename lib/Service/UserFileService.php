<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;

/**
 * Duyuruya bağlanan dosyaların (kapak + ekler) dosya tarafı:
 * "bu dosya bu kullanıcının alanında mı?" ve "dosyayı aç".
 *
 * NEDEN: kapak ve ekler onları SEÇEN kişinin dosyalarında durur. Eskiden bu
 * kişinin hep yazar olduğu varsayılıyordu. İki sonucu vardı (7 Eki 2026):
 *   1. Yönetici başkasının duyurusuna kendi dosyasından kapak/ek koyunca dosya
 *      yazarın alanında aranıyor, okuyuculara 404 dönüyordu.
 *   2. 🔴 Dosya kimliği kayıtta HİÇ doğrulanmıyordu: başkasının duyurusunu
 *      düzenleyebilen biri (yönetici) yazarın ÖZEL bir dosyasının kimliğini
 *      ek olarak yazabilir, dosya duyuruyu gören herkese servis edilirdi.
 * Artık her yeni kapak/ek, ekleyenin alanında doğrulanır ve sahibi kaydedilir
 * (`cover_owner`, `owner_uid`; boş = yazar).
 */
class UserFileService {

    /** Kapak olarak servis edilebilecek türler; başka tür keyfi dosya indirmeye dönüşürdü. */
    public const IMAGE_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
    ];

    public function __construct(
        private IRootFolder $rootFolder,
    ) {
    }

    /** Dosya kullanıcının alanında bulunan bir görsel mi? (kapak) */
    public function isUserImage(string $uid, int $fileId): bool {
        $file = $this->open($uid, $fileId);

        return $file !== null && in_array($file->getMimeType(), self::IMAGE_TYPES, true);
    }

    /**
     * Dosya kullanıcının alanındaysa GERÇEK adı, değilse null. (ek)
     * Ad istemciden alınmaz: "maas_bordrosu.pdf" adıyla başka bir dosya
     * gösterilemesin.
     */
    public function userFileName(string $uid, int $fileId): ?string {
        return $this->open($uid, $fileId)?->getName();
    }

    /**
     * Dosyayı kullanıcının alanında arar. Bulunamazsa (silinmiş, taşınmış,
     * paylaşımı kaldırılmış, klasör) null döner.
     */
    public function open(string $uid, int $fileId): ?File {
        try {
            $nodes = $this->rootFolder->getUserFolder($uid)->getById($fileId);
        } catch (\Throwable) {
            return null;
        }

        foreach ($nodes as $node) {
            if ($node instanceof File) {
                return $node;
            }
        }

        return null;
    }
}
