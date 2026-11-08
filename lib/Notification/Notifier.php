<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Notification;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Service\IconUrl;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Çan ikonundaki bildirimi kullanıcının diline göre metne çevirir.
 *
 * Bildirim ÜRETİLİRKEN metin yazılmaz; yalnızca parametreler saklanır ve metin
 * burada, okuyan kişinin dilinde kurulur. Bildirim günlerce durabildiği için
 * metni üretim anında sabitlemek yanlış dilde kalmasına yol açardı.
 */
class Notifier implements INotifier {

    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return $this->l10nFactory->get(Application::APP_ID)->t('Duyuru Panosu');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== Application::APP_ID) {
            // Bize ait olmayan bildirime dokunmamalıyız.
            throw new UnknownNotificationException();
        }

        if ($notification->getSubject() !== 'announcement') {
            throw new UnknownNotificationException();
        }

        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $parameters = $notification->getSubjectParameters();

        $authorUid = (string)($parameters['author'] ?? '');
        $subject = (string)($parameters['subject'] ?? '');
        $excerpt = (string)($notification->getMessageParameters()['excerpt'] ?? '');

        $authorUser = $this->userManager->get($authorUid);
        $authorName = $authorUser?->getDisplayName() ?? $authorUid;

        $notification
            ->setIcon(IconUrl::absolute($this->urlGenerator, 'app-dark.svg'))
            // Zengin metni desteklemeyen istemciler için düz karşılık.
            ->setParsedSubject($l->t('%1$s bir duyuru yayınladı: %2$s', [$authorName, $subject]))
            ->setRichSubject(
                $l->t('{author} bir duyuru yayınladı: {announcement}'),
                [
                    'author' => [
                        'type' => 'user',
                        'id' => $authorUid,
                        'name' => $authorName,
                    ],
                    'announcement' => [
                        'type' => 'highlight',
                        'id' => (string)$notification->getObjectId(),
                        'name' => $subject,
                    ],
                ]
            );

        if ($excerpt !== '') {
            $notification->setParsedMessage($excerpt);
        }

        return $notification;
    }
}
