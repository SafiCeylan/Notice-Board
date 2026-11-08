<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Db\Announcement;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * K1 — "Kim duyuru yayınlayabilir?" sorusunun TEK cevap noktası.
 *
 * Kural:
 *   · Nextcloud yöneticisi → her zaman yayınlayabilir
 *   · Yönetici tarafından yayıncı atanmış hesap → yayınlayabilir
 *   · Yayıncı atanmış bir grubun üyesi → yayınlayabilir
 *   · Diğer herkes → yayınlayamaz
 *
 * ⚠️ Controller'da `if ($isAdmin)` gibi dağınık kontrol YAZILMAZ. Bir uçta unutulursa
 * güvenlik açığı olur; bu yüzden soru hep buraya sorulur.
 */
class PublisherService {

    public const CONFIG_UIDS = 'publisher_uids';
    public const CONFIG_GIDS = 'publisher_gids';

    public function __construct(
        private IAppConfig $appConfig,
        private IGroupManager $groupManager,
    ) {
    }

    /**
     * Nextcloud yöneticisi mi? Yönetim listesinin kapsamı buna göre daralır.
     */
    public function isAdministrator(?IUser $user): bool {
        return $user !== null && $this->groupManager->isAdmin($user->getUID());
    }

    public function canPublish(?IUser $user): bool {
        if ($user === null) {
            return false;
        }

        if ($this->isAdministrator($user)) {
            return true;
        }

        if (in_array($user->getUID(), $this->getPublisherUids(), true)) {
            return true;
        }

        $publisherGids = $this->getPublisherGids();
        if ($publisherGids === []) {
            return false;
        }

        return array_intersect($this->groupManager->getUserGroupIds($user), $publisherGids) !== [];
    }

    /**
     * Var olan bir duyuruyu değiştirme/silme yetkisi.
     *
     * Yönetici her duyuruya dokunabilir; yayıncı yalnızca KENDİ yazdığına.
     * Aksi halde bir departman yayıncısı başka departmanın duyurusunu silebilirdi.
     */
    public function canEdit(?IUser $user, Announcement $announcement): bool {
        if ($user === null) {
            return false;
        }

        if ($this->isAdministrator($user)) {
            return true;
        }

        return $this->canPublish($user) && $announcement->getAuthorUid() === $user->getUID();
    }

    /**
     * @return string[]
     */
    public function getPublisherUids(): array {
        return $this->appConfig->getValueArray(Application::APP_ID, self::CONFIG_UIDS);
    }

    /**
     * @return string[]
     */
    public function getPublisherGids(): array {
        return $this->appConfig->getValueArray(Application::APP_ID, self::CONFIG_GIDS);
    }

    /**
     * @param string[] $uids
     * @param string[] $gids
     */
    public function setPublishers(array $uids, array $gids): void {
        $this->appConfig->setValueArray(Application::APP_ID, self::CONFIG_UIDS, $this->clean($uids));
        $this->appConfig->setValueArray(Application::APP_ID, self::CONFIG_GIDS, $this->clean($gids));
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private function clean(array $values): array {
        $cleaned = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $cleaned[] = $value;
            }
        }

        return array_values(array_unique($cleaned));
    }
}
