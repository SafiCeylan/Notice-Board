<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Attachment>
 */
class AttachmentMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'noticeboard_files', Attachment::class);
    }

    /**
     * @return Attachment[]
     */
    public function findByAnnouncement(int $announcementId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq(
                'announcement_id',
                $qb->createNamedParameter($announcementId, IQueryBuilder::PARAM_INT)
            ))
            ->orderBy('id', 'ASC');

        return $this->findEntities($qb);
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
