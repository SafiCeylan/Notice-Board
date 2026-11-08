<?php

declare(strict_types=1);

/**
 * TAM TEMİZLİK — Duyuru Panosu'nun Nextcloud'da bıraktığı HER izi siler.
 *
 * NEDEN: Nextcloud bir app'i kaldırırken (web'de "Kaldır" ya da `occ app:remove`)
 * YALNIZ app klasörünü siler (`Installer::removeApp` → rmdir). Tablolar, migration
 * kayıtları, ayarlar, arka plan işleri, bildirim/etkinlik/yorum satırları ve
 * kullanıcıların pano düzeni kalır. Kalıntı yeniden kurulumu bozar:
 *   · migration kaydı kalıp tablo giderse NC migration'ları "zaten koştu" sayar,
 *     tablolar HİÇ oluşmaz, app ilk istekte "tablo yok" hatasıyla düşer;
 *   · sürüm kaydı (installed_version) kalırsa yeni kurulum "güncelleme" sanılır;
 *   · eski arka plan işi / bildirim / pano düzeni yeni kuruluma geri döner.
 * Bu betikten sonra yeniden kurulum gerçekten SIFIRDAN olur.
 *
 * Neden info.xml `<uninstall>` onarım adımı DEĞİL: Nextcloud o adımları her
 * `app:disable`'da koşar (AppManager::disableApp). install.sh güncellemede app'i
 * kapatıp açıyor — orada silme yapılsaydı her güncelleme tüm duyuruları silerdi.
 *
 * App kodu YÜKLENMEZ, `OCA\NoticeBoard` sınıfı kullanılmaz: klasör zaten silinmiş
 * olabilir (web'den kaldırılmış, eski 0.3.0 kurulumu...). Yalnız çekirdek + sabit adlar.
 * Yeni tablo / widget / iş eklenirse buradaki listeler de güncellenmeli —
 * tests/PurgeContractTest.php kaynakla karşılaştırıp kilitler.
 *
 * Kullanıcıların Dosyalar'ındaki "Duyuru kapakları" klasörlerine DOKUNULMAZ:
 * kullanıcı dosyasıdır, app onları okumaz; yalnız raporlanır.
 *
 * install.sh --uninstall tarafından /tmp'ye kopyalanıp web kullanıcısıyla koşar.
 *   php purge.php <Nextcloud kökü> [--dry-run]
 * Çıkış: 0 = iz kalmadı (dry-run: sayım yapıldı) · 1 = hata ya da iz kaldı ·
 *        2 = kullanım hatası · 3 = app hâlâ etkin (önce `occ app:disable noticeboard`)
 * Son satır her zaman `TOPLAM: <n>` — install.sh bunu okur.
 */

use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;

const NB_APP = 'noticeboard';
// lib/Migration'daki her createTable() — PurgeContractTest kilitler.
const NB_TABLOLAR = ['noticeboard_files', 'noticeboard_groups', 'noticeboard_posts'];
// lib/Dashboard'daki her WIDGET_ID — PurgeContractTest kilitler.
const NB_WIDGETLAR = ['noticeboard-list', 'noticeboard-card', 'noticeboard-spotlight'];
// lib/BackgroundJob altındaki her sınıf bu önekle başlar (ScheduleJob, kuyruktaki SendMailJob).
const NB_IS_ONEKI = 'OCA\\NoticeBoard\\';
// Activity app kullanıcı ayarı: 'notify_<yöntem>_<Activity\Setting::getIdentifier()>'.
const NB_ETKINLIK_AYARLARI = ['notify_email_noticeboard', 'notify_notification_noticeboard', 'notify_stream_noticeboard'];
// Kapak düzenleyicisinin yükleme klasörü (kullanıcının diline göre adı değişir).
const NB_KAPAK_KLASORLERI = ['Duyuru kapakları', 'Announcement covers'];
// Talk mesajlarını gönderen, app'in kendi açtığı hesap (TalkService::BOT_UID) — PurgeContractTest kilitler.
const NB_TALK_HESABI = 'noticeboard-bot';

