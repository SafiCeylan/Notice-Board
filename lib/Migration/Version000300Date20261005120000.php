<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Kapak konumu (5 Eki 2026).
 *
 * Kapak görseli çerçeveye `object-fit: cover` ile sığdırılıyor; görselin oranı
 * çerçeveden farklıysa bir kısmı kırpılır. Hangi bölgenin görüneceğini yayıncı
 * seçebilsin diye yüzde cinsinden yatay/dikey konum tutulur (CSS `object-position`).
 *
 * İkisi de boş bırakılabilir: null = orta (50 %), yani eski duyurular aynen görünür.
 */
class Version000300Date20261005120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('noticeboard_posts')) {
            return null;
        }

        $table = $schema->getTable('noticeboard_posts');
        $changed = false;

        foreach (['cover_x', 'cover_y'] as $column) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, Types::SMALLINT, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
