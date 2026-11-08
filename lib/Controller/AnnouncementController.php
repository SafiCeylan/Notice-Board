<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Controller;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Exception\AccessDeniedException;
use OCA\NoticeBoard\Exception\ValidationException;
use OCA\NoticeBoard\Service\AnnouncementService;
use OCA\NoticeBoard\Service\PublisherService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Duyuru REST API'si.
 *
 * Taban URL: /ocs/v2.php/apps/noticeboard/api/v1/announcements
 *
 * 🔐 YETKİ MODELİ (Faz 2):
 * Tüm uçlar `#[NoAdminRequired]` — yani oturum açmış herkese AÇIK GÖRÜNÜR, ama
 * gerçek karar servis katmanında verilir:
 *   · okuma  → AnnouncementService, VisibilityService'e sorar (K3 + K5)
 *   · yazma  → PublisherService::canPublish() / canEdit() (K1)
 * Böylece yönetici olmayan ama yayıncı atanmış kullanıcılar da çalışabilir.
 */
class AnnouncementController extends OCSController {

    public function __construct(
        IRequest $request,
        private AnnouncementService $service,
        private PublisherService $publisherService,
        private IUserSession $userSession,
        private IL10N $l,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Okuyucu listesi: yayında ve zamanı uygun duyurular.
     */
    #[NoAdminRequired]
    public function index(int $limit = 50, int $offset = 0): DataResponse {
        return new DataResponse($this->service->findVisibleFor(
            $this->userSession->getUser(),
            $this->clampLimit($limit),
            max(0, $offset)
        ));
    }

    /**
     * Yönetim listesi: taslak ve zamanlanmışlar dahil. Yalnızca yayıncılar (K1).
     */
    #[NoAdminRequired]
    public function all(int $limit = 50, int $offset = 0): DataResponse {
        $user = $this->userSession->getUser();
        if (!$this->publisherService->canPublish($user)) {
            return $this->forbidden();
        }

        return new DataResponse($this->service->findManageable(
            $user,
            $this->clampLimit($limit),
            max(0, $offset)
        ));
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse {
        try {
            return new DataResponse($this->service->findFor($id, $this->userSession->getUser()));
        } catch (AccessDeniedException | DoesNotExistException) {
            // 🔐 "Erişemezsin" ile "yok" AYNI yanıtı verir. Ayrılsaydı kullanıcı,
            // kimlikleri tarayarak hangi duyuruların var olduğunu — yani kendisine
            // kapalı kaç duyuru bulunduğunu — öğrenebilirdi (enumeration).
            return $this->notFound();
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param string[] $groups
     * @param array<int, array<string, mixed>> $attachments
     */
    /**
     * Hız sınırı: yetkili bir hesap ele geçirilse bile saatte 30 duyuruyla
     * sınırlı kalır. Her duyuru tüm kullanıcılara bildirim/e-posta üretebildiği
     * için sınırsız oluşturma, sunucuyu spam aracına çevirirdi.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 3600)]
    public function create(
        string $subject,
        string $message = '',
        ?string $category = null,
        int $priority = 0,
        bool $pinned = false,
        ?int $coverFileId = null,
        ?int $coverX = null,
        ?int $coverY = null,
        ?int $publishAt = null,
        ?int $deleteAt = null,
        array $groups = [],
        array $attachments = [],
        array $options = [],
        bool $draft = false,
    ): DataResponse {
        $user = $this->userSession->getUser();
        if (!$this->publisherService->canPublish($user)) {
            return $this->forbidden();
        }
        /** @var IUser $user canPublish null kullanıcıyı zaten eler */

        try {
            $created = $this->service->create($user->getUID(), [
                'subject' => $subject,
                'message' => $message,
                'category' => $category,
                'priority' => $priority,
                'pinned' => $pinned,
                'coverFileId' => $coverFileId,
                'coverX' => $coverX,
                'coverY' => $coverY,
                'publishAt' => $publishAt,
                'deleteAt' => $deleteAt,
                'groups' => $groups,
                'attachments' => $attachments,
                'options' => $options,
                'draft' => $draft,
            ]);
        } catch (ValidationException $e) {
            return new DataResponse(['message' => $e->translate($this->l)], Http::STATUS_BAD_REQUEST);
        }

        return new DataResponse($created, Http::STATUS_CREATED);
    }

    /**
     * @param array<string, mixed> $options
     * @param string[]|null $groups
     * @param array<int, array<string, mixed>>|null $attachments
     */
    #[NoAdminRequired]
    public function update(
        int $id,
        ?string $subject = null,
        ?string $message = null,
        ?string $category = null,
        ?int $priority = null,
        ?bool $pinned = null,
        ?int $coverFileId = null,
        ?int $coverX = null,
        ?int $coverY = null,
        ?int $publishAt = null,
        ?int $deleteAt = null,
        ?array $groups = null,
        ?array $attachments = null,
        ?array $options = null,
        ?bool $draft = null,
    ): DataResponse {
        $denied = $this->assertCanEdit($id);
        if ($denied !== null) {
            return $denied;
        }

        // Yalnızca gerçekten gönderilen alanlar güncellenir; gönderilmeyenler
        // dokunulmadan kalır. Kapak/zamanlama/kategoride gönderilen null ise
        // "boşalt" demektir — ayrım pickSentFields()'ta.
        $data = AnnouncementService::pickSentFields([
            'subject' => $subject,
            'message' => $message,
            'category' => $category,
            'priority' => $priority,
            'pinned' => $pinned,
            'coverFileId' => $coverFileId,
            'coverX' => $coverX,
            'coverY' => $coverY,
            'publishAt' => $publishAt,
            'deleteAt' => $deleteAt,
            'groups' => $groups,
            'attachments' => $attachments,
            'options' => $options,
            'draft' => $draft,
        ], $this->request->getParams());

        try {
            // Düzenleyen kişi, değişen kapağın kimin dosyalarında arandığını belirler.
            return new DataResponse($this->service->update($id, $data, $this->userSession->getUser()?->getUID()));
        } catch (DoesNotExistException) {
            return $this->notFound();
        } catch (ValidationException $e) {
            return new DataResponse(['message' => $e->translate($this->l)], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function destroy(int $id): DataResponse {
        $denied = $this->assertCanEdit($id);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->service->delete($id);
        } catch (DoesNotExistException) {
            return $this->notFound();
        }

        return new DataResponse([]);
    }

    /**
     * Değiştirme/silme yetkisini kontrol eder (K1).
     * Yetki varsa null, yoksa doğrudan döndürülecek hata yanıtını verir.
     */
    private function assertCanEdit(int $id): ?DataResponse {
        try {
            $announcement = $this->service->findEntity($id);
        } catch (DoesNotExistException) {
            return $this->notFound();
        }

        if (!$this->publisherService->canEdit($this->userSession->getUser(), $announcement)) {
            return $this->forbidden();
        }

        return null;
    }

    private function forbidden(): DataResponse {
        return new DataResponse(['message' => $this->l->t('Bu işlem için yetkiniz yok.')], Http::STATUS_FORBIDDEN);
    }

    private function notFound(): DataResponse {
        return new DataResponse(['message' => $this->l->t('Duyuru bulunamadı.')], Http::STATUS_NOT_FOUND);
    }

    /**
     * Sayfa boyutu sınırlanmazsa tek istekle tüm tablo çekilebilir.
     */
    private function clampLimit(int $limit): int {
        return max(1, min($limit, 100));
    }
}
