<?php

declare(strict_types=1);

/**
 * Tam kaldırma sözleşmesi — `purge.php` (install.sh --uninstall) app'in Nextcloud'a
 * yazdığı HER şeyi biliyor mu?
 *
 * NEDEN BU TEST VAR: purge.php app kodunu yüklemez (klasör silinmiş olabilir), bu
 * yüzden tablo, widget, iş ve kimlik adlarını SABİT olarak taşır. Yarın bir migration
 * yeni tablo eklese ya da yeni widget gelse, purge.php güncellenmezse kaldırma
 * "iz kalmadı" der ama tablo kalır — ve sonraki kurulumda o tablonun migration kaydı
 * silinmiş olduğu için NC tabloyu yeniden yaratmaya çalışır ya da eski veriyle açılır.
 * Hiçbir hata alınmaz; bu yüzden kaynak karşılaştırılarak kilitlenir.
 *
 * Ayrıca purge.php'nin TERSİ de kilitlenir: listede bize ait olmayan bir tablo olursa
 * başka bir uygulamanın tablosu düşürülür; koşulsuz DELETE başka uygulamaların
 * satırlarını (comments, jobs, activity...) siler.
 *
 * Çalıştırma:  php tests/PurgeContractTest.php
 */

require __DIR__ . '/bootstrap.php';

$kok = __DIR__ . '/..';

function pc_oku(string $yol): string {
    $s = @file_get_contents($yol);
    return $s === false ? '' : $s;
}

/** Yorumları ayıklar (Hata #22: kuralı anlatan yorum ihlal sanılmasın). */
function pc_kod(string $s): string {
    $s = preg_replace('#/\*.*?\*/#s', '', $s) ?? $s;
    return preg_replace('#^\s*(//|\#).*$#m', '', $s) ?? $s;
}

$purgeHam = pc_oku("$kok/purge.php");
$purge = pc_kod($purgeHam);

/** purge.php'deki `const NB_X = ...;` değerini NC olmadan okur. */
function pc_sabit(string $kaynak, string $ad): mixed {
    if (!preg_match('/^const ' . $ad . ' = (.+?);$/m', $kaynak, $m)) {
        return null;
    }
    return eval('return ' . $m[1] . ';');
}

echo "Tam kaldirma sozlesmesi (purge.php)\n";
check('purge.php var', $purge !== '');

// ---------------------------------------------------------------- kimlik
$application = pc_oku("$kok/lib/AppInfo/Application.php");
preg_match("/const APP_ID = '([^']+)'/", $application, $m);
$appId = $m[1] ?? '';
check("app kimligi okundu: '$appId'", $appId !== '');
check('NB_APP = Application::APP_ID', pc_sabit($purge, 'NB_APP') === $appId);

// ---------------------------------------------------------------- tablolar
$migrationTablolari = [];
foreach (glob("$kok/lib/Migration/*.php") ?: [] as $dosya) {
    preg_match_all("/createTable\('([^']+)'\)/", pc_kod(pc_oku($dosya)), $mm);
    $migrationTablolari = array_merge($migrationTablolari, $mm[1]);
}
$migrationTablolari = array_values(array_unique($migrationTablolari));
sort($migrationTablolari);
$purgeTablolari = (array)pc_sabit($purge, 'NB_TABLOLAR');
sort($purgeTablolari);
check('migration tablolari bulundu (' . count($migrationTablolari) . ')', count($migrationTablolari) >= 3);
check(
    'purge her migration tablosunu dusuruyor: eksik = ' . implode(', ', array_diff($migrationTablolari, $purgeTablolari)),
    array_diff($migrationTablolari, $purgeTablolari) === []
);
check(
    'purge bize ait olmayan tablo dusurmuyor: fazla = ' . implode(', ', array_diff($purgeTablolari, $migrationTablolari)),
    array_diff($purgeTablolari, $migrationTablolari) === []
);
foreach ($purgeTablolari as $t) {
    check("'$t' app onekiyle basliyor", str_starts_with((string)$t, $appId . '_'));
}
check('tablolar dropTable ile ve yalniz NB_TABLOLAR uzerinden dusuruluyor',
    substr_count($purge, 'dropTable(') === 1 && str_contains($purge, 'foreach (NB_TABLOLAR as $tablo)'));

// ---------------------------------------------------------------- widget'lar
$widgetlar = [];
foreach (glob("$kok/lib/Dashboard/*.php") ?: [] as $dosya) {
    if (preg_match("/WIDGET_ID = '([^']+)'/", pc_oku($dosya), $mm)) {
        $widgetlar[] = $mm[1];
    }
}
sort($widgetlar);
$purgeWidgetlar = (array)pc_sabit($purge, 'NB_WIDGETLAR');
sort($purgeWidgetlar);
check('widget kimlikleri bulundu (' . count($widgetlar) . ')', count($widgetlar) === 3);
check('purge her widget kimligini pano duzenlerinden siliyor', $purgeWidgetlar === $widgetlar);