$argumanlar = array_slice($argv, 1);
$dry = in_array('--dry-run', $argumanlar, true);
$ncKok = array_values(array_filter($argumanlar, static fn ($a) => !str_starts_with($a, '--')))[0] ?? '';
if ($ncKok === '' || !is_file(rtrim($ncKok, '/') . '/lib/base.php')) {
    fwrite(STDERR, "kullanim: php purge.php <Nextcloud koku> [--dry-run]\n");
    exit(2);
}
require rtrim($ncKok, '/') . '/lib/base.php';

$db = Server::get(IDBConnection::class);
$config = Server::get(IConfig::class);
$appConfig = Server::get(IAppConfig::class);
$appManager = Server::get(IAppManager::class);

// Etkin app'in altından tablo çekilirse açık istekler ve cron yarıda patlar.
if (!$dry && $appManager->isEnabledForAnyone(NB_APP)) {
    fwrite(STDERR, "HATA: noticeboard hala etkin. Once: occ app:disable noticeboard\n");
    exit(3);
}

/**
 * Tek sayım/silme yolu. Koşulsuz DELETE başka uygulamaların tablosunu (comments,
 * jobs, activity, notifications...) BOŞALTIR; bu yüzden WHERE'siz sorgu çalışmaz.
 * Dosyadaki tek `->delete(` burasıdır — PurgeContractTest kilitler.
 *
 * @param callable(IQueryBuilder): void $kosul
 * @return int silmeden ÖNCEKİ satır sayısı
 */
function nb_satirlar(IDBConnection $db, string $tablo, callable $kosul, bool $sil): int {
    if (!$db->tableExists($tablo)) {
        return 0;
    }
    $qb = $db->getQueryBuilder();
    $qb->select($qb->func()->count('*', 'n'))->from($tablo);
    $kosul($qb);
    $sonuc = $qb->executeQuery();
    $n = (int)$sonuc->fetchOne();
    $sonuc->closeCursor();

    if ($sil && $n > 0) {
        $qb = $db->getQueryBuilder();
        $qb->delete($tablo);
        $kosul($qb);
        if (!str_contains($qb->getSQL(), ' WHERE ')) {
            throw new \LogicException("$tablo icin kosulsuz silme reddedildi");
        }
        $qb->executeStatement();
    }
    return $n;
}

/** @return list<array<string, mixed>> */
function nb_oku(IDBConnection $db, string $tablo, array $sutunlar, callable $kosul): array {
    if (!$db->tableExists($tablo)) {
        return [];
    }
    $qb = $db->getQueryBuilder();
    $qb->select(...$sutunlar)->from($tablo);
    $kosul($qb);
    $sonuc = $qb->executeQuery();
    $satirlar = $sonuc->fetchAll();
    $sonuc->closeCursor();
    return $satirlar;
}

/** Virgüllü pano düzeninden bizim widget'larımızı çıkarır; değişmediyse null. */
function nb_duzenden_cikar(string $duzen): ?string {
    $hepsi = explode(',', $duzen);
    $kalan = array_values(array_filter($hepsi, static fn ($id) => !in_array(trim($id), NB_WIDGETLAR, true)));
    return count($kalan) === count($hepsi) ? null : implode(',', $kalan);
}

$esit = static fn (string $sutun, string $deger) => static function (IQueryBuilder $qb) use ($sutun, $deger): void {
    $qb->where($qb->expr()->eq($sutun, $qb->createNamedParameter($deger)));
};

/*
 * Her adım: function(bool $sil): int — silmeden önce bulduğu iz sayısını döner.
 * Silmeden sonra hepsi $sil=false ile yeniden koşulur ve 0 beklenir (doğrulama).
 * Sıra önemli: yorumların tepkileri yorumlardan, tablolar en son silinir.
 */
