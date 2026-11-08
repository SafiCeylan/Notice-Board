#!/usr/bin/env bash
#
# Duyuru Panosu — MÜŞTERİ KURULUM PAKETİ üretir.
#
# ⚠️ `deploy.sh` ile KARIŞTIRMA:
#   · deploy.sh        → İÇ geliştirme; kendi test sunucumuza SSH ile atar.
#   · build-release.sh → DIŞ dağıtım; kurulum betiği + app + rehber tek arşivde.
#                        BEYAZ LİSTE: burada adı geçmeyen dosya pakete GİREMEZ.
#                        Sonra paket sızıntıya karşı taranır; kırmızıysa arşiv ÜRETİLMEZ.
#
# Kullanım:
#   ./build-release.sh                 # test + build + paketle
#   ./build-release.sh --skip-build    # mevcut js/ çıktısını kullan
#   ./build-release.sh --skip-tests    # testleri atla (önerilmez)
#
# Çıktı:
#   dist/noticeboard-<sürüm>.tar.gz (+ .sha256)
#     noticeboard-<sürüm>/
#       install.sh            kurulum / güncelleme / kaldırma betiği
#       noticeboard/          Nextcloud uygulaması (apps/ altına giden klasör)
#       tools/smoke-test.php  kurulum sonrası doğrulama (app klasörüne girmez)
#       tools/purge.php       install.sh --uninstall: Nextcloud'daki tüm izleri siler
#       KURULUM.md / .pdf     kurulum rehberi
#       GUNCELLEME-<sürüm>.md / .pdf  bu sürümün güncelleme notları (docs/'ta varsa)
#       KISA-KURULUM.md / .pdf        tek sayfalık kısa kurulum (docs/'ta varsa)
#   dist/appstore/noticeboard-<sürüm>.tar.gz (+ .sha256)   ← APPS.NEXTCLOUD.COM için
#     noticeboard/            YALNIZ uygulama (mağaza tek üst klasör = app id ister;
#                             install.sh/tools/rehber GİRMEZ). İmzalama ve yükleme:
#                             docs/YAYIN.md. Mağaza denetimi: tools/appstore/validate.py
#
# ⚠️ CRLF TUZAĞI: bu dosya Windows'ta düzenleniyor, satır sonları LF kalmalı.
set -euo pipefail

APP_ID=noticeboard
HERE="$(cd "$(dirname "$0")" && pwd)"
cd "$HERE"

SKIP_BUILD=0; SKIP_TESTS=0
for arg in "$@"; do
	case "$arg" in
		--skip-build) SKIP_BUILD=1 ;;
		--skip-tests) SKIP_TESTS=1 ;;
		-h|--help) sed -n 3,30p "$0"; exit 0 ;;
		*) echo "Bilinmeyen seçenek: $arg" >&2; exit 2 ;;
	esac
done

red() { printf '\033[31m%s\033[0m\n' "$*"; }
grn() { printf '\033[32m%s\033[0m\n' "$*"; }
die() { red "✖ $*"; exit 1; }

VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -1)"
[ -n "$VERSION" ] || die "appinfo/info.xml içinden <version> okunamadı."
NAME="$APP_ID-$VERSION"
OUT=dist
STAGE="$OUT/$NAME"
echo "▶ $NAME"

echo "==> 1/6 Testler"
if [ "$SKIP_TESTS" = 0 ]; then ./run-tests.sh >/dev/null && grn "    · hepsi yeşil"
else red "    · ATLANDI (--skip-tests)"; fi

echo "==> 2/6 Ön yüz derlemesi"
if [ "$SKIP_BUILD" = 0 ]; then npm run build >/dev/null 2>&1 || die "npm run build başarısız (ayrıntı için elle çalıştırın)"
	grn "    · derlendi"
else red "    · ATLANDI (--skip-build) — js/ bayat olabilir"; fi
[ -f "js/$APP_ID-main.js" ] || die "js/$APP_ID-main.js yok. Önce: npm run build"

