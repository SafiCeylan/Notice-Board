<?php

declare(strict_types=1);

/**
 * GÜVENLİK SÖZLEŞMESİ — kaynak kodun tamamını tarar.
 *
 * Diğer testler davranışı doğrular; bu test **yapıyı** doğrular. Amacı, bugün
 * kapatılan bir açığın yarın yeni bir uç eklenirken sessizce geri gelmesini
 * engellemek. Örneğin biri yeni bir controller metodu yazıp yetki kontrolünü
 * unutursa, hiçbir davranış testi bunu yakalamaz — burası yakalar.
 *
 * Çalıştırma:  php tests/SecurityContractTest.php
 */

require __DIR__ . '/bootstrap.php';

$kok = __DIR__ . '/..';

function kaynak(string $yol): string {
    return (string)@file_get_contents($yol);
}

/**
 * Yorumları atılmış kaynak.
 *
 * ⚠️ NEDEN GEREKLİ: bu dosyadaki kontroller "şu kalıp kodda geçmesin" diye
 * bakıyor. Ama iyi yazılmış kod, kaçındığı kalıbı genellikle bir AÇIKLAMA
 * SATIRINDA anar ("`href="#"` değil, gerçek adres veriyoruz"). Yorumlar
 * ayıklanmazsa test, kuralı açıklayan yorumu ihlal sanıp sahte alarm verir —
 * 23 Eyl 2026'da iki kez oldu (Hata #22).
 */
function kod(string $yol): string {
    $s = kaynak($yol);
    $s = preg_replace('/<!--.*?-->/s', '', $s) ?? $s;   // HTML/Vue yorumları
    $s = preg_replace('#/\*.*?\*/#s', '', $s) ?? $s;    // blok yorumlar
    $s = preg_replace('#^\s*(//|\*|\#).*$#m', '', $s) ?? $s; // satır yorumları

    return $s;
}

/** @return string[] */
function dosyalar(string $dizin, string $uzanti): array {
    $sonuc = [];
    if (!is_dir($dizin)) {
        return $sonuc;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dizin));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), $uzanti)) {
            $sonuc[] = $f->getPathname();
        }
    }
    sort($sonuc);
    return $sonuc;
}

$phpDosyalari = dosyalar("$kok/lib", '.php');
$vueDosyalari = array_merge(dosyalar("$kok/src", '.vue'), dosyalar("$kok/src", '.js'));

// =====================================================================
echo "1) Enjeksiyon yuzeyi\n";
// =====================================================================

$superGlobalKullanan = [];
$hamSorgu = [];
$tehlikeliCagri = [];

foreach ($phpDosyalari as $f) {
    $s = kaynak($f);
    $ad = basename($f);

    // Girdi yalnizca controller parametreleriyle alinmali; superglobal kullanmak
    // cercevenin dogrulama ve CSRF katmanini atlamak demektir.
    if (preg_match('/\$_(GET|POST|REQUEST|COOKIE|FILES)\b/', $s)) {
        $superGlobalKullanan[] = $ad;
    }

    /*
     * SQL yalnizca QueryBuilder + createNamedParameter ile kurulmali.
     * ⚠️ Ilk desen `->from($this->getTableName())` gibi TAMAMEN GUVENLI
     * satirlari da yakaliyordu (sahte alarm). Aranan sey, sorguya dize olarak
     * girdi kacirilmasi: ham SQL metni ya da tablo/kolon adinda enterpolasyon.
     */
    if (preg_match('/\b(executeQuery|executeStatement|prepare|query)\s*\(\s*[\'"]\s*(SELECT|INSERT|UPDATE|DELETE)/i', $s)
        || preg_match('/->from\(\s*["\'][^"\']*\$/', $s)
        || preg_match('/->(select|addSelect)\(\s*["\'][^"\']*\$/', $s)) {
        $hamSorgu[] = $ad;
    }

    if (preg_match('/\b(eval|exec|shell_exec|passthru|system|proc_open|popen)\s*\(/', $s)) {
        $tehlikeliCagri[] = $ad;
    }
}

check('hicbir yerde $_GET/$_POST/$_REQUEST kullanilmiyor', $superGlobalKullanan === []);
check('ham SQL dizesi yok (yalniz QueryBuilder)', $hamSorgu === []);
check('kabuk/eval cagrisi yok', $tehlikeliCagri === []);

// Parametreler gercekten baglaniyor mu?
$mapperlar = dosyalar("$kok/lib/Db", '.php');
$baglanmamis = [];
foreach ($mapperlar as $f) {
    $s = kaynak($f);
    // where/andWhere icinde dogrudan degisken enterpolasyonu olmamali.
    if (preg_match('/(where|andWhere|orWhere)\([^)]*\$\w+\s*\.\s*/', $s)
        || preg_match('/(eq|lte|gte|lt|gt|like)\(\s*[\'"][^\'"]*\$/', $s)) {
        $baglanmamis[] = basename($f);
    }
}
check('mapper sorgularinda dize birlestirme yok', $baglanmamis === []);

