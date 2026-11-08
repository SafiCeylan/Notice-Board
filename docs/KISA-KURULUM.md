# Duyuru Panosu 0.5.2 — Kısa Kurulum

**Yeni kurulum ve güncelleme için aynı adımlar** · Nextcloud 30 ve sonrası · Ekim 2026

Kurulum betiği sunucuda Duyuru Panosu'nun kurulu olup olmadığını kendisi anlar: kurulu değilse
kurar, eski bir sürüm varsa (0.4.x, 0.5.0 ya da 0.5.1) güncelleme yapar. Ayrıntılı rehber pakettedir
(`KURULUM.pdf`, `GUNCELLEME-0.5.2.pdf`).

---

## 1. Gönderilen dosyalar

| Dosya | Ne işe yarar |
|---|---|
| `noticeboard-0.5.2.tar.gz` | Uygulama paketi ve kurulum betiği |
| `noticeboard-0.5.2.tar.gz.sha256` | Paketin bozulmadan geldiğini doğrulamak için |
| `Duyuru-Panosu-0.5.2-Kisa-Kurulum.pdf` | Bu belge |

---

## 2. Başlamadan önce

- Sunucuda `sudo` yetkiniz olmalı.
- **0.4.x'ten güncelliyorsanız veritabanı yedeği alın.** Bu güncelleme veritabanına bir kolon ekler;
  0.4.x'e geri dönmek yalnızca bu yedekle mümkündür. 0.5.x'ten güncelliyorsanız veritabanı değişmez.
- Uygulama dosyalarının yedeğini betik kendisi alır (`/var/backups/noticeboard/`).

---

## 3. Kurulum (4 adım)

**1. Dosyaları sunucuya kopyalayın** (kendi bilgisayarınızdan):

```bash
scp noticeboard-0.5.2.tar.gz noticeboard-0.5.2.tar.gz.sha256 kullanici@SUNUCU:/tmp/
```

**2. Sunucuda paketi doğrulayın:**

```bash
cd /tmp
sha256sum -c noticeboard-0.5.2.tar.gz.sha256
```

`noticeboard-0.5.2.tar.gz: OK` yazmalı. `FAILED` yazarsa paketi yeniden kopyalayın.

**3. Paketi açın:**

```bash
tar xzf noticeboard-0.5.2.tar.gz
```

**4. Kurulum betiğini çalıştırın:**

```bash
sudo ./noticeboard-0.5.2/install.sh
```

Betik her değişiklikten önce `[e/H]` diye sorar: onaylamak için **`e` yazıp Enter**'a basın
(yalnız Enter "hayır" demektir). Hiç sormadan yapmasını isterseniz:

```bash
sudo ./noticeboard-0.5.2/install.sh --yes
```

Uygulama birkaç saniye kapalı kalır, Nextcloud'un geri kalanı çalışmaya devam eder.

---

## 4. Bitti mi?

Betiğin son satırı şunlardan biri olmalı:

```
######## Bitti: noticeboard 0.5.2 (ilk kurulum)
######## Bitti: noticeboard 0.5.2 (güncelleme 0.5.1 -> 0.5.2)
```

Ardından tarayıcıda **Ctrl+F5** ile sayfayı yenileyin. Sol menüde **Duyuru Panosu** görünür.

---

## 5. Kısa deneme

1. Yönetici hesabıyla **Duyuru Panosu**'nu açıp **Yeni** düğmesine tıklayın (düğme yöneticilere ve
   yayıncı yetkisi verilenlere görünür). Metne **Görsel ekle** ile bir görsel ekleyin.
2. Görsel seçili gelir ve üstte görsel çubuğu açılır. **Solda**'yı seçin, görselin yanına birkaç
   satır yazın: metin görselin sağından akmalı.
3. Görselin köşesindeki noktayı sürükleyin: görsel büyüyüp küçülmeli.
4. Duyuruyu yayınlayın ve başka bir hesapla açıp aynı göründüğünü kontrol edin.
5. Talk kuruluysa: birkaç dakika sonra (cron çalışınca) Talk'ta megafon simgeli **Duyuru Panosu**
   sohbetinde duyuru mesajı görünmeli. Sohbet kilitlidir; yanıt kutusu ve arama düğmesi yoktur.

---

## 6. Sorun olursa

Betik bir adımda hata alırsa önceki sürümü kendisi geri yükler. Şu iki çıktıyı bize gönderin
(`www-data` ve Nextcloud yolunu kendi sunucunuza göre değiştirin):

```bash
sudo cat /var/log/noticeboard-install.log
sudo -u www-data php /var/www/nextcloud/occ status
```
