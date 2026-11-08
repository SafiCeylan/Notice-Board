#!/usr/bin/env bash
#
# Duyuru Panosu — HERKESE AÇIK kaynak deposunun içeriğini hazırlar.
#
# Neden: App Store herkese açık bir kaynak deposu, hata takip adresi ve indirme
# bağlantısı ister. Asıl (özel) depoda ise iç notlar var: CLAUDE.md (sunucu adresi,
# deploy ayrıntıları), deploy.sh, güvenlik denetimi raporları, günlük raporlar.
# Bunlar açık depoya GİRMEZ. Bu betik BEYAZ LİSTE ile dist/public-repo/ klasörünü
# kurar, sonra sızıntıya karşı tarar. Kırmızıysa klasörü siler.
#
# Betik git'e DOKUNMAZ ve hiçbir yere göndermez. Açık depoyu oluşturmak ve
# göndermek yayıncının işidir (docs/YAYIN.md, 2. adım).
#
# Kullanım:  ./tools/public-export.sh
# Çıktı:     dist/public-repo/   (her koşuda yeniden kurulur; .git korunur)
#
# ⚠️ Yeni bir kaynak klasörü/dosyası eklersen ve açık depoda olması gerekiyorsa
#    aşağıdaki listeye de ekle.
set -euo pipefail

HERE="$(cd "$(dirname "$0")/.." && pwd)"
cd "$HERE"
OUT=dist/public-repo

red() { printf '\033[31m%s\033[0m\n' "$*"; }
grn() { printf '\033[32m%s\033[0m\n' "$*"; }

DIRS="appinfo lib templates img css l10n src tests tools/appstore"
FILES="README.md README.tr.md COPYING CHANGELOG.md SECURITY.md .gitignore .gitattributes
package.json package-lock.json webpack.config.js babel.config.js
build-l10n.py run-tests.sh mutation-check.sh build-release.sh install.sh purge.php smoke-test.php
tools/public-export.sh
docs/KURULUM.md docs/KURULUM.pdf docs/KISA-KURULUM.md docs/KISA-KURULUM.pdf"

# dist/public-repo/.git açık deponun yerel kopyasıdır (8 Kas 2026'dan beri): KORUNUR.
# Geri kalan her şey silinip beyaz listeden yeniden kurulur; sonra orada
# `git add -A && git commit && git push` ile açık depo güncellenir (docs/YAYIN.md).
mkdir -p "$OUT"
clean_out() { find "$OUT" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +; }
clean_out
for d in $DIRS; do
	[ -d "$d" ] || { red "✖ klasör yok: $d"; exit 1; }
	mkdir -p "$OUT/$(dirname "$d")"; cp -r "$d" "$OUT/$d"
done
for f in $FILES; do
	[ -f "$f" ] || { red "✖ dosya yok: $f"; exit 1; }
	mkdir -p "$OUT/$(dirname "$f")"; cp "$f" "$OUT/$f"
done
find "$OUT" \( -name '__pycache__' -o -name '*.pyc' -o -name '.DS_Store' -o -name 'Thumbs.db' \) -prune -exec rm -rf {} +

FAIL=0
while IFS= read -r bad; do red "  ✖ YASAK DOSYA: ${bad#$OUT/}"; FAIL=1; done < <(find "$OUT" -path "$OUT/.git" -prune -o \( \
	-name '.env*' -o -name '*.pem' -o -name '*.key' -o -name '*.csr' -o -name '*.crt' -o -name 'id_rsa*' \
	-o -name 'CLAUDE.md' -o -name 'ROADMAP.md' -o -name 'GUVENLIK_DENETIMI*' -o -name 'deploy.sh' \
	-o -name 'node_modules' -o -name '.git' -o -name 'dist' -o -path "$OUT/js" -o -name 'GUNLUK_RAPOR*' \) -print)
while IFS= read -r hit; do red "  ✖ SIZINTI: ${hit#$OUT/}"; FAIL=1; done < <(
	grep -rEIon '172\.(1[6-9]|2[0-9]|3[01])\.[0-9]+\.[0-9]+|192\.168\.[0-9]+\.[0-9]+|root@[0-9]|BEGIN [A-Z ]*PRIVATE KEY|\.ssh/[A-Za-z0-9_.-]+\.pem' \
	"$OUT" --exclude-dir=.git 2>/dev/null | cut -c1-160 || true)
if [ "$FAIL" != 0 ]; then clean_out; red "✖ Tarama kırmızı — açık depo içeriği ÜRETİLMEDİ."; exit 1; fi
grn "✔ $OUT hazır ($(find "$OUT" -path "$OUT/.git" -prune -o -type f -print | wc -l) dosya) — iç not/deploy/rapor dosyası yok."
