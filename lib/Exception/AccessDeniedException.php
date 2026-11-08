<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Exception;

/**
 * Kullanıcının yetkisi olmayan bir işlem denendiğinde fırlatılır.
 * Controller bunu 403 Forbidden'a çevirir.
 *
 * ⚠️ Mesaj kullanıcıya gösterilir; "hangi duyuru vardı da göremedin" gibi
 * bilgi sızdırmayacak şekilde genel tutulur.
 */
class AccessDeniedException extends TranslatableException {
}
