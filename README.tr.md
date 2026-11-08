# Duyuru Panosu — Nextcloud

[English](README.md) · **Türkçe**

Duyuru Panosu (`noticeboard`), Nextcloud içinde kurumsal duyuru yayınlamak için bir uygulamadır.
Yetkili yayıncılar zengin metin editöründe duyuru yazar ve herkese ya da seçtikleri gruplara
hedefler. Okuyucular duyuruları uygulamada, üç pano widget'ında, üst arama kutusunda, bildirimlerde,
etkinlik akışında veya e-postada görür.

- **Nextcloud:** 30–36 (API’ler 32, 33, 34 ve 35’e karşı doğrulandı; canlı test 35). App Store üst sınır istiyor; her yeni Nextcloud sürümünde artırılır
- **PHP:** 8.1+
- **Ön yüz:** Vue 3 + `@nextcloud/vue` 9
- **Diller:** Türkçe, İngilizce
- **Lisans:** AGPL-3.0-or-later

---

## Özellikler

### Duyuru yazma
- Zengin metin gövde: kalın, italik, altı çizili, üstü çizili, başlıklar, listeler, alıntı, kod
  bloğu, bağlantı, yazı rengi ve vurgu. Formun yanındaki canlı önizleme, okuyucunun göreceğinin
  aynısını gösterir.
