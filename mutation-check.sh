#!/usr/bin/env bash
#
# MUTASYON DENETIMI — "testler yesil" yetmez, testin BOZULMAYI yakaladigini kanitlar.
#
# Her mutasyon icin: dosyayi yedekle → kurali kasten boz → testi kos → KIRMIZI
# olmasini bekle → dosyayi geri koy. Test yesil kalirsa o kural aslinda test
# EDILMIYOR demektir ve betik hata verir.
#
# Kullanim: ./mutation-check.sh
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
cd "$HERE"

PHP="${PHP:-C:/Users/memoc/OneDrive/Desktop/Projeler/php/php.exe}"
PHP_EXT_DIR="${PHP_EXT_DIR:-C:/Users/memoc/OneDrive/Desktop/Projeler/php/ext}"
PHP_FLAGS=(-n -d "extension_dir=$PHP_EXT_DIR" -d extension=mbstring)

BACKUP="$(mktemp -d)"
GECEN=0
KALAN=0

geri_al() {
  if [ -d "$BACKUP" ]; then
    for f in "$BACKUP"/*.bak; do
      [ -e "$f" ] || continue
      hedef="$(basename "$f" .bak | tr '@' '/')"
      cp "$f" "$hedef"
    done
    rm -rf "$BACKUP"
  fi
}
trap geri_al EXIT

# mutasyon <aciklama> <dosya> <test> <eski_metin> <yeni_metin>
mutasyon() {
  local aciklama="$1" dosya="$2" test="$3" eski="$4" yeni="$5"

  local anahtar
  anahtar="$(echo "$dosya" | tr '/' '@')"
  cp "$dosya" "$BACKUP/$anahtar.bak"

  if ! grep -qF "$eski" "$dosya"; then
    echo "  [ATLANDI] $aciklama — hedef kod bulunamadi (kod degismis olabilir)"
    KALAN=$((KALAN + 1))
    return
  fi

  # Yalnizca ilk esleşmeyi degistir.
  "$PHP" "${PHP_FLAGS[@]}" -r '
    $dosya = $argv[1]; $eski = $argv[2]; $yeni = $argv[3];
    $s = file_get_contents($dosya);
    $p = strpos($s, $eski);
    $s = substr_replace($s, $yeni, $p, strlen($eski));
    file_put_contents($dosya, $s);
  ' "$dosya" "$eski" "$yeni"

  if "$PHP" "${PHP_FLAGS[@]}" "$test" >/dev/null 2>&1; then
    echo "  [KACTI!]  $aciklama — kod bozuldu ama test YESIL kaldi"
    KALAN=$((KALAN + 1))
  else
    echo "  [ok]      $aciklama — test bozulmayi yakaladi"
    GECEN=$((GECEN + 1))
  fi

  cp "$BACKUP/$anahtar.bak" "$dosya"
}

echo "MUTASYON DENETIMI"
echo

echo "Guvenlik kurallari:"
mutasyon "XSS: script etiketi silinmesin" \
  lib/Service/HtmlSanitizer.php tests/HtmlSanitizerTest.php \
  "'script', 'style', 'iframe', 'object', 'embed'" \
  "'style', 'iframe', 'object', 'embed'"

mutasyon "XSS: javascript: baglantisi engellenmesin" \
  lib/Service/HtmlSanitizer.php tests/HtmlSanitizerTest.php \
  "if (\$name === 'href' && !\$this->isSafeUrl(\$attribute->value))" \
  "if (false)"

mutasyon "Yetki: yayinci olmayan da yayinlayabilsin" \
  lib/Service/PublisherService.php tests/PermissionTest.php \
  "public function canPublish(?IUser \$user): bool {" \
  "public function canPublish(?IUser \$user): bool { return true;"

mutasyon "Yetki: yayinci BASKASININ duyurusunu duzenleyebilsin" \
  lib/Service/PublisherService.php tests/PermissionTest.php \
  "return \$this->canPublish(\$user) && \$announcement->getAuthorUid() === \$user->getUID();" \
  "return \$this->canPublish(\$user);"

mutasyon "Hedefleme: herkes her duyuruyu gorebilsin" \
  lib/Service/VisibilityService.php tests/PermissionTest.php \
  "if (\$targetGids === []) {" \
  "if (true) {"

# ⚠️ Kosulun TAMAMI degistirilir. Ilk denemede yalniz ilk satira `false &&`
# eklenmisti; PHP'de `&&` `||`den once bagladigi icin ikinci kosul hala calisiyor
# ve kural aslinda bozulmuyordu — sahte bir "KACTI" sinyali verdi (Hata #18).
mutasyon "Yorum: baska duyurunun yorumu silinebilsin (IDOR)" \
  lib/Service/CommentService.php tests/CommentTest.php \
  "if (\$comment->getObjectType() !== Application::APP_ID
            || \$comment->getObjectId() !== (string)\$announcementId) {" \
  "if (false) {"

mutasyon "Yorum: kapali duyuruya yorum yazilabilsin" \
  lib/Service/CommentService.php tests/CommentTest.php \
  "if (!\$announcement->getOptComments()) {" \
  "if (false) {"

echo
echo "Zamanlama ve dagitim kurallari:"
mutasyon "Zaman: suresi dolan duyuru hala gorunsun" \
  lib/Db/Announcement.php tests/AnnouncementTest.php \
  "if (\$this->getDeleteAt() !== null && \$this->getDeleteAt() <= \$now) {" \
  "if (false) {"

mutasyon "Zaman: yayin vakti gelmeden gorunsun" \
  lib/Db/Announcement.php tests/AnnouncementTest.php \
  "if (\$this->getPublishAt() !== null && \$this->getPublishAt() > \$now) {" \
  "if (false) {"

mutasyon "Durum: istemci status gonderip zamanlamayi atlayabilsin" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  "(\$publishAt !== null && \$publishAt > \$now)
                    ? Announcement::STATUS_SCHEDULED
                    : Announcement::STATUS_PUBLISHED" \
  "Announcement::STATUS_PUBLISHED"

mutasyon "Dagitim: yayindaki duyuru duzenlenince yeniden bildirim gitsin" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  "if (!\$wasPublished && \$announcement->getStatus() === Announcement::STATUS_PUBLISHED) {" \
  "if (\$announcement->getStatus() === Announcement::STATUS_PUBLISHED) {"

mutasyon "Dogrulama: silme zamani yayin zamanindan once olabilsin" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  "if (\$publishAt !== null && \$deleteAt !== null && \$deleteAt <= \$publishAt) {" \
  "if (false) {"

echo
echo "Widget sozlesmesi (K8):"
mutasyon "PHP'deki widget kimligi JS'den ayrissin" \
  lib/Dashboard/ListWidget.php tests/WidgetContractTest.php \
  "WIDGET_ID = 'noticeboard-list'" \
  "WIDGET_ID = 'noticeboard-liste'"

mutasyon "getId() sabit yerine elle yazilmis dize dondursun" \
  lib/Dashboard/CardWidget.php tests/WidgetContractTest.php \
  "return self::WIDGET_ID;" \
  "return 'noticeboard-card';"

mutasyon "Application.php bir widget'i kaydetmeyi unutsun" \
  lib/AppInfo/Application.php tests/WidgetContractTest.php \
  "\$context->registerDashboardWidget(SpotlightWidget::class);" \
  "// kayit silindi"

mutasyon "webpack dashboard girisini uretmesin" \
  webpack.config.js tests/WidgetContractTest.php \
  "dashboard: path.join(__dirname, 'src', 'dashboard.js')," \
  "// giris silindi"

mutasyon "widget kendi veri sorgusunu yazsin (ortak mantigi atlasin)" \
  src/widgets/ListWidget.vue tests/WidgetContractTest.php \
  "import { widgetData } from './useAnnouncements.js'" \
  "import axios from '@nextcloud/axios'"

# 🔐 Sizinti mutasyonu: widget hedefleme suzmesini atlasin.
mutasyon "widget TUM duyurulari sizdirsin (findAll)" \
  lib/Dashboard/AbstractAnnouncementWidget.php tests/WidgetContractTest.php \
  "\$this->service->findVisibleFor(\$user, \$limit)" \
  "\$this->service->findAll(\$limit)"

echo
echo "Guvenlik denetiminde kapatilan aciklar (23 Eyl 2026):"
mutasyon "Yonetim listesi baskasinin taslagini sizdirsin" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  "\$this->publisherService->isAdministrator(\$user)
            ? \$this->mapper->findAll(\$limit, \$offset)
            : \$this->mapper->findByAuthor(\$user->getUID(), \$limit, \$offset);" \
  "\$this->mapper->findAll(\$limit, \$offset);"

mutasyon "Ek dosya sayisi sinirsiz olsun" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  "if (count(\$data['attachments']) > self::MAX_ATTACHMENTS) {" \
  "if (false) {"

mutasyon "Istemci authorUid dayatabilsin" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  "\$announcement->setAuthorUid(\$authorUid);" \
  "\$announcement->setAuthorUid((string)(\$data['authorUid'] ?? \$authorUid));"

mutasyon "Okuma ucu 403 ile 404'u ayirsin (kimlik saymaya acik)" \
  lib/Controller/AnnouncementController.php tests/SecurityContractTest.php \
  "} catch (AccessDeniedException | DoesNotExistException) {" \
  "} catch (AccessDeniedException) { return \$this->forbidden(); } catch (DoesNotExistException) {"

mutasyon "Yorum yazma hiz siniri kaldirilsin" \
  lib/Controller/CommentController.php tests/SecurityContractTest.php \
  "#[UserRateLimit(limit: 60, period: 3600)]" \
  "// hiz siniri kaldirildi"

mutasyon "Yonetici ayarlari herkese acilsin" \
  lib/Controller/AdminController.php tests/SecurityContractTest.php \
  "    public function getSettings(): DataResponse {" \
  "    #[\OCP\AppFramework\Http\Attribute\NoAdminRequired]
    public function getSettings(): DataResponse {"

# Beyaz liste 7 Eki'de UserFileService'e tasindi (kayit ani dogrulamasi da kullaniyor).
mutasyon "Kapak ucu SVG de servis etsin (SVG icinde script calisir)" \
  lib/Service/UserFileService.php tests/SecurityContractTest.php \
  "'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'," \
  "'image/jpeg', 'image/png', 'image/svg+xml',"

# 20 Eki'de satir InlineImages::bind() sarmalayicisina tasindi; hedef yeniden ayarlandi (27 Eki).
mutasyon "Govde sanitize edilmeden kaydedilsin" \
  lib/Service/AnnouncementService.php tests/SecurityContractTest.php \
  "                \$this->sanitizer->sanitize(\$message)," \
  "                \$message,"

mutasyon "XSS: gorsel genisligi dogrulanmasin (27 Eki)" \
  lib/Service/HtmlSanitizer.php tests/HtmlSanitizerTest.php \
  "'data-nb-width' => preg_match(self::IMAGE_WIDTH, \$value) === 1 ? \$value : null," \
  "'data-nb-width' => \$value,"

mutasyon "XSS: gorsel yerlesimi dogrulanmasin (27 Eki)" \
  lib/Service/HtmlSanitizer.php tests/HtmlSanitizerTest.php \
  "'data-nb-align' => in_array(\$value, self::IMAGE_ALIGNS, true) ? \$value : null," \
  "'data-nb-align' => \$value,"

mutasyon "Gorsel aciklamasi sinirsiz kalsin (27 Eki)" \
  lib/Service/HtmlSanitizer.php tests/HtmlSanitizerTest.php \
  "return mb_substr(\$value, 0, self::MAX_IMAGE_ALT);" \
  "return \$value;"

echo
echo "Unified search (Faz 9):"
# ⚠️ Girinti bilerek 16 bosluk: ayni satir findVisibleFor()'da 12 boslukla
# geciyor ve mutasyon ILK eslesmeyi degistirir. Yanlis yeri bozmamak icin.
mutasyon "Arama hedeflemeyi atlasin (grup duyurusu sizsin)" \
  lib/Service/AnnouncementService.php tests/SearchTest.php \
  "                if (!\$this->visibilityService->isTargeted(\$user, \$gids)) {" \
  "                if (false) {"

mutasyon "Arama taslak/suresi dolmus duyuruyu da versin" \
  lib/Service/AnnouncementService.php tests/SearchTest.php \
  "if (!\$announcement->isVisibleAt(\$now)) {" \
  "if (false) {"

mutasyon "Arama HTML etiket adlarini eslesme saysin" \
  lib/Service/AnnouncementService.php tests/SearchTest.php \
  "if (!\$this->matchesPlainText(\$announcement, \$needle)) {" \
  "if (false) {"

mutasyon "Turkce I/İ/ı katlamasi kalksin" \
  lib/Service/AnnouncementService.php tests/SearchTest.php \
  "strtr(\$text, ['İ' => 'i', 'I' => 'i', 'ı' => 'i'])" \
  "\$text"

mutasyon "Tek harfle arama yapilabilsin" \
  lib/Service/AnnouncementService.php tests/SearchTest.php \
  "mb_strlen(\$term) < self::MIN_SEARCH_LENGTH ||" \
  ""

mutasyon "Imlec atlanan satirlari saymasin (sonraki sayfa kayit atlar)" \
  lib/Service/AnnouncementService.php tests/SearchTest.php \
  "\$cursor++;" \
  ""

mutasyon "Tek arama tum tabloyu tarasin (parti tavani kalksin)" \
  lib/Service/AnnouncementService.php tests/SearchTest.php \
  "\$round < self::MAX_SEARCH_BATCHES;" \
  "\$round < 1000;"

mutasyon "Arama sorgusu LIKE jokerlerini kacirmasin" \
  lib/Db/AnnouncementMapper.php tests/SearchTest.php \
  "\$this->db->escapeLikeParameter(\$term)" \
  "\$term"

mutasyon "Arama saglayicisi kaydedilmesin" \
  lib/AppInfo/Application.php tests/SearchTest.php \
  "\$context->registerSearchProvider(SearchProvider::class);" \
  "// kayit silindi"

echo
echo "Ceviri sozlesmesi (Faz 9 · KAPI 9):"
mutasyon "Hata mesaji cevrilmeden arayuze donsun" \
  lib/Controller/AnnouncementController.php tests/L10nContractTest.php \
  "'message' => \$e->translate(\$this->l)" \
  "'message' => \$e->getMessage()"

mutasyon "Controller ham Turkce mesaj yazsin" \
  lib/Controller/PickerController.php tests/L10nContractTest.php \
  "\$this->l->t('Bu işlem için yetkiniz yok.')" \
  "'Bu işlem için yetkiniz yok.'"

mutasyon "Ceviri parametreleri dussun (%s bos kalsin)" \
  lib/Exception/TranslatableException.php tests/L10nContractTest.php \
  "return \$l->t(\$this->template, \$this->parameters);" \
  "return \$l->t(\$this->template);"

mutasyon "Istisna sablonu birlestirilerek yazilsin (sozluge giremez)" \
  lib/Service/CommentService.php tests/L10nContractTest.php \
  "'Yorum en fazla %s karakter olabilir.', [self::MAX_MESSAGE_LENGTH]" \
  "'Yorum en fazla ' . self::MAX_MESSAGE_LENGTH . ' karakter olabilir.'"

mutasyon "Cevirisi olmayan yeni hata mesaji eklensin" \
  lib/Service/CommentService.php tests/L10nContractTest.php \
  "'Bu yorumu silemezsiniz.'" \
  "'Bu yorumu kesinlikle silemezsiniz.'"

mutasyon "Arayuzde t() disinda Turkce metin kalsin" \
  src/components/AnnouncementComposer.vue tests/L10nContractTest.php \
  "{{ t('noticeboard', 'Önizleme') }}" \
  "Önizleme"

mutasyon "tr.json'da bir metin Ingilizce kalsin (Turkce kullaniciya Ingilizce gider)" \
  l10n/tr.json tests/L10nContractTest.php \
  "\"Duyuru listesi\": \"Duyuru listesi\"" \
  "\"Duyuru listesi\": \"Notice list\""

echo
echo "Ikonlar (Hata #29):"
mutasyon "Ust menu ikonu yine siyah olsun" \
  img/app.svg tests/WidgetContractTest.php \
  "fill=\"#ffffff\"" \
  "fill=\"#000000\""

mutasyon "Widget ikonu beyaz olsun (karanlik temada siyah gorunur)" \
  img/widget-cards.svg tests/WidgetContractTest.php \
  "fill=\"#222222\"" \
  "fill=\"#ffffff\""

mutasyon "Widget baslik ikonunun CSS'i silinsin" \
  css/dashboard.css tests/WidgetContractTest.php \
  ".icon-noticeboard-slider {" \
  ".baska-sinif {"

mutasyon "Widget basligi seçicide kesilecek kadar uzun olsun" \
  lib/Dashboard/ListWidget.php tests/WidgetContractTest.php \
  "return \$this->l->t('Duyuru listesi');" \
  "return \$this->l->t('Duyurular (liste gorunumu)');"

echo
echo "Web panosu tasarimi (Hata #31, #32):"
mutasyon "Widget yine V2 olsun (pano Vue tasarimini atlar, uc widget ayni gorunur)" \
  lib/Dashboard/AbstractAnnouncementWidget.php tests/WidgetContractTest.php \
  "implements IAPIWidget, IIconWidget" \
  "implements IAPIWidgetV2, IIconWidget"

mutasyon "Widget yine V2 olsun — yukleme testi de yakalamali" \
  lib/Dashboard/AbstractAnnouncementWidget.php tests/WidgetInterfaceTest.php \
  "implements IAPIWidget, IIconWidget" \
  "implements IAPIWidgetV2, IIconWidget"

# ⚠️ Canli olay (Hata #33): IReloadableWidget V2'yi MIRAS alir. Kaynak tarayan
# test bunu goremez; WidgetInterfaceTest NC'nin arayuz zinciriyle sinifi YUKLER.
mutasyon "IReloadableWidget geri eklensin (V2 miras yoluyla doner, sinif eksik kalir)" \
  lib/Dashboard/AbstractAnnouncementWidget.php tests/WidgetInterfaceTest.php \
  "implements IAPIWidget, IIconWidget, IButtonWidget {" \
  "implements IAPIWidget, IIconWidget, IButtonWidget, \\OCP\\Dashboard\\IReloadableWidget {"

mutasyon "NcButton gorunumu yine type ile verilsin (gri dugme)" \
  src/App.vue tests/WidgetContractTest.php \
  "<NcButton variant=\"primary\" @click=\"openComposer(null)\">" \
  "<NcButton type=\"primary\" @click=\"openComposer(null)\">"

# --- Kapak sahibi ve vitrin ayarlari (7 Eki 2026) ---------------------------
mutasyon "Kapak dosyasi dogrulanmasin (baskasinin/olmayan dosya kapak olur)" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  'if (!$this->userFiles->isUserImage($actorUid, $coverFileId)) {' \
  'if (false) {'

mutasyon "Ayni kapak yeniden gonderilince de dogrulansin (yazar duzenleyemez olur)" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  '$coverFileId !== null && $coverFileId !== $announcement->getCoverFileId()' \
  '$coverFileId !== null'

mutasyon "Kapak ucu yine yazarin alaninda arasin (yoneticinin kapagi 404)" \
  lib/Controller/FileController.php tests/SecurityContractTest.php \
  '$announcement->getCoverFileOwnerUid(), $fileId' \
  '$announcement->getAuthorUid(), $fileId'

mutasyon "Yeni ek dogrulanmasin (yazarin ozel dosyasi herkese servis edilir)" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  '$name = $this->userFiles->userFileName($actorUid, $fileId);' \
  '$name = (string)($item['"'"'fileName'"'"'] ?? '"'"''"'"');'

mutasyon "Mevcut ek korunmasin, yeniden dogrulansin (yazar duzenleyemez olur)" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  'if (isset($existing[$fileId])) {' \
  'if (false) {'

mutasyon "Ek ucu yine yazarin alaninda arasin (yoneticinin eki 404)" \
  lib/Controller/FileController.php tests/SecurityContractTest.php \
  '$attachment->getFileOwnerUid($announcement->getAuthorUid())' \
  '$announcement->getAuthorUid()'

mutasyon "Hedef gruplar okuyucuya da gitsin" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  'unset($payload['"'"'groups'"'"']);' \
  ''

mutasyon "Hedef gruplar duzenleyene de gitmesin (duzenleme hedeflemeyi siler)" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  'if (!$this->publisherService->canEdit($user, $announcement)) {' \
  'if (true) {'

mutasyon "Duzenleme ekrani grupsuz veriyi herkese acik saysin" \
  src/components/AnnouncementComposer.vue tests/SecurityContractTest.php \
  'if (!Array.isArray(a.groups)) {' \
  'if (false) {'

mutasyon "Ikon adresi icerik ozetini kaybetsin (eski ikon 6 ay onbellekte kalir)" \
  lib/Service/IconUrl.php tests/SearchTest.php \
  "return \$version === '' ? \$path : \$path . '?v=' . \$version;" \
  'return $path;'

mutasyon "Ayarlar menusu yine dogrudan imagePath kullansin" \
  lib/Settings/AdminSection.php tests/WidgetContractTest.php \
  "return IconUrl::path(\$this->urlGenerator, 'app-dark.svg');" \
  "return \$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');"

mutasyon "SVG aciklamasina iki tire girsin (dosya bozulur, ikon bos kutu)" \
  img/app-dark.svg tests/WidgetContractTest.php \
  'temada bunu kendisi beyaza' \
  'temada bunu --x ile kendisi beyaza'

mutasyon "Vitrin donme suresi alt siniri gevsesin" \
  lib/Service/SpotlightSettings.php tests/SpotlightSettingsTest.php \
  '$interval >= self::MIN_INTERVAL' \
  '$interval >= 1'

mutasyon "Vitrin ayari sayfaya verilmesin" \
  lib/Dashboard/SpotlightWidget.php tests/WidgetInterfaceTest.php \
  '$this->initialState->provideInitialState(self::STATE_KEY, $this->spotlightSettings->get());' \
  ''

echo
echo "Tam kaldirma (purge.php):"
mutasyon "Kaldirma bir tabloyu unutsun (yeniden kurulumda eski tablo kalir)" \
  purge.php tests/PurgeContractTest.php \
  "const NB_TABLOLAR = ['noticeboard_files', 'noticeboard_groups', 'noticeboard_posts'];" \
  "const NB_TABLOLAR = ['noticeboard_groups', 'noticeboard_posts'];"

mutasyon "Kaldirma migration kayitlarini biraksin (yeniden kurulum tablo yaratmaz)" \
  purge.php tests/PurgeContractTest.php \
  "return nb_satirlar(\$db, 'migrations', \$esit('app', NB_APP), \$sil);" \
  'return 0;'

mutasyon "Kosulsuz DELETE engeli kalksin (baska app satirlari silinir)" \
  purge.php tests/PurgeContractTest.php \
  "if (!str_contains(\$qb->getSQL(), ' WHERE ')) {" \
  'if (false) {'

mutasyon "Pano duzeninden bir widget silinmesin" \
  purge.php tests/PurgeContractTest.php \
  "const NB_WIDGETLAR = ['noticeboard-list', 'noticeboard-card', 'noticeboard-spotlight'];" \
  "const NB_WIDGETLAR = ['noticeboard-list', 'noticeboard-card'];"

mutasyon "install.sh json() ifadesi yerine deyim yazilsin (custom_apps sessizce taranmaz)" \
  install.sh tests/PurgeContractTest.php \
  'implode("\n", array_column((array)$j, "path")) . "\n"' \
  'foreach ((array)$j as $e) { echo $e["path"], "\n"; }'

echo
echo "Talk kanali (0.5.0):"
mutasyon "Yazar kopya almasin (hedef grupta degilse hic almaz)" \
  lib/Service/TalkService.php tests/TalkTest.php \
  '$uids = array_values(array_unique([$announcement->getAuthorUid(), ...$uids]));' \
  '$uids = array_values(array_unique($uids));'

mutasyon "Yazar grupta da varsa iki kez alsin" \
  lib/Service/TalkService.php tests/TalkTest.php \
  'array_values(array_unique([$announcement->getAuthorUid(), ...$uids]))' \
  'array_values([$announcement->getAuthorUid(), ...$uids])'

mutasyon "Yazarin kopyasi listenin sonuna kalsin" \
  lib/Service/TalkService.php tests/TalkTest.php \
  '[$announcement->getAuthorUid(), ...$uids]' \
  '[...$uids, $announcement->getAuthorUid()]'

mutasyon "Talk anahtari kapaliyken de gonderilsin" \
  lib/Service/TalkService.php tests/TalkTest.php \
  'if (!$announcement->getOptTalk() || !$announcement->isVisibleAt(' \
  'if (!$announcement->isVisibleAt('

mutasyon "Yayinda olmayan (taslak/suresi dolmus) duyuru da gonderilsin" \
  lib/Service/TalkService.php tests/TalkTest.php \
  'if (!$announcement->getOptTalk() || !$announcement->isVisibleAt($this->timeFactory->getTime())) {' \
  'if (!$announcement->getOptTalk()) {'

mutasyon "Talk'u kullanamayan kisiye de sohbet acilsin" \
  lib/Service/TalkService.php tests/TalkTest.php \
  ' || $this->gateway->isDisabledFor($user)' \
  ''

mutasyon "Kapatilmis hesaba da gonderilsin" \
  lib/Service/TalkService.php tests/TalkTest.php \
  ' || !$user->isEnabled()' \
  ''

mutasyon "Bot hesabinin kotasi sinirlanmasin (dosya tutabilir)" \
  lib/Service/TalkService.php tests/TalkTest.php \
  "\$bot->setQuota('0 B');" \
  ''

mutasyon "Bir kisideki hata tum gonderimi durdursun" \
  lib/Service/TalkService.php tests/TalkTest.php \
  '} catch (\Exception $e) {' \
  '} catch (\LogicException $e) {'

mutasyon "Talk API kirilinca (\\Error) her kisi icin tekrar denensin" \
  lib/Service/TalkService.php tests/TalkTest.php \
  '} catch (\Exception $e) {' \
  '} catch (\Throwable $e) {'

mutasyon "Herkes ayni dilde (Turkce) mesaj alsin" \
  lib/Service/TalkService.php tests/TalkTest.php \
  '$language = $this->l10nFactory->getUserLanguage($user);' \
  "\$language = 'tr';"

mutasyon "Ozet siniri kaysin (tam 300 karakter de kesilsin)" \
  lib/Service/TalkService.php tests/TalkTest.php \
  'mb_strlen($plain) > self::EXCERPT_LENGTH' \
  'mb_strlen($plain) >= self::EXCERPT_LENGTH'

# Duyuru sohbeti (0.5.2): kişi başına tek sohbet, anahtarı tercihlerde.
mutasyon "Sohbet anahtari kaydedilmesin (her duyuruda yeni sohbet acilir)" \
  lib/Service/TalkService.php tests/TalkTest.php \
  '$this->config->setUserValue($uid, Application::APP_ID, self::ROOM_KEY, $newToken);' \
  ''

mutasyon "Kayitli anahtar okunmasin (hep bos gider)" \
  lib/Service/TalkService.php tests/TalkTest.php \
  "\$token = \$this->config->getUserValue(\$uid, Application::APP_ID, self::ROOM_KEY, '');" \
  "\$token = '';"

mutasyon "Anahtar degismese de her gonderimde tercih yazilsin" \
  lib/Service/TalkService.php tests/TalkTest.php \
  'if ($newToken !== $token) {' \
  'if (true) {'

mutasyon "Sohbet adi kisinin dilinde olmasin" \
  lib/Service/TalkService.php tests/TalkTest.php \
  '$roomInfos[$language] ??= $this->roomInfo($language);' \
  "\$roomInfos[\$language] ??= \$this->roomInfo('tr');"

mutasyon "Dagitim Talk anahtarina bakmasin" \
  lib/Service/DispatchService.php tests/TalkTest.php \
  'if ($announcement->getOptTalk()) {' \
  'if (true) {'

mutasyon "Talk kurulu degilken de Talk isi kuyruga girsin" \
  lib/Service/DispatchService.php tests/TalkTest.php \
  'if ($this->talkGateway->isAvailable()) {' \
  'if (true) {'

mutasyon "Yeni duyuruda Talk anahtari kolon varsayilanina (kapali) kalsin" \
  lib/Service/AnnouncementService.php tests/AnnouncementServiceTest.php \
  '$announcement->setOptTalk(true);' \
  ''

mutasyon "Talk sinif varsayilani yine true olsun (NC set'i yazmaz, kayit kapali duser — Hata #47)" \
  lib/Db/Announcement.php tests/AnnouncementServiceTest.php \
  'protected $optTalk = false;' \
  'protected $optTalk = true;'

mutasyon "Talk sinif varsayilani kolondan ayrissin — sozlesme testi de yakalamali" \
  lib/Db/Announcement.php tests/AnnouncementTest.php \
  'protected $optTalk = false;' \
  'protected $optTalk = true;'

mutasyon "Kaldirma baska bir hesabi silsin" \
  purge.php tests/PurgeContractTest.php \
  "const NB_TALK_HESABI = 'noticeboard-bot';" \
  "const NB_TALK_HESABI = 'admin';"

echo
echo "-----------------------------------------------"
echo "Yakalanan: $GECEN    Kacan/atlanan: $KALAN"

if [ "$KALAN" -gt 0 ]; then
  echo "UYARI: bazi bozulmalar testlerden KACTI — o kurallar yeterince test edilmiyor."
  exit 1
fi

echo "Tum kasitli bozulmalar testlerce yakalandi."
