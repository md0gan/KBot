# KBot — Binance TR DCA & Kar-Al Botu

Binance TR (`https://www.binance.tr`) üzerinde **düzenli alım (DCA)** yapan ve bir coin
belirlenen **çarpana** ulaştığında **karı USDT'ye çevirip yalnızca sermayeyi coinde bırakan**
bir bottur. Tüm yönetim **web arayüzünden** yapılır.

> **Örnek senaryo:** Her hafta düzenli **10 USDT** BTC al. BTC pozisyonunun değeri
> sermayenin **2 katına** ulaşınca, **yarısını sat** ve geriye **sadece sermaye** kalsın;
> kazanılan kısım USDT olarak cebe girsin.

**Stack:** PHP 8.2+ · Laravel 12 · MySQL · Tailwind (CDN) · Çoklu kullanıcı.

> Not: PHP 8.2 ile **Laravel 12** kullanılır. PHP'yi **8.3+**'a yükseltirseniz
> `composer.json` içinde `laravel/framework` sürümünü `^13.0` yapıp Laravel 13'e geçebilirsiniz.

---

## ⚠️ Önemli Uyarı

Bu yazılım **yatırım tavsiyesi değildir**. Kripto para işlemleri yüksek risk içerir ve
**tüm sorumluluk size aittir**. Stratejinizi önce **simülasyon modunda** test edin.
Canlı modda emirler gerçek paranızla uygulanır. API anahtarınıza **yalnızca spot işlem**
izni verin, **para çekme (withdraw) iznini kapatın**.

---

## İçindekiler

