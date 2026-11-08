<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\Db\Announcement;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * K3 + K5 — "Bu kullanıcı bu duyuruyu görebilir mi?" sorusunun TEK cevap noktası.
 *
 * İki kural birlikte uygulanır:
 *   1. ZAMAN  (K5) → Announcement::isVisibleAt(): yayında mı, vakti geldi mi, süresi dolmuş mu
 *   2. HEDEF  (K3) → duyurunun hedef grubu yoksa herkese açık; varsa kullanıcı o gruplardan
 *                    birinde olmalı
 *
 * ⚠️ Liste, detay, widget ve arama bu servisi kullanır. Kural kopyalanırsa bir gün
 * ayrışır ve hedeflenmemiş bir kullanıcı duyuruyu bir yerden görür.
 */
class VisibilityService {

    public function __construct(
        private IGroupManager $groupManager,
    ) {
    }

    /**
     * @param string[] $targetGids duyurunun hedef grupları; boş = herkese açık
     */
    public function canSee(?IUser $user, Announcement $announcement, array $targetGids, int $now): bool {
        if ($user === null) {
            return false;
        }

        if (!$announcement->isVisibleAt($now)) {
            return false;
        }

        return $this->isTargeted($user, $targetGids);
    }

    /**
     * Hedefleme kuralı tek başına — zaman kuralından bağımsız sorulabilsin diye ayrı.
     *
     * Yazarın kendi duyurusunu görmesi ayrıca ele alınmaz: yazar hedef grubun dışındaysa
     * duyuruyu okuyucu listesinde göremez ama yönetim listesinde (kendi taslakları)
     * görür. Bu bilinçli — okuyucu listesi "bana gelen duyurular" demektir.
     *
     * @param string[] $targetGids
     */
    public function isTargeted(?IUser $user, array $targetGids): bool {
        if ($user === null) {
            return false;
        }

        // Hedef grup verilmemişse duyuru herkese açıktır (K3).
        if ($targetGids === []) {
            return true;
        }

        return array_intersect($this->groupManager->getUserGroupIds($user), $targetGids) !== [];
    }
}
