<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Controller;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Service\PublisherService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Oluşturma ekranındaki hedef grup seçicisini besler.
 *
 * 🔐 Yalnızca yayıncılara açık. Herkese açık olsaydı, sıradan bir kullanıcı
 * sunucudaki tüm grup adlarını (departman yapısı) listeleyebilirdi.
 */
class PickerController extends OCSController {

    private const SEARCH_LIMIT = 25;

    public function __construct(
        IRequest $request,
        private PublisherService $publisherService,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private IL10N $l,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function groups(string $search = ''): DataResponse {
        if (!$this->publisherService->canPublish($this->userSession->getUser())) {
            return new DataResponse(['message' => $this->l->t('Bu işlem için yetkiniz yok.')], Http::STATUS_FORBIDDEN);
        }

        $result = [];
        foreach ($this->groupManager->search($search, self::SEARCH_LIMIT) as $group) {
            $result[] = [
                'id' => $group->getGID(),
                'label' => $group->getDisplayName(),
            ];
        }

        return new DataResponse($result);
    }
}