1. [Strateji Nasıl Çalışır?](#strateji-nasıl-çalışır)
2. [Gereksinimler](#gereksinimler)
3. [Kurulum](#kurulum)
4. [Zamanlanmış Görev (Scheduler)](#zamanlanmış-görev-scheduler)
5. [Binance TR API Anahtarı](#binance-tr-api-anahtarı)
6. [Kullanım](#kullanım)
7. [Artisan Komutları](#artisan-komutları)
8. [Proje Yapısı](#proje-yapısı)
9. [Güvenlik](#güvenlik)
10. [Sorun Giderme](#sorun-giderme)

---

## Strateji Nasıl Çalışır?

Her coin için bir **pozisyon** tutulur:

- **Sermaye (`cost_basis`)** — coine yatırdığınız ve hâlâ coinde duran kote (USDT) tutar.
- **Miktar (`quantity`)** — botun tuttuğu coin adedi.
- **Çevrilen kar (`realized_profit`)** — şimdiye kadar USDT'ye çevrilen toplam kar.

### 1) Düzenli Alım (DCA)

Her periyotta (saatlik / günlük / haftalık / aylık) sabit kote tutar kadar **MARKET alım**
yapılır. Binance TR'de MARKET alım `quoteOrderQty` ile yapıldığından "tam 10 USDT harca"
mümkündür. Alım sonrası sermaye ve miktar büyür.

### 2) Kar-Al (Take-Profit)

Pozisyonun güncel değeri **≥ çarpan × sermaye** olunca tetiklenir:

| Strateji | Davranış |
| --- | --- |
| **Sermayeyi bırak** (varsayılan) | Karın **tamamı** satılır; geriye **tam olarak sermaye** kalır. |
| **Sabit oran** | Karın belirlenen **oranı** (örn. %50) satılır; gerisi coinde kalır. |

Satıştan sonra **kalan miktarın güncel değeri yeni sermaye** olur (çarpan 1'e döner),
böylece bir sonraki kar-al aynı çarpanla tekrar tetiklenir.

**Örnek (çarpan = 2, "sermayeyi bırak"):**

```
Başlangıç: 10 USDT ile BTC alındı  → sermaye = 10, değer = 10  (1.0x)
Fiyat 2 katına çıktı               → değer = 20             (2.0x) → TETİK
Satılan: değer − sermaye = 10 USDT (pozisyonun yarısı)
Sonuç:   coinde 10 USDT'lik BTC kaldı (sermaye) + 10 USDT kar cebe (realized_profit)
Yeni durum: sermaye = 10, değer = 10 (1.0x) → döngü baştan
```

> Düzenli alımlar devam ettikçe sermaye büyür; kar-al her zaman **o anki sermayeye** göre hesaplanır.

---

## Gereksinimler

- **PHP 8.2+** — `pdo_mysql`, `mbstring`, `openssl`, `bcmath`, `curl` eklentileri (XAMPP ile gelir)
- **Composer 2**
- **MySQL 8** (veya MariaDB 10.4+)
- (Opsiyonel) canlı işlem için Binance TR hesabı + API anahtarı

---

## Kurulum

```bash
# 1) Bağımlılıklar
composer install

# 2) Ortam dosyası
cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate

# 3) .env içinde veritabanı bilgilerini doldurun:
#    DB_DATABASE=kbot
#    DB_USERNAME=root
#    DB_PASSWORD=...
#    (önce MySQL'de "kbot" veritabanını oluşturun)

# 4) Tabloları ve örnek veriyi oluştur
php artisan migrate --seed

# 5) Sunucuyu başlat
php artisan serve
```

Tarayıcıdan `http://localhost:8000` adresine gidin.

**Örnek (seed) giriş bilgileri:**

- E-posta: `admin@kbot.local`
- Şifre: `password`

Veya `/register` üzerinden yeni hesap oluşturun. (İlk işiniz şifreyi değiştirmek olsun.)

> Seed ile **BTC_USDT** ve **ETH_USDT** örnek olarak (kapalı/simülasyon) eklenir.
> Panelden "Başlat" diyerek aktif edebilirsiniz.

---

## Sunucuya Kurulum (Sıfır Ubuntu — Önerilen)

MySQL'in kurulu olduğu **boş bir Ubuntu 22.04/24.04** sunucuda tek komutla kurulum:

```bash
# Projeyi /var/www altına klonlayın (Nginx erişimi için önerilir; /home altında 403 olabilir)
sudo mkdir -p /var/www
cd /var/www
sudo git clone https://github.com/md0gan/KBot.git kbot
cd kbot
sudo DOMAIN=bot.ornek.com EMAIL=siz@ornek.com bash deploy/install.sh
```

Script şunları yapar: PHP 8.3 + eklentiler, Composer, **Nginx + PHP-FPM**, dosya izinleri,
`APP_KEY`, **scheduler cron'u** ve **`DOMAIN` verildiyse Certbot ile ücretsiz SSL** (HTTP→HTTPS yönlendirmeli).

> **SSL için ön koşul:** Alan adının (`bot.ornek.com`) DNS **A kaydı** sunucunuzun IP'sine
> bakıyor olmalı ve 80/443 portları açık olmalı. DNS henüz yayılmadıysa SSL adımı atlanır
> (HTTP site çalışır); sonra `sudo certbot --nginx -d bot.ornek.com --redirect` ile alabilirsiniz.
> `EMAIL` verilmezse e-postasız kayıt yapılır (yenileme uyarısı gelmez).

> Kurulum, scriptin bulunduğu **proje klasörüne (yerinde)** yapılır; ayrı bir yere kopyalamaz.
> Bu yüzden `/var/www/kbot` önerilir. `/home` veya `/root` altında kurarsanız script sizi uyarır.

Script şunları yapar: PHP 8.3 + eklentiler, Composer, **Nginx + PHP-FPM**, dosya izinleri,
`APP_KEY` üretimi ve **scheduler için cron** (`* * * * * php artisan schedule:run`).

Ardından tarayıcıdan **kurulum sihirbazını** açın:

```
http://SUNUCU_IP/install   (veya http://alan-adiniz/install)
```

Sihirbaz 3 adımdır:

1. **Gereksinimler** — PHP sürümü, eklentiler ve yazma izinleri kontrol edilir.
2. **Veritabanı** — MySQL bilgilerini girersiniz; veritabanı yoksa (yetki varsa) otomatik
   oluşturulur, `.env` yazılır ve migrasyonlar uygulanır.
3. **Yönetici** — ilk admin hesabını oluşturursunuz.

Kurulum bitince sihirbaz otomatik kapanır (tekrar açılmaz) ve giriş ekranına yönlenirsiniz.
Veritabanı/admin bilgileri burada girildiği için elle `.env` düzenlemeye veya `artisan migrate`
çalıştırmaya gerek yoktur.

> Yeniden kurmak isterseniz `storage/app/installed.lock` dosyasını silin.

---

### Cloudflare arkasındaysanız (SSL — önerilen)

Alan adınız Cloudflare üzerinden (turuncu bulut/proxied) geliyorsa, Let's Encrypt/certbot
genelde sorun çıkarır (Error 526 vb.). En sağlıklı yol **Cloudflare Origin Certificate**'tır:

1. Kurulumu Cloudflare modunda yapın (certbot atlanır):

   ```bash
   sudo CLOUDFLARE=1 DOMAIN=kbot.modago.com.tr bash deploy/install.sh
   ```

2. Cloudflare Dashboard → **SSL/TLS → Origin Server → Create Certificate**. Çıkan iki kutuyu
   sunucuda dosyaya kaydedin:

   ```bash
   sudo mkdir -p /etc/ssl/cloudflare
   sudo nano /etc/ssl/cloudflare/kbot.modago.com.tr.pem   # Origin Certificate yapıştır
   sudo nano /etc/ssl/cloudflare/kbot.modago.com.tr.key   # Private Key yapıştır
   sudo chmod 600 /etc/ssl/cloudflare/kbot.modago.com.tr.key
   ```

3. Nginx'i 443 için yapılandırın ve CF modunu **Full (strict)** yapın:

   ```bash
   sudo DOMAIN=kbot.modago.com.tr bash deploy/cloudflare-ssl.sh
   ```

Sonuç: uçtan uca şifreli HTTPS, 15 yıl geçerli, yenileme derdi yok. Site:
`https://kbot.modago.com.tr/install`.

> Cloudflare kullanmıyorsanız bu adımları atlayın; `DOMAIN` verince script Let's Encrypt
> ile otomatik SSL alır.

## Bot Nasıl Çalışır? (Cron)

Bot bir Laravel **scheduler** ile çalışır; sürekli açık bir süreç (daemon) gerekmez.
Kurulum scripti sunucuya tek bir cron satırı ekler:

```cron
* * * * * cd /proje/yolu && php artisan schedule:run >> /dev/null 2>&1
```

Bu satır her dakika `schedule:run`'ı tetikler; o da `bootstrap/app.php`'de tanımlı işleri
çalıştırır: `bot:dca` (vadesi gelen alımlar), `bot:evaluate` (kar-al), `bot:sync-symbols`.
Komutlar kendi içinde "vakti geldi mi?" kontrolü yapar, bu yüzden sık çalışmaları güvenlidir.

## Zamanlanmış Görev (Scheduler)

Botun otomatik çalışması için Laravel zamanlayıcısını dakikada bir tetikleyin.

**Linux/macOS (crontab -e):**

```cron
* * * * * cd /proje/yolu && php artisan schedule:run >> /dev/null 2>&1
```

**Windows (Görev Zamanlayıcı):** Her dakika çalışan bir görev oluşturun:

```
Program: php
Argüman:  artisan schedule:run
Başlangıç: C:\proje\yolu
```

Tanımlı zamanlamalar (`bootstrap/app.php`):

| Komut | Sıklık | İş |
| --- | --- | --- |
| `bot:dca` | 5 dakikada bir | Vadesi gelen coinlerde düzenli alım |
| `bot:evaluate` | 5 dakikada bir | Açık pozisyonlarda kar-al kontrolü |
| `bot:sync-symbols` | her gün 03:00 | Borsa lot/step/minNotional filtrelerini günceller |

> Komutlar kendi içinde "vakti geldi mi?" kontrolü yaptığından sık çalışmaları güvenlidir.
> Zamanlayıcı kurulmasa bile panelden **"Botu şimdi çalıştır"** ile elle tetikleyebilirsiniz.

---

## Binance TR API Anahtarı

1. Binance TR hesabınızda **API Yönetimi** bölümünden yeni anahtar oluşturun.
2. İzinler: **Spot işlem (okuma + emir) AÇIK**, **Para çekme KAPALI**.
3. KBot'ta **Ayarlar** sayfasına API Key + Secret girip kaydedin.
4. **"Bağlantıyı test et"** ile doğrulayın.

Anahtarlar veritabanında `APP_KEY` ile **şifrelenerek** saklanır.

> API olmadan da **simülasyon** modu çalışır (fiyatlar herkese açık uçtan çekilir);
> sadece gerçek emir gönderimi için API gerekir.

---

## Kullanım

- **Panel:** Toplam sermaye, güncel değer, açık K/Z, USDT'ye çevrilen kar; her coin için
  çarpana ilerleme çubuğu.
- **Coin ekle/düzenle:** Coin, karşı para, tutar, periyot, çarpan, kar-al stratejisi, mod.
  İstediğiniz zaman **ekleyip çıkarabilir**siniz.
- **İşlem modu:**
  - *Genel ayarı kullan* — Ayarlar'daki genel mod (simülasyon/canlı)
  - *Simülasyon* — sadece bu coin kâğıt üzerinde
  - *Canlı* — sadece bu coin gerçek emir
- **Manuel işlemler:** Coin detayında "Şimdi al", "Değerlendir", "Sat / Tümünü sat".
- **Takip:** İşlem geçmişi (filtreli) ve bot günlüğü ile tüm hareketleri izleyin.
- **Hesap:** Hesap sayfasından ad/e-posta ve şifrenizi değiştirebilirsiniz.
- **Canlıya geçiş:** Genel modu *Canlı* yaptığınızda simülasyon işlemleri ve tüm pozisyonlar
  otomatik **sıfırlanır** (temiz başlangıç). Ayarlar sayfasında onay sorulur.

---

## Artisan Komutları

```bash
php artisan bot:dca            # Vadesi gelen alımları yap
php artisan bot:evaluate       # Kar-al değerlendirmesi
php artisan bot:sync-symbols   # Sembol filtrelerini güncelle
php artisan bot:dca --user=1   # Sadece belirli kullanıcı için
php artisan bot:ping           # Botun ayakta olduğunu test et
```

---

## Proje Yapısı

```
app/
 ├─ Models/            User, Setting, Coin, Position, Trade, BotLog
 ├─ Services/
 │   ├─ BinanceTrClient.php   # İmzalı (HMAC-SHA256) API istemcisi
 │   └─ TradingBot.php        # DCA + kar-al strateji motoru
 ├─ Console/Commands/  RunDcaBuys, EvaluatePositions, SyncSymbols
 └─ Http/Controllers/  Dashboard, Coin, Setting, Bot, Trade, Auth
config/bot.php          # API uçları, varsayılanlar, enum eşlemeleri
database/migrations/    # users, settings, coins, positions, trades, bot_logs
resources/views/        # Tailwind tabanlı arayüz (Blade)
routes/web.php          # Web rotaları
bootstrap/app.php       # Zamanlama tanımları
```

### Binance TR API notları (config/bot.php)

- Base URL: `https://www.binance.tr` — imzalı uçlar `/open/v1/...`
- Emir: `POST /open/v1/orders` · `side` 0=BUY/1=SELL · `type` 2=MARKET
- MARKET alım `quoteOrderQty` (harcanacak USDT), MARKET satış `quantity` (coin adedi)
- Piyasa fiyatı: MAIN semboller için `https://api.binance.me/api/v3/...`

---

## Güvenlik

- API anahtarları DB'de şifreli; arayüzde maskeli gösterilir.
- API anahtarına **para çekme izni vermeyin**.
- Çoklu kullanıcı: her kullanıcı yalnızca kendi coin/işlem/ayarlarını görür.
- Canlı moda geçmeden önce simülasyonda doğrulayın; üst bantta kırmızı **CANLI** uyarısı çıkar.
- `.env` ve `APP_KEY` gizli kalmalı (APP_KEY değişirse kayıtlı API anahtarları çözülemez).

---

## Sorun Giderme

| Belirti | Çözüm |
| --- | --- |
| `composer install` script hatası | `cp .env.example .env && php artisan key:generate` sonra tekrar `composer install` |
| Sayfalar boş/500 | `storage` ve `bootstrap/cache` yazılabilir olmalı; `php artisan migrate` çalıştı mı? |
| Fiyat alınamadı | Sunucudan `binance.tr`/`api.binance.me` erişimi olmalı; "Sembolleri senkronla" deneyin |
| Canlı emir reddi | API izinleri (spot trade) ve `min_notional`/`step_size` (senkron) kontrol edin |
| Sunucu saati kayması | İmza `recvWindow` ile korunur; sunucu saatini NTP ile senkron tutun |

---

KBot · Eğitim ve kişisel kullanım içindir. Yatırım tavsiyesi değildir.
