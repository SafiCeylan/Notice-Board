<?php

declare(strict_types=1);

/**
 * Duyuru Panosu — URL yönlendirme tablosu.
 *
 * 'routes' → normal sayfa rotaları   (noticeboard.page.index)
 * 'ocs'    → REST API rotaları       (/ocs/v2.php/apps/noticeboard/...)
 */
return [
    'routes' => [
        // Vue uygulamasını barındıran ana sayfa (sol menü buraya gider).
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Kapak görseli ve ekler. OCS değil normal rota: tarayıcı bunları <img src>
        // ve indirme bağlantısı olarak doğrudan ister, JSON sarmalayıcı istemez.
        ['name' => 'file#cover', 'url' => '/announcements/{id}/cover', 'verb' => 'GET',
            'requirements' => ['id' => '\d+']],
        ['name' => 'file#image', 'url' => '/announcements/{id}/images/{fileId}', 'verb' => 'GET',
            'requirements' => ['id' => '\d+', 'fileId' => '\d+']],
        ['name' => 'file#attachment', 'url' => '/announcements/{id}/attachments/{attachmentId}', 'verb' => 'GET',
            'requirements' => ['id' => '\d+', 'attachmentId' => '\d+']],
    ],

    'ocs' => [
        // Okuyucu listesi — yayında ve zamanı uygun duyurular.
        ['name' => 'announcement#index', 'url' => '/api/v1/announcements', 'verb' => 'GET'],

        // Yönetim listesi — taslak ve zamanlanmışlar dahil (yalnız yetkili).
        ['name' => 'announcement#all', 'url' => '/api/v1/announcements/all', 'verb' => 'GET'],

        // Tek duyuru. `requirements` olmazsa {id} deseni "all" kelimesini de
        // yakalar ve yönetim listesi yerine buraya düşer.
        ['name' => 'announcement#show', 'url' => '/api/v1/announcements/{id}', 'verb' => 'GET',
            'requirements' => ['id' => '\d+']],

        ['name' => 'announcement#create', 'url' => '/api/v1/announcements', 'verb' => 'POST'],
        ['name' => 'announcement#update', 'url' => '/api/v1/announcements/{id}', 'verb' => 'PUT',
            'requirements' => ['id' => '\d+']],
        ['name' => 'announcement#destroy', 'url' => '/api/v1/announcements/{id}', 'verb' => 'DELETE',
            'requirements' => ['id' => '\d+']],

        // --- Yorumlar (K6) ---
        ['name' => 'comment#index', 'url' => '/api/v1/announcements/{id}/comments', 'verb' => 'GET',
            'requirements' => ['id' => '\d+']],
        ['name' => 'comment#create', 'url' => '/api/v1/announcements/{id}/comments', 'verb' => 'POST',
            'requirements' => ['id' => '\d+']],
        ['name' => 'comment#destroy', 'url' => '/api/v1/announcements/{id}/comments/{commentId}', 'verb' => 'DELETE',
            'requirements' => ['id' => '\d+', 'commentId' => '\d+']],

        // --- Yönetici ayarları (K1) — yalnızca yöneticiye açık ---
        ['name' => 'admin#getSettings', 'url' => '/api/v1/settings/publishers', 'verb' => 'GET'],
        ['name' => 'admin#saveSettings', 'url' => '/api/v1/settings/publishers', 'verb' => 'POST'],
        ['name' => 'admin#searchUsers', 'url' => '/api/v1/settings/users', 'verb' => 'GET'],
        ['name' => 'admin#getSpotlight', 'url' => '/api/v1/settings/spotlight', 'verb' => 'GET'],
        ['name' => 'admin#saveSpotlight', 'url' => '/api/v1/settings/spotlight', 'verb' => 'POST'],
        // Hedef grup seçici — yayıncılara açık (yönetici ayar ekranı da bunu kullanır).
        ['name' => 'picker#groups', 'url' => '/api/v1/groups', 'verb' => 'GET'],
    ],
];
