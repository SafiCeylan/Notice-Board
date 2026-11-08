<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Exception;

/**
 * Kullanıcı girdisi kurallara uymadığında fırlatılır.
 * Controller bunu 400 Bad Request'e çevirir; mesaj kullanıcıya gösterilir,
 * bu yüzden mesajlar çevrilebilir ve anlaşılır tutulur.
 */
class ValidationException extends TranslatableException {
}
