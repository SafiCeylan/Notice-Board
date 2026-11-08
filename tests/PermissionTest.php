<?php

declare(strict_types=1);

/**
 * PublisherService (K1) + VisibilityService (K3/K5) — yetki kapıları.
 *
 * Bu projedeki EN KRİTİK testler bunlar. Koruduğu şey:
 *   · yetkisiz kullanıcı duyuru yayınlayamaz
 *   · yayıncı BAŞKASININ duyurusunu değiştiremez
 *   · hedeflenmemiş kullanıcı duyuruyu göremez
 *
 * Bu kurallardan biri sessizce bozulursa uygulama "çalışıyor" görünür ama
 * kurumsal duyurular yanlış kişilere açılır. Testler o yüzden burada.
 *
 * Çalıştırma:  php tests/PermissionTest.php
 */

namespace {
    require __DIR__ . '/bootstrap.php';
    require __DIR__ . '/../lib/Db/Announcement.php';
    require __DIR__ . '/../lib/Service/PublisherService.php';
    require __DIR__ . '/../lib/Service/VisibilityService.php';

    use OCA\NoticeBoard\Db\Announcement;
    use OCA\NoticeBoard\Service\PublisherService;
    use OCA\NoticeBoard\Service\VisibilityService;
    use OCA\NoticeBoard\Tests\FakeAppConfig;
    use OCA\NoticeBoard\Tests\FakeGroupManager;
    use OCA\NoticeBoard\Tests\FakeUser;

    const NOW = 1_700_000_000;

    // Sahne: admin yönetici; ayse İK'da; mehmet muhasebede; can hiçbir grupta.
    function makeGroupManager(): FakeGroupManager {
        return new FakeGroupManager(
            admins: ['admin'],
            memberships: [
                'ayse' => ['ik'],
                'mehmet' => ['muhasebe'],
                'admin' => ['admin'],
                'can' => [],
            ]
        );
    }

    // =====================================================================
    echo "PublisherService — kim yayinlayabilir (K1)\n";
    // =====================================================================

    $groups = makeGroupManager();
    $config = new FakeAppConfig();
    $publisher = new PublisherService($config, $groups);

    $admin = new FakeUser('admin');
    $ayse = new FakeUser('ayse');
    $mehmet = new FakeUser('mehmet');
    $can = new FakeUser('can');

    check('yonetici her zaman yayinlayabilir', $publisher->canPublish($admin));
    check('atanmamis kullanici yayinlayamaz', !$publisher->canPublish($ayse));
    check('oturum yoksa yayinlayamaz', !$publisher->canPublish(null));

    // Hesap bazlı atama
    $publisher->setPublishers(['ayse'], []);
    check('yayinci atanan hesap yayinlayabilir', $publisher->canPublish($ayse));
    check('atanmayan hesap hala yayinlayamaz', !$publisher->canPublish($mehmet));

    // Grup bazlı atama
    $publisher->setPublishers([], ['muhasebe']);
    check('yayinci atanan grubun uyesi yayinlayabilir', $publisher->canPublish($mehmet));
    check('o grupta olmayan yayinlayamaz', !$publisher->canPublish($ayse));
    check('hicbir grupta olmayan yayinlayamaz', !$publisher->canPublish($can));
    check('grup atamasi yonetciyi etkilemez', $publisher->canPublish($admin));

    // Temizleme
    $publisher->setPublishers(['  ayse  ', '', 'ayse'], ['  ik  ', '']);
    check('yayinci listesi tekillestirilir ve temizlenir', $publisher->getPublisherUids() === ['ayse']);
    check('yayinci grup listesi temizlenir', $publisher->getPublisherGids() === ['ik']);

    // =====================================================================
    echo "\nPublisherService — kim duzenleyebilir (K1)\n";
    // =====================================================================

    $publisher->setPublishers(['ayse', 'mehmet'], []);

    $ayseninDuyurusu = new Announcement();
    $ayseninDuyurusu->setAuthorUid('ayse');
    $ayseninDuyurusu->setSubject('IK duyurusu');

    check('yazar kendi duyurusunu duzenleyebilir', $publisher->canEdit($ayse, $ayseninDuyurusu));
    check(
        'BASKA bir yayinci, baskasinin duyurusunu duzenleyemez',
        !$publisher->canEdit($mehmet, $ayseninDuyurusu)
    );
    check('yonetici her duyuruyu duzenleyebilir', $publisher->canEdit($admin, $ayseninDuyurusu));
    check('yetkisiz kullanici duzenleyemez', !$publisher->canEdit($can, $ayseninDuyurusu));
    check('oturum yoksa duzenleyemez', !$publisher->canEdit(null, $ayseninDuyurusu));

    // Yayıncılıktan çıkarılan kişi kendi eski duyurusunu da düzenleyemez.
    $publisher->setPublishers(['mehmet'], []);
    check(
        'yayincilik geri alinan kisi kendi duyurusunu artik duzenleyemez',
        !$publisher->canEdit($ayse, $ayseninDuyurusu)
    );

    // =====================================================================
    echo "\nVisibilityService — kim gorebilir (K3 + K5)\n";
    // =====================================================================

    $visibility = new VisibilityService($groups);

    function yayinda(?int $publishAt = null, ?int $deleteAt = null): Announcement {
        $a = new Announcement();
        $a->setSubject('Duyuru');
        $a->setStatus(Announcement::STATUS_PUBLISHED);
        $a->setPublishAt($publishAt);
        $a->setDeleteAt($deleteAt);
        return $a;
    }

    // Hedefleme kuralı
    check('hedef grubu olmayan duyuruyu herkes gorur', $visibility->isTargeted($can, []));
    check('hedeflenen grubun uyesi gorur', $visibility->isTargeted($ayse, ['ik']));
    check('hedeflenmeyen kullanici GOREMEZ', !$visibility->isTargeted($mehmet, ['ik']));
    check('hicbir grupta olmayan, hedefli duyuruyu goremez', !$visibility->isTargeted($can, ['ik']));
    check(
        'birden fazla hedeften birine uyan gorur',
        $visibility->isTargeted($mehmet, ['ik', 'muhasebe'])
    );
    check('oturum yoksa goremez', !$visibility->isTargeted(null, []));

    // 🔐 Yönetici ayrıcalığı hedeflemede GEÇMEZ: yönetici de hedef değilse
    // okuyucu listesinde görmez. (Yönetim listesi ayrı bir uçtur.)
    check(
        'yonetici bile hedeflenmemis duyuruyu okuyucu listesinde goremez',
        !$visibility->isTargeted($admin, ['ik'])
    );

    // Zaman + hedef birlikte
    check(
        'hem zamani uygun hem hedefli ise gorur',
        $visibility->canSee($ayse, yayinda(), ['ik'], NOW)
    );
    check(
        'hedefli ama zamani gelmemis ise goremez',
        !$visibility->canSee($ayse, yayinda(NOW + 3600), ['ik'], NOW)
    );
    check(
        'hedefli ama suresi dolmus ise goremez',
        !$visibility->canSee($ayse, yayinda(null, NOW - 1), ['ik'], NOW)
    );
    check(
        'zamani uygun ama hedefli degilse goremez',
        !$visibility->canSee($mehmet, yayinda(), ['ik'], NOW)
    );

    $taslak = new Announcement();
    $taslak->setStatus(Announcement::STATUS_DRAFT);
    check(
        'taslak, hedef grubun uyesine bile gorunmez',
        !$visibility->canSee($ayse, $taslak, ['ik'], NOW)
    );

    finish('PermissionTest');
}