$adimlar = [
    'yorumlar (+okundu isaretleri, tepkiler)' => function (bool $sil) use ($db, $esit): int {
        $idler = array_map(
            static fn ($r) => (int)$r['id'],
            nb_oku($db, 'comments', ['id'], $esit('object_type', NB_APP))
        );
        $n = 0;
        foreach (array_chunk($idler, 500) as $parca) {
            $n += nb_satirlar($db, 'reactions', static function (IQueryBuilder $qb) use ($parca): void {
                $qb->where($qb->expr()->in('parent_id', $qb->createNamedParameter($parca, IQueryBuilder::PARAM_INT_ARRAY)));
            }, $sil);
        }
        $n += nb_satirlar($db, 'comments', $esit('object_type', NB_APP), $sil);
        $n += nb_satirlar($db, 'comments_read_markers', $esit('object_type', NB_APP), $sil);
        return $n;
    },

    'bildirimler' => function (bool $sil) use ($db, $esit, $appManager): int {
        $n = nb_satirlar($db, 'notifications', $esit('app', NB_APP), false);
        if ($sil && $n > 0) {
            // Önce resmi yol: telefonlara "sil" push'u da gider. Olmazsa SQL süpürür.
            try {
                if ($appManager->isEnabledForAnyone('notifications')) {
                    $appManager->loadApp('notifications');
                    $yonetici = Server::get(\OCP\Notification\IManager::class);
                    $bildirim = $yonetici->createNotification();
                    $bildirim->setApp(NB_APP);
                    $yonetici->markProcessed($bildirim);
                }
            } catch (\Throwable $e) {
                echo '   (bildirim API: ' . $e->getMessage() . " — SQL ile silinecek)\n";
            }
            nb_satirlar($db, 'notifications', $esit('app', NB_APP), true);
        }
        return $n;
    },

    'etkinlik akisi (+e-posta kuyrugu)' => function (bool $sil) use ($db, $esit): int {
        return nb_satirlar($db, 'activity', $esit('app', NB_APP), $sil)
            + nb_satirlar($db, 'activity_mq', $esit('amq_appid', NB_APP), $sil);
    },

    'arka plan isleri' => function (bool $sil) use ($db): int {
        // LIKE yerine PHP süzgeci: ters bölünün LIKE kaçışı veritabanına göre değişiyor.
        $qb = $db->getQueryBuilder();
        $sonuc = $qb->selectDistinct('class')->from('jobs')->executeQuery();
        $siniflar = array_values(array_filter(
            $sonuc->fetchAll(\PDO::FETCH_COLUMN),
            static fn ($s) => str_starts_with((string)$s, NB_IS_ONEKI)
        ));
        $sonuc->closeCursor();
        if ($siniflar === []) {
            return 0;
        }
        return nb_satirlar($db, 'jobs', static function (IQueryBuilder $qb) use ($siniflar): void {
            $qb->where($qb->expr()->in('class', $qb->createNamedParameter($siniflar, IQueryBuilder::PARAM_STR_ARRAY)));
        }, $sil);
    },

    'kullanici ayarlari (app + etkinlik tercihleri)' => function (bool $sil) use ($db, $config, $esit): int {
        $n = nb_satirlar($db, 'preferences', $esit('appid', NB_APP), false);
        if ($sil && $n > 0) {
            $config->deleteAppFromAllUsers(NB_APP);
        }
        $tercihler = nb_oku($db, 'preferences', ['userid', 'configkey'], static function (IQueryBuilder $qb): void {
            $qb->where($qb->expr()->eq('appid', $qb->createNamedParameter('activity')))
                ->andWhere($qb->expr()->in('configkey', $qb->createNamedParameter(NB_ETKINLIK_AYARLARI, IQueryBuilder::PARAM_STR_ARRAY)));
        });
        foreach ($tercihler as $t) {
            if ($sil) {
                $config->deleteUserValue((string)$t['userid'], 'activity', (string)$t['configkey']);
            }
        }
        return $n + count($tercihler);
    },

    'kullanici pano duzenleri (widget kayitlari)' => function (bool $sil) use ($db, $config): int {
        $n = 0;
        $duzenler = nb_oku($db, 'preferences', ['userid', 'configvalue'], static function (IQueryBuilder $qb): void {
            $qb->where($qb->expr()->eq('appid', $qb->createNamedParameter('dashboard')))
                ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('layout')));
        });
        foreach ($duzenler as $d) {
            $yeni = nb_duzenden_cikar((string)$d['configvalue']);
            if ($yeni === null) {
                continue;
            }
            $n++;
            if ($sil) {
                // Yalnız bizimkiler varsa kayıt silinir: kullanıcı boş pano değil varsayılanı görür.
                $yeni === ''
                    ? $config->deleteUserValue((string)$d['userid'], 'dashboard', 'layout')
                    : $config->setUserValue((string)$d['userid'], 'dashboard', 'layout', $yeni);
            }
        }
        return $n;
    },

    'kullanici menu siralamasi' => function (bool $sil) use ($db, $config): int {
        $n = 0;
        $siralar = nb_oku($db, 'preferences', ['userid', 'configvalue'], static function (IQueryBuilder $qb): void {
            $qb->where($qb->expr()->eq('appid', $qb->createNamedParameter('core')))
                ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('apporder')));
        });
        foreach ($siralar as $s) {
            $sira = json_decode((string)$s['configvalue'], true);
            if (!is_array($sira)) {
                continue;  // bozuk kayda dokunma — bizim değil
            }
            $kalan = array_filter(
                $sira,
                static fn ($v, $k) => $k !== NB_APP && !(is_array($v) && ($v['app'] ?? null) === NB_APP),
                ARRAY_FILTER_USE_BOTH
            );
            if (count($kalan) === count($sira)) {
                continue;
            }
            $n++;
            if ($sil) {
                $kalan === []
                    ? $config->deleteUserValue((string)$s['userid'], 'core', 'apporder')
                    : $config->setUserValue((string)$s['userid'], 'core', 'apporder', json_encode($kalan));
            }
        }
        return $n;
    },

    'yonetici varsayilan pano duzeni' => function (bool $sil) use ($appConfig): int {
        try {
            if (!$appConfig->hasKey('dashboard', 'layout')) {
                return 0;
            }
            $yeni = nb_duzenden_cikar($appConfig->getValueString('dashboard', 'layout'));
            if ($yeni === null) {
                return 0;
            }
            if ($sil) {
                $yeni === ''
                    ? $appConfig->deleteKey('dashboard', 'layout')
                    : $appConfig->setValueString('dashboard', 'layout', $yeni);
            }
            return 1;
        } catch (\Throwable $e) {
            echo '   (varsayilan pano duzeni okunamadi: ' . $e->getMessage() . ")\n";
            return 0;
        }
    },

    // Hesap silinince Talk (etkinse) onu sohbetlerden kendisi çıkarır: duyuru sohbetleri
    // (0.5.2'den önce bire bir) kişide kilitli hâlde kalır, kişi "Sohbetten ayrıl" ile
    // kaldırabilir. Kişilere gitmiş mesajlar Talk'un verisidir, dokunulmaz.
    // Bu SQL silmesi değil, çekirdeğin hesap silme yolu (dosyalar, tercihler, avatar dahil).
    'Talk duyuru hesabi (' . NB_TALK_HESABI . ')' => function (bool $sil): int {
        $hesap = Server::get(\OCP\IUserManager::class)->get(NB_TALK_HESABI);
        if ($hesap === null) {
            return 0;
        }
        if ($sil && !$hesap->delete()) {
            throw new \RuntimeException(NB_TALK_HESABI . ' hesabi silinemedi');
        }
        return 1;
    },

    // installed_version + enabled dahil: yeniden kurulum "güncelleme" değil İLK KURULUM olur.
    'app ayarlari (surum kaydi, yayincilar, vitrin)' => function (bool $sil) use ($db, $appConfig, $esit): int {
        $n = nb_satirlar($db, 'appconfig', $esit('appid', NB_APP), false);
        if ($sil && $n > 0) {
            $appConfig->deleteApp(NB_APP);
        }
        return $n;
    },

    // Tablolar gidip bu kayıt kalırsa yeniden kurulum tabloları OLUŞTURMAZ (bkz. dosya başı).
    'migration kayitlari' => function (bool $sil) use ($db, $esit): int {
        return nb_satirlar($db, 'migrations', $esit('app', NB_APP), $sil);
    },

    'tablolar (duyurular, hedef gruplar, ekler)' => function (bool $sil) use ($db): int {
        $n = 0;
        foreach (NB_TABLOLAR as $tablo) {
            if ($db->tableExists($tablo)) {
                $n++;
                if ($sil) {
                    $db->dropTable($tablo);
                }
            }
        }
        return $n;
    },
];

