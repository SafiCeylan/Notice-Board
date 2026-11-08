# Duyuru Panosu — Kurulum Rehberi

**Paket:** noticeboard-0.5.2 · Nextcloud 30 ve sonrası · Ekim 2026

Duyuru Panosu, Nextcloud içinde kurumsal duyuru yayınlamak için bir uygulamadır: zengin metinli
duyuru, kapak görseli ve ek, herkese ya da seçili gruplara hedefleme, ileri tarihli yayın, bildirim,
e-posta ve üç pano widget'ı.

Kurulum **tek komutla** yapılır. Betik Nextcloud'u kendisi bulur, uyumluluğu denetler, dosyaları
yerleştirir, veritabanı tablolarını oluşturur ve sonunda uygulamanın gerçekten çalıştığını sınar.
Aynı komut **güncelleme** için de kullanılır.

---

## 1. Paketteki dosyalar

| Dosya | Ne işe yarar |
|---|---|
| `noticeboard-0.5.2.tar.gz` | Kurulum paketi |
| `noticeboard-0.5.2.tar.gz.sha256` | Paketin bozulmadan kopyalandığını doğrulamak için |

Arşivin içinde:

| Yol | İçerik |
|---|---|
| `install.sh` | Kurulum, güncelleme ve kaldırma betiği |
| `noticeboard/` | Nextcloud uygulamasının kendisi (derlenmiş, kullanıma hazır) |
| `tools/smoke-test.php` | Kurulum sonrası doğrulama. Nextcloud'a kopyalanmaz |
| `tools/purge.php` | Tam kaldırmada Nextcloud'daki tüm izleri siler (bölüm 7). Nextcloud'a kopyalanmaz |
| `KURULUM.md` | Bu rehber |

## 2. Gereksinimler

