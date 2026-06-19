# KBot — Binance TR DCA & Kar-Al Botu

Binance TR (`https://www.binance.tr`) üzerinde **düzenli alım (DCA)** yapan ve bir coin
belirlenen **çarpana** ulaştığında **karı nakde (kote para) çevirip yalnızca sermayeyi coinde bırakan**
bir bottur. Tüm yönetim **web arayüzünden** yapılır.

> Binance TR genelde **TRY** paritesi kullanır (örn. `BTC_TRY`), bu yüzden varsayılan kote para
> birimi **TRY**'dir. İsterseniz coin başına `USDT` de seçebilirsiniz (parite mevcutsa).

> **Örnek senaryo:** Her hafta düzenli **200 TRY** BTC al. BTC pozisyonunun değeri
> sermayenin **2 katına** ulaşınca, **yarısını sat** ve geriye **sadece sermaye** kalsın;
> kazanılan kısım TRY olarak cebe girsin.

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

- **Sermaye (`cost_basis`)** — coine yatırdığınız ve hâlâ coinde duran kote (TRY) tutar.
- **Miktar (`quantity`)** — botun tuttuğu coin adedi.
- **Çevrilen kar (`realized_profit`)** — şimdiye kadar nakde (TRY) çevrilen toplam kar.

### 1) Düzenli Alım (DCA)

Her periyotta (saatlik / günlük / haftalık / aylık) sabit kote tutar kadar **MARKET alım**
yapılır. Binance TR'de MARKET alım `quoteOrderQty` ile yapıldığından "tam 200 TRY harca"
mümkündür. Alım sonrası sermaye ve miktar büyür.

**Fiyat filtresi (opsiyonel):** Coin için **Maksimum Alım Fiyatı** belirlerseniz, zamanlanmış alım
yalnızca güncel fiyat bu değerin **altındayken** yapılır. Fiyat üstündeyse o tur atlanır (sonraki
alıma ertelenmez; dip gelince ilk fırsatta alınır). Manuel **"Al"** bu filtreyi yok sayar.

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
Başlangıç: 200 TRY ile BTC alındı  → sermaye = 200, değer = 200  (1.0x)
Fiyat 2 katına çıktı               → değer = 400               (2.0x) → TETİK
Satılan: değer − sermaye = 200 TRY (pozisyonun yarısı)
Sonuç:   coinde 200 TRY'lik BTC kaldı (sermaye) + 200 TRY kar cebe (realized_profit)
Yeni durum: sermaye = 200, değer = 200 (1.0x) → döngü baştan
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

> Seed ile **BTC_TRY** ve **ETH_TRY** örnek olarak (kapalı/simülasyon) eklenir.
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
| `bot:balance-check` | saatlik | Canlı kote (TRY) bakiyesini kontrol eder, azalma/düşük bakiye Telegram bildirimi |
| `bot:trade` | her 30 saniye | Etkin trade botlarını (grid/rsi/ma) çalıştırır (sub-minute) |
| `bot:telegram-poll` | her dakika | Ortak Telegram botuna gelen "bağla" mesajlarını işler (chat eşleştirme) |

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

## Trade / Scalp Modu (yatırımdan tamamen ayrı)

Yatırım modunun (DCA + kar-al) yanında, gün içi al-sat yapan **ayrı** bir trade modu vardır.
Tamamen bağımsız izlenir (ayrı tablolar `trade_*`, ayrı sayfa); **aynı coini hem yatırım hem
trade için** kullanabilirsiniz, işlemler birbirine karışmaz.

Menüden **Trade → + Trade botu ekle**. Stratejiler:

