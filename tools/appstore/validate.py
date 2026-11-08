#!/usr/bin/env python3
"""
App Store ön denetimi — apps.nextcloud.com'un yükleme anında yaptığı denetimin aynısı.

Mağaza (nextcloud/appstore, nextcloudappstore/api/v1/release/parser.py) info.xml'i
önce pre-info.xslt ile yeniden sıralar, sonra info.xsd ile doğrular. Bu iki dosya
mağaza deposundan birebir kopyadır (master, info.xsd son değişiklik c8fe8dcb38,
20 Ağu 2026; indirildi 8 Kas 2026). Mağaza şemayı değiştirirse buradakini yenile:

  gh api repos/nextcloud/appstore/contents/nextcloudappstore/api/v1/release/info.xsd \
     -H "Accept: application/vnd.github.raw" > tools/appstore/info.xsd
  (pre-info.xslt için aynı yol)

Ayrıca: name/summary/description için İngilizce ("en" ya da dilsiz) şart, arşiv
tek üst klasör = app id, .git yasak, arşiv en fazla 30 MB, info.xml en fazla 1 MB.

Kullanım:  python -I tools/appstore/validate.py appinfo/info.xml [arşiv.tar.gz]
Çıkış: 0 geçerli · 1 geçersiz · 3 lxml yok (denetim yapılamadı)
"""
import os
import re
import sys
import tarfile

# Windows konsolu cp1254: ✔/✖ basılamayıp denetim "kırmızı" görünüyordu (8 Kas 2026).
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")

try:
    import lxml.etree as E
except ImportError:
    print("lxml yok — mağaza denetimi YAPILAMADI (pip install lxml)")
    sys.exit(3)

HERE = os.path.dirname(os.path.abspath(__file__))
MAX_ARCHIVE = 30 * 1024 ** 2
MAX_INFO = 1024 ** 2
APP_FOLDER = re.compile(r"^[a-z]+[a-z0-9_]*(?:/.*)*$")


def fail(msg):
    print("  ✖ " + msg)
    return 1


def check_info(xml_bytes):
    errors = 0
    parser = E.XMLParser(resolve_entities=False)
    doc = E.fromstring(xml_bytes, parser)
    for _ in doc.iter(E.Entity):
        errors += fail("info.xml varlık (entity) içermemeli")
    pre = E.XSLT(E.parse(os.path.join(HERE, "pre-info.xslt")))
    schema = E.XMLSchema(E.parse(os.path.join(HERE, "info.xsd")))
    transformed = pre(doc)
    if not schema.validate(transformed):
        for e in schema.error_log:
            errors += fail(f"şema: {e.message}")
    for field in ("name", "summary", "description"):
        langs = [el.get("lang", "en") for el in doc.findall(field)]
        if "en" not in langs:
            errors += fail(f'İngilizce "{field}" yok (dilsiz ya da lang="en")')
    return doc, errors


def main(argv):
    if len(argv) < 2:
        print(__doc__)
        return 2
    with open(argv[1], "rb") as f:
        doc, errors = check_info(f.read())
    app_id = doc.findtext("id")
    version = doc.findtext("version")

    if len(argv) > 2:
        path = argv[2]
        if os.path.getsize(path) > MAX_ARCHIVE:
            errors += fail("arşiv 30 MB'tan büyük")
        with tarfile.open(path, "r:gz") as tar:
            names = tar.getnames()
            folders = {n.split("/")[0] for n in names if APP_FOLDER.match(n)}
            others = {n.split("/")[0] for n in names} - folders
            if folders != {app_id} or others:
                errors += fail(f"arşivde tek üst klasör '{app_id}' olmalı, bulunan: {sorted(folders | others)}")
            if any(re.search(r"\.git$", n) for n in names):
                errors += fail(".git klasörü/dosyası var")
            try:
                member = tar.getmember(f"{app_id}/appinfo/info.xml")
                if member.size > MAX_INFO:
                    errors += fail("info.xml 1 MB'tan büyük")
                packed = tar.extractfile(member).read()
                with open(argv[1], "rb") as f:
                    if packed.replace(b"\r\n", b"\n") != f.read().replace(b"\r\n", b"\n"):
                        errors += fail("arşivdeki info.xml denetlenenle aynı değil")
            except KeyError:
                errors += fail(f"{app_id}/appinfo/info.xml arşivde yok")
            if f"{app_id}/CHANGELOG.md" in names:
                text = tar.extractfile(f"{app_id}/CHANGELOG.md").read().decode("utf-8")
                if not re.search(r"^## (?:\[)?(?:v)?" + re.escape(version) + r"\b", text, re.M):
                    errors += fail(f"CHANGELOG.md'de '## {version}' başlığı yok (mağaza sürüm notunu boş gösterir)")
            else:
                errors += fail("CHANGELOG.md yok (mağaza sürüm notunu buradan okur)")

    if errors:
        print(f"  ✖ App Store denetimi: {errors} hata")
        return 1
    print(f"  ✔ App Store denetimi geçti ({app_id} {version})")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
