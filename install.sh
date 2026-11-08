#!/bin/bash
# =============================================================================
# Duyuru Panosu (noticeboard) — Nextcloud uygulaması kurulum / güncelleme betiği
#
#   sudo ./install.sh                  # etkileşimli: her değişiklikten önce sorar
#   sudo ./install.sh --yes            # sormadan, varsayılanlarla
#   sudo ./install.sh --dry-run        # yalnız ne yapacağını yazar, hiçbir şeyi değiştirmez
#   sudo ./install.sh --uninstall      # app'i ve TÜM izlerini siler: tablolar, ayarlar,
#                                      # arka plan işleri, bildirim/etkinlik/yorumlar,
#                                      # pano düzenleri, yedekler, log. GERİ ALINAMAZ.
#   sudo ./install.sh --uninstall --keep-data   # yalnız dosyaları kaldırır, VERİ KALIR
#
# Seçenekler:
#   --nc-path DIR          Nextcloud kök dizini (verilmezse aranır)
#   --web-user KULLANICI   web sunucusu kullanıcısı (verilmezse config.php sahibinden okunur)
#   --skip-cron            arka plan işi (cron) denetimini atla
#   --force                Nextcloud sürümü desteklenen aralığın dışında olsa da kur;
#                          paket kuruludan ESKİYSE de üzerine yaz (önerilmez)
#
# Tekrar çalıştırılabilir: her adım önce durumu ölçer, doğruysa dokunmaz.
# Eski sürüm /var/backups/noticeboard altına yedeklenir; hata olursa geri yüklenir —
# yeni sürümün migration'ları koşmaya başladıysa yüklenmez (eski kod yeni şemayı okuyamaz).
# App dizinine yedek BIRAKMAZ (Nextcloud onu ikinci bir app sanar).
# Log: /var/log/noticeboard-install.log
# =============================================================================
set -euo pipefail

APP_ID=noticeboard
PKG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG=/var/log/noticeboard-install.log
TS=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR=/var/backups/noticeboard

NC_OPT=""; WEBUSER=""; SKIP_CRON=0; FORCE=0; YES=0; DRY=0; UNINSTALL=0; KEEP_DATA=0
while [ $# -gt 0 ]; do
	case "$1" in
		--nc-path) NC_OPT="${2:?--nc-path bir dizin ister}"; shift 2 ;;
		--web-user) WEBUSER="${2:?--web-user bir kullanıcı ister}"; shift 2 ;;
		--skip-cron) SKIP_CRON=1; shift ;;
		--force) FORCE=1; shift ;;
		--uninstall) UNINSTALL=1; shift ;;
		--keep-data) KEEP_DATA=1; shift ;;
		--yes|-y) YES=1; shift ;;
		--dry-run) DRY=1; shift ;;
		-h|--help) sed -n 2,24p "$0"; exit 0 ;;
		*) echo "bilinmeyen seçenek: $1 (yardım: $0 --help)"; exit 2 ;;
	esac
done
[ "$KEEP_DATA" = 0 ] || [ "$UNINSTALL" = 1 ] || { echo "--keep-data yalnız --uninstall ile kullanılır"; exit 2; }

# ---------------------------------------------------------------- yardımcılar
mkdir -p "$(dirname "$LOG")" && touch "$LOG" && chmod 600 "$LOG"
say()  { echo "$*" | tee -a "$LOG"; }
step() { say ""; say "== $*"; }
ok()   { say "   OK    $*"; }
warn() { say "   UYARI $*"; }
die()  {
	say "   HATA  $*"
	[ "${REPLACED:-0}" = 1 ] && rollback
	say "Kurulum durdu. Ayrıntı: $LOG"
	exit 1
}
ask() {  # ask "soru" -> 0 = evet
	[ "$DRY" = 1 ] && { say "   (dry-run) $1 -> atlandı"; return 1; }
	[ "$YES" = 1 ] && return 0
	local a; read -r -p "   ? $1 [e/H] " a </dev/tty || return 1
	[[ "$a" =~ ^[eEyY] ]]
}
do_() {  # do_ "açıklama" komut...   (dry-run'da çalıştırmaz)
	local what="$1"; shift
	if [ "$DRY" = 1 ]; then say "   (dry-run) $what"; return 0; fi
	say "   ->    $what"
	"$@" >>"$LOG" 2>&1 || die "$what başarısız"
}

