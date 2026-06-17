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
# Opsiyonel alan adi:
#     sudo DOMAIN=bot.ornek.com bash deploy/install.sh
#
set -euo pipefail

PHP_VER="${PHP_VER:-8.3}"
DOMAIN="${DOMAIN:-_}"
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

echo "==> Bagimliliklar yukleniyor (composer install)"
cd "$APP_DIR"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Ortam dosyasi (.env)"
if [[ ! -f .env ]]; then
    cp .env.example .env
fi
# Sunucu icin uretim ayarlari
sed -i 's/^APP_ENV=.*/APP_ENV=production/' .env
sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env
php artisan key:generate --force

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

echo "==> Scheduler cron'u ekleniyor (www-data, tekrarsiz)"
CRON_LINE="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
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
    echo "    http://${DOMAIN}/install"
fi
echo ""
echo " Sihirbazda MySQL bilgilerini girin ve ilk yoneticiyi olusturun."
echo " Bot, eklenen cron sayesinde her dakika scheduler ile calisir:"
echo "    bot:dca / bot:evaluate / bot:sync-symbols"
echo "============================================================"
