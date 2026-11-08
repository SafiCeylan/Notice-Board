<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Controller;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Exception\ValidationException;
use OCA\NoticeBoard\Service\PublisherService;
use OCA\NoticeBoard\Service\SpotlightSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Yayıncı atama ayarları (K1).
 *
 * 🔐 Hiçbir metotta `#[NoAdminRequired]` YOK → Nextcloud bu uçları yalnızca
 * yöneticilere açar. Yayıncı atama yetkisi yayıncılara devredilemez; aksi halde
 * bir yayıncı kendini çoğaltarak yetkiyi yayabilirdi.
 */
class AdminController extends OCSController {

    private const SEARCH_LIMIT = 25;

    // Grup arama burada DEĞİL, PickerController'da — oluşturma ekranı da aynı
    // ucu kullanıyor ve o uç yayıncılara açık olmak zorunda.
    public function __construct(
        IRequest $request,
        private PublisherService $publisherService,
        private IUserManager $userManager,
        private SpotlightSettings $spotlightSettings,
        private IL10N $l,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function getSettings(): DataResponse {
        $uids = $this->publisherService->getPublisherUids();

        return new DataResponse([
            'publisherUids' => $uids,
            // Seçicide kayıtlı hesaplar da aramadakiyle aynı etiketle görünsün.
            'publisherUsers' => array_map(fn (string $uid) => [
                'id' => $uid,
                'label' => $this->accountLabel($uid, $this->userManager->get($uid)),
            ], $uids),
            'publisherGids' => $this->publisherService->getPublisherGids(),
        ]);
    }

    /**
     * @param string[] $publisherUids
     * @param string[] $publisherGids
     */
    public function saveSettings(array $publisherUids = [], array $publisherGids = []): DataResponse {
        $this->publisherService->setPublishers($publisherUids, $publisherGids);

        return $this->getSettings();
    }

    /** Vitrin widget'ının kurum geneli ayarları. */
    public function getSpotlight(): DataResponse {
        return new DataResponse($this->spotlightSettings->get());
    }

    public function saveSpotlight(int $interval = SpotlightSettings::DEFAULT_INTERVAL, string $filter = SpotlightSettings::FILTER_ALL): DataResponse {
        try {
            $this->spotlightSettings->set($interval, $filter);
        } catch (ValidationException $e) {
            return new DataResponse(['message' => $e->translate($this->l)], Http::STATUS_BAD_REQUEST);
        }

        return $this->getSpotlight();
    }

    /**
     * Yayıncı seçicisi için hesap arama.
     *
     * `searchDisplayName()` kimlik, görünen ad VE e-postada arar. Eski `search()`
     * yalnız kimlikte arıyordu ve NC 27'den beri kullanımdan kalkmış durumda
     * (NC 35'te hâlâ var ama ileride silinecek).
     */
    public function searchUsers(string $search = ''): DataResponse {
        $result = [];
        foreach ($this->userManager->searchDisplayName($search, self::SEARCH_LIMIT) as $user) {
            $result[] = [
                'id' => $user->getUID(),
                'label' => $this->accountLabel($user->getUID(), $user),
            ];
        }

        return new DataResponse($result);
    }

    /**
     * Seçicide görünen ad: e-posta → görünen ad → kimlik.
     *
     * NEDEN e-posta: AD/LDAP'a bağlı sunucularda kimlik (objectGUID vb.) ve
     * çoğu zaman görünen ad da rakamlardan oluşuyor; yönetici kimi seçtiğini
     * ayırt edemiyordu (20 Eki 2026, kullanıcı bildirdi). E-posta her ortamda okunur.
     * Silinmiş hesap listede kalabilir; o zaman kimlik gösterilir.
     */
    private function accountLabel(string $uid, ?IUser $user): string {
        if ($user === null) {
            return $uid;
        }

        $email = trim((string)$user->getEMailAddress());
        if ($email !== '') {
            return $email;
        }

        $displayName = trim($user->getDisplayName());

        return $displayName !== '' ? $displayName : $uid;
    }
}
