<?php

declare(strict_types=1);

/**
 * Test altyapısı — Nextcloud KURULU OLMADAN çalışır.
 *
 * Neden böyle: bu projede testin işi, iş kurallarını (durum türetme, doğrulama,
 * görünürlük) kilitlemek. Bunlar veritabanına değil saf mantığa bağlı. Nextcloud'un
 * tamamını ayağa kaldırmak yerine dokunduğumuz OCP sınıflarının küçük ikizlerini
 * burada tanımlıyoruz; gerçek sınıflar hiç yüklenmiyor.
 *
 * ⚠️ Bu ikizler gerçek Nextcloud davranışını TAKLİT eder, garanti etmez. OCP tarafı
 * değişirse testler yeşil kalıp canlı kırılabilir — sözleşmeyi değiştiren bir NC
 * yükseltmesinden sonra buraya bakılır.
 */

// ---------------------------------------------------------------------------
// OCP ikizleri
// ---------------------------------------------------------------------------

namespace OCP\AppFramework\Db {

    class DoesNotExistException extends \Exception {
    }

    /**
     * NC'nin Entity sınıfının küçültülmüş hali: sihirli get/set + tip dönüşümü.
     */
    abstract class Entity {
        public $id = null;
        private array $_fieldTypes = [];
        private array $_updatedFields = [];

        public function getId(): ?int {
            return $this->id === null ? null : (int)$this->id;
        }

        public function setId(int $id): void {
            $this->id = $id;
        }

        protected function addType(string $field, string $type): void {
            $this->_fieldTypes[$field] = $type;
        }

        public function resetUpdatedFields(): void {
            $this->_updatedFields = [];
        }

        /**
         * NC'deki gibi: QBMapper::insert() YALNIZ bu alanları yazar. Set edilmemiş
         * alan, sınıftaki varsayılanı değil KOLONUN varsayılanını alır.
         *
         * @return array<string, bool>
         */
        public function getUpdatedFields(): array {
            return $this->_updatedFields;
        }

        public function __call(string $method, array $args) {
            if (str_starts_with($method, 'get')) {
                $property = lcfirst(substr($method, 3));
                return $this->$property;
            }

            if (str_starts_with($method, 'set')) {
                $property = lcfirst(substr($method, 3));
                $value = $args[0];
                // NC'deki gibi (Entity::setter): değer AYNIYSA alan işaretlenmez ve
                // INSERT/UPDATE onu yazmaz. İkiz bunu taklit etmeyince Hata #47 kaçtı.
                if ($value === $this->$property) {
                    return null;
                }
                // NC'de olduğu gibi: null asla dönüştürülmez, tip korunur.
                if ($value !== null && isset($this->_fieldTypes[$property])) {
                    settype($value, $this->_fieldTypes[$property]);
                }
                $this->$property = $value;
                $this->_updatedFields[$property] = true;
                return null;
            }

            throw new \BadMethodCallException("Bilinmeyen metod: $method");
        }
    }
}

namespace OCP\AppFramework\Utility {

    interface ITimeFactory {
        public function getTime(): int;
    }
}

namespace OCP {

    interface IUser {
        public function getUID(): string;
        public function getDisplayName(): string;
    }

    interface IGroupManager {
        public function isAdmin(string $uid): bool;
        /** @return string[] */
        public function getUserGroupIds(IUser $user): array;
    }

    interface IAppConfig {
        public function getValueArray(string $app, string $key, array $default = []): array;
        public function setValueArray(string $app, string $key, array $value): bool;
        public function getValueInt(string $app, string $key, int $default = 0): int;
        public function setValueInt(string $app, string $key, int $value): bool;
        public function getValueString(string $app, string $key, string $default = ''): string;
        public function setValueString(string $app, string $key, string $value): bool;
    }

    interface IL10N {
        public function t(string $text, $parameters = []): string;
    }
}

namespace OCP\Comments {

    class NotFoundException extends \Exception {
    }

    interface IComment {
        public function getId(): string;
        public function getActorType(): string;
        public function getActorId(): string;
        public function getObjectType(): string;
        public function getObjectId(): string;
        public function getMessage(): string;
        public function setMessage(string $message, int $maxLength = 1000): IComment;
        public function getVerb(): string;
        public function setVerb(string $verb): IComment;
        public function getCreationDateTime(): \DateTime;
    }

    interface ICommentsManager {
        public function create(string $actorType, string $actorId, string $objectType, string $objectId): IComment;
        public function save(IComment $comment): void;
        public function get(string $id): IComment;
        public function delete(string $id): bool;
        /** @return IComment[] */
        public function getForObject(string $objectType, string $objectId, int $limit = 0, int $offset = 0): array;
        public function getNumberOfCommentsForObject(string $objectType, string $objectId): int;
        public function deleteCommentsAtObject(string $objectType, string $objectId): bool;
    }
}