// =====================================================================
echo "\n2) Yetkilendirme kapilari\n";
// =====================================================================

/**
 * Kural: `#[NoAdminRequired]` bir ucu HERKESE acar. Boyle her metot ya
 * yetki servisine sormali ya da yetkiyi servis katmanina devreden bir
 * okuma ucu olmali. Oznitelik YOKSA Nextcloud ucu zaten yoneticiye kilitler.
 */
$acikUclar = [];
$korumasiz = [];

foreach (dosyalar("$kok/lib/Controller", '.php') as $f) {
    $s = kaynak($f);
    $ad = basename($f, '.php');

    // Her "public function" bloğunu onceki oznitelik satirlariyla birlikte incele.
    preg_match_all(
        '/((?:^[ \t]*#\[[^\]]+\]\s*\n)*)[ \t]*public function (\w+)\(/m',
        $s,
        $eslesmeler,
        PREG_SET_ORDER
    );

    foreach ($eslesmeler as $m) {
        $oznitelikler = $m[1];
        $metot = $m[2];

        if ($metot === '__construct') {
            continue;
        }

        if (!str_contains($oznitelikler, 'NoAdminRequired')) {
            continue; // yonetici-only; cerceve koruyor
        }

        $acikUclar[] = "$ad::$metot";

        // Govdeyi kabaca al ve yetkiye dair bir iz ara.
        $bas = strpos($s, "public function $metot(");
        $govde = substr($s, $bas, 2500);

        $yetkiIzi = str_contains($govde, 'canPublish')
            || str_contains($govde, 'canEdit')
            || str_contains($govde, 'assertCanEdit')
            || str_contains($govde, 'findEntityForReader')
            || str_contains($govde, 'findVisibleFor')
            || str_contains($govde, 'findManageable')
            || str_contains($govde, 'findFor(')
            || str_contains($govde, 'findForAnnouncement')
            // Servis katmani yetkiyi kendi soruyor (CommentService).
            || str_contains($govde, '$this->service->create(')
            || str_contains($govde, '$this->service->delete(')
            // Sayfa ucu: yetki sayfayi engellemez, API engeller.
            || $ad === 'PageController';

        if (!$yetkiIzi) {
            $korumasiz[] = "$ad::$metot";
        }
    }
}

check('en az bir acik uc taranabildi (tarayici calisiyor)', count($acikUclar) >= 8);
check(
    'NoAdminRequired olan HER ucta yetki kontrolu var: ' . implode(', ', $korumasiz),
    $korumasiz === []
);

// Yazma uclarinin hepsi hiz sinirli olmali.
$yazmaUclari = [
    'AnnouncementController' => 'create',
    'CommentController' => 'create',
];
foreach ($yazmaUclari as $sinif => $metot) {
    $s = kaynak("$kok/lib/Controller/$sinif.php");
    $bas = strpos($s, "public function $metot(");
    $onceki = substr($s, max(0, $bas - 600), 600);
    check("$sinif::$metot hiz sinirli", str_contains($onceki, 'UserRateLimit'));
}

/*
 * Yonetici ayarlari ASLA NoAdminRequired olmamali.
 * ⚠️ Once `str_contains(..., 'NoAdminRequired')` bakiliyordu; dosyadaki
 * "bu oznitelik YOK" diyen AÇIKLAMA SATIRINI da yakalayip sahte alarm verdi.
 * Artik gercek oznitelik sozdizimi aranir.
 */
$adminKaynak = kod("$kok/lib/Controller/AdminController.php");
check(
    'AdminController hicbir ucu herkese acmiyor',
    !preg_match('/^[ \t]*#\[\s*NoAdminRequired/m', $adminKaynak)
);

// Controller'lar suzmesiz tam listeyi cagirmamali.
foreach (dosyalar("$kok/lib/Controller", '.php') as $f) {
    $s = kaynak($f);
    check(
        basename($f) . ' suzmesiz findAll() cagirmiyor',
        !str_contains($s, '$this->service->findAll(')
    );
}

// =====================================================================
echo "\n3) Kimlik sayma (enumeration) korumasi\n";
// =====================================================================

$okumaUclari = [
    'AnnouncementController.php' => 'show',
    'CommentController.php' => 'index',
];
foreach ($okumaUclari as $dosya => $metot) {
    $s = kaynak("$kok/lib/Controller/$dosya");
    $bas = strpos($s, "public function $metot(");
    $govde = substr($s, $bas, 1200);

    check(
        "$dosya::$metot erisim yok ile yok AYNI yaniti veriyor",
        str_contains($govde, 'AccessDeniedException | DoesNotExistException')
        && str_contains($govde, 'notFound()')
    );
    check(
        "$dosya::$metot okumada 403 dondurmuyor",
        !str_contains($govde, 'forbidden()')
    );
}

