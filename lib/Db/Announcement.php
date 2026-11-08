<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Bir duyuru kaydı.
 *
 * @method string getAuthorUid()
 * @method void setAuthorUid(string $authorUid)
 * @method string getSubject()
 * @method void setSubject(string $subject)
 * @method string getMessage()
 * @method void setMessage(string $message)
 * @method string|null getCategory()
 * @method void setCategory(?string $category)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method bool getPinned()
 * @method void setPinned(bool $pinned)
 * @method int|null getCoverFileId()
 * @method void setCoverFileId(?int $coverFileId)
 * @method int|null getCoverX()
 * @method void setCoverX(?int $coverX)
 * @method int|null getCoverY()
 * @method void setCoverY(?int $coverY)
 * @method string|null getCoverOwner()
 * @method void setCoverOwner(?string $coverOwner)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int|null getPublishAt()
 * @method void setPublishAt(?int $publishAt)
 * @method int|null getDeleteAt()
 * @method void setDeleteAt(?int $deleteAt)
 * @method bool getOptActivity()
 * @method void setOptActivity(bool $optActivity)
 * @method bool getOptNotification()
 * @method void setOptNotification(bool $optNotification)
 * @method bool getOptEmail()
 * @method void setOptEmail(bool $optEmail)
 * @method bool getOptComments()
 * @method void setOptComments(bool $optComments)
 * @method bool|null getOptTalk()
 * @method void setOptTalk(bool $optTalk)
 */
class Announcement extends Entity implements JsonSerializable {

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const PRIORITY_NORMAL = 0;
    public const PRIORITY_IMPORTANT = 1;
    public const PRIORITY_URGENT = 2;

    protected $authorUid = '';
    protected $subject = '';
    protected $message = '';
    protected $category = null;
    protected $priority = 0;
    protected $pinned = false;
    protected $coverFileId = null;
    /** Kapağın kırpılırken hangi bölgesinin görüneceği, yüzde (0–100). null = orta. */
    protected $coverX = null;
    protected $coverY = null;
    /** Kapak dosyasının sahibi (onu seçen). null = yazar. Okuyucuya gönderilmez. */
    protected $coverOwner = null;
    protected $status = self::STATUS_DRAFT;
    protected $createdAt = 0;
    protected $publishAt = null;
    protected $deleteAt = null;
    protected $optActivity = true;
    protected $optNotification = true;
    protected $optEmail = false;
    protected $optComments = true;
    /**
     * Talk özel mesajı (0.5.0). Yeni duyuruda AÇIK — onu AnnouncementService::create() yazar.
     *
     * ⚠️ Varsayılan KOLONLA AYNI (false) kalmalı. NC'nin Entity::setter'ı değer
     * değişmiyorsa alanı "güncellendi" saymaz ve INSERT o kolonu hiç yazmaz.
     * Burada true olsaydı create()'teki setOptTalk(true) boşa düşer, kayıt kolon
     * varsayılanıyla KAPALI olurdu (12 Eki, canlıda oldu — Hata #47).
     */
    protected $optTalk = false;

    public function __construct() {
        $this->addType('authorUid', 'string');
        $this->addType('subject', 'string');
        $this->addType('message', 'string');
        $this->addType('category', 'string');
        $this->addType('priority', 'integer');
        $this->addType('pinned', 'boolean');
        $this->addType('coverFileId', 'integer');
        $this->addType('coverX', 'integer');
        $this->addType('coverY', 'integer');
        $this->addType('coverOwner', 'string');
        $this->addType('status', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('publishAt', 'integer');
        $this->addType('deleteAt', 'integer');
        $this->addType('optActivity', 'boolean');
        $this->addType('optNotification', 'boolean');
        $this->addType('optEmail', 'boolean');
        $this->addType('optComments', 'boolean');
        $this->addType('optTalk', 'boolean');
    }

    /** Kapak dosyasının hangi kullanıcının alanında arandığı: seçen kişi, yoksa yazar. */
    public function getCoverFileOwnerUid(): string {
        $owner = $this->getCoverOwner();

        return $owner !== null && $owner !== '' ? $owner : $this->getAuthorUid();
    }

    /**
     * Duyurunun şu an okunabilir olup olmadığı (K5).
     * Zamanlama kuralı tek yerde dursun ki liste, detay, widget ve arama
     * aynı cevabı versin.
     */
    public function isVisibleAt(int $now): bool {
        if ($this->getStatus() !== self::STATUS_PUBLISHED) {
            return false;
        }
        if ($this->getPublishAt() !== null && $this->getPublishAt() > $now) {
            return false;
        }
        if ($this->getDeleteAt() !== null && $this->getDeleteAt() <= $now) {
            return false;
        }
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'authorUid' => $this->getAuthorUid(),
            'subject' => $this->getSubject(),
            'message' => $this->getMessage(),
            'category' => $this->getCategory(),
            'priority' => $this->getPriority(),
            'pinned' => (bool)$this->getPinned(),
            'coverFileId' => $this->getCoverFileId(),
            'coverX' => $this->getCoverX(),
            'coverY' => $this->getCoverY(),
            'status' => $this->getStatus(),
            'createdAt' => $this->getCreatedAt(),
            'publishAt' => $this->getPublishAt(),
            'deleteAt' => $this->getDeleteAt(),
            'options' => [
                'activity' => (bool)$this->getOptActivity(),
                'notification' => (bool)$this->getOptNotification(),
                'email' => (bool)$this->getOptEmail(),
                'comments' => (bool)$this->getOptComments(),
                'talk' => (bool)$this->getOptTalk(),
            ],
        ];
    }
}