namespace OCP {

    interface IUserManager {
        public function get(string $uid): ?IUser;
        public function getDisplayName(string $uid): ?string;
    }
}

namespace OCA\NoticeBoard\AppInfo {

    /**
     * Gerçek Application sınıfı OCP\AppFramework\App'ten türüyor ve Nextcloud'un
     * yarısını yükler. Servisler ondan yalnızca APP_ID sabitini kullanıyor;
     * testlerde sabiti veren bu ikiz yeterli.
     *
     * ⚠️ Bu yüzden testlerde `lib/AppInfo/Application.php` ASLA require edilmez.
     */
    class Application {
        public const APP_ID = 'noticeboard';
    }
}

namespace OCA\NoticeBoard\Tests {

    /** Testlerde kullanılan basit kullanıcı ikizi. */
    class FakeUser implements \OCP\IUser {
        public function __construct(private string $uid) {
        }

        public function getUID(): string {
            return $this->uid;
        }

        public function getDisplayName(): string {
            return $this->uid;
        }
    }

    /** Admin listesi ve grup üyelikleri elle verilen grup yöneticisi ikizi. */
    class FakeGroupManager implements \OCP\IGroupManager {
        /**
         * @param string[] $admins
         * @param array<string, string[]> $memberships uid => gid listesi
         */
        public function __construct(
            private array $admins = [],
            private array $memberships = [],
        ) {
        }

        public function isAdmin(string $uid): bool {
            return in_array($uid, $this->admins, true);
        }

        public function getUserGroupIds(\OCP\IUser $user): array {
            return $this->memberships[$user->getUID()] ?? [];
        }
    }

    /** Görünen adları elle verilen kullanıcı yöneticisi; bilinmeyen kullanıcıda null (silinmiş hesap). */
    class FakeDisplayNames implements \OCP\IUserManager {
        /** @param array<string, string> $names uid => görünen ad */
        public function __construct(private array $names = []) {
        }

        public function get(string $uid): ?\OCP\IUser {
            return isset($this->names[$uid]) ? new FakeUser($uid) : null;
        }

        public function getDisplayName(string $uid): ?string {
            return $this->names[$uid] ?? null;
        }
    }

    /** Bellekte tutan uygulama ayarı ikizi. */
    class FakeAppConfig implements \OCP\IAppConfig {
        private array $store = [];

        public function getValueArray(string $app, string $key, array $default = []): array {
            return $this->store["$app:$key"] ?? $default;
        }

        public function setValueArray(string $app, string $key, array $value): bool {
            $this->store["$app:$key"] = $value;
            return true;
        }

        public function getValueInt(string $app, string $key, int $default = 0): int {
            return $this->store["$app:$key"] ?? $default;
        }

        public function setValueInt(string $app, string $key, int $value): bool {
            $this->store["$app:$key"] = $value;
            return true;
        }

        public function getValueString(string $app, string $key, string $default = ''): string {
            return $this->store["$app:$key"] ?? $default;
        }

        public function setValueString(string $app, string $key, string $value): bool {
            $this->store["$app:$key"] = $value;
            return true;
        }
    }
}

// ---------------------------------------------------------------------------
// Test koşum yardımcıları
// ---------------------------------------------------------------------------

namespace {

    // Kullanıcıya gösterilen istisnaların tabanı; Validation/AccessDenied bundan
    // türediği için onları require eden her testten ÖNCE yüklenmeli.
    require_once __DIR__ . '/../lib/Exception/TranslatableException.php';

    $GLOBALS['nb_passed'] = 0;
    $GLOBALS['nb_failed'] = 0;

    function check(string $name, bool $condition): void {
        if ($condition) {
            $GLOBALS['nb_passed']++;
            echo "  [ok] $name\n";
        } else {
            $GLOBALS['nb_failed']++;
            echo "  [HATA] $name\n";
        }
    }

    /** Verilen çağrının ValidationException fırlattığını doğrular. */
    function checkThrows(string $name, callable $fn): void {
        try {
            $fn();
            check($name, false);
        } catch (\OCA\NoticeBoard\Exception\ValidationException) {
            check($name, true);
        }
    }

    function finish(string $suite): void {
        $passed = $GLOBALS['nb_passed'];
        $failed = $GLOBALS['nb_failed'];
        echo "\n$suite: $passed gecti, $failed kaldi\n";
        exit($failed === 0 ? 0 : 1);
    }
}
