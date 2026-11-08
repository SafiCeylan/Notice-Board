<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Exception\AccessDeniedException;
use OCA\NoticeBoard\Exception\CommentsClosedException;
use OCA\NoticeBoard\Exception\ValidationException;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Duyuru yorumları (K6 · `opt_comments`).
 *
 * Yorumlar Nextcloud'un KENDİ altyapısında tutulur (`oc_comments`); kendi
 * tablomuz yok. Böylece silme, arama ve bakım işleri çekirdekle uyumlu kalır.
 *
 * 🔐 İki kapı birden geçilir:
 *   1. Kullanıcı duyuruyu okuyabiliyor mu? (AnnouncementService üzerinden, K3+K5)
 *   2. O duyuruda yorum açık mı? (`opt_comments`)
 * Anahtar kapalıyken arayüz yorum alanını gizler; ama asıl kontrol burada —
 * doğrudan API'ye istek atan biri de geçemez.
 */
class CommentService {

    private const MAX_MESSAGE_LENGTH = 1000;

    public function __construct(
        private ICommentsManager $commentsManager,
        private AnnouncementService $announcementService,
        private PublisherService $publisherService,
        private IUserManager $userManager,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws AccessDeniedException
     */
    public function findForAnnouncement(int $announcementId, ?IUser $user, int $limit = 100, int $offset = 0): array {
        // Duyuruyu göremeyen yorumları da göremez.
        $this->announcementService->findEntityForReader($announcementId, $user);

        $comments = $this->commentsManager->getForObject(
            Application::APP_ID,
            (string)$announcementId,
            $limit,
            $offset
        );

        return array_map(
            fn (IComment $comment) => $this->present($comment, $user),
            $comments
        );
    }

    /**
     * @return array<string, mixed>
     * @throws AccessDeniedException
     * @throws ValidationException
     */
    public function create(int $announcementId, ?IUser $user, string $message): array {
        if ($user === null) {
            throw new AccessDeniedException('Yorum yazmak için oturum açmalısınız.');
        }

        $announcement = $this->announcementService->findEntityForReader($announcementId, $user);

        if (!$announcement->getOptComments()) {
            // Kullanıcı duyuruyu zaten görebiliyor; burada 403 bilgi sızdırmaz.
            throw new CommentsClosedException('Bu duyuruda yorumlar kapalı.');
        }

        $message = trim($message);
        if ($message === '') {
            throw new ValidationException('Yorum boş olamaz.');
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new ValidationException('Yorum en fazla %s karakter olabilir.', [self::MAX_MESSAGE_LENGTH]);
        }

        $comment = $this->commentsManager->create('users', $user->getUID(), Application::APP_ID, (string)$announcementId);
        $comment->setMessage($message);
        $comment->setVerb('comment');

        $this->commentsManager->save($comment);

        return $this->present($comment, $user);
    }

    /**
     * @throws AccessDeniedException
     */
    public function delete(int $announcementId, string $commentId, ?IUser $user): void {
        if ($user === null) {
            throw new AccessDeniedException('Bu işlem için yetkiniz yok.');
        }

        $announcement = $this->announcementService->findEntityForReader($announcementId, $user);

        try {
            $comment = $this->commentsManager->get($commentId);
        } catch (NotFoundException) {
            throw new AccessDeniedException('Yorum bulunamadı.');
        }

        // ⚠️ Yorumun GERÇEKTEN bu duyuruya ait olduğu doğrulanır. Yoksa bir
        // duyuruyu görebilen kullanıcı, göremediği bir duyurunun yorumunu silebilirdi.
        if ($comment->getObjectType() !== Application::APP_ID
            || $comment->getObjectId() !== (string)$announcementId) {
            throw new AccessDeniedException('Yorum bulunamadı.');
        }

        if (!$this->canDelete($comment, $user, $announcement)) {
            throw new AccessDeniedException('Bu yorumu silemezsiniz.');
        }

        $this->commentsManager->delete($commentId);
    }

    // Duyuru silinince yorumların temizlenmesi burada DEĞİL, CommentCleaner'da —
    // bu sınıf AnnouncementService'e bağlı olduğu için tersi döngü yaratırdı.

    public function countForAnnouncement(int $announcementId): int {
        return $this->commentsManager->getNumberOfCommentsForObject(
            Application::APP_ID,
            (string)$announcementId
        );
    }

    /**
     * Yorumu kim silebilir: yazarı, ya da duyuruyu düzenleyebilen kişi
     * (yayıncı kendi duyurusunun altını, yönetici her yeri temizleyebilmeli).
     */
    private function canDelete(IComment $comment, IUser $user, \OCA\NoticeBoard\Db\Announcement $announcement): bool {
        if ($comment->getActorType() === 'users' && $comment->getActorId() === $user->getUID()) {
            return true;
        }

        return $this->publisherService->canEdit($user, $announcement);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(IComment $comment, ?IUser $user): array {
        $actorId = $comment->getActorId();
        $displayName = $comment->getActorType() === 'users'
            ? ($this->userManager->get($actorId)?->getDisplayName() ?? $actorId)
            : $actorId;

        return [
            'id' => $comment->getId(),
            'actorId' => $actorId,
            'actorDisplayName' => $displayName,
            'message' => $comment->getMessage(),
            'createdAt' => $comment->getCreationDateTime()->getTimestamp(),
            // Arayüz sil düğmesini buna göre gösterir; asıl kontrol delete()'te.
            'canDelete' => $user !== null
                && $comment->getActorType() === 'users'
                && $comment->getActorId() === $user->getUID(),
        ];
    }
}
