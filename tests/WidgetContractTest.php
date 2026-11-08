<?php

declare(strict_types=1);

/**
 * Widget sözleşmesi (K8) — PHP ile JavaScript arasındaki bağın testi.
 *
 * NEDEN BU TEST VAR: pano widget'ı iki parçadan oluşur. PHP tarafı kimliği
 * bildirir, JavaScript tarafı o kimlik için bir çizim fonksiyonu kaydeder.
 * İki taraf ayrışırsa **hiçbir hata alınmaz** — widget panoda sessizce boş bir
 * kutu olarak görünür. Böyle bir bozulmayı çalışma zamanında fark etmek zordur,
 * o yüzden burada kaynak dosyalar karşılaştırılarak kilitlenir.
 *
 * Çalıştırma:  php tests/WidgetContractTest.php
 */

require __DIR__ . '/bootstrap.php';

$kok = __DIR__ . '/..';

/** @return string */
function oku(string $yol): string {
    $icerik = @file_get_contents($yol);
    if ($icerik === false) {
        return '';
    }
    return $icerik;
}

$varyantlar = [
    'ListWidget' => 'noticeboard-list',
    'CardWidget' => 'noticeboard-card',
    'SpotlightWidget' => 'noticeboard-spotlight',
];

echo "Widget sozlesmesi — PHP tarafi\n";

$siralar = [];

foreach ($varyantlar as $sinif => $beklenenId) {
    $php = oku("$kok/lib/Dashboard/$sinif.php");

    check("$sinif dosyasi var", $php !== '');

    check(
        "$sinif kimligi '$beklenenId'",
        str_contains($php, "WIDGET_ID = '$beklenenId'")
    );

    // getId() sabiti dondurmeli; elle yazilmis bir dize ileride sabitten ayrisir.
    check(
        "$sinif::getId() sabiti dondurur",
        (bool)preg_match('/function getId\(\): string \{\s*return self::WIDGET_ID;/', $php)
    );

    if (preg_match('/function getOrder\(\): int \{\s*return (\d+);/', $php, $m)) {
        $siralar[$sinif] = (int)$m[1];
    }
}

check('uc varyantin da sirasi tanimli', count($siralar) === 3);
check('siralar birbirinden farkli', count(array_unique($siralar)) === 3);

echo "\nWidget sozlesmesi — JavaScript tarafi\n";

$js = oku("$kok/src/dashboard.js");
check('dashboard.js var', $js !== '');

foreach ($varyantlar as $sinif => $beklenenId) {
    check(
        "dashboard.js '$beklenenId' kimligini kaydediyor",
        str_contains($js, "'$beklenenId'")
    );

    check(
        "dashboard.js $sinif bileşenini yüklüyor",
        str_contains($js, "widgets/$sinif.vue")
    );

    check(
        "src/widgets/$sinif.vue dosyasi var",
        file_exists("$kok/src/widgets/$sinif.vue")
    );
}

// JS'de FAZLADAN bir kimlik olmamali: PHP'de karsiligi olmayan kayit,
// panoda hicbir zaman cagrilmayan olu koddur.
preg_match_all("/'(noticeboard-[a-z]+)'/", $js, $m);
$jsKimlikleri = array_values(array_unique($m[1]));
sort($jsKimlikleri);
$beklenen = array_values($varyantlar);
sort($beklenen);
check('JS tarafinda fazladan/eksik kimlik yok', $jsKimlikleri === $beklenen);

echo "\nWidget sozlesmesi — kayit ve derleme\n";

$app = oku("$kok/lib/AppInfo/Application.php");
foreach (array_keys($varyantlar) as $sinif) {
    check(
        "Application.php $sinif widget'ini kaydediyor",
        str_contains($app, "registerDashboardWidget($sinif::class)")
    );
}

// PHP `noticeboard-dashboard` betigini yukluyor; webpack onu uretmeli.
$taban = oku("$kok/lib/Dashboard/AbstractAnnouncementWidget.php");
check(
    "widget, '-dashboard' betigini yukluyor",
    str_contains($taban, "Application::APP_ID . '-dashboard'")
);

