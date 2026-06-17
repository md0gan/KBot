#!/usr/bin/env bash
#
# KBot - Sifir Ubuntu sunucu kurulum scripti (Nginx + PHP-FPM)
# ------------------------------------------------------------------
# Ubuntu 22.04 / 24.04 uzerinde calisir. MySQL'in KURULU oldugu varsayilir.
# PHP, Composer, Nginx kurar; uygulamayi hazirlar; scheduler cron'unu ekler.
# Veritabani bilgileri ve ilk yonetici WEB ARAYUZUNDEN girilir (/install).
#
# Kullanim (proje kok dizininde, root olarak):
#     sudo bash deploy/install.sh
# Alan adi (HTTPS icin gerekli) ve SSL e-postasi ile:
#     sudo DOMAIN=bot.ornek.com EMAIL=ben@ornek.com bash deploy/install.sh
#
# DOMAIN verilirse kurulum sonunda Certbot ile ucretsiz SSL alinir (DNS, alan adini
# sunucu IP'sine yonlendiriyor olmali). EMAIL verilmezse e-postasiz kayit yapilir.
#
set -euo pipefail

PHP_VER="${PHP_VER:-8.3}"
PHP_BIN="php${PHP_VER}"   # KBot her zaman bu surumu kullanir (sistemin 'php' varsayilanina dokunmaz)
DOMAIN="${DOMAIN:-_}"
EMAIL="${EMAIL:-}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"

echo "==> KBot kurulumu basliyor"
echo "    Proje dizini : $APP_DIR"
echo "    PHP surumu   : $PHP_VER"
echo "    Alan adi     : $DOMAIN"

if [[ "${EUID}" -ne 0 ]]; then
    echo "HATA: Bu script root ile calistirilmali (sudo bash deploy/install.sh)" >&2
    exit 1
fi

# Konum uyarisi: /home veya /root altinda Nginx (www-data) erisemeyebilir (403).
case "$APP_DIR" in
    /home/*|/root/*)
        echo ""
        echo "!!! UYARI: Proje '$APP_DIR' altinda."
        echo "    Nginx (www-data) bu dizine erisemeyebilir ve 403 alabilirsiniz."
        echo "    Onerilen: projeyi /var/www altina klonlayin:"
        echo "      cd /var/www && sudo git clone https://github.com/md0gan/KBot.git kbot && cd kbot"
        echo "      sudo DOMAIN=${DOMAIN} bash deploy/install.sh"
        echo ""
        echo "    Yine de buraya kurmak icin 8 saniye icinde bir sey yapmayin (Ctrl+C = iptal)..."
        sleep 8
        ;;
esac

export DEBIAN_FRONTEND=noninteractive

echo "==> Paketler kuruluyor (PHP $PHP_VER, Nginx, araclar)"
apt-get update -y
apt-get install -y software-properties-common curl unzip git ca-certificates
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y \
    nginx \
    "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-mysql" \
    "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" "php${PHP_VER}-curl" \
    "php${PHP_VER}-bcmath" "php${PHP_VER}-zip" "php${PHP_VER}-intl" "php${PHP_VER}-gd"

echo "==> Composer kuruluyor"
if ! command -v composer >/dev/null 2>&1; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo "==> Bagimliliklar yukleniyor (composer install, ${PHP_BIN})"
cd "$APP_DIR"
COMPOSER_BIN="$(command -v composer || echo /usr/local/bin/composer)"
# Sistemin varsayilan 'php' surumu farkli olabilir (orn. 8.5); KBot icin acikca php8.3 kullan.
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

echo "==> Ortam dosyasi (.env)"
if [[ ! -f .env ]]; then
    cp .env.example .env
fi
# Sunucu icin uretim ayarlari
sed -i 's/^APP_ENV=.*/APP_ENV=production/' .env
sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env
"$PHP_BIN" artisan key:generate --force