- **Grid:** Fiyat aralığı kademelere bölünür; fiyat bir kademenin alış seviyesine inince alır,
  satış seviyesine çıkınca satar. Aralık **manuel** (alt/üst fiyat), **otomatik** (kademe adımı %)
  veya **ATR** (volatiliteye göre dinamik) belirlenir; bütçe kademelere bölünür. Otomatik modda
  **Kademe Adımı (%)** girersiniz: bu oran **kademe başınadır** — her alış bir öncekinin %X altında,
  her satış kendi alışının %X üstündedir (örn. %5 → 5 kademe yaklaşık %25'lik bir bandı kapsar).
  **ATR modunda** kademe adımı = ATR × çarpan (fiyat birimi); volatilite arttıkça kademeler otomatik
  genişler (canlı motor gerçek H/L kullanır, backtest kapanış bazlı yaklaşıkla hesaplar). **Başlangıç noktası** seçilir:
  *alım merdiveni* (tüm kademeler güncel fiyatın altında — bot yalnızca düştükçe alır) ya da
  *simetrik* (fiyatın altı ve üstü). **Trailing** açılırsa, fiyat aralık dışına çıkıp pozisyon
  boşaldığında grid güncel fiyata göre yeniden kurulur (piyasayı takip). Bir kademe yalnızca fiyat
  o seviyeye **yukarıdan inerek** dokununca alır (kurulumda toplu alım olmaz). **Sabit Satış Kârı (%)**
  seçilirse kademeler alım aralığından bağımsız, her biri **alış × (1+%X)** hedefiyle satar
  (örn. %3 aralıkla biriktir, %8 kârla sat; 0 ise bir üst seviyeden satar).
- **RSI:** RSI aşırı satım eşiğinin altına inince alır, aşırı alım eşiğinin üstüne çıkınca satar.
- **MA Kesişimi:** Kısa MA uzun MA'yı yukarı keserse alır, aşağı keserse satar (SMA/EMA).
- **MACD:** MACD çizgisi sinyal çizgisini yukarı keserse alır, aşağı keserse satar.
- **Bollinger:** Fiyat alt banda inince alır, üst banda çıkınca satar.

**Ek filtreler (MACD & Bollinger):** Alımları daraltmak için opsiyonel filtreler — **Trend MA**
(yalnızca fiyat EMA üzerindeyken al), MACD'de **sıfır çizgisi** (yalnızca MACD > 0 iken al),
Bollinger'de **RSI onayı** (alımda RSI ≤ 40). Bu filtreler hem canlıda hem backtest'te uygulanır.

**Üst zaman dilimi (HTF) trend onayı:** RSI/MA/MACD/Bollinger için, alım yalnızca **üst zaman diliminde**
(örn. 4h) fiyat **EMA(periyot)**'ın üzerindeyse yapılır (trend yukarı). "EMA Periyot = 0" ile kapalıdır;
grid'i etkilemez. Bu filtre **canlıda** uygulanır (backtest ham strateji sinyallerini ölçer; stop-loss/trailing gibi).

**Backtest:** Her trade botunun detayında **Backtest** ile, botun kayıtlı ayarlarını geçmiş mum
verisi üzerinde simüle edersiniz (gerçek emir verilmez). Sonuçta işlem sayısı, kazanma oranı,
toplam K/Z, al-tut karşılaştırması ve **equity (sermaye) eğrisi grafiği** gösterilir.
**Komisyon (%)** ve **kayma/slippage (%)** modele dahil edilir (formdan ayarlanır).

**Optimize:** Detaydaki **Optimize** ile, stratejiye göre bir parametre ızgarası otomatik taranır
(grid'de adım %/kademe/satış kârı; RSI'da periyot ve eşikler; MA'da kısa/uzun; MACD'de hızlı/yavaş/sinyal;
Bollinger'de periyot/k). Her kombinasyon aynı geçmiş veride backtest edilip **Toplam K/Z**'ye göre sıralanır;
en iyiler tabloda listelenir. **Sonuç otomatik uygulanmaz** — beğendiğiniz kombinasyonu **Düzenle**'den elle girersiniz.

**Detay grafiği:** Her botun detayında **mum (candlestick) + hacim** grafiği vardır (bağımsız canvas,
ek kütüphane yok); zaman dilimi seçilebilir. Grid botlarında **alış (yeşil)** ve **satış (kırmızı)**
kademeleri grafiğe yatay çizgi olarak biner, böylece fiyatın kademelere göre konumunu görürsünüz.
**İşlem geçmişi** sayfasından tüm trade emirlerini **CSV (Excel uyumlu)** indirebilirsiniz.

Her botta **bütçe**, **işlem tutarı**, **maksimum alım fiyatı** ve **mod** (sim/canlı) ayarlanır.
Ek seçenekler: **Compounding** (açıksa gerçekleşen kâr bütçeye eklenir, alım tutarları büyür;
zararda küçülür — kapalıysa bütçe sabit), **Zarar Durdurma** (toplam K/Z, bütçenin belirlenen
yüzdesi kadar zarara ulaşınca bot otomatik durdurulur) ve **Trailing Take-Profit / kâr koruma**
(toplam K/Z bir zirve yaptıktan sonra bütçenin belirlenen yüzdesi kadar geri çekilirse pozisyon
tamamen kapatılıp kâr bankaya yazılır; bot çalışmaya devam edip yeniden girer). Hepsi Telegram'a bildirilir.
`bot:trade` komutu scheduler ile **her 30 saniyede** çalışır (Laravel sub-minute; dakikalık `schedule:run` bunu yönetir). İşlemler ve hatalar Telegram'a da düşer.
Strateji çerçevesi geneldir; ileride yeni stratejiler eklenebilir.

> Borsadaki gerçek bakiye ortaktır; bot her modu **kendi defterinde** (ayrı maliyet/pozisyon/kar)
> izler. Trade işlemleri yatırım işlemlerine karışmaz.

**API dayanıklılığı:** Fiyat/mum/bakiye gibi **okuma** çağrıları bağlantı/zaman aşımı hatasında
otomatik **3 kez** (artan beklemeyle) yeniden denenir. **Emir** (alım/satım) çağrıları güvenlik için
**asla** tekrar denenmez — zaman aşımına uğrayan bir emir borsada dolmuş olabilir, ikinci kez göndermek
çift işlem riski yaratır. API/işlem hataları üst üste 3 turdur sürerse Telegram'dan **tek** bir uyarı
gelir (her turda değil); toparlayınca kısa bir bilgi notu düşer.

## Telegram Bildirimleri (opsiyonel)

Bildirimler **hesaba bağlıdır**: her kullanıcı kendi Telegram'ını bağlar ve bildirimleri yalnızca
**kendi** sohbetine gider (yalnızca bilgilendirme, işlem yapmaz).

**Kurulum — yönetici (tek sefer):** İlk kullanıcı (kurulumda oluşturulan yönetici) **@BotFather**
ile **tek bir ortak bot** oluşturur ve token'ı **Ayarlar → Uygulama Telegram Botu** alanına girer.
Token doğrulanır (`getMe`) ve bot kullanıcı adı otomatik kaydedilir.

**Bağlanma — her kullanıcı (tek tık):** **Ayarlar → Telegram Bildirimleri → "Telegram'ı Bağla"**
→ açılan **"Telegram'da Aç"** ile bota gidip **Başlat**'a basar. Bot `/start <kod>` mesajını alır
(webhook kuruluysa **anında**, değilse `bot:telegram-poll` ile ~1 dk), kodu kullanıcıyla eşleştirir
ve chat ID'sini otomatik kaydeder. Sayfa bağlanmayı canlı algılar — token/chat ID elle girmeye gerek yoktur.

**Telegram'dan şalter:** Bağlı kullanıcı Telegram'a **/salter** yazarak tüm otomatik işlemleri
(yatırım + trade) durdurabilir/başlatabilir (`bot_enabled` aç/kapat); **/durum** mevcut durumu gösterir.
Komut, **webhook** kuruluysa anında, değilse polling ile ~1 dk içinde uygulanır (anında durdurmak için
panelde **Ayarlar → Bot aktif** kutusunu da kullanabilirsiniz).

> **Gelişmiş:** Ortak bot yerine kendi botunu kullanmak isteyen kullanıcılar, Telegram kartındaki
> **Gelişmiş** bölümünden kendi token + chat ID'lerini girebilir; doluysa bildirimler o botla gider.

Bildirim türleri ayrı ayrı açılıp kapatılır: **işlemler** (alım/kar-al/satış), **hatalar**,
**bakiye azalması / düşük bakiye**. Düşük bakiye eşiği belirleyebilirsiniz; canlı kote (TRY)
bakiyesi saatlik `bot:balance-check` ile kontrol edilir ve azalma/eşik altı durumunda bildirilir.

**Trade bütçesi yetersizliği:** `bot:balance-check`, **canlı** trade botlarının bütçelerini
karşılamak için gereken kote (TRY) tutarını (her botun kalan bütçesi = bütçe − harcanan) kote
varlığı başına toplar ve serbest bakiyeyle karşılaştırır. Yetersizse Telegram'dan uyarır
("bakiye" bildirimi açıksa). Tekrarı önlemek için yalnızca durum değişiminde (yetersiz↔yeterli)
bir kez bildirilir.

> Ortak bot, token kaydedilirken **webhook** kurar (anlık komut/bağlanma). Webhook kurulamazsa
> (örn. APP_URL https değilse) sistem otomatik dakikalık **polling**'e düşer. Webhook için sunucuda
> `APP_URL=https://alan-adınız` olmalı ve `/telegram/webhook/...` ucu Telegram'dan erişilebilir olmalı.
> Webhook'u kaldırmak için Ayarlar'dan botu **Kaldır** (deleteWebhook yapılır).

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
php artisan bot:balance-check  # Canlı bakiye kontrolü + Telegram bildirimi
php artisan bot:trade          # Trade botlarını (grid/rsi/ma) çalıştır
php artisan bot:telegram-poll  # Ortak Telegram botuna gelen "bağla" mesajlarını işle (webhook yoksa)
php artisan bot:telegram-webhook # Ortak bot için webhook'u (yeniden) kur (APP_URL https olmalı)
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