# W: web sunucusu kullanıcısı olarak çalıştırır (occ ve duman testi root ile koşmamalı).
W() {
	if command -v runuser >/dev/null; then runuser -u "$WEBUSER" -- "$@"
	else sudo -u "$WEBUSER" "$@"; fi
}
occ() { W php "$NC/occ" --no-interaction "$@"; }
json() {  # json '<php ifadesi; $j = çözülmüş girdi>'   (jq gerekmez; php zaten var)
	php -r '$j = json_decode(stream_get_contents(STDIN), true); echo '"$1"';'
}
xml_get() {  # xml_get DOSYA ETİKET  -> ilk etiketin içeriği
	sed -n "s:.*<$2>\(.*\)</$2>.*:\1:p" "$1" | head -1
}
app_state() {  # -> enabled | disabled | absent
	occ app:list --output=json 2>/dev/null | json 'isset($j["enabled"]["'$APP_ID'"]) ? "enabled" : (isset($j["disabled"]["'$APP_ID'"]) ? "disabled" : "absent")'
}
installed_version() {  # Nextcloud'un veritabanında kayıtlı sürüm (dosyalardaki değil)
	occ config:app:get "$APP_ID" installed_version 2>/dev/null || true
}
ver_cmp() {  # ver_cmp A B -> -1 / 0 / 1
	php -r 'echo version_compare($argv[1], $argv[2]);' "$1" "$2"
}

