<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Ek sahibi (7 Eki 2026) — kapak sahibinin (Version000400) eklerdeki karşılığı.
 *
 * Ek onu EKLEYENİN dosyalarından servis edilir; yönetici başkasının duyurusuna
 * kendi dosyasını eklediğinde kimin alanında aranacağını bilmek gerekiyor.
 * null = yazar (eski ekler aynen çalışır).
 *
 * ⚠️ `$table` NC 34'e kadar Doctrine tablosu, NC 35'ten itibaren OCP\DB\Schema\ITable;
 * yalnız ikisinde de olan `hasColumn`/`addColumn` kullanılır.
 */
class Version000401Date20261007140000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('noticeboard_files')) {
            return null;
        }

        $table = $schema->getTable('noticeboard_files');
        if ($table->hasColumn('owner_uid')) {
            return null;
        }

        $table->addColumn('owner_uid', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);

        return $schema;
    }
}
