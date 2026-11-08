<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * "Bu duyuru kimlere gidecek?" — bildirim, etkinlik ve e-posta bu tek kaynaktan
 * beslenir.
 *
 * ⚠️ Hedefleme kuralı (K3) burada da VisibilityService ile aynı olmalı:
 * hedef grup listesi boşsa duyuru herkese gider, doluysa yalnız o grupların
 * üyelerine. Kural iki yerde ayrışırsa kullanıcı listede görmediği bir duyuru
 * için bildirim alır (ya da tersi).
 */
class RecipientService {

    public function __construct(
        private IGroupManager $groupManager,
        private IUserManager $userManager,
    ) {
    }

    /**
     * Duyuruyu alacak kullanıcı kimlikleri.
     *
     * @param string[] $targetGids boş = herkes
     * @return string[] tekrarsız uid listesi
     */
    public function resolveUids(array $targetGids, string $excludeUid = ''): array {
        $uids = $targetGids === []
            ? $this->allUserIds()
            : $this->groupMemberIds($targetGids);

        if ($excludeUid !== '') {
            $uids = array_values(array_filter($uids, static fn (string $uid) => $uid !== $excludeUid));
        }

        return $uids;
    }

    /**
     * @param string[] $gids
     * @return string[]
     */
    private function groupMemberIds(array $gids): array {
        $uids = [];

        foreach ($gids as $gid) {
            $group = $this->groupManager->get($gid);
            if ($group === null) {
                // Grup silinmiş olabilir; duyuru kaydı grubu hâlâ gösteriyor olsa da
                // gönderim buna takılıp tamamen durmamalı.
                continue;
            }

            foreach ($group->getUsers() as $user) {
                $uids[$user->getUID()] = true;
            }
        }

        return array_keys($uids);
    }

    /**
     * @return string[]
     */
    private function allUserIds(): array {
        $uids = [];

        // callForSeenUsers, hiç giriş yapmamış hesapları atlar — bildirim ya da
        // e-posta göndermenin anlamlı olduğu kitle zaten bu.
        $this->userManager->callForSeenUsers(static function (IUser $user) use (&$uids): void {
            $uids[] = $user->getUID();
        });

        return $uids;
    }
}