$hata = false;
$bulunan = [];
echo $dry ? "   Bulunan izler (dry-run, hicbir sey silinmedi):\n" : "   Siliniyor:\n";
foreach ($adimlar as $ad => $adim) {
    try {
        $bulunan[$ad] = $adim(!$dry);
        printf("   %-48s %d\n", $ad, $bulunan[$ad]);
    } catch (\Throwable $e) {
        $hata = true;
        $bulunan[$ad] = 1;
        echo "   HATA $ad: " . $e->getMessage() . "\n";
    }
}

// Bilgi: kullanıcı dosyası, silinmez. Yalnız kök dizindeki klasör aranır (düzenleyici oraya yükler).
try {
    $klasorler = nb_oku($db, 'filecache', ['storage'], static function (IQueryBuilder $qb): void {
        $qb->where($qb->expr()->in('path', $qb->createNamedParameter(
            array_map(static fn ($k) => 'files/' . $k, NB_KAPAK_KLASORLERI),
            IQueryBuilder::PARAM_STR_ARRAY
        )));
    });
    if ($klasorler !== []) {
        $depolar = nb_oku($db, 'storages', ['id'], static function (IQueryBuilder $qb) use ($klasorler): void {
            $qb->where($qb->expr()->in('numeric_id', $qb->createNamedParameter(
                array_map(static fn ($k) => (int)$k['storage'], $klasorler),
                IQueryBuilder::PARAM_INT_ARRAY
            )));
        });
        $kisiler = array_map(static fn ($d) => preg_replace('/^(home::|object::user:)/', '', (string)$d['id']), $depolar);
        echo '   Bilgi: ' . count($kisiler) . " kullanicinin Dosyalar'inda kapak klasoru var (kullanici dosyasi, SILINMEDI): "
            . implode(', ', array_slice($kisiler, 0, 10)) . (count($kisiler) > 10 ? ', ...' : '') . "\n";
    }
} catch (\Throwable $e) {
    // Yalnız bilgi amaçlı; temizliği etkilemez.
}

if (!$dry && !$hata) {
    echo "   Dogrulama (yeniden sayim):\n";
    foreach ($adimlar as $ad => $adim) {
        try {
            $kalan = $adim(false);
        } catch (\Throwable $e) {
            $kalan = 1;
            echo "   HATA $ad: " . $e->getMessage() . "\n";
        }
        if ($kalan !== 0) {
            $hata = true;
            echo "   KALDI $ad: $kalan\n";
        }
    }
    if (!$hata) {
        echo "   iz kalmadi\n";
    }
}

$toplam = $dry ? array_sum($bulunan) : ($hata ? 1 : 0);
echo "TOPLAM: $toplam\n";
exit($hata ? 1 : 0);