// ---------------------------------------------------------------- arka plan işleri
$onek = (string)pc_sabit($purge, 'NB_IS_ONEKI');
check('is oneki OCA\\NoticeBoard\\', $onek === 'OCA\\NoticeBoard\\');
foreach (glob("$kok/lib/BackgroundJob/*.php") ?: [] as $dosya) {
    preg_match('/^namespace ([^;]+);/m', pc_oku($dosya), $mm);
    $sinif = ($mm[1] ?? '') . '\\' . basename($dosya, '.php');
    check("is sinifi oneke uyuyor: $sinif", str_starts_with($sinif, $onek));
}
preg_match_all('#<job>([^<]+)</job>#', pc_oku("$kok/appinfo/info.xml"), $mm);
foreach ($mm[1] as $sinif) {
    check("info.xml isi oneke uyuyor: $sinif", str_starts_with(trim($sinif), $onek));
}

// ---------------------------------------------------------------- app'in başka yerlere yazdıkları
// Her yazma yolunun kimliği purge'un sildiği kimlikle aynı olmalı.
$comment = pc_kod(pc_oku("$kok/lib/Service/CommentService.php"));
check('yorumlar object_type = APP_ID ile yaziliyor', str_contains($comment, "->create('users', \$user->getUID(), Application::APP_ID,"));
check("purge yorumlari object_type ile siliyor", str_contains($purge, "nb_satirlar(\$db, 'comments', \$esit('object_type', NB_APP), \$sil)"));
check("purge okundu isaretlerini siliyor", str_contains($purge, "nb_satirlar(\$db, 'comments_read_markers', \$esit('object_type', NB_APP), \$sil)"));

check('bildirimler app = APP_ID ile yaziliyor', str_contains(pc_kod(pc_oku("$kok/lib/Service/NotificationService.php")), '->setApp(Application::APP_ID)'));
check("purge bildirimleri app ile siliyor", str_contains($purge, "nb_satirlar(\$db, 'notifications', \$esit('app', NB_APP), true)"));

check('etkinlikler app = APP_ID ile yaziliyor', str_contains(pc_kod(pc_oku("$kok/lib/Service/ActivityService.php")), '->setApp(Application::APP_ID)'));
check("purge etkinlikleri ve e-posta kuyrugunu siliyor",
    str_contains($purge, "nb_satirlar(\$db, 'activity', \$esit('app', NB_APP), \$sil)")
    && str_contains($purge, "nb_satirlar(\$db, 'activity_mq', \$esit('amq_appid', NB_APP), \$sil)"));

// Activity app kullanıcı tercihini 'notify_<yöntem>_<Setting::getIdentifier()>' anahtarıyla saklar.
check('etkinlik ayari kimligi APP_ID',
    (bool)preg_match('/function getIdentifier\(\): string \{\s*return Application::APP_ID;/', pc_oku("$kok/lib/Activity/Setting.php")));
foreach ((array)pc_sabit($purge, 'NB_ETKINLIK_AYARLARI') as $anahtar) {
    check("etkinlik tercihi anahtari '$anahtar' bizim kimligimizle bitiyor", (bool)preg_match('/^notify_[a-z]+_' . preg_quote($appId, '/') . '$/', (string)$anahtar));
}

// Ayarlar ve sürüm kaydı: kalırsa yeniden kurulum "güncelleme" sanılır.
check('ayarlar IAppConfig ile yaziliyor (purge deleteApp ile siliyor)',
    str_contains(pc_oku("$kok/lib/Service/PublisherService.php"), '$this->appConfig->setValueArray(Application::APP_ID')
    && str_contains($purge, '$appConfig->deleteApp(NB_APP)'));
// Migration kaydı kalıp tablo giderse yeniden kurulum tabloları HİÇ oluşturmaz.
check("purge migration kayitlarini siliyor", str_contains($purge, "nb_satirlar(\$db, 'migrations', \$esit('app', NB_APP), \$sil)"));
check('purge kullanici ayarlarini siliyor', str_contains($purge, '$config->deleteAppFromAllUsers(NB_APP)'));

// Talk bot hesabı (0.5.0): app'in açtığı hesap kalırsa yeniden kurulumda eski hesap
// kullanılır; yanlış ad yazılırsa başka birinin hesabı silinir.
$talk = pc_kod(pc_oku("$kok/lib/Service/TalkService.php"));
preg_match("/const BOT_UID = '([^']+)'/", $talk, $mm);
$botUid = $mm[1] ?? '';
check("Talk hesabi adi okundu: '$botUid'", $botUid !== '');
check('NB_TALK_HESABI = TalkService::BOT_UID', pc_sabit($purge, 'NB_TALK_HESABI') === $botUid);
check('Talk hesabi app kimligiyle basliyor (insan hesabiyla karismaz)', str_starts_with($botUid, $appId . '-'));
check('purge yalniz NB_TALK_HESABI hesabini siliyor',
    str_contains($purge, 'Server::get(\OCP\IUserManager::class)->get(NB_TALK_HESABI)')
    && substr_count($purge, 'IUserManager::class') === 1);