- Nextcloud dosyalarınızdan **kapak görseli**. **Odak noktasını** siz belirlersiniz: görselde bir
  yere tıklayın ya da işareti sürükleyin. Kapak nerede kırpılırsa kırpılsın (detay bandı, liste
  küçük resmi, kart widget'ı) o nokta görünür kalır.
- Dosyalarınızdan en fazla 20 **ek**.
- **Kategori**, **öncelik** (normal / önemli / acil) ve **en üste sabitleme**.
- **Taslak**, **ileri tarihli yayın** ve belirlenen zamanda **otomatik yayından kalkma**.

### Hedef kitle
- **Tüm kullanıcılar** ya da **seçilen Nextcloud grupları**. Hedeflenmeyen kullanıcı duyuruyu hiçbir
  yerde görmez: listede, widget'ta, aramada, bildirimde, hatta adresini tahmin ederek de.

### Dağıtım (her duyuruda ayrı ayrı açılıp kapanır)
| Seçenek | Ne yapar |
|---|---|
| Etkinlik akışı | Hedef kullanıcıların Nextcloud *Etkinlik* (Activity) akışına "duyuru yayınladı" kaydı düşer |
| Bildirim | Zil ikonuna bildirim gider; duyuru silinince ya da süresi dolunca bildirim kendiliğinden geri çekilir |
| E-posta | Hedef kullanıcılara e-posta kuyruğa alınır (çalışan bir SMTP ayarı gerekir) |
| Yorumlar | Okuyucular duyurunun altına yorum yazabilir (Nextcloud'un kendi yorum altyapısı) |

Dağıtım **yalnızca duyuru yayına girdiği anda** yapılır. Yayındaki bir duyuruda yazım hatası
düzeltmek herkese yeniden bildirim göndermez.

### Okuma
- Liste + detay görünümlü uygulama sayfası, sayfalama ve kalıcı bağlantı
  (`/apps/noticeboard/#/announcement/<id>`).
- Üç **pano widget'ı**: *Duyuru listesi*, *Duyuru kartları* ve *Duyuru vitrini*.
- **Birleşik arama** (üst arama kutusu). Sonuçlar hedeflemeye ve zamanlamaya uyar.

### Kim yayınlayabilir
Nextcloud yöneticileri her zaman yayınlayabilir. Ayrıca **Yönetim ayarları → Duyuru Panosu**
ekranından belirli **kullanıcılara** ya da **gruplara** yayın izni verebilirler. Yayıncı yalnızca
kendi duyurularını düzenleyip silebilir; yönetici her duyuruyu düzenleyebilir.

---

## Kurulum

### Hazır paketle (önerilen)

`./build-release.sh` müşteri paketini üretir: `dist/noticeboard-<sürüm>.tar.gz` (+ `.sha256`).
Paketin içinde tek komutla kuran/güncelleyen `install.sh` ve adım adım rehber
(`docs/KURULUM.md` / `.pdf`) vardır. Sunucuda:

```bash
cd /tmp && sha256sum -c noticeboard-0.3.0.tar.gz.sha256 && tar xzf noticeboard-0.3.0.tar.gz && sudo ./noticeboard-0.3.0/install.sh
```

### Kaynaktan

Derlenmiş JavaScript (`js/`) depoda yok. Kurmadan önce uygulamayı derleyin:

```bash
git clone https://github.com/SafiCeylan/Notice-Board.git noticeboard
cd noticeboard
npm ci
npm run build
```

Klasörü Nextcloud'un `apps/` dizinine **`noticeboard`** adıyla kopyalayın. Klasör adı app id ile aynı
olmalıdır. Ardından uygulamayı etkinleştirin:

```bash
sudo -u www-data php occ app:enable noticeboard
```

İleri tarihli yayın ve otomatik yayından kalkma, 5 dakikada bir çalışan bir arka plan işiyle yapılır.
Zamanında çalışması için arka plan işlerini **cron** ile çalıştırın (`occ background:cron`).

### Güncelleme
Yeni dosyaları eskilerinin üzerine kopyalayın. `appinfo/info.xml` içindeki sürüm değiştiyse
veritabanı değişikliklerinin uygulanması için `occ upgrade` çalıştırın.

---

## Geliştirme

```
appinfo/     uygulama tanımı ve rotalar
lib/         PHP: controller, servis, mapper, migration, widget, arama, etkinlik, bildirim
src/         Vue 3 ön yüz (uygulama, yönetici ayarları, pano widget'ları)
l10n/        çeviriler (build-l10n.py üretir)
tests/       PHP test paketleri (Nextcloud kurulumu gerekmeden çalışır)
```

| Komut | Amaç |
|---|---|
| `npm run dev` / `npm run watch` | Geliştirme derlemesi |
| `npm run build` | Üretim derlemesi |
| `./run-tests.sh` | PHP söz dizimi, `info.xml` doğrulaması, çeviri bütünlüğü ve tüm birim testleri |
| `./mutation-check.sh` | Güvenlik kurallarını kasten bozar ve testlerin her bozmayı yakaladığını doğrular |
| `python build-l10n.py` | `src/` ve `lib/` içinde kullanılan metinlerden `l10n/` dosyalarını üretir |

`run-tests.sh` PHP'nin yolunu `PHP` ortam değişkeninden okur, örneğin
`PHP=/usr/bin/php ./run-tests.sh`.

Arayüz metinlerinin kaynak dili Türkçedir; İngilizce karşılıkları `build-l10n.py` içindedir. Yeni bir
metin eklerseniz İngilizcesini de oraya ekleyin, yoksa çeviri denetimi başarısız olur.

### Güvenlik modeli
- Yetki kontrolü tam olarak iki yerdedir: `PublisherService` (kim yazabilir) ve `VisibilityService`
  (kim okuyabilir). Her okuma yolu `AnnouncementService::findEntityForReader()` üzerinden geçer.
- Duyuru HTML'i **yazılırken** temizlenir (`HtmlSanitizer`). İzin listesi editör araç çubuğuyla
  aynıdır.
- Kapak ve ekler, yazarın dosya izinlerine göre değil duyurunun görünürlüğüne göre verilir. Ekin
  istenen duyuruya gerçekten ait olduğu ayrıca doğrulanır.
- Okuma uçlarında "erişiminiz yok" ile "böyle bir duyuru yok" aynı 404 yanıtını verir; böylece
  kimlik numaraları taranarak gizli duyurular sayılamaz.
- Duyuru oluşturma (saatte 30) ve yorum yazma (saatte 60) kullanıcı başına hız sınırlıdır.

`tests/SecurityContractTest.php` kaynak kodu tarar ve yeni bir uç bu kuralları atlarsa başarısız olur.

---

## Lisans

AGPL-3.0-or-later © Mehmet Safi Ceylan
