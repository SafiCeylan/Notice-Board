<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Service;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Db\Announcement;
use OCP\Defaults;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Duyuru e-postası (K6 · `opt_email`).
 *
 * ⚠️ Bu sınıf ARKA PLAN İŞİNDEN çağrılır (`SendMailJob`), web isteğinden değil.
 * Binlerce kullanıcılı bir sunucuda posta göndermek dakikalar sürebilir; istek
 * içinde yapılsaydı duyuru kaydeden kişi zaman aşımı görürdü.
 */
class MailService {

    private const EXCERPT_LENGTH = 400;

    public function __construct(
        private IMailer $mailer,
        private IUserManager $userManager,
        private IURLGenerator $urlGenerator,
        private IFactory $l10nFactory,
        private Defaults $defaults,
        private HtmlSanitizer $sanitizer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string[] $uids
     */
    public function sendTo(Announcement $announcement, array $uids): void {
        $link = $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('noticeboard.page.index')
            . '#/announcement/' . $announcement->getId()
        );

        $excerpt = mb_substr(
            $this->sanitizer->toPlainText($announcement->getMessage()),
            0,
            self::EXCERPT_LENGTH
        );

        $authorName = $this->userManager->get($announcement->getAuthorUid())?->getDisplayName()
            ?? $announcement->getAuthorUid();

        $sent = 0;
        $skipped = 0;

        foreach ($uids as $uid) {
            $user = $this->userManager->get($uid);
            $address = $user?->getEMailAddress();

            if ($user === null || $address === null || $address === '') {
                // E-posta adresi olmayan hesap normaldir; hata değil.
                $skipped++;
                continue;
            }

            try {
                $this->sendOne($user->getDisplayName(), $address, $uid, $announcement, $authorName, $excerpt, $link);
                $sent++;
            } catch (\Throwable $e) {
                $this->logger->warning('Duyuru e-postası gönderilemedi', [
                    'app' => Application::APP_ID,
                    'uid' => $uid,
                    'exception' => $e,
                ]);
            }
        }

        $this->logger->info('Duyuru e-postaları tamamlandı', [
            'app' => Application::APP_ID,
            'announcement' => $announcement->getId(),
            'sent' => $sent,
            'skipped' => $skipped,
        ]);
    }

    private function sendOne(
        string $displayName,
        string $address,
        string $uid,
        Announcement $announcement,
        string $authorName,
        string $excerpt,
        string $link,
    ): void {
        $l = $this->l10nFactory->get(Application::APP_ID, $this->l10nFactory->getUserLanguage(
            $this->userManager->get($uid)
        ));

        $template = $this->mailer->createEMailTemplate('noticeboard.Announcement', [
            'subject' => $announcement->getSubject(),
        ]);

        $template->setSubject($l->t('Duyuru: %s', [$announcement->getSubject()]));
        $template->addHeader();
        $template->addHeading($announcement->getSubject());
        $template->addBodyText($l->t('%s bir duyuru yayınladı.', [$authorName]));

        if ($excerpt !== '') {
            $template->addBodyText($excerpt);
        }

        $template->addBodyButton($l->t('Duyuruyu aç'), $link);
        $template->addFooter();

        $message = $this->mailer->createMessage();
        $message->setTo([$address => $displayName]);
        $message->useTemplate($template);

        $this->mailer->send($message);
    }
}