$webpack = oku("$kok/webpack.config.js");
check(
    'webpack dashboard girisini uretiyor',
    (bool)preg_match("/dashboard: path\.join\(__dirname, 'src', 'dashboard\.js'\)/", $webpack)
);

echo "\nWidget sozlesmesi — veri kaynagi\n";

// Widget'lar kendi veri mantigini YAZMAMALI; uygulamanin API'sini kullanmali.
// Aksi halde hedefleme/zamanlama kurallari zamanla ayrisir.
foreach (array_keys($varyantlar) as $sinif) {
    $vue = oku("$kok/src/widgets/$sinif.vue");
    check(
        "$sinif ortak veri mantigini kullaniyor (kendi sorgusunu yazmiyor)",
        str_contains($vue, "useAnnouncements.js")
    );
    check(
        "$sinif dogrudan axios cagirmiyor",
        !str_contains($vue, 'axios')
    );
}

$ortak = oku("$kok/src/widgets/useAnnouncements.js");
check(
    'ortak veri mantigi uygulamanin kendi API katmanini kullaniyor',
    str_contains($ortak, "from '../api.js'")
);

echo "\nWidget sozlesmesi — SIZINTI korumasi\n";

/*
 * 🔐 EN KRİTİK WIDGET TESTİ.
 * PHP tarafındaki `getItems()` mobil/masaüstü istemcilere veri döner ve
 * hedefleme süzmesini KENDİSİ yapmaz — onu `findVisibleFor()`e bırakır.
 * Biri bunu `findAll()` ya da `findVisible()` ile değiştirirse widget,
 * kullanıcının görmemesi gereken duyuruları sızdırır ve hiçbir hata alınmaz.
 */
check(
    "getItems hedeflemeye uyan findVisibleFor() kullaniyor",
    str_contains($taban, '$this->service->findVisibleFor($user')
);

check(
    "widget taban sinifi findAll() KULLANMIYOR (tum duyurulari sizdirirdi)",
    !str_contains($taban, 'findAll(')
);

check(
    "widget taban sinifi ham findVisible() KULLANMIYOR (grup suzmesi atlanirdi)",
    !preg_match('/->findVisible\(/', $taban)
);

// Kullanici cozulemezse widget bos donmeli; istisna firlatmak panonun
// TAMAMINI bozar (diger app'lerin widget'lari da cizilmez).
check(
    'kullanici bulunamazsa widget bos doner, istisna firlatmaz',
    str_contains($taban, 'if ($user === null) {')
    && str_contains($taban, 'return [];')
);

// =====================================================================
echo "\nWidget sozlesmesi — web panosu BIZIM tasarimi ciziyor mu (Hata #31)\n";
// =====================================================================

/*
 * NC 32 web panosu: `isApiWidgetV2(id) ? genel liste : uygulamanın Vue tasarımı`.
 * IAPIWidgetV2 uygulanınca üç widget da aynı genel listeyle çizildi, Vue
 * tasarımları hiç görünmedi — hiçbir hata alınmadan. Tüm widget kodu yazılı ve
 * testleri yeşilken kullanıcı üç AYNI widget gördü.
 */
$tabanKod = preg_replace('#/\*.*?\*/#s', '', $taban) ?? $taban;
check('widget IAPIWidgetV2 UYGULAMIYOR (web panosu Vue tasarimini atlardi)', !preg_match('/implements[^{]*\bIAPIWidgetV2\b/', $tabanKod));
check('widget getItemsV2 tanimlamiyor', !str_contains($tabanKod, 'function getItemsV2('));
check('mobil istemciler icin IAPIWidget (v1) uygulaniyor', (bool)preg_match('/implements[^{]*\bIAPIWidget\b/', $tabanKod));
check('load() Vue girisini yukluyor', str_contains($taban, "Util::addScript(Application::APP_ID, Application::APP_ID . '-dashboard')"));

