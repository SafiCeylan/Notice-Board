<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Activity;

use OCA\NoticeBoard\AppInfo\Application;
use OCA\NoticeBoard\Service\IconUrl;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
// ⚠️ NC 30+ yolu `Exceptions\` alt ad alanında. `OCP\Activity\UnknownActivityException`
// diye bir sınıf YOK: her yabancı etkinlikte "Class not found" ile patlıyordu (Hata #39).
// NC 29 ve öncesinde bu sınıf da yok, orada `\InvalidArgumentException` beklenir →
// doğrudan `new` YAZMA, `unknownActivity()` kullan. (`use` sınıfı yüklemez, güvenli.)
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * Etkinlik akışındaki satırı okuyanın dilinde kurar (K6 · `opt_activity`).
 *
 * Bildirimde olduğu gibi burada da metin ÜRETİM anında değil OKUMA anında
 * kurulur; etkinlik kaydı yıllarca durabilir.
 */
class Provider implements IProvider {

    public const SUBJECT_PUBLISHED = 'announcement_published';

    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
    ) {
    }

    /**
     * ⚠️ `$language` BİLEREK tipsiz: `OCP\Activity\IProvider::parse()` onu tipsiz
     * tanımlıyor ve PHP, arayüzden daha dar bir parametre tipi kabul etmiyor.
     * `string $language` yazmak canlıda ölümcül hata verdi (Hata #15).
     *
     * @param string $language
     */
    public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
        if ($event->getApp() !== Application::APP_ID) {
            throw self::unknownActivity();
        }

        if ($event->getSubject() !== self::SUBJECT_PUBLISHED) {
            throw self::unknownActivity();
        }

        $l = $this->l10nFactory->get(Application::APP_ID, $language);
        $parameters = $event->getSubjectParameters();

        $authorUid = (string)($parameters['author'] ?? '');
        $subject = (string)($parameters['subject'] ?? '');
        $authorName = $this->userManager->get($authorUid)?->getDisplayName() ?? $authorUid;

        $event->setIcon(IconUrl::absolute($this->urlGenerator, 'app-dark.svg'));

        $event->setParsedSubject($l->t('%1$s bir duyuru yayınladı: %2$s', [$authorName, $subject]));
        $event->setRichSubject(
            $l->t('{author} bir duyuru yayınladı: {announcement}'),
            [
                'author' => [
                    'type' => 'user',
                    'id' => $authorUid,
                    'name' => $authorName,
                ],
                'announcement' => [
                    'type' => 'highlight',
                    'id' => (string)$event->getObjectId(),
                    'name' => $subject,
                ],
            ]
        );

        return $event;
    }

    /**
     * NC'nin "bu etkinlik benim değil" istisnası: NC 30+ `UnknownActivityException`,
     * NC 29 ve öncesi `\InvalidArgumentException`.
     */
    public static function unknownActivity(): \Exception {
        return class_exists(UnknownActivityException::class)
            ? new UnknownActivityException()
            : new \InvalidArgumentException();
    }
}
