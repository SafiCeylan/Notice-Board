<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Controller;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Db\AttachmentMapper;
use OCA\NoticeBoard\Exception\AccessDeniedException;
use OCA\NoticeBoard\Service\AnnouncementService;
use OCA\NoticeBoard\Service\InlineImages;
use OCA\NoticeBoard\Service\UserFileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Duyurunun kapak görselini ve eklerini servis eder.
 *
 * 🔐 NEDEN AYRI BİR UÇ: dosyalar duyuruyu YAZANIN alanında duruyor. Okuyucunun o
 * dosyaya Nextcloud izni yok — olsaydı da duyuru hedeflemesinden bağımsız olurdu.
 * Bu yüzden dosya, sahibi adına açılır ama **duyuru görünürlüğü** kontrol edildikten
 * sonra verilir. Yani erişimin ölçüsü dosya izni değil, duyuruyu görebilmektir.
 *
 * ⚠️ Kontrol `AnnouncementService::findEntityForReader()` ile yapılır — detay ucuyla
 * aynı kural. Buraya ayrı bir kontrol YAZILMAZ.
 */
class FileController extends Controller {

    public function __construct(
        IRequest $request,
        private AnnouncementService $service,
        private AttachmentMapper $attachmentMapper,
        private UserFileService $files,
        private InlineImages $inlineImages,
        private IUserSession $userSession,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Duyurunun kapak görseli.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function cover(int $id): Response {
        try {
            $announcement = $this->service->findEntityForReader($id, $this->userSession->getUser());
        } catch (AccessDeniedException | DoesNotExistException) {
            // Erişilemeyen ve var olmayan aynı yanıtı verir (bkz. AnnouncementController).
            return $this->gone();
        }

        $fileId = $announcement->getCoverFileId();
        if ($fileId === null) {
            return $this->gone();
        }

        // Kapak onu SEÇENİN alanında durur (yönetici başkasının duyurusunda
        // değiştirdiyse yöneticinin); eski kayıtlarda sahip boş = yazar.
        $file = $this->files->open($announcement->getCoverFileOwnerUid(), $fileId);
        if ($file === null) {
            return $this->gone();
        }

        // Kapak yalnızca görsel olabilir; aksi halde bu uç keyfi dosya indirmeye
        // dönüşürdü.
        if (!in_array($file->getMimeType(), UserFileService::IMAGE_TYPES, true)) {
            return $this->gone();
        }

        $response = new FileDisplayResponse($file, Http::STATUS_OK, [
            'Content-Type' => $file->getMimeType(),
        ]);
        // Kapak nadiren değişir; tarayıcı 1 saat saklasın (özel önbellek, paylaşılan değil).
        $response->cacheFor(3600, false, true);

        return $response;
    }

    /**
     * Duyuru metnine gömülü görsel (InlineImages).
     *
     * Görselin bu duyuruya ait olduğunun kanıtı, kimliğinin duyurunun KAYITLI
     * gövdesinde geçmesidir; sahibi de oradan okunur. Kontrol edilmeseydi bir
     * duyuruyu görebilen kullanıcı, kimlik değiştirerek yazarın herhangi bir
     * görselini indirebilirdi.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function image(int $id, int $fileId): Response {
        try {
            $announcement = $this->service->findEntityForReader($id, $this->userSession->getUser());
        } catch (AccessDeniedException | DoesNotExistException) {
            return $this->gone();
        }

        $owner = $this->inlineImages->owners($announcement->getMessage(), $announcement->getAuthorUid())[$fileId] ?? null;
        if ($owner === null) {
            return $this->gone();
        }

        $file = $this->files->open($owner, $fileId);
        if ($file === null || !in_array($file->getMimeType(), UserFileService::IMAGE_TYPES, true)) {
            return $this->gone();
        }

        $response = new FileDisplayResponse($file, Http::STATUS_OK, [
            'Content-Type' => $file->getMimeType(),
        ]);
        // Gövdeye yeni görsel yeni kimlikle girer; aynı kimliğin içeriği değişmez.
        $response->cacheFor(3600, false, true);

        return $response;
    }

    /**
     * Duyuruya iliştirilmiş dosyayı indirir.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function attachment(int $id, int $attachmentId): Response {
        try {
            $announcement = $this->service->findEntityForReader($id, $this->userSession->getUser());
        } catch (AccessDeniedException | DoesNotExistException) {
            return $this->gone();
        }

        // Ek gerçekten BU duyuruya mı ait? Kontrol edilmezse bir duyuruyu görebilen
        // kullanıcı, id değiştirerek göremediği bir duyurunun ekini indirebilirdi.
        $attachment = null;
        foreach ($this->attachmentMapper->findByAnnouncement((int)$announcement->getId()) as $candidate) {
            if ((int)$candidate->getId() === $attachmentId) {
                $attachment = $candidate;
                break;
            }
        }

        if ($attachment === null) {
            return $this->gone();
        }

        // Ek onu EKLEYENİN alanında durur; eski kayıtlarda sahip boş = yazar.
        $file = $this->files->open($attachment->getFileOwnerUid($announcement->getAuthorUid()), $attachment->getFileId());
        if ($file === null) {
            return $this->gone();
        }

        return new FileDisplayResponse($file, Http::STATUS_OK, [
            'Content-Type' => $file->getMimeType(),
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($file->getName()) . '"',
        ]);
    }

    /**
     * Tek bir "yok" yanıtı. Erişim yok / duyuru yok / dosya yok / tür uygun değil
     * durumlarının hepsi AYNI cevabı verir; ayrılsaydı istemci, göremediği bir
     * duyurunun kapağı olup olmadığını öğrenebilirdi.
     */
    private function gone(): DataDisplayResponse {
        return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
    }
}