# Paketteki migration'lardan biri yedekteki eski sürümde yoksa 0 döner: app:enable
# veritabanını eski kodun tanımadığı bir şemaya geçirebilir. Yedek okunamazsa da 0
# (emin olunamıyorsa eski dosyaları geri koymamak güvenli taraftır).
new_migrations() {  # new_migrations YEDEK.tar.gz
	local old m
	old=$(tar -tzf "$1" 2>/dev/null | sed -n "s:^$APP_ID/lib/Migration/\([^/]*\.php\)$:\1:p") || return 0
	for m in "$PKG_APP"/lib/Migration/*.php; do
		[ -e "$m" ] || continue
		# here-string, boru DEĞİL: pipefail altında `grep -q` erken çıkınca printf SIGPIPE alır.
		grep -qxF "$(basename "$m")" <<<"$old" || return 0
	done
	return 1
}

rollback() {
	REPLACED=0
	say ""
	say "== GERİ ALMA"
	if [ "${SCHEMA_AHEAD:-0}" = 1 ]; then
		# ⚠️ Eski dosyalar BİLEREK geri konmaz: migration koştuysa eski sürüm yeni kolonu
		# tanımaz (0.4.x + 0.5.0'ın `opt_talk`'u → Entity BadFunctionCallException), duyuru
		# listesi, widget'lar ve arama boş kalır. Yeni sürüm yerinde kalır; yedek durur.
		say "   ->    eski dosyalar GERİ KONMADI: veritabanı $PKG_VER şemasına geçmiş olabilir,"
		say "         eski sürüm onu okuyamaz. $APP_ID $PKG_VER dosyaları yerinde bırakıldı."
		say "         Eski sürümün yedeği: $BACKUP_FILE"
		if [ "${WAS_ENABLED:-0}" = 1 ] && [ "$(app_state)" != enabled ]; then
			occ app:enable ${NCV_FLAG:-} "$APP_ID" >>"$LOG" 2>&1 && say "   ->    $APP_ID $PKG_VER etkinleştirildi" \
				|| say "   HATA  $APP_ID etkinleştirilemedi — sorun giderilince: occ app:enable $APP_ID"
		fi
		say "         Log'u destek ekibine iletin; sorun giderilince betiği yeniden çalıştırın."
	elif [ -n "${BACKUP_FILE:-}" ] && [ -f "$BACKUP_FILE" ]; then
		say "   ->    eski dosyalar geri yükleniyor ($BACKUP_FILE)"
		rm -rf "$APP_DIR" >>"$LOG" 2>&1 || true
		tar -xzf - -C "$(dirname "$APP_DIR")" <"$BACKUP_FILE" >>"$LOG" 2>&1 || say "   HATA  geri yükleme başarısız — elle açın: $BACKUP_FILE"
		chown -R "$WEBUSER:$WEBUSER" "$APP_DIR" >>"$LOG" 2>&1 || true
		if [ "${WAS_ENABLED:-0}" = 1 ]; then
			occ app:enable "$APP_ID" >>"$LOG" 2>&1 && say "   ->    eski sürüm yeniden etkinleştirildi" 				|| say "   HATA  eski sürüm etkinleştirilemedi — elle: occ app:enable $APP_ID"
		fi
	else
		say "   ->    önceki sürüm yoktu, yeni dosyalar kaldırılıyor"
		occ app:disable "$APP_ID" >>"$LOG" 2>&1 || true
		rm -rf "$APP_DIR" >>"$LOG" 2>&1 || true
	fi
	# Yarıda kalan `occ upgrade` sunucuyu bakım kipinde bırakabiliyor.
	occ maintenance:mode --off >>"$LOG" 2>&1 || true
	say "   Nextcloud bakım kipinden çıkarıldı. Durum: occ status"
}

say ""
say "######## $APP_ID kurulumu $TS  (paket: $PKG_DIR)"
[ "$DRY" = 1 ] && say "######## DRY-RUN: hiçbir şey değişmeyecek"

# ---------------------------------------------------------------- 1. ön kontrol
step "1. Ön kontrol"
[ "$(id -u)" = 0 ] || die "root olarak çalıştırın:  sudo $0"
PKG_APP="$PKG_DIR/$APP_ID"
if [ "$UNINSTALL" = 0 ]; then
	[ -f "$PKG_APP/appinfo/info.xml" ] || die "paket eksik: $APP_ID/appinfo/info.xml yok"
	[ -f "$PKG_APP/js/$APP_ID-main.js" ] || die "paket eksik: derlenmiş ön yüz (js/$APP_ID-main.js) yok"
	PKG_VER=$(xml_get "$PKG_APP/appinfo/info.xml" version)
	NC_MIN=$(sed -n 's:.*<nextcloud min-version="\([0-9]*\)".*:\1:p' "$PKG_APP/appinfo/info.xml" | head -1)
	NC_MAX=$(sed -n 's:.*<nextcloud[^>]*max-version="\([0-9]*\)".*:\1:p' "$PKG_APP/appinfo/info.xml" | head -1)
	PHP_MIN=$(sed -n 's:.*<php min-version="\([0-9.]*\)".*:\1:p' "$PKG_APP/appinfo/info.xml" | head -1)
	[ -n "$PKG_VER" ] && [ -n "$NC_MIN" ] || die "paket bozuk: info.xml'den sürüm okunamadı"
	ok "paket: $APP_ID $PKG_VER (Nextcloud $NC_MIN${NC_MAX:+–$NC_MAX}$([ -n "$NC_MAX" ] || echo +), PHP $PHP_MIN+)"
fi

# Snap Nextcloud kapalı kutu: kendi PHP'si, salt okunur apps dizini.
if [ -z "$NC_OPT" ] && { [ -d /var/snap/nextcloud ] || { command -v snap >/dev/null && snap list nextcloud >/dev/null 2>&1; }; }; then
	die "snap ile kurulmuş Nextcloud desteklenmiyor (klasik kurulum gerekli: Apache/nginx + PHP)"
fi

NC=""
for d in "$NC_OPT" /var/www/nextcloud /var/www/html/nextcloud /var/www/html /srv/www/nextcloud /usr/share/nginx/nextcloud /opt/nextcloud; do
	if [ -n "$d" ] && [ -f "$d/occ" ] && [ -f "$d/config/config.php" ]; then NC="$d"; break; fi
done
[ -n "$NC" ] || die "Nextcloud bulunamadı — --nc-path ile verin"
[ -n "$WEBUSER" ] || WEBUSER=$(stat -c %U "$NC/config/config.php")
[ "$WEBUSER" != root ] || die "config.php'nin sahibi root — web kullanıcısını --web-user ile verin"
ok "Nextcloud: $NC (web kullanıcısı: $WEBUSER)"

STATUS=$(occ status --output=json 2>>"$LOG") || die "occ çalışmadı (ayrıntı: $LOG)"
[ "$(echo "$STATUS" | json '$j["installed"] ? "1" : "0"')" = 1 ] || die "Nextcloud kurulu değil"
if [ "$(echo "$STATUS" | json '$j["maintenance"] ? "1" : "0"')" = 1 ]; then
	die "Nextcloud bakım kipinde — önce kapatın:  occ maintenance:mode --off"
fi
if [ "$(echo "$STATUS" | json '!empty($j["needsDbUpgrade"]) ? "1" : "0"')" = 1 ]; then
	die "Nextcloud bekleyen bir yükseltme bekliyor (needsDbUpgrade) — önce onu tamamlayın:  occ upgrade"
fi
NC_VER=$(echo "$STATUS" | json '$j["versionstring"]'); NC_MAJOR=${NC_VER%%.*}
ok "Nextcloud $NC_VER çalışıyor"

# ================================================================ KALDIRMA
if [ "$UNINSTALL" = 1 ] && [ "$KEEP_DATA" = 1 ]; then
	step "Kaldırma (--keep-data)"
	APP_DIR=$(occ app:getpath "$APP_ID" 2>/dev/null || true)
	[ -n "$APP_DIR" ] || { ok "$APP_ID zaten kurulu değil"; exit 0; }
	say "   Veritabanındaki duyurular ve ayarlar SİLİNMEZ; yeniden kurulunca geri gelir."
	ask "$APP_ID kapatılıp $APP_DIR kaldırılsın mı?" || { say "vazgeçildi"; exit 0; }
	if [ "$DRY" = 0 ]; then
		mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
		BACKUP_FILE="$BACKUP_DIR/$APP_ID-$(installed_version)-$TS.tar.gz"
		tar -czf - -C "$(dirname "$APP_DIR")" "$APP_ID" >"$BACKUP_FILE" 2>>"$LOG" || die "yedek alınamadı"
		ok "yedek: $BACKUP_FILE"
	fi
	do_ "occ app:disable $APP_ID" occ app:disable "$APP_ID"
	do_ "dosyalar kaldırılıyor" rm -rf "$APP_DIR"
	say ""; say "Kaldırıldı. Yedek: $BACKUP_FILE"
	exit 0
fi

# TAM TEMİZLİK. Nextcloud bir app'i kaldırırken (web'de "Kaldır", `occ app:remove`)
# YALNIZ klasörü siler. Tablolar, migration ve sürüm kayıtları, ayarlar, arka plan
# işleri, bildirim/etkinlik/yorumlar ve pano düzenleri kalır; sonraki kurulumda
# migration'lar "zaten koştu" sayılır ve tablolar oluşmaz. Ayrıntı: tools/purge.php.
# App kodu gerekmez: web'den kaldırılmış ya da eski sürüm kalıntısı da temizlenir.
if [ "$UNINSTALL" = 1 ]; then
	[ -f "$PKG_DIR/tools/purge.php" ] || die "paket eksik: tools/purge.php yok"
	purge() {  # purge [--dry-run] -> çıktı PURGE_OUT'ta ve log'da; dönüş kodu betiğinki
		install -m 644 "$PKG_DIR/tools/purge.php" /tmp/noticeboard-purge.php
		local rc=0
		PURGE_OUT=$(W sh -c "cd /tmp && php /tmp/noticeboard-purge.php '$NC' $*" 2>&1) || rc=$?
		rm -f /tmp/noticeboard-purge.php
		say "$PURGE_OUT"
		return $rc
	}

	step "Kalıntı taraması"
	# Aynı app birden çok uygulama dizininde durabilir (apps/ + custom_apps/); hepsi gider.
	DIRS=()
	while IFS= read -r root; do
		{ [ -n "$root" ] && [ -d "$root/$APP_ID" ]; } || continue
		d=$(readlink -f "$root/$APP_ID")
		case " ${DIRS[*]-} " in *" $d "*) ;; *) DIRS+=("$d") ;; esac
	# json() ifadeyi `echo <ifade>;` içine koyar: burası ifade olmalı, deyim (foreach) DEĞİL.
	# Son "\n" şart: read son satırı satır sonu yoksa okumaz.
	done < <({ echo "$NC/apps"; occ config:system:get apps_paths --output=json 2>/dev/null \
		| json 'implode("\n", array_column((array)$j, "path")) . "\n"' || true; })
	for d in ${DIRS[@]+"${DIRS[@]}"}; do say "   uygulama klasörü: $d"; done
	[ -d "$BACKUP_DIR" ] && say "   eski sürüm yedekleri: $BACKUP_DIR"
	purge --dry-run || die "kalıntı taraması başarısız (ayrıntı yukarıda)"
	FOUND=$(echo "$PURGE_OUT" | sed -n 's/^TOPLAM: \([0-9][0-9]*\)$/\1/p' | tail -1)
	[ -n "$FOUND" ] || die "kalıntı taraması beklenmeyen çıktı verdi"
	[ "$FOUND" != 0 ] || [ "${#DIRS[@]}" != 0 ] || ok "Nextcloud'da $APP_ID izi yok"
	[ "$DRY" = 0 ] || { say ""; say "DRY-RUN: hiçbir şey silinmedi."; exit 0; }

	say ""
	say "   GERİ ALINAMAZ: duyurular, ekleri, yorumları, yayıncı ve vitrin ayarları kalıcı olarak silinir."
	say "   Kullanıcıların Dosyalar'ındaki kapak klasörlerine dokunulmaz (kullanıcı dosyası)."
	ask "$APP_ID ve yukarıdaki TÜM izleri kalıcı olarak silinsin mi?" || { say "vazgeçildi"; exit 0; }

	step "Siliniyor"
	# Önce kapat: etkin app'in altından tablo çekilirse açık istekler ve cron yarıda patlar.
	# Etkin değilse zararsız ("No such app enabled", çıkış 0).
	do_ "occ app:disable $APP_ID" occ app:disable "$APP_ID"
	for d in ${DIRS[@]+"${DIRS[@]}"}; do do_ "$d kaldırılıyor" rm -rf "$d"; done
	purge || die "temizlik tamamlanamadı — betiği yeniden çalıştırın, kaldığı yerden devam eder"
	[ "$(app_state)" = absent ] || die "Nextcloud $APP_ID uygulamasını hâlâ görüyor (occ app:list)"
	rm -rf "$BACKUP_DIR" /tmp/noticeboard-smoke.php /tmp/noticeboard-purge.php
	ok "eski sürüm yedekleri ve geçici dosyalar silindi"
	# Kurulum günlüğü de bizim izimiz: başarıda o da gider (hata olsaydı die onu korurdu).
	# Bu satırdan sonra say değil echo — say günlüğü yeniden oluşturur.
	rm -f "$LOG"
	echo ""
	echo "######## $APP_ID tamamen kaldırıldı: veritabanında, arka plan işlerinde ve diskte iz kalmadı."
	echo "  · Yeniden kurulum sıfırdan olur:  sudo ./install.sh"
	echo "  · Nextcloud'un kendi cron kaydına dokunulmadı (diğer uygulamalar da kullanır)."
	exit 0
fi

# ---------------------------------------------------------------- 2. uyumluluk
step "2. Uyumluluk"
PHP_VER=$(php -r 'echo PHP_VERSION;')
[ "$(ver_cmp "$PHP_VER" "$PHP_MIN")" -ge 0 ] || die "PHP $PHP_VER — en az $PHP_MIN gerekli"
ok "PHP $PHP_VER"
php -r 'exit(function_exists("mb_strlen") ? 0 : 1);' && ok "PHP mbstring" || die "PHP mbstring eklentisi yok (Nextcloud için de zorunlu)"

# Üst sınır: 0.4.0–0.5.2 arası info.xml'de YOKTU (her yeni Nextcloud kabul).
# App Store şeması zorunlu tuttuğu için max-version geri geldi (8 Kas 2026);
# varsa aşağıda NC_MAX ile denetlenir, aşan sürümde --force gerekir.
# NC_VERIFIED_MAX yalnız bilgi içindir — kaynağına karşı doğrulanan son sürüm.
# Daha yenisinde kurulum durmaz; adım 7'deki duman testi app'i GERÇEK
# Nextcloud'da yükler, kırmızıysa kurulum kendiliğinden geri alınır.
NC_VERIFIED_MAX=35
if [ -n "$NC_MAX" ]; then NC_RANGE="$NC_MIN-$NC_MAX"; else NC_RANGE="$NC_MIN+"; fi
NC_OK=1
[ "$NC_MAJOR" -ge "$NC_MIN" ] || NC_OK=0
[ -z "$NC_MAX" ] || [ "$NC_MAJOR" -le "$NC_MAX" ] || NC_OK=0
if [ "$NC_OK" = 1 ]; then
	ok "Nextcloud $NC_MAJOR destekleniyor ($NC_RANGE)"
	[ "$NC_MAJOR" -le "$NC_VERIFIED_MAX" ] || warn "Nextcloud $NC_MAJOR bu paket hazırlanırken henüz doğrulanmamıştı (son doğrulanan: $NC_VERIFIED_MAX) — duman testi denetleyecek"
elif [ "$FORCE" = 1 ]; then warn "Nextcloud $NC_MAJOR desteklenen aralığın ($NC_RANGE) dışında — --force ile devam, DOĞRULANMADI"
else die "Nextcloud $NC_MAJOR desteklenmiyor (bu paket: $NC_RANGE). Yine de denemek için --force"; fi

STATE=$(app_state); OLD_VER=$(installed_version)
CUR_DIR=$(occ app:getpath "$APP_ID" 2>/dev/null || true)
if [ "$STATE" = absent ] || [ -z "$OLD_VER" ]; then
	MODE=install; ok "ilk kurulum"
else
	case "$(ver_cmp "$PKG_VER" "$OLD_VER")" in
		1)  MODE=upgrade;   ok "güncelleme: $OLD_VER -> $PKG_VER ($STATE)" ;;
		0)  MODE=reinstall; ok "aynı sürüm ($OLD_VER) kurulu — dosyalar yenilenecek ($STATE)" ;;
		-1) [ "$FORCE" = 1 ] || die "kurulu sürüm ($OLD_VER) paketten ($PKG_VER) YENİ — sürüm düşürme veritabanını bozabilir. Yine de: --force"
		    MODE=reinstall; warn "sürüm düşürülüyor $OLD_VER -> $PKG_VER (--force)" ;;
	esac
fi

if [ "$(occ app:list --output=json 2>/dev/null | json 'isset($j["enabled"]["announcementcenter"]) ? "1" : "0"')" = 1 ]; then
	warn "resmi 'announcementcenter' de kurulu — ikisi yan yana çalışır, menüde iki ayrı duyuru girişi görünür"
fi

# ---------------------------------------------------------------- 3. hedef dizin
step "3. Uygulama dizini"
if [ -n "$CUR_DIR" ]; then
	APP_DIR="$CUR_DIR"; ok "mevcut yer: $APP_DIR"
else
	# apps_paths içinde yazılabilir ilk dizin (ör. custom_apps); yoksa $NC/apps.
	APPS_ROOT=$(occ config:system:get apps_paths --output=json 2>/dev/null | json '
		$p = ""; foreach ((array)$j as $e) { if (!empty($e["writable"])) { $p = $e["path"]; break; } } echo $p;' || true)
	[ -n "$APPS_ROOT" ] || APPS_ROOT="$NC/apps"
	test -d "$APPS_ROOT" || die "uygulama dizini yok: $APPS_ROOT"
	APP_DIR="$APPS_ROOT/$APP_ID"
	ok "kurulacak yer: $APP_DIR"
fi

# ---------------------------------------------------------------- 4. arka plan işleri
# İleri tarihli yayın, otomatik yayından kalkma ve e-posta kuyruğu arka plan işiyle çalışır.
step "4. Arka plan işleri (zamanlanmış yayın için)"
if [ "$SKIP_CRON" = 1 ]; then ok "atlandı (--skip-cron)"
else
	BJ_MODE=$(occ config:app:get core backgroundjobs_mode 2>/dev/null || echo ajax)
	if [ "$BJ_MODE" = cron ]; then ok "mod: cron"
	elif [ "$BJ_MODE" = webcron ]; then ok "mod: webcron (dış servis tetikliyor)"
	elif ask "Arka plan işleri '$BJ_MODE' modunda — zamanlanmış duyurular ancak biri sayfa açınca yayınlanır. cron'a geçilsin mi?"; then
		do_ "occ background:cron" occ background:cron
		BJ_MODE=cron
	else warn "mod '$BJ_MODE' — zamanlanmış yayın ve e-posta gecikebilir"; fi

	if [ "$BJ_MODE" = cron ]; then
		if command -v crontab >/dev/null && crontab -u "$WEBUSER" -l 2>/dev/null | grep -q "cron.php"; then ok "$WEBUSER crontab'ında cron.php var"
		elif grep -rqs "cron.php" /etc/cron.d/ /etc/crontab 2>/dev/null; then ok "/etc/cron.d içinde cron.php var"
		elif systemctl list-timers --all 2>/dev/null | grep -qi nextcloud; then ok "systemd zamanlayıcısı var"
		elif command -v crontab >/dev/null && ask "$WEBUSER için cron.php kaydı (5 dk'da bir) eklensin mi?"; then
			TMP=$(mktemp); crontab -u "$WEBUSER" -l 2>/dev/null >"$TMP" || true
			echo "*/5 * * * * php -f $NC/cron.php" >>"$TMP"
			do_ "crontab -u $WEBUSER" crontab -u "$WEBUSER" "$TMP"; rm -f "$TMP"
		else warn "cron.php'yi çalıştıran bir kayıt bulunamadı — Nextcloud arka plan işleri koşmayabilir"; fi
	fi
fi

SMTP=$(occ config:system:get mail_smtpmode 2>/dev/null || true)
if [ -n "$SMTP" ] && [ "$SMTP" != null ]; then ok "e-posta: $SMTP ayarlı (e-posta kanalı kullanılabilir)"
else warn "e-posta (SMTP) ayarlı görünmüyor — duyurunun 'E-posta' kanalı gönderim yapamaz. Yönetim → Temel ayarlar → E-posta sunucusu"; fi

# ---------------------------------------------------------------- 5. dosyalar
step "5. Dosyalar"
if [ "$DRY" = 1 ]; then :
elif [ "$MODE" != install ]; then
	ask "$APP_ID ${OLD_VER} -> $PKG_VER: dosyalar değiştirilsin mi? (eski sürüm yedeklenir)" || { say "vazgeçildi"; exit 0; }
else
	ask "$APP_ID $PKG_VER şuraya kurulsun mu: $APP_DIR ?" || { say "vazgeçildi"; exit 0; }
fi

if [ "$DRY" = 0 ] && test -d "$APP_DIR"; then
	mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
	BACKUP_FILE="$BACKUP_DIR/$APP_ID-${OLD_VER:-x}-$TS.tar.gz"
	tar -czf - -C "$(dirname "$APP_DIR")" "$APP_ID" >"$BACKUP_FILE" 2>>"$LOG" || die "yedek alınamadı"
	ok "eski sürüm yedeklendi: $BACKUP_FILE"
fi

# Dosyalar değişirken app AÇIK kalırsa Nextcloud yeni sürümü görüp tüm sunucuyu
# "güncelleme gerekli" sayfasına düşürür ve `occ upgrade` (sunucu geneli) ister.
# Önce kapatıp sonra açınca app:enable bekleyen migration'ları yalnız bu app için çalıştırır.
if [ "$STATE" = enabled ]; then
	do_ "occ app:disable $APP_ID (dosyalar değişirken)" occ app:disable "$APP_ID"
	[ "$DRY" = 1 ] || WAS_ENABLED=1
fi

if [ "$DRY" = 1 ]; then
	say "   (dry-run) $APP_DIR yerine paketteki $APP_ID kopyalanacak, sahibi $WEBUSER yapılacak"
else
	REPLACED=1
	rm -rf "$APP_DIR" >>"$LOG" 2>&1 || die "eski dizin silinemedi"
	cp -r "$PKG_APP" "$APP_DIR" 2>>"$LOG" || die "dosyalar kopyalanamadı"
	chown -R "$WEBUSER:$WEBUSER" "$APP_DIR" || die "chown başarısız"
	find "$APP_DIR" -type d -exec chmod 750 {} + && find "$APP_DIR" -type f -exec chmod 640 {} +
	# SELinux (Rocky/RHEL) kalıcı Enforcing ise etiket verilmezse web sunucusu app'i okuyamaz.
	if command -v restorecon >/dev/null; then restorecon -R "$APP_DIR" >>"$LOG" 2>&1 || true; fi
	ok "dosyalar yerinde ($(find "$APP_DIR" -type f | wc -l) dosya)"
fi

# ---------------------------------------------------------------- 6. etkinleştirme + veritabanı
step "6. Etkinleştirme ve veritabanı"
NCV_FLAG=""; [ "$NC_OK" = 1 ] || NCV_FLAG="--force"
# app:enable: ilk kurulumda tabloları oluşturur, güncellemede bekleyen migration'ları
# çalıştırır; info.xml'deki ayar sayfası, arka plan işi ve etkinlik kayıtlarını yeniden okur.
# Duyurular, yayıncılar ve kullanıcı ayarları bundan etkilenmez.
# Bu andan sonra şema eski sürümün önüne geçebilir: yeni migration varsa geri alma
# eski dosyaları geri koymaz (bkz. rollback).
SCHEMA_AHEAD=0
if [ "$DRY" = 0 ] && [ -n "${BACKUP_FILE:-}" ] && new_migrations "$BACKUP_FILE"; then SCHEMA_AHEAD=1; fi
do_ "occ app:enable $APP_ID" occ app:enable $NCV_FLAG "$APP_ID"

if [ "$DRY" = 0 ]; then
	# Güvenlik ağı: yine de "güncelleme gerekli" durumu oluştuysa (ör. başka bir app
	# yüzünden) sunucu kullanılamaz kalmasın.
	if [ "$(occ status --output=json | json '!empty($j["needsDbUpgrade"]) ? "1" : "0"')" = 1 ]; then
		warn "Nextcloud 'güncelleme gerekli' durumunda — 'occ upgrade' gerekiyor (bekleyen başka app güncellemeleri de uygulanır, kısa bakım kipi)"
		ask "occ upgrade çalıştırılsın mı?" || die "occ upgrade yapılmadan Nextcloud kullanılamaz"
		do_ "occ upgrade" occ upgrade
	fi
	[ "$(app_state)" = enabled ] || die "app etkinleşmedi"
	NOW_VER=$(installed_version)
	[ "$NOW_VER" = "$PKG_VER" ] || die "veritabanındaki sürüm $NOW_VER, beklenen $PKG_VER"
	[ "$(occ status --output=json | json '$j["maintenance"] ? "1" : "0"')" = 0 ] || die "Nextcloud bakım kipinde kaldı"
	ok "$APP_ID $NOW_VER etkin"
	# Tarayıcılar eski ön yüz parçalarını önbellekten koşmasın (sürüm artmadan dosya değiştiğinde).
	if [ "$MODE" != install ]; then
		CB=$(occ config:app:get theming cachebuster 2>/dev/null || echo 0)
		occ config:app:set theming cachebuster --value="$((CB + 1))" >>"$LOG" 2>&1 || true
		ok "tarayıcı önbelleği tazelendi"
	fi
fi

# ---------------------------------------------------------------- 7. duman testi
step "7. Doğrulama"
if [ "$DRY" = 1 ]; then say "   (dry-run) duman testi atlandı"
elif [ -f "$PKG_DIR/tools/smoke-test.php" ]; then
	install -m 644 "$PKG_DIR/tools/smoke-test.php" /tmp/noticeboard-smoke.php
	if W sh -c "cd /tmp && php /tmp/noticeboard-smoke.php '$NC'" 2>&1 | tee -a "$LOG"; then
		rm -f /tmp/noticeboard-smoke.php
		ok "app sınıfları gerçek Nextcloud içinde yüklendi"
	else
		rm -f /tmp/noticeboard-smoke.php
		die "duman testi kırmızı — app bu Nextcloud'da düzgün yüklenmiyor"
	fi
else warn "tools/smoke-test.php pakette yok — doğrulama atlandı"; fi
REPLACED=0

say ""
case "$MODE" in install) MODE_TR="ilk kurulum" ;; upgrade) MODE_TR="güncelleme $OLD_VER -> $PKG_VER" ;; *) MODE_TR="yeniden kurulum" ;; esac
[ "$DRY" = 1 ] && MODE_TR="$MODE_TR, DRY-RUN: hiçbir şey değişmedi"
say "######## Bitti: $APP_ID $PKG_VER ($MODE_TR)"
say "  · Sol menüde 'Duyuru Panosu' görünür (sayfayı yenileyin)."
say "  · Duyuruyu yöneticiler yayınlar. Başka kişi/gruplara yetki:"
say "      Yönetim ayarları -> Duyuru Panosu -> Yayıncılar"
say "  · Pano (Dashboard) -> Widget'ları özelleştir: Liste / Kart / Vitrin"
[ -n "${BACKUP_FILE:-}" ] && say "  · Önceki sürümün yedeği: $BACKUP_FILE"
say "  · Log: $LOG"
