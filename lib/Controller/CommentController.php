<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Controller;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Exception\AccessDeniedException;
use OCA\NoticeBoard\Exception\CommentsClosedException;
use OCA\NoticeBoard\Exception\ValidationException;
use OCA\NoticeBoard\Service\CommentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Duyuru yorumları API'si (K6 · `opt_comments`).
 *
 * Yetki kararı tamamen CommentService'te; burada yalnızca istisnalar HTTP
 * durumuna çevrilir.
 */
class CommentController extends OCSController {

    public function __construct(
        IRequest $request,
        private CommentService $service,
        private IUserSession $userSession,
        private IL10N $l,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * 🔐 Okuma ucunda "erişemezsin" ile "yok" aynı yanıtı verir (404); ayrılsaydı
     * duyuru kimlikleri taranarak hangi duyuruların var olduğu öğrenilebilirdi.
     */
    #[NoAdminRequired]
    public function index(int $id, int $limit = 100, int $offset = 0): DataResponse {
        try {
            return new DataResponse($this->service->findForAnnouncement(
                $id,
                $this->userSession->getUser(),
                max(1, min($limit, 200)),
                max(0, $offset)
            ));
        } catch (AccessDeniedException | DoesNotExistException) {
            return $this->notFound();
        }
    }

    /**
     * Hız sınırı, yorum akınına karşı: bir kullanıcı saatte en fazla 60 yorum
     * yazabilir. Nextcloud sınırı aşınca isteği kendisi reddeder.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 3600)]
    public function create(int $id, string $message): DataResponse {
        try {
            return new DataResponse(
                $this->service->create($id, $this->userSession->getUser(), $message),
                Http::STATUS_CREATED
            );
        } catch (CommentsClosedException $e) {
            // Duyuruyu zaten görebiliyor; bu 403 bilgi sızdırmaz ve sebebi söyler.
            return new DataResponse(['message' => $e->translate($this->l)], Http::STATUS_FORBIDDEN);
        } catch (AccessDeniedException | DoesNotExistException) {
            return $this->notFound();
        } catch (ValidationException $e) {
            return new DataResponse(['message' => $e->translate($this->l)], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function destroy(int $id, string $commentId): DataResponse {
        try {
            $this->service->delete($id, $commentId, $this->userSession->getUser());
        } catch (AccessDeniedException | DoesNotExistException) {
            return $this->notFound();
        }

        return new DataResponse([]);
    }

    private function notFound(): DataResponse {
        return new DataResponse(['message' => $this->l->t('Duyuru bulunamadı.')], Http::STATUS_NOT_FOUND);
    }
}
