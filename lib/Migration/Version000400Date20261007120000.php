<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kapak sahibi (7 Eki 2026).
 *
 * Kapak görseli onu SEÇEN kişinin dosyalarından servis edilir. Yönetici başkasının
 * duyurusunda kapağı değiştirince dosya yönetici alanındadır; kim olduğunu
 * bilmeden görsel bulunamıyordu. null = yazar (eski duyurular aynen çalışır).
 */
class Version000400Date20261007120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('noticeboard_posts')) {
            return null;
        }

        $table = $schema->getTable('noticeboard_posts');
        if ($table->hasColumn('cover_owner')) {
            return null;
        }

        $table->addColumn('cover_owner', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);

        return $schema;
    }
}
