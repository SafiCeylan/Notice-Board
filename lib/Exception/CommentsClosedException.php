<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Exception;

/**
 * Duyuruyu görebiliyor ama yorumlar kapalı.
 *
 * `AccessDeniedException`ın bir alt türü olması bilinçli: çağıran ayırmak
 * istemezse aynı davranışı görür. Ayırmak isteyen ise **403** (görebiliyorsun
 * ama yorum kapalı) ile **404** (bunu göremezsin) arasında ayrım yapabilir —
 * bu ayrım, var olmayan duyuru ile erişilemeyen duyurunun karıştırılmasını
 * sağlayarak kimlik saymayı (enumeration) engeller.
 */
class CommentsClosedException extends AccessDeniedException {
}