// NcButton (@nextcloud/vue 9) görünümü `variant` ile alır; `type` HTML düğme
// türüdür. `type="primary"` sessizce gri düğme çizer (Hata #32).
$yanlisDugme = [];
$vueKok = "$kok/src";
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vueKok)) as $f) {
    if (!$f->isFile() || !str_ends_with($f->getFilename(), '.vue')) {
        continue;
    }
    $icerik = oku($f->getPathname());
    // NcButton açılış etiketlerini (çok satırlı olabilir) topla.
    preg_match_all('/<NcButton\b[^>]*>/s', $icerik, $m);
    foreach ($m[0] as $etiket) {
        if (preg_match('/\btype="(primary|secondary|tertiary|tertiary-no-background|tertiary-on-primary|error|warning|success)"/', $etiket)) {
            $yanlisDugme[] = $f->getFilename();
        }
    }
}
check('NcButton gorunumu variant ile veriliyor, type ile degil: ' . implode(', ', array_unique($yanlisDugme)), $yanlisDugme === []);

// =====================================================================
echo "\nIkonlar ve basliklar (24 Eyl 2026 kullanici geri bildirimi)\n";
// =====================================================================

/*
 * Nextcloud kuralı: app.svg BEYAZ (renkli üst menü), app-dark.svg ve widget
 * ikonları KOYU (açık zemin; karanlık temada NC kendisi beyaza çevirir).
 * Ters olunca menüde siyah, karanlık temada widget'ta siyah ikon çıktı (Hata #29).
 */
function dolgu(string $svg): string {
    return preg_match('/fill="(#[0-9a-fA-F]{3,6})"/', $svg, $m) ? strtolower($m[1]) : '(yok)';
}
function koyuMu(string $renk): bool {
    if (!preg_match('/^#([0-9a-f]{6})$/', $renk, $m)) {
        return false;
    }
    [$r, $g, $b] = array_map('hexdec', str_split($m[1], 2));
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) < 100;
}

/*
 * 🔴 Hata #45: app-dark.svg 24 Eyl'den 7 Eki'ye kadar GEÇERSİZ XML'di — açıklamada
 * `--` (XML açıklamada yasak) geçiyordu; tarayıcı ikonu hiç açmadı, ayarlar
 * menüsünde boş kutu çizdi. Renk testleri metin aradığı için yeşildi. Her SVG
 * gerçekten AYRIŞTIRILIR; açıklamalarda `--` ayrıca yakalanır.
 */
libxml_use_internal_errors(true);
foreach (glob("$kok/img/*.svg") as $svgDosya) {
    $svgMetin = oku($svgDosya);
    $ad = basename($svgDosya);
    libxml_clear_errors();
    $xml = simplexml_load_string($svgMetin);
    check("$ad gecerli XML (tarayici acabilir)", $xml !== false && libxml_get_errors() === []);
    check("$ad kok ogesi svg", $xml !== false && $xml->getName() === 'svg');
    preg_match_all('/<!--(.*?)-->/s', $svgMetin, $aciklamalar);
    $tireli = array_filter($aciklamalar[1], static fn ($a) => str_contains($a, '--'));
    check("$ad aciklamalarinda iki tire yok", $tireli === []);
}

check('app.svg (ust menu) BEYAZ', dolgu(oku("$kok/img/app.svg")) === '#ffffff');
check('app-dark.svg KOYU', koyuMu(dolgu(oku("$kok/img/app-dark.svg"))));

$css = oku("$kok/css/dashboard.css");
$ikonDosyalari = [];
$turkceBasliklar = [];
$l10nEn = json_decode(oku("$kok/l10n/en.json"), true)['translations'] ?? [];

