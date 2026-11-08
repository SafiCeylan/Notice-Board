<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Announcement>
 */
class AnnouncementMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'noticeboard_posts', Announcement::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id): Announcement {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Yönetim listesi: durum farkı gözetmeden hepsi (taslaklar dahil).
     * Yayıncı ekranında kullanılır — okuyucu listesi için findVisible().
     *
     * @return Announcement[]
     */
    public function findAll(int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('pinned', 'DESC')
            ->addOrderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * Bir yayıncının kendi duyuruları (taslak ve zamanlanmışlar dahil).
     *
     * 🔐 Yönetim listesi bunu kullanır. `findAll()` yalnızca yöneticilere açıktır;
     * sıradan bir yayıncıya tüm duyuruları göstermek, başka departmanın HENÜZ
     * YAYINLANMAMIŞ taslağını okutmak demektir.
     *
     * @return Announcement[]
     */
    public function findByAuthor(string $authorUid, int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('author_uid', $qb->createNamedParameter($authorUid)))
            ->orderBy('pinned', 'DESC')
            ->addOrderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * Okuyucu listesi: yalnızca yayında ve zamanı uygun olanlar (K5).
     *
     * ⚠️ Hedefleme (K3) BURADA UYGULANMAZ; grup süzmesi VisibilityService'te
     * yapılır ki kural tek yerde dursun. Bu metodu doğrudan controller'a bağlama.
     *
     * @return Announcement[]
     */
    public function findVisible(int $now, int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(Announcement::STATUS_PUBLISHED)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('publish_at'),
                $qb->expr()->lte('publish_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('delete_at'),
                $qb->expr()->gt('delete_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ))
            ->orderBy('pinned', 'DESC')
            ->addOrderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * Arama adayları: yayında, zamanı uygun (K5) ve başlık/gövde/kategoride
     * terimi içerenler. Sıra en yeniden eskiye, `id` eşitlik bozucu — sayfalama
     * imleci ofset olduğu için sıra kararlı olmalı.
     *
     * ⚠️ Bu ADAY listesidir, sonuç değil:
     *   · Hedefleme (K3) burada uygulanmaz (bkz. findVisible).
     *   · Gövde HTML olarak saklanır; "strong" araması her kalın yazılı duyuruyu
     *     getirir. Asıl eşleşme servis katmanında düz metin üzerinden doğrulanır.
     *
     * `iLike` kullanılır çünkü tablo `utf8mb4_bin`: düz `like` büyük/küçük harfe
     * duyarlı olurdu. Terimdeki `%` ve `_` kaçırılır, yoksa "%" araması her
     * satırı eşleştirirdi.
     *
     * @return Announcement[]
     */
    public function searchVisible(int $now, string $term, int $limit, int $offset): array {
        $qb = $this->db->getQueryBuilder();
        $pattern = $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($term) . '%');

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(Announcement::STATUS_PUBLISHED)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('publish_at'),
                $qb->expr()->lte('publish_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('delete_at'),
                $qb->expr()->gt('delete_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->iLike('subject', $pattern),
                $qb->expr()->iLike('message', $pattern),
                $qb->expr()->iLike('category', $pattern)
            ))
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * Zamanı gelmiş, hâlâ `scheduled` duran duyurular (Faz 6 arka plan işi).
     *
     * @return Announcement[]
     */
    public function findDueForPublishing(int $now): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(Announcement::STATUS_SCHEDULED)))
            ->andWhere($qb->expr()->isNotNull('publish_at'))
            ->andWhere($qb->expr()->lte('publish_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * Süresi dolmuş ama hâlâ yayında görünen duyurular (Faz 6 arka plan işi).
     *
     * @return Announcement[]
     */
    public function findExpired(int $now): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(Announcement::STATUS_PUBLISHED)))
            ->andWhere($qb->expr()->isNotNull('delete_at'))
            ->andWhere($qb->expr()->lte('delete_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }
}