echo "==> Izinler ayarlaniyor (www-data)"
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR/storage" -type d -exec chmod 775 {} \;
find "$APP_DIR/bootstrap/cache" -type d -exec chmod 775 {} \;
chmod 664 .env

echo "==> Nginx site yapilandirmasi"
cat > /etc/nginx/sites-available/kbot <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${APP_DIR}/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php\$ {
        fastcgi_pass unix:${FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/kbot /etc/nginx/sites-enabled/kbot
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable nginx >/dev/null 2>&1 || true
systemctl restart nginx
systemctl enable "php${PHP_VER}-fpm" >/dev/null 2>&1 || true
systemctl restart "php${PHP_VER}-fpm"

echo "==> SSL sertifikasi (Let's Encrypt / Certbot)"
SSL_OK=0
if [[ "$DOMAIN" != "_" ]]; then
    apt-get install -y certbot python3-certbot-nginx

    # ufw aktifse HTTPS portunu ac
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
        ufw allow 'Nginx Full' || true
    fi

    # E-posta verildiyse kullan, yoksa e-postasiz kayit
    if [[ -n "$EMAIL" ]]; then
        CERTBOT_REG=(-m "$EMAIL")
    else
        CERTBOT_REG=(--register-unsafely-without-email)
    fi

    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect "${CERTBOT_REG[@]}"; then
        SSL_OK=1
        echo "    SSL kuruldu. HTTP -> HTTPS yonlendirmesi aktif. Otomatik yenileme: certbot.timer"
    else
        echo "    !!! SSL ALINAMADI. Olasi sebep: DNS henuz '$DOMAIN' -> sunucu IP'sine bakmiyor"
        echo "        ya da 80/443 disaridan erisilebilir degil. HTTP site yine de calisir."
        echo "        DNS hazir oldugunda sunu calistirin:"
        echo "          sudo certbot --nginx -d $DOMAIN --redirect"
    fi
else
    echo "    DOMAIN verilmedigi icin SSL atlandi. HTTPS icin: sudo DOMAIN=alan.adi EMAIL=eposta bash deploy/install.sh"
fi

echo "==> Scheduler cron'u ekleniyor (www-data, tekrarsiz)"
CRON_LINE="* * * * * cd ${APP_DIR} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1"
# Idempotent: bu projeye ait eski satir (varsa) cikarilir, tek satir birakilir.
# Yalnizca bu projenin dizinini iceren satirlar temizlenir; baska projelerin
# cron'lari KORUNUR. Boylece script tekrar tekrar calistirilsa da cift satir olmaz.
EXISTING_CRON="$(crontab -u www-data -l 2>/dev/null || true)"
FILTERED_CRON="$(printf '%s\n' "$EXISTING_CRON" | grep -vF "$APP_DIR" || true)"
printf '%s\n%s\n' "$FILTERED_CRON" "$CRON_LINE" | grep -v '^[[:space:]]*$' | crontab -u www-data -

IP_ADDR="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo ""
echo "============================================================"
echo " KBot kurulumu TAMAMLANDI."
echo ""
echo " Simdi tarayicidan kurulum sihirbazini acin:"
if [[ "$DOMAIN" == "_" ]]; then
    echo "    http://${IP_ADDR:-SUNUCU_IP}/install"
else
    PROTO="http"
    [[ "${SSL_OK:-0}" -eq 1 ]] && PROTO="https"
    echo "    ${PROTO}://${DOMAIN}/install"
    [[ "$PROTO" == "https" ]] && echo "    (Sihirbazda APP_URL'i https://${DOMAIN} olarak girin)"
fi
echo ""
echo " Sihirbazda MySQL bilgilerini girin ve ilk yoneticiyi olusturun."
echo " Bot, eklenen cron sayesinde her dakika scheduler ile calisir:"
echo "    bot:dca / bot:evaluate / bot:sync-symbols"
echo "============================================================"
