<?php

declare(strict_types=1);

/**
 * Announcement::isVisibleAt() — zamanlama kuralı (K5).
 *
 * Bu testin koruduğu şey: "duyuru şu an görünür mü" sorusuna liste, detay, widget
 * ve arama AYNI cevabı vermeli. Kural tek bir metotta duruyor; burası o metodu
 * kilitler. Kural kopyalanırsa bir gün ikisi ayrışır ve zamanı gelmemiş duyuru
 * bir yerden sızar.
 *
 * Çalıştırma:  php tests/AnnouncementTest.php
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../lib/Db/Announcement.php';

use OCA\NoticeBoard\Db\Announcement;

const NOW = 1_700_000_000;

function makeAnnouncement(string $status, ?int $publishAt = null, ?int $deleteAt = null): Announcement {
    $a = new Announcement();
    $a->setSubject('Deneme');
    $a->setStatus($status);
    $a->setPublishAt($publishAt);
    $a->setDeleteAt($deleteAt);
    return $a;
}

echo "Announcement::isVisibleAt\n";

check(
    'yayindaki duyuru, tarih yoksa gorunur',
    makeAnnouncement(Announcement::STATUS_PUBLISHED)->isVisibleAt(NOW)
);

check(
    'taslak duyuru gorunmez',
    !makeAnnouncement(Announcement::STATUS_DRAFT)->isVisibleAt(NOW)
);

check(
    'zamanlanmis (henuz yayinlanmamis) duyuru gorunmez',
    !makeAnnouncement(Announcement::STATUS_SCHEDULED, NOW + 3600)->isVisibleAt(NOW)
);

check(
    'arsivlenmis duyuru gorunmez',
    !makeAnnouncement(Announcement::STATUS_ARCHIVED)->isVisibleAt(NOW)
);

check(
    'yayin zamani gelecekte ise gorunmez',
    !makeAnnouncement(Announcement::STATUS_PUBLISHED, NOW + 1)->isVisibleAt(NOW)
);

check(
    'yayin zamani tam su an ise gorunur',
    makeAnnouncement(Announcement::STATUS_PUBLISHED, NOW)->isVisibleAt(NOW)
);

check(
    'yayin zamani gecmiste ise gorunur',
    makeAnnouncement(Announcement::STATUS_PUBLISHED, NOW - 3600)->isVisibleAt(NOW)
);

check(
    'silme zamani gelecekte ise hala gorunur',
    makeAnnouncement(Announcement::STATUS_PUBLISHED, null, NOW + 1)->isVisibleAt(NOW)
);

check(
    'silme zamani tam su an ise artik gorunmez',
    !makeAnnouncement(Announcement::STATUS_PUBLISHED, null, NOW)->isVisibleAt(NOW)
);

check(
    'silme zamani gecmiste ise gorunmez',
    !makeAnnouncement(Announcement::STATUS_PUBLISHED, null, NOW - 1)->isVisibleAt(NOW)
);

check(
    'yayin basladi ve silme zamani gelmedi ise gorunur',
    makeAnnouncement(Announcement::STATUS_PUBLISHED, NOW - 100, NOW + 100)->isVisibleAt(NOW)
);

// jsonSerialize sozlesmesi: arayuz bu anahtarlara gore yazilacak.
$payload = makeAnnouncement(Announcement::STATUS_PUBLISHED)->jsonSerialize();
check(
    'jsonSerialize dagitim anahtarlarini options altinda toplar',
    isset($payload['options']['activity'], $payload['options']['notification'],
        $payload['options']['email'], $payload['options']['comments'], $payload['options']['talk'])
);

// Her dağıtım anahtarının sınıf varsayılanı KOLON varsayılanıyla aynı olmalı (Hata #47):
// NC aynı değere set edilen alanı yazmaz; ayrışırsa yeni kayıt sessizce kolon değerini alır.
$kolonlar = [];
foreach (glob(__DIR__ . '/../lib/Migration/*.php') ?: [] as $dosya) {
    preg_match_all("/addColumn\('(opt_\w+)', Types::BOOLEAN, \[[^\]]*'default' => (true|false)/", (string)file_get_contents($dosya), $mm, PREG_SET_ORDER);
    foreach ($mm as $m) {
        $kolonlar[$m[1]] = $m[2] === 'true';
    }
}
check('migration dagitim kolonlari okundu (' . count($kolonlar) . ')', count($kolonlar) === 5);
$bos = new Announcement();
foreach ($kolonlar as $kolon => $varsayilan) {
    $getter = 'get' . str_replace('_', '', ucwords($kolon, '_'));
    check("$kolon: sinif varsayilani = kolon varsayilani", $bos->$getter() === $varsayilan);
}

// Eski satır: opt_talk kolonu sonradan eklendi; veritabanından null gelebilir.
$eski = makeAnnouncement(Announcement::STATUS_PUBLISHED);
$eski->setOptTalk(false);
check('Talk anahtari kapali duyuru options.talk = false', $eski->jsonSerialize()['options']['talk'] === false);
check(
    'jsonSerialize okundu takibi alani ICERMEZ (karar K2)',
    !array_key_exists('read', $payload) && !array_key_exists('readCount', $payload)
);

finish('AnnouncementTest');
