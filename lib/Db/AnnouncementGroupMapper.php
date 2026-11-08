<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<AnnouncementGroup>
 */
class AnnouncementGroupMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'noticeboard_groups', AnnouncementGroup::class);
    }

    /**
     * Bir duyurunun hedef grup kimlikleri. Boş dizi = herkese açık (K3).
     *
     * @return string[]
     */
    public function findGids(int $announcementId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('gid')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'announcement_id',
                $qb->createNamedParameter($announcementId, IQueryBuilder::PARAM_INT)
            ));

        $result = $qb->executeQuery();
        $gids = array_column($result->fetchAll(), 'gid');
        $result->closeCursor();

        return $gids;
    }

    /**
     * Bir duyurunun hedeflerini toptan değiştirir: önce sil, sonra yaz.
     * Boş dizi verilirse duyuru herkese açık hale gelir.
     *
     * @param string[] $gids
     */
    public function setGids(int $announcementId, array $gids): void {
        $this->deleteByAnnouncement($announcementId);

        foreach (array_unique($gids) as $gid) {
            $entity = new AnnouncementGroup();
            $entity->setAnnouncementId($announcementId);
            $entity->setGid((string)$gid);
            $this->insert($entity);
        }
    }

    public function deleteByAnnouncement(int $announcementId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq(
                'announcement_id',
                $qb->createNamedParameter($announcementId, IQueryBuilder::PARAM_INT)
            ));
        $qb->executeStatement();
    }
}
