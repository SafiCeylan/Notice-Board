<?php

declare(strict_types=1);

namespace OCA\NoticeBoard\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Faz 1 — ilk şema.
 *
 * ⚠️ TABLO ADI SINIRI — 23 Eyl 2026'da canlıda öğrenildi (Hata Günlüğü #7):
 * Nextcloud birincil indeks adını `<tam_tablo_adı>_seq` diye üretir ve bunun
 * 30 karakteri aşmasına izin vermez. Yani:
 *
 *     strlen('oc_' + tablo_adı) + 5 <= 30   →   tablo adı en fazla 22 karakter
 *
 * İlk denemede `noticeboard_announcements` (25) ve `noticeboard_attachments` (23)
 * yazılmıştı; ikisi de sınırı aştı ve migration canlıda hata verdi. Kısaltıldı:
 *   noticeboard_posts = 17 ✓   noticeboard_groups = 18 ✓   noticeboard_files = 17 ✓
 *
 * ❌ Okundu takibi tablosu bilinçli olarak YOK (karar K2).
 */
class Version000100Date20260923120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('noticeboard_posts')) {
            $table = $schema->createTable('noticeboard_posts');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('author_uid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('subject', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            // Zengin metin. Sanitize edilmiş HTML tutulur — ham kullanıcı HTML'i
            // asla doğrudan yazılmaz (bkz. Faz 10 güvenlik denetimi).
            $table->addColumn('message', Types::TEXT, [
                'notnull' => true,
                'default' => '',
            ]);
            $table->addColumn('category', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            // 0 = normal, 1 = önemli, 2 = acil
            $table->addColumn('priority', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('pinned', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            $table->addColumn('cover_file_id', Types::BIGINT, [
                'notnull' => false,
                'length' => 20,
            ]);
            // draft | scheduled | published | archived
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 16,
                'default' => 'draft',
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
                'length' => 20,
            ]);
            // null → hemen yayında (K5)
            $table->addColumn('publish_at', Types::BIGINT, [
                'notnull' => false,
                'length' => 20,
            ]);
            // null → süresiz (K5)
            $table->addColumn('delete_at', Types::BIGINT, [
                'notnull' => false,
                'length' => 20,
            ]);
            // Duyuru başına dağıtım anahtarları (K6)
            $table->addColumn('opt_activity', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
            $table->addColumn('opt_notification', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
            $table->addColumn('opt_email', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
            $table->addColumn('opt_comments', Types::BOOLEAN, ['notnull' => false, 'default' => true]);

            $table->setPrimaryKey(['id']);
            // Liste sorgusu: yayındakileri tarihe göre sıralar.
            $table->addIndex(['status', 'publish_at'], 'nb_ann_status_pub_idx');
            // Zamanlanmış iş: süresi dolanları tarar (Faz 6).
            $table->addIndex(['delete_at'], 'nb_ann_delete_idx');
            $table->addIndex(['author_uid'], 'nb_ann_author_idx');
        }

        // Hedefleme (K3): bir duyuru için burada SATIR YOKSA herkese açıktır.
        if (!$schema->hasTable('noticeboard_groups')) {
            $table = $schema->createTable('noticeboard_groups');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('announcement_id', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('gid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['announcement_id'], 'nb_grp_ann_idx');
            $table->addIndex(['gid'], 'nb_grp_gid_idx');
        }

        // Dosya ekleri (K4). Nextcloud dosyasına referans tutulur, kopya tutulmaz.
        if (!$schema->hasTable('noticeboard_files')) {
            $table = $schema->createTable('noticeboard_files');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('announcement_id', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('file_id', Types::BIGINT, [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('file_name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['announcement_id'], 'nb_att_ann_idx');
        }

        return $schema;
    }
}
