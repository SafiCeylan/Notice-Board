<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Duyuru → grup hedefi (K3).
 * Bir duyuru için hiç satır yoksa duyuru herkese açıktır.
 *
 * @method int getAnnouncementId()
 * @method void setAnnouncementId(int $announcementId)
 * @method string getGid()
 * @method void setGid(string $gid)
 */
class AnnouncementGroup extends Entity {

    protected $announcementId = 0;
    protected $gid = '';

    public function __construct() {
        $this->addType('announcementId', 'integer');
        $this->addType('gid', 'string');
    }
}