echo "==> 3/6 Paket içeriği (beyaz liste)"
rm -rf "$STAGE"; mkdir -p "$STAGE/$APP_ID" "$STAGE/tools"
# Yeni bir çalışma-zamanı klasörü eklersen BU LİSTEYE de ekle, yoksa müşteride eksik çıkar.
# js/ tümüyle alınır: webpack `clean: true` ile her derlemede klasörü sıfırlıyor,
# içinde yalnız güncel parçalar var (tembel parçalar ayrı dosyalar, adları içerik damgalı).
for d in appinfo lib templates img css l10n js; do
	[ -d "$d" ] || die "beklenen klasör yok: $d"
	cp -r "$d" "$STAGE/$APP_ID/$d"
done
# Lisans metni ve sürüm notları app klasörünün KÖKÜNE: mağaza sürüm notunu
# <app>/CHANGELOG.md'den okur; AGPL metni uygulamayla birlikte dağıtılmalı.
cp COPYING CHANGELOG.md "$STAGE/$APP_ID/"
cp install.sh "$STAGE/install.sh"
cp smoke-test.php "$STAGE/tools/smoke-test.php"
cp purge.php "$STAGE/tools/purge.php"
cp docs/KURULUM.md "$STAGE/KURULUM.md"
[ -f docs/KURULUM.pdf ] && cp docs/KURULUM.pdf "$STAGE/KURULUM.pdf"
# Sürümün güncelleme notları (varsa): yalnız bu sürümünki girer. Kısa kurulum da (varsa).
for f in "docs/GUNCELLEME-$VERSION.md" "docs/GUNCELLEME-$VERSION.pdf" docs/KISA-KURULUM.md docs/KISA-KURULUM.pdf; do
	[ -f "$f" ] && cp "$f" "$STAGE/$(basename "$f")"
done
find "$STAGE" \( -name '*.map' -o -name '.DS_Store' -o -name 'Thumbs.db' -o -name '*~' \) -delete
# Windows'ta düzenlenen kaynaklar CRLF olabilir; sunucuya LF gider. Derlenmiş js'e dokunulmaz.
find "$STAGE" -type f \( -name '*.php' -o -name '*.xml' -o -name '*.json' -o -name '*.css' \
	-o -name '*.svg' -o -name '*.md' -o -name '*.sh' \) -exec sed -i 's/\r$//' {} +
find "$STAGE/$APP_ID/l10n" -name '*.js' -exec sed -i 's/\r$//' {} +
chmod 755 "$STAGE/install.sh"

echo "==> 4/6 Sızıntı ve bütünlük taraması"
FAIL=0
while IFS= read -r bad; do red "    ✖ YASAK DOSYA: ${bad#$STAGE/}"; FAIL=1; done < <(find "$STAGE" \( \
	-name '.env*' -o -name '*.pem' -o -name '*.key' -o -name 'id_rsa*' -o -name 'CLAUDE.md' \
	-o -name 'ROADMAP.md' -o -name 'GUVENLIK_DENETIMI*' -o -name 'deploy.sh' -o -name 'node_modules' \
	-o -name 'package*.json' -o -name 'webpack.config.js' -o -name '*.map' -o -name 'tests' \) -print)
# İç altyapı izleri ve sırlar. `grep -o` ŞART: derlenmiş js tek satır, -o olmadan satırın tamamı basılır.
# Desenler GENEL tutulur (bu dosya açık depoda da duruyor): iç adresin kendisini yazma.
# 172.16/12 ve 192.168/16 özel ağ aralıkları; 10/8 bilerek yok (js'teki sürüm dizileriyle karışır).
while IFS= read -r hit; do red "    ✖ SIZINTI: ${hit#$STAGE/}"; FAIL=1; done < <(
	grep -rEon '172\.(1[6-9]|2[0-9]|3[01])\.[0-9]+\.[0-9]+|192\.168\.[0-9]+\.[0-9]+|root@[0-9]|BEGIN [A-Z ]*PRIVATE KEY|\.ssh/[A-Za-z0-9_.-]+\.pem|//[#@] sourceMappingURL=' \
	"$STAGE" --exclude=smoke-test.php 2>/dev/null | cut -c1-160 || true)
