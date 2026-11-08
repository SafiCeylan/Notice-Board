<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Talk kanalı (12 Eki 2026) — K6'nın beşinci anahtarı `opt_talk`.
 *
 * Açıksa duyuru yayına çıktığında hedeflenen kişilere "Duyuru Panosu" hesabından
 * Talk özel mesajı gider (TalkService). Eski duyurular için varsayılan KAPALI:
 * yazarları bu kanalı hiç görmedi; zamanlanmış eski bir duyuru yayına çıkınca
 * kimseye beklenmedik mesaj gitmesin.
 *
 * ⚠️ Boolean kolon `notnull => false` (NC kuralı: Oracle'da boş metin = NULL);
 * diğer opt_* kolonlarıyla aynı.
 */
class Version000500Date20261012120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('noticeboard_posts')) {
            return null;
        }

        $table = $schema->getTable('noticeboard_posts');
        if ($table->hasColumn('opt_talk')) {
            return null;
        }

        $table->addColumn('opt_talk', Types::BOOLEAN, ['notnull' => false, 'default' => false]);

        return $schema;
    }
}