// Kapak klasörü (kullanıcı dosyası, silinmez ama raporlanır) — adı çeviriyle aynı olmalı.
$klasorler = (array)pc_sabit($purgeHam, 'NB_KAPAK_KLASORLERI');
$enJson = json_decode(pc_oku("$kok/l10n/en.json"), true);
check("kapak klasoru Turkce adi biliniyor", in_array('Duyuru kapakları', $klasorler, true)
    && str_contains(pc_oku("$kok/src/components/AnnouncementComposer.vue"), "t('noticeboard', 'Duyuru kapakları')"));
check("kapak klasoru Ingilizce adi ceviriyle ayni", in_array($enJson['translations']['Duyuru kapakları'] ?? '?', $klasorler, true));

// ---------------------------------------------------------------- güvenlik
// App kodu yüklenmemeli: klasör silinmiş ya da eski/bozuk sürüm olabilir.
check('purge app sinifi kullanmiyor', !preg_match('/OCA\\\\NoticeBoard\\\\[A-Z]\w*\\\\|use OCA\\\\NoticeBoard/', $purge));
check("purge app'i yuklemiyor", !str_contains($purge, 'loadApp(NB_APP)') && !str_contains($purge, "loadApp('noticeboard')"));
// Koşulsuz DELETE başka uygulamaların satırlarını da siler.
// İkinci `->delete(` SQL değil: Talk bot hesabının çekirdek yoluyla silinmesi (IUser::delete).
check('tek SQL DELETE yolu nb_satirlar', substr_count($purge, '$qb->delete(') === 1);
check('SQL disindaki tek silme Talk hesabinin silinmesi',
    substr_count($purge, '->delete(') === 2 && substr_count($purge, '$hesap->delete()') === 1);
check('kosulsuz DELETE calisma aninda reddediliyor', str_contains($purge, "if (!str_contains(\$qb->getSQL(), ' WHERE ')) {"));
check('etkin app uzerinde silme reddediliyor', str_contains($purge, 'if (!$dry && $appManager->isEnabledForAnyone(NB_APP)) {'));
check('silmeden sonra yeniden sayiyor (dogrulama)', str_contains($purge, 'Dogrulama (yeniden sayim)'));

// Nextcloud <uninstall> adımlarını her app:disable'da koşar; install.sh güncellemede app'i
// kapatıp açtığı için orada silme yapılsaydı her güncelleme tüm duyuruları silerdi.
check("info.xml'de <uninstall> onarim adimi yok (her disable'da kosar)", !str_contains(pc_oku("$kok/appinfo/info.xml"), '<uninstall>'));

// ---------------------------------------------------------------- install.sh / paket
$install = pc_oku("$kok/install.sh");
$kapat = strpos($install, 'do_ "occ app:disable $APP_ID" occ app:disable "$APP_ID"' . "\n\tfor d in");
$temizle = strpos($install, 'purge || die "temizlik');
check('install.sh tam kaldirmada once kapatip sonra temizliyor', $kapat !== false && $temizle !== false && $kapat < $temizle);
check('install.sh --keep-data ile eski davranis korunuyor', str_contains($install, '--keep-data) KEEP_DATA=1'));
check('install.sh yedekleri ve logu da siliyor', str_contains($install, 'rm -rf "$BACKUP_DIR" /tmp/noticeboard-smoke.php') && str_contains($install, 'rm -f "$LOG"'));
check('paket purge.php tasiyor', str_contains(pc_oku("$kok/build-release.sh"), 'cp purge.php "$STAGE/tools/purge.php"'));

// install.sh'ın json() yardımcısı `php -r '... echo <ifade>;'` çalıştırır. İfade yerine deyim
// (foreach) yazılırsa PHP söz dizimi hatası verir, süreç ikamesi hatayı yutar ve betik sessizce
// eksik veriyle devam eder (7 Eki: custom_apps taranmadı, test sunucusundaki dry-run'da görüldü).
// Yalnız gerçek çağrılar (`| json '...'`): yardımcının kendi açıklama satırı çağrı değil (Hata #22).
preg_match_all("/\| json '(.*?)'/s", str_replace("'\$APP_ID'", 'noticeboard', $install), $mm);
check('install.sh json() cagrilari bulundu (' . count($mm[1]) . ')', count($mm[1]) >= 5);
foreach ($mm[1] as $ifade) {
    try {
        eval('return static function ($j) { echo ' . $ifade . '; };');
        $gecerli = true;
    } catch (\ParseError) {
        $gecerli = false;
    }
    check('install.sh json ifadesi gecerli PHP: ' . substr(preg_replace('/\s+/', ' ', $ifade), 0, 60), $gecerli);
}
$cikar = eval('return static function ($j) { return ' . "implode(\"\\n\", array_column((array)\$j, \"path\")) . \"\\n\"" . '; };');
check('apps_paths ifadesi her dizini ayri satirda veriyor',
    $cikar([['path' => '/a', 'writable' => false], ['path' => '/b', 'writable' => true]]) === "/a\n/b\n" && $cikar(null) === "\n");
check('install.sh apps_paths icin bu ifadeyi kullaniyor',
    str_contains($install, "json 'implode(\"\\n\", array_column((array)\$j, \"path\")) . \"\\n\"'"));

finish('PurgeContractTest');
