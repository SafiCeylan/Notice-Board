#!/usr/bin/env bash
#
# Duyuru Panosu — söz dizimi denetimi + birim testleri.
#
# Kullanım:  ./run-tests.sh
#            PHP=/baska/yol/php.exe ./run-tests.sh
#
# ⚠️ CRLF TUZAĞI: bu dosya Windows'ta düzenleniyor, satır sonları LF kalmalı.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
cd "$HERE"

PHP="${PHP:-C:/Users/memoc/OneDrive/Desktop/Projeler/php/php.exe}"
PHP_EXT_DIR="${PHP_EXT_DIR:-C:/Users/memoc/OneDrive/Desktop/Projeler/php/ext}"

if ! "$PHP" -v >/dev/null 2>&1; then
  echo "HATA: PHP bulunamadi. PHP=/tam/yol/php.exe ./run-tests.sh" >&2
  exit 1
fi

# `-n`: yerel php.ini bozuk bir extension_dir gosterdigi icin ini'siz kosuyoruz.
# mbstring ELLE aciliyor: basliklar Turkce, karakter sayimi mb_strlen ile yapiliyor;
# eklenti yoksa testler "undefined function" ile patlar (23 Eyl 2026'da oldu).
PHP_FLAGS=(-n -d "extension_dir=$PHP_EXT_DIR" -d extension=mbstring)

echo "==> 1/3 PHP soz dizimi"
find lib tests purge.php -name '*.php' -print0 | xargs -0 -n1 "$PHP" "${PHP_FLAGS[@]}" -l >/dev/null
echo "    lib/, tests/ ve purge.php temiz"

echo "==> 2/3 appinfo/info.xml gecerli XML mi"
"$PHP" "${PHP_FLAGS[@]}" -r '
    $x = @simplexml_load_file("appinfo/info.xml");
    if ($x === false) { fwrite(STDERR, "HATA: info.xml bozuk\n"); exit(1); }
    echo "    info.xml gecerli (surum " . $x->version . ")\n";
'

# Kaynak dil Turkce; Nextcloud Ingilizceyken arayuz l10n/en.json'dan cevrilir.
# Cevirisi olmayan metin varsa burada yakalanir, kullanici karisik dil gormeden.
# ⚠️ Birim testlerden ONCE kosar: L10nContractTest taze en.json'u okumali,
# yoksa bir onceki kosunun eski sozlugunu denetler.
echo "==> 3/4 Ceviri butunlugu"
PYTHONIOENCODING=utf-8 python build-l10n.py

echo ""
echo "==> 4/4 Birim testleri"
for t in tests/*Test.php; do
  "$PHP" "${PHP_FLAGS[@]}" "$t"
done

echo ""
echo "==> 5/5 JS birim testleri (kapak duzenleyicisi yardimcilari)"
node --disable-warning=MODULE_TYPELESS_PACKAGE_JSON --test "tests/js/*.test.mjs"

echo ""
echo "Hepsi yesil."