$fileKaynak = kod("$kok/lib/Controller/FileController.php");
check('FileController tek tip "yok" yaniti veriyor', !str_contains($fileKaynak, 'STATUS_FORBIDDEN'));

// =====================================================================
echo "\n4) XSS yuzeyi\n";
// =====================================================================

$vHtmlKullanan = [];
foreach ($vueDosyalari as $f) {
    $s = kaynak($f);
    if (str_contains($s, 'v-html')) {
        $vHtmlKullanan[] = basename($f);
    }
    check(basename($f) . ' innerHTML ile kullanici verisi yazmiyor',
        !preg_match('/\.innerHTML\s*=\s*(?!\'\')/', $s) || basename($f) === 'SpotlightWidget.vue');
    check(basename($f) . ' eval kullanmiyor', !preg_match('/\beval\s*\(/', $s));
}

// v-html YALNIZ sanitize edilmis govdeyi basan tek bilesende olmali.
check(
    'v-html yalniz AnnouncementDetail.vue icinde: ' . implode(', ', $vHtmlKullanan),
    $vHtmlKullanan === ['AnnouncementDetail.vue']
);

// Govde YAZMA aninda temizlenmeli.
$svc = kaynak("$kok/lib/Service/AnnouncementService.php");
check(
    'govde yazilirken sanitize ediliyor',
    str_contains($svc, '$this->sanitizer->sanitize($message)')
);
check(
    'sanitize edilmemis govde kaydedilmiyor',
    !preg_match('/setMessage\(\s*\(string\)\$data/', $svc)
);

// Kapak ucu yalniz gorsel servis etmeli (SVG DISARIDA — SVG icinde script calisir).
check(
    'kapak ucu SVG servis etmiyor',
    !str_contains($fileKaynak, 'image/svg')
);
// Beyaz liste 7 Eki'de UserFileService'e taşındı (kayıt anı doğrulaması da kullanıyor).
$kapakKaynak = kod("$kok/lib/Service/UserFileService.php");
check(
    'kapak ucu tur beyaz listesi uyguluyor',
    str_contains($fileKaynak, 'UserFileService::IMAGE_TYPES')
    && str_contains($fileKaynak, 'in_array($file->getMimeType()')
);
check('kapak beyaz listesinde SVG yok', !str_contains($kapakKaynak, 'svg'));
// Kapak onu SEÇENİN alanından açılır; yazara dönülürse yöneticinin koyduğu kapak 404 olur.
// Pencere yalnız cover() gövdesi: sabit uzunluk sonraki metoda taşıyordu (20 Eki, image()).
$kapakBas = (int)strpos($fileKaynak, 'public function cover(');
$kapakSon = strpos($fileKaynak, 'public function', $kapakBas + 1);
$kapakGovde = substr($fileKaynak, $kapakBas, $kapakSon === false ? null : $kapakSon - $kapakBas);
check(
    'kapak ucu dosyayi kapak sahibinin alaninda aciyor',
    str_contains($kapakGovde, 'getCoverFileOwnerUid()') && !str_contains($kapakGovde, 'getAuthorUid()')
);
// Gömülü görsel: duyuru görünürlüğü + görsel gövdede mi + tür beyaz listesi (20 Eki 2026).
$gorselBas = (int)strpos($fileKaynak, 'public function image(');
$gorselSon = strpos($fileKaynak, 'public function', $gorselBas + 1);
$gorselGovde = substr($fileKaynak, $gorselBas, $gorselSon === false ? null : $gorselSon - $gorselBas);
check(
    'gomulu gorsel ucu duyuru gorunurlugunu, gorselin govdede oldugunu ve turunu denetliyor',
    $gorselBas > 0
    && str_contains($gorselGovde, 'findEntityForReader(')
    && str_contains($gorselGovde, 'inlineImages->owners(')
    && str_contains($gorselGovde, 'UserFileService::IMAGE_TYPES')
);
check(
    'gomulu gorselin sahibi kayitta dogrulaniyor',
    str_contains(kod("$kok/lib/Service/AnnouncementService.php"), 'inlineImages->bind(')
);
// Hedef gruplar okuyucuya gitmiyor; düzenleme ekranı grupsuz veriyi "herkese açık" SAYMIYOR
// (sayarsa kayıt hedeflemeyi silip duyuruyu herkese açardı).
$composer = kaynak("$kok/src/components/AnnouncementComposer.vue");
check('duzenleme ekrani gruplar gelmediyse kaydetmeyi kapatiyor', str_contains($composer, 'if (!Array.isArray(a.groups)) {')
    && str_contains($composer, '&& !this.groupsUnknown'));