| Gereken | Açıklama |
|---|---|
| Nextcloud | **30 ve sonrası** — üst sınır yok. 32, 33, 34 ve 35 sürümlerine karşı doğrulandı (canlı test: 32.0.15). Daha yeni bir sürümde kurulum uyarı verir, duman testi başarısız olursa kendiliğinden geri alınır. Klasik kurulum (Apache/nginx + PHP). Snap ile kurulmuş Nextcloud desteklenmez. |
| PHP | 8.1 ve üzeri (Nextcloud'un kullandığı PHP) |
| İşletim sistemi | Ubuntu, Debian, Rocky/RHEL/AlmaLinux. SELinux açıksa betik etiketleri kendisi verir. |
| Yetki | Sunucuda **root** (sudo) |
| İnternet | **Gerekmez.** Paket her şeyi içerir. |
| Arka plan işleri | **cron** önerilir. İleri tarihli yayın ve otomatik yayından kalkma bununla çalışır. Betik denetler ve isterseniz ayarlar. |
| E-posta (isteğe bağlı) | Duyuruların e-postayla gitmesi için Nextcloud'da SMTP ayarlı olmalı |
| Talk (isteğe bağlı) | Duyuruların Talk'taki duyuru sohbetine düşmesi için **Nextcloud Talk** etkin olmalı (doğrulanan: Talk 22 ve 25). Talk yoksa bu seçenek oluşturma ekranında hiç görünmez. |

Kurulumdan önce Nextcloud'un **yedeğini almanız** önerilir (veritabanı + `config/config.php`).
Betik uygulamanın eski sürümünü ayrıca kendisi yedekler.

---

## 3. Kurulum şeması

```
┌────────────────────────────────────────────────────────────┐
│ YÖNETİCİNİN BİLGİSAYARI                                    │
│   noticeboard-0.5.2.tar.gz  +  .sha256                     │
└────────────────────────────────────────────────────────────┘
       │  adım 1: scp ile sunucuya kopyala
       ▼
┌────────────────────────────────────────────────────────────┐
│ NEXTCLOUD SUNUCUSU  (/tmp)                                 │
│   sha256sum -c ...     adım 3a: paketi doğrula             │
│   tar xzf ...          adım 3b: paketi aç                  │
│   sudo ./install.sh    adım 3c: kur                        │
└────────────────────────────────────────────────────────────┘
       │  install.sh kendiliğinden:
       ▼
┌────────────────────────────────────────────────────────────┐
│ 1 Ön kontrol    Nextcloud dizini, web kullanıcısı, occ     │
│ 2 Uyumluluk     NC 30+, PHP 8.1+; kurulum/güncelleme       │
│ 3 Hedef dizin   apps/ (ya da yazılabilir custom_apps/)     │
│ 4 Cron + SMTP   denetle, istenirse cron'u ayarla           │
│ 5 Dosyalar      eskiyi yedekle, kopyala, izin, SELinux     │
│ 6 Etkinleştir   occ app:enable (tablolar / migration)      │
│ 7 Doğrula       duman testi (gerçek Nextcloud içinde)      │
│                                                            │
│ Hata olursa: eski sürüm geri yüklenir,                     │
│              bakım kipi kapatılır.                         │
└────────────────────────────────────────────────────────────┘
       │
       ▼
┌────────────────────────────────────────────────────────────┐
│ NEXTCLOUD: sol menüde "Duyuru Panosu"                      │
└────────────────────────────────────────────────────────────┘
```

Nextcloud'da oluşan şeyler:

```
<nextcloud>/apps/noticeboard/        (yazılabilir custom_apps/ tanımlıysa oraya)
veritabanı: oc_noticeboard_posts · oc_noticeboard_groups · oc_noticeboard_files
arka plan işi: zamanlanmış yayın / otomatik yayından kalkma (5 dakikada bir)
yedekler: /var/backups/noticeboard/        log: /var/log/noticeboard-install.log
```

---

## 4. Kurulum adımları

Aşağıdaki blokları **olduğu gibi** konsola yapıştırın. Değiştirmeniz gereken tek şey 1. adımdaki
`kullanici@SUNUCU`.

### Adım 1 — Paketi sunucuya kopyalayın

Kendi bilgisayarınızda (Windows PowerShell, macOS ya da Linux terminali), paketin bulunduğu klasörde:

```bash
scp noticeboard-0.5.2.tar.gz noticeboard-0.5.2.tar.gz.sha256 kullanici@SUNUCU:/tmp/
```

### Adım 2 — Sunucuya bağlanın

```bash
ssh kullanici@SUNUCU
```

### Adım 3 — Doğrulayın, açın ve kurun

```bash
cd /tmp
sha256sum -c noticeboard-0.5.2.tar.gz.sha256
tar xzf noticeboard-0.5.2.tar.gz
sudo ./noticeboard-0.5.2/install.sh
```

Doğrulama satırında `noticeboard-0.5.2.tar.gz: OK` görmelisiniz. `FAILED` çıkarsa paketi yeniden
kopyalayın.

Betik her değişiklikten önce ne yapacağını yazar ve `[e/H]` diye sorar. `e` yazıp Enter'a basın.
Sonunda şu satırı görürsünüz:

```
######## Bitti: noticeboard 0.5.2 (ilk kurulum)
```

### Soru sormadan, tek seferde

Adım 3'ün hiç soru sormayan hâli. Otomasyon ve uzaktan kurulum için:

```bash
cd /tmp && sha256sum -c noticeboard-0.5.2.tar.gz.sha256 && tar xzf noticeboard-0.5.2.tar.gz && sudo ./noticeboard-0.5.2/install.sh --yes
```

`--yes` ile betik tüm soruları "evet" kabul eder: arka plan işleri `ajax` modundaysa `cron`'a
geçirir ve web kullanıcısının crontab'ına `cron.php` kaydı ekler.

### Önce ne yapacağını görmek isterseniz

Hiçbir şeyi değiştirmez, yalnızca yapacaklarını listeler:

```bash
sudo ./noticeboard-0.5.2/install.sh --dry-run
```

---

## 5. Seçenekler

| Seçenek | Ne yapar |
|---|---|
| `--yes` | Hiç soru sormaz, hepsini "evet" kabul eder |
| `--dry-run` | Yalnızca ne yapacağını yazar, hiçbir şeyi değiştirmez |
| `--nc-path DIZIN` | Nextcloud kök dizini. Betik `/var/www/nextcloud`, `/var/www/html/nextcloud`, `/var/www/html`, `/srv/www/nextcloud`, `/usr/share/nginx/nextcloud`, `/opt/nextcloud` dizinlerine kendisi bakar; Nextcloud başka yerdeyse bunu verin |
| `--web-user KULLANICI` | Web sunucusu kullanıcısı. Verilmezse `config.php` dosyasının sahibinden okunur (`www-data`, `apache`, `nginx`…) |
| `--skip-cron` | Arka plan işleri denetimini atlar |
| `--force` | Nextcloud 30’dan eskiyse de kurar; kurulu sürüm paketten yeniyse üzerine yazar. **Önerilmez.** |
| `--uninstall` | Uygulamayı ve Nextcloud'daki **tüm izlerini** siler. Geri alınamaz (bkz. bölüm 7) |
| `--uninstall --keep-data` | Yalnızca uygulama dosyalarını kaldırır; duyurular ve ayarlar veritabanında kalır |
| `--help` | Kısa yardım |

---

## 6. Güncelleme

Yeni sürümün paketini aynı şekilde kopyalayıp **aynı komutu** çalıştırın:

```bash
cd /tmp
sha256sum -c noticeboard-0.5.2.tar.gz.sha256
tar xzf noticeboard-0.5.2.tar.gz
sudo ./noticeboard-0.5.2/install.sh
```

Betik kurulu sürümü görür ve şunları yapar:

1. Eski sürümü `/var/backups/noticeboard/noticeboard-<eski sürüm>-<tarih>.tar.gz` olarak yedekler.
2. Uygulamayı birkaç saniyeliğine kapatır, dosyaları değiştirir, yeniden açar. Yeni sürümün
   veritabanı değişiklikleri bu sırada **yalnızca bu uygulama için** uygulanır. Sunucunun geri
   kalanı bakım kipine girmez.
3. Kullanıcıların tarayıcısındaki eski arayüz dosyalarını geçersiz kılar.

**Duyurular, yayıncı yetkileri ve kullanıcıların pano ayarları korunur.**

Kurulu sürümden **eski** bir paket kurulmak istenirse betik durur. Sürüm düşürmek veritabanını
bozabilir.

---

## 7. Kaldırma ve geri alma

### Tam kaldırma (iz bırakmadan)

Önce neyin silineceğini görün. Bu komut hiçbir şeyi değiştirmez:

```bash
sudo ./noticeboard-0.5.2/install.sh --uninstall --dry-run
```

Sonra kaldırın:

```bash
sudo ./noticeboard-0.5.2/install.sh --uninstall
```

Betik silmeden önce bulduklarını listeler ve onay ister. Onaydan sonra şunlar silinir:

| Ne | Nerede |
|---|---|
| Uygulama dosyaları | Tüm uygulama dizinlerinde (`apps/`, `custom_apps/`…) |
| Duyurular, hedef gruplar, ek kayıtları | `oc_noticeboard_*` tabloları |
| Migration ve sürüm kayıtları | `oc_migrations`, `oc_appconfig` |
| Yayıncı ve vitrin ayarları | `oc_appconfig` |
| Arka plan işleri (zamanlanmış yayın, bekleyen e-postalar) | `oc_jobs` |
| Bildirimler, etkinlik akışı, etkinlik e-posta kuyruğu | `oc_notifications`, `oc_activity`, `oc_activity_mq` |
| Duyuru yorumları ve okundu işaretleri | `oc_comments`, `oc_comments_read_markers` |
| Kullanıcıların pano düzenindeki Duyuru Panosu widget'ları ve bildirim tercihleri | `oc_preferences` |
| Talk mesajlarını gönderen `noticeboard-bot` hesabı | Nextcloud kullanıcıları (çekirdeğin hesap silme yoluyla) |
| Eski sürüm yedekleri ve kurulum günlüğü | `/var/backups/noticeboard/`, `/var/log/noticeboard-install.log` |

Betik silme işleminden sonra her şeyi yeniden sayar ve iz kalmadığını doğrular. Sonra
`sudo ./install.sh` ile yapılan kurulum, **ilk kurulum olarak sıfırdan** yapılır.

**Bu işlem geri alınamaz.** Duyuruları saklamak istiyorsanız önce veritabanı yedeği alın.

Dokunulmayanlar:

- **Kullanıcıların Dosyalar'ındaki "Duyuru kapakları" klasörleri.** Bunlar kullanıcı dosyasıdır,
  uygulama tarafından okunmaz. Betik hangi kullanıcılarda bulunduğunu yazar.
- **Nextcloud'un kendi cron kaydı.** Diğer uygulamalar da kullanır.
- **Kişilere Talk'ta gitmiş duyuru mesajları.** Gönderilmiş e-postalar gibi artık Talk'un verisidir;
  `noticeboard-bot` hesabı silinince Talk o sohbetleri "eski sohbet" olarak kapatır.

> **Web arayüzünden "Kaldır" ya da `occ app:remove` yeterli değildir.** Nextcloud yalnızca
> uygulama klasörünü siler; veritabanındaki kayıtlar kalır ve sonraki kurulumda sorun çıkarabilir.
> Uygulamayı bu yollarla zaten kaldırdıysanız `install.sh --uninstall` komutunu yine çalıştırın.
> Kalan izleri uygulama klasörü olmadan da bulur ve siler.

### Yalnızca dosyaları kaldırma (veri kalır)

```bash
sudo ./noticeboard-0.5.2/install.sh --uninstall --keep-data
```

Uygulama kapatılır ve dosyaları `/var/backups/noticeboard/` altına yedeklenip kaldırılır.
Veritabanındaki duyurular silinmez. Uygulama yeniden kurulduğunda hepsi geri gelir.

### Kurulum yarıda kalırsa

Betik bir adımda hata alırsa kendiliğinden geri alır:

- Önceki sürüm varsa yedekten geri yüklenir ve yeniden etkinleştirilir.
- **İstisna:** yeni sürüm veritabanı değişikliği getiriyorsa (ör. 0.4.x → 0.5.0) ve hata bu
  değişiklik uygulanmaya başladıktan sonra olduysa eski dosyalar **geri konmaz**: eski sürüm yeni
  veritabanını okuyamaz. Yeni sürüm yerinde kalır, betik bunu açıkça yazar; eski sürümün yedeği
  `/var/backups/noticeboard/` altında durur. Sorun giderilince betiği yeniden çalıştırın.
- İlk kurulumsa yeni dosyalar kaldırılır.
- Nextcloud bakım kipinde kaldıysa bakım kipi kapatılır.

Hatanın ayrıntısı `/var/log/noticeboard-install.log` dosyasındadır.

### Önceki sürüme elle dönmek

> **0.5.x'ten 0.4.x'e dosya geri koyarak dönülmez.** 0.5.0 veritabanına `opt_talk` kolonunu
> ekler; 0.4.x bu kolonu tanımaz ve duyuruları okurken hata verir (liste, widget'lar, arama boş
> kalır). 0.4.x'e dönmeniz gerekiyorsa veritabanını güncelleme öncesi yedeğinden geri yükleyin ya da
> destek ekibine başvurun. 0.5.x içindeki geri dönüşler (ör. 0.5.1 → 0.5.0) etkilenmez; yalnız
> 0.5.1'de verilen görsel yerleşimi 0.5.0'da görünmez, görseller kendi satırında gösterilir.

```bash
ls /var/backups/noticeboard/
```

Ardından yedeği uygulama dizinine açın. Aşağıdaki örnek klasik kurulum içindir: yolu ve
`www-data` kullanıcısını kendi sunucunuza göre değiştirin.

```bash
sudo -u www-data php /var/www/nextcloud/occ app:disable noticeboard
sudo rm -rf /var/www/nextcloud/apps/noticeboard
sudo tar xzf /var/backups/noticeboard/noticeboard-<eski sürüm>-<tarih>.tar.gz -C /var/www/nextcloud/apps/
sudo chown -R www-data:www-data /var/www/nextcloud/apps/noticeboard
sudo -u www-data php /var/www/nextcloud/occ app:enable noticeboard
```

---

## 8. Kurulumdan sonra

1. Tarayıcıda Nextcloud sayfasını yenileyin. Sol menüde **Duyuru Panosu** görünür.
2. **Kim duyuru yayınlayabilir:** Nextcloud yöneticileri her zaman yayınlar. Başka kişi ya da
   gruplara yetki vermek için: **Yönetim ayarları → Duyuru Panosu → Yayıncılar**.
3. **Pano widget'ları:** her kullanıcı Pano (Dashboard) sayfasında **Widget'ları özelleştir**
   düğmesinden *Duyuru listesi*, *Duyuru kartları* ya da *Duyuru vitrini* ekleyebilir.
4. **E-posta kanalı:** Yönetim ayarları → Temel ayarlar → E-posta sunucusu. SMTP ayarlı değilse
   duyuru yine yayınlanır, yalnızca e-posta gitmez.
5. **Talk kanalı:** Talk etkinse oluşturma ekranında *Talk mesajı gönderilsin* seçeneği çıkar
   (varsayılan açık). Duyuru yayınlanınca hedeflenen herkesin (yayınlayana da bir kopya) Talk'taki
   megafon simgeli **Duyuru Panosu** sohbetine mesaj düşer; mesaj bir sonraki cron çalışmasında gönderilir.
   Sohbet, Talk'un kendi "Talk updates" sohbeti gibi **kilitlidir**: yanıt yazılamaz, sesli ya da
   görüntülü arama başlatılamaz, kişi eklenemez. Her kişinin kendi sohbeti vardır; ilk duyuruda
   açılır, sonrakiler aynı sohbete gelir. Mesajları yazan hesap (`noticeboard-bot`) ilk gönderimde
   **kendiliğinden açılır**, sizin bir şey yapmanız gerekmez:
   - Parolası hiçbir yerde saklanmaz, o hesapla oturum açılamaz; kotası 0'dır, hiçbir gruba eklenmez.
   - Kişi sohbetten ayrılırsa bir sonraki duyuruda yeniden eklenir; bildirimi sessize almak
     isterse sohbetin bildirim ayarını kullanabilir.
   - Görünen adını ya da profil resmini **Kullanıcılar** sayfasından değiştirebilirsiniz.
   - Talk'ta "Talk'u kullanabilecek gruplar" kısıtı varsa, o gruplarda olmayan kişilere mesaj gitmez.

Kurulum Nextcloud'un resmi *Announcement Center* uygulamasına dokunmaz. O da kuruluysa ikisi yan
yana çalışır ve sol menüde iki ayrı duyuru girişi görünür.

---

## 9. Sorun giderme

| Belirti | Neden / çözüm |
|---|---|
| `HATA Nextcloud bulunamadı` | Nextcloud standart dışı bir dizinde. `--nc-path /nextcloud/dizini` verin. |
| `HATA root olarak çalıştırın` | Komutun başına `sudo` ekleyin. |
| `HATA Nextcloud 29 desteklenmiyor` | Paket Nextcloud 30 ve sonrası içindir; önce Nextcloud’u yükseltin. Denemek için `--force` kullanılabilir ama önerilmez. |
| `UYARI Nextcloud 36 … henüz doğrulanmamıştı` | Kurulum devam eder; duman testi uygulamayı gerçek Nextcloud’da yükler. Kırmızı çıkarsa kurulum kendiliğinden geri alınır. |
| `HATA Nextcloud bakım kipinde` | Önce `occ maintenance:mode --off`. Bakım kipinin nedeni başka bir işlemse onun bitmesini bekleyin. |
| `HATA ... needsDbUpgrade` | Nextcloud'un kendisi yarım kalmış bir güncelleme bekliyor. Önce `occ upgrade` ile onu tamamlayın. |
| `UYARI ... ajax modunda` | Zamanlanmış duyurular ancak biri Nextcloud'u açınca yayınlanır. Betikte `e` deyin ya da `--yes` kullanın. |
| Menüde görünüyor ama sayfa boş | Tarayıcıda `Ctrl+F5`. Sürmezse betiği yeniden çalıştırın; aynı sürümde dosyaları yeniler. |
| `duman testi kırmızı` | Uygulama bu Nextcloud'da yüklenemedi. Betik eski duruma döndü. Log dosyasını destek ekibine iletin. Veritabanı değişikliği getiren bir güncellemede (ör. 0.4.x → 0.5.0) betik eski dosyaları geri koymaz, yeni sürümü yerinde bırakır (bkz. 7. bölüm, "Kurulum yarıda kalırsa"). |

Destek için şu iki çıktıyı gönderin:

```bash
sudo cat /var/log/noticeboard-install.log
sudo -u www-data php /var/www/nextcloud/occ status
```

(`www-data` ve Nextcloud yolunu kendi sunucunuza göre değiştirin.)