foreach (array_keys($varyantlar) as $sinif) {
    $s = oku("$kok/lib/Dashboard/$sinif.php");
    preg_match("/getVariant\(\): string \{\s*return '([a-z]+)';/", $s, $v);
    $varyant = $v[1] ?? '';
    check("$sinif varyant adi bildiriyor", $varyant !== '');

    $dosya = "$kok/img/widget-$varyant.svg";
    $ikonDosyalari[] = $dosya;
    check("$sinif ikon dosyasi var (widget-$varyant.svg)", is_file($dosya));
    check("$sinif ikonu KOYU (karanlik temada NC cevirir)", koyuMu(dolgu(oku($dosya))));
    // ⚠️ Metin araması YETMEZ: sınıf hem ortak kuralda (filtre) hem kendi kuralında
    // (resim) geçiyor. İlk sürüm yalnız "sınıf adı geçiyor mu" diye bakıyordu;
    // ortak kuraldan düşen sınıf filtresini kaybettiği halde test yeşil kaldı
    // (mutasyon denetimi yakaladı). Artık sınıfa UYGULANAN bildirimler toplanır.
    $bildirimler = '';
    preg_match_all('/([^{}]+)\{([^}]*)\}/', preg_replace('#/\*.*?\*/#s', '', $css) ?? $css, $kurallar, PREG_SET_ORDER);
    foreach ($kurallar as $kural) {
        $seciciler = array_map('trim', explode(',', $kural[1]));
        if (in_array(".icon-noticeboard-$varyant", $seciciler, true)) {
            $bildirimler .= $kural[2];
        }
    }
    check("$sinif baslik ikonu dogru dosyayi aliyor", str_contains($bildirimler, "url('../img/widget-$varyant.svg')"));
    check("$sinif baslik ikonuna tema filtresi uygulaniyor", str_contains($bildirimler, 'filter: var(--background-invert-if-dark)'));

    preg_match("/getTitle\(\): string \{\s*return \\\$this->l->t\('([^']+)'\);/", $s, $b);
    $baslik = $b[1] ?? '';
    $turkceBasliklar[] = $baslik;
    // Seçicide ~16 karakterden sonra "…" ile kesiliyor; kesilen başlık ayırt edilemez.
    check("$sinif Turkce basligi kisa (<=16): '$baslik'", $baslik !== '' && mb_strlen($baslik) <= 16);
    $en = $l10nEn[$baslik] ?? '';
    check("$sinif Ingilizce basligi kisa (<=16): '$en'", $en !== '' && mb_strlen($en) <= 16);
}

check('uc widget uc FARKLI ikon kullaniyor', count(array_unique(array_map('md5_file', array_filter($ikonDosyalari, 'is_file')))) === 3);
check('uc widget uc FARKLI baslik kullaniyor', count(array_unique($turkceBasliklar)) === 3);
check('pano stili yukleniyor (baslik ikonlari icin)', str_contains($taban, "Util::addStyle(Application::APP_ID, 'dashboard')"));
check('ayarlar menusu koyu ikonu kullaniyor', str_contains(oku("$kok/lib/Settings/AdminSection.php"), "'app-dark.svg'"));

/*
 * 🔴 Hata #45: NC ikonları 6 ay önbellekletiyor; aynı adla düzeltilen ikon kullanıcıya
 * ulaşmadı, eski beyaz ikon karanlık temada SİYAH kare göründü. Her ikon adresi
 * içerik özetli IconUrl'den geçmeli — doğrudan imagePath() yazan yer yakalanır.
 */
$dogrudan = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$kok/lib")) as $f) {
    if ($f->isFile() && $f->getExtension() === 'php' && $f->getFilename() !== 'IconUrl.php') {
        $yorumsuz = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', oku($f->getPathname()));
        if (str_contains($yorumsuz, 'imagePath(')) {
            $dogrudan[] = $f->getFilename();
        }
    }
}
check('ikon adresleri yalniz IconUrl uzerinden (onbellek kirici): ' . implode(', ', $dogrudan), $dogrudan === []);
$ikonUrl = oku("$kok/lib/Service/IconUrl.php");
check('IconUrl adresi dosya ICERIGINE bagliyor (md5_file)', str_contains($ikonUrl, 'md5_file(') && str_contains($ikonUrl, "'?v='"));

finish('WidgetContractTest');