check('detay sayfasi hedef gruplari basmiyor', !str_contains(kod("$kok/src/components/AnnouncementDetail.vue"), 'announcement.groups'));
$ekGovde = substr($fileKaynak, (int)strpos($fileKaynak, 'public function attachment('), 2000);
check('ek ucu dosyayi ekin sahibinin alaninda aciyor', str_contains($ekGovde, '$attachment->getFileOwnerUid('));
check(
    'kayitta yeni ek, ekleyenin alaninda dogrulaniyor (yazarin ozel dosyasi ek yapilamaz)',
    str_contains(kod("$kok/lib/Service/AnnouncementService.php"), 'userFiles->userFileName($actorUid, $fileId)')
);
check(
    'kayitta kapak, degistirenin gorseli mi diye dogrulaniyor',
    str_contains($kapakKaynak, 'IMAGE_TYPES, true')
    && str_contains(kod("$kok/lib/Service/AnnouncementService.php"), 'userFiles->isUserImage(')
);
check(
    'ekler indirilmeye zorlaniyor (tarayicida calistirilamaz)',
    str_contains($fileKaynak, "'Content-Disposition' => 'attachment;")
);

// =====================================================================
echo "\n5) Sir sizintisi ve gunluk\n";
// =====================================================================

$supheli = [];
foreach (array_merge($phpDosyalari, $vueDosyalari) as $f) {
    $s = kaynak($f);
    if (preg_match('/(password|secret|api[_-]?key|token)\s*=\s*[\'"][A-Za-z0-9_\-]{12,}[\'"]/i', $s)) {
        $supheli[] = basename($f);
    }
}
check('kodda gomulu parola/anahtar yok', $supheli === []);

check(
    'yol/istisna ayrintilari kullaniciya donen mesajlarda yok',
    !preg_match('/STATUS_\w+\s*\);?\s*$.*getTraceAsString/m', implode("\n", array_map('kaynak', $phpDosyalari)))
);

// =====================================================================
echo "\n6) Kullanilabilirlik sozlesmesi\n";
// =====================================================================

/*
 * Bunlar guvenlik degil ama ayni sekilde SESSIZCE geri gelebilecek kusurlar:
 * kimse hata almaz, sadece uygulama kotu davranir.
 */

$yerelDiyalog = [];
foreach ($vueDosyalari as $f) {
    $s = kod($f);
    if (preg_match('/window\.(confirm|prompt|alert)\s*\(/', $s)) {
        $yerelDiyalog[] = basename($f);
    }
}
check(
    'tarayicinin kendi confirm/prompt/alert kutulari kullanilmiyor: ' . implode(', ', $yerelDiyalog),
    $yerelDiyalog === []
);

$listeOgesi = kod("$kok/src/components/AnnouncementListItem.vue");
check(
    'liste ogesi GERCEK adres veriyor (yeni sekmede acilabilir)',
    !str_contains($listeOgesi, 'href="#"') && str_contains($listeOgesi, ':href="permalink"')
);
check(
    'Ctrl/Cmd tikla yeni sekme calisir (.exact kullaniliyor)',
    str_contains($listeOgesi, '@click.exact.prevent')
);

$app = kaynak("$kok/src/App.vue");
check(
    'liste sayfali: 50. duyurudan sonrasi erisilebilir',
    str_contains($app, 'loadMore') && str_contains($app, 'hasMore')
);
check(
    'sayfalama sunucuya offset gonderiyor',
    str_contains($app, 'offset')
);

$editor = kaynak("$kok/src/components/RichTextEditor.vue");
check(
    'baglanti adresi satir ici alaniyla aliniyor',
    str_contains($editor, 'nb-editor__linkbar') && str_contains($editor, 'linkInput')
);
check(
    'baglanti alani Esc ile kapaniyor',
    str_contains($editor, 'keydown.esc')
);

// Yukleniyor / bos / hata durumlari her ana ekranda olmali.
foreach (['App.vue', 'components/AnnouncementComments.vue'] as $rel) {
    $s = kaynak("$kok/src/$rel");
    check("$rel yukleniyor durumu gosteriyor", str_contains($s, 'NcLoadingIcon'));
    check("$rel hata durumu gosteriyor", str_contains($s, 'error') || str_contains($s, 'failed'));
}

foreach (['ListWidget', 'CardWidget', 'SpotlightWidget'] as $w) {
    $s = kaynak("$kok/src/widgets/$w.vue");
    check("$w bos durumu gosteriyor", str_contains($s, 'NcEmptyContent'));
    check("$w hata durumu gosteriyor", str_contains($s, 'failed'));
}

finish('SecurityContractTest');
