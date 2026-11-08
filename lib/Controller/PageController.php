<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Controller;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Service\PublisherService;
use OCA\NoticeBoard\Service\TalkGateway;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/**
 * Vue uygulamasını barındıran tek sayfayı döner.
 *
 * Asset adı ile webpack çıktısı birbirine bağlıdır:
 *   webpack.config.js → js/noticeboard-main.js
 *   buradaki addScript('noticeboard', 'noticeboard-main')
 * Ad değişirse sayfa sessizce boş açılır — ikisi birlikte değiştirilir.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class PageController extends Controller {

    public function __construct(
        IRequest $request,
        private IInitialState $initialState,
        private PublisherService $publisherService,
        private IUserSession $userSession,
        private TalkGateway $talkGateway,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Sayfa her oturum açmış kullanıcıya açıktır. Yayınlama yetkisi burada
     * sayfayı engellemez, yalnızca arayüze "oluştur" düğmesini gösterip
     * göstermeyeceğini söyler — gerçek kontrol her zaman API'de yapılır.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $user = $this->userSession->getUser();
        $canPublish = $this->publisherService->canPublish($user);

        $this->initialState->provideInitialState('canPublish', $canPublish);
        // Talk kurulu ve uyumlu değilse oluşturma ekranı Talk anahtarını hiç göstermez.
        $this->initialState->provideInitialState('talkAvailable', $canPublish && $this->talkGateway->isAvailable());
        $this->initialState->provideInitialState('currentUid', $user?->getUID() ?? '');
        // Yönetici başkasının duyurusunu da düzenleyebilir (PublisherService::canEdit);
        // arayüz bunu bilmezse "Düzenle" düğmesi yalnız kendi duyurularında çıkıyordu.
        $this->initialState->provideInitialState('isAdmin', $this->publisherService->isAdministrator($user));

        Util::addScript(Application::APP_ID, Application::APP_ID . '-main');

        return new TemplateResponse(Application::APP_ID, 'main');
    }
}
