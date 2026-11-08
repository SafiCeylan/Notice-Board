<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Duyuruya iliştirilmiş Nextcloud dosyası (K4).
 * Dosyanın kopyası tutulmaz, yalnız file_id referansı tutulur.
 *
 * @method int getAnnouncementId()
 * @method void setAnnouncementId(int $announcementId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getFileName()
 * @method void setFileName(string $fileName)
 * @method string|null getOwnerUid()
 * @method void setOwnerUid(?string $ownerUid)
 */
class Attachment extends Entity implements JsonSerializable {

    protected $announcementId = 0;
    protected $fileId = 0;
    protected $fileName = '';
    /** Dosyayı ekleyen (dosya onun alanında). null = yazar. Okuyucuya gönderilmez. */
    protected $ownerUid = null;

    public function __construct() {
        $this->addType('announcementId', 'integer');
        $this->addType('fileId', 'integer');
        $this->addType('fileName', 'string');
        $this->addType('ownerUid', 'string');
    }

    /** Dosyanın hangi kullanıcının alanında arandığı: ekleyen, yoksa duyurunun yazarı. */
    public function getFileOwnerUid(string $authorUid): string {
        $owner = $this->getOwnerUid();

        return $owner !== null && $owner !== '' ? $owner : $authorUid;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'fileId' => $this->getFileId(),
            'fileName' => $this->getFileName(),
        ];
    }
}