# -U/$'\r' KULLANMA: Git Bash betik içinde $'\r'yi BOŞ metne çeviriyor, boş desen her dosyayı
# "CRLF" sayıyordu (6 Eki 2026). tr '\r' kaçışını kendisi çözer, kabuğa bağlı değil.
while IFS= read -r -d '' f; do
	[ "$(tr -cd '\r' <"$f" | wc -c)" = 0 ] || { red "    ✖ CRLF: ${f#$STAGE/}"; FAIL=1; }
done < <(find "$STAGE" -type f ! -path "*/js/*" ! -name '*.png' ! -name '*.pdf' -print0)
head -1 "$STAGE/install.sh" | grep -q '^#!/bin/bash$' || { red "    ✖ install.sh shebang bozuk"; FAIL=1; }
bash -n "$STAGE/install.sh" || { red "    ✖ install.sh söz dizimi"; FAIL=1; }
[ "$FAIL" = 0 ] || die "Tarama kırmızı — PAKET ÜRETİLMEDİ."
grn "    · temiz ($(find "$STAGE/$APP_ID" -type f | wc -l) app dosyası)"

echo "==> 5/6 Müşteri arşivi"
rm -f "$OUT/$NAME.tar.gz" "$OUT/$NAME.tar.gz.sha256"
# Sahiplik 0:0 — Windows kullanıcısının uid'i (197609) sunucuya taşınmasın.
tar --owner=0 --group=0 --numeric-owner -czf "$OUT/$NAME.tar.gz" -C "$OUT" "$NAME"
(cd "$OUT" && sha256sum "$NAME.tar.gz" > "$NAME.tar.gz.sha256")
grn "✔ $OUT/$NAME.tar.gz ($(du -h "$OUT/$NAME.tar.gz" | cut -f1))"
cat "$OUT/$NAME.tar.gz.sha256"

echo "==> 6/6 App Store arşivi (apps.nextcloud.com)"
# Mağaza: arşivde TEK üst klasör = app id, içinde appinfo/info.xml; .git yasak; ≤ 30 MB.
# Müşteri paketinin noticeboard/ klasörünün AYNISI, yalnız sarmalayıcısız.
# İmza (openssl) burada ATILMAZ: özel anahtar yalnız yayıncının makinesinde — docs/YAYIN.md.
STORE="$OUT/appstore"
mkdir -p "$STORE"
rm -f "$STORE/$NAME.tar.gz" "$STORE/$NAME.tar.gz.sha256"
tar --owner=0 --group=0 --numeric-owner -czf "$STORE/$NAME.tar.gz" -C "$STAGE" "$APP_ID"
# Gerçekten ÇALIŞAN yorumlayıcıyı seç: Windows'ta `python3` Microsoft Store yönlendiricisi
# olabilir ("Python bulunamadı" der ve hata döner; 8 Kas 2026'da görüldü).
STORE_PY=""
for c in python3 python; do
	"$c" -c 'import sys' >/dev/null 2>&1 && { STORE_PY="$c"; break; }
done
if [ -n "$STORE_PY" ]; then
	set +e; "$STORE_PY" -I tools/appstore/validate.py appinfo/info.xml "$STORE/$NAME.tar.gz"; RC=$?; set -e
	case "$RC" in
		0) ;;
		3) red "    · mağaza denetimi YAPILAMADI (lxml yok) — yüklemeden önce elle denetleyin" ;;
		*) rm -f "$STORE/$NAME.tar.gz"; die "App Store denetimi kırmızı — MAĞAZA ARŞİVİ ÜRETİLMEDİ (müşteri paketi hazır)." ;;
	esac
else
	red "    · python yok — mağaza denetimi YAPILAMADI"
fi
(cd "$STORE" && sha256sum "$NAME.tar.gz" > "$NAME.tar.gz.sha256")
grn "✔ $STORE/$NAME.tar.gz ($(du -h "$STORE/$NAME.tar.gz" | cut -f1))"
echo "    İmza (yayıncının makinesinde):"
echo "    openssl dgst -sha512 -sign ~/.nextcloud/certificates/$APP_ID.key $STORE/$NAME.tar.gz | openssl base64"
