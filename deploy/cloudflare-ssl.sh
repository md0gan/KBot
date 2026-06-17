#!/usr/bin/env bash
#
# KBot - Cloudflare Origin Certificate ile Nginx 443 (HTTPS) yapilandirmasi
# ------------------------------------------------------------------------
# Alan adi Cloudflare arkasindaysa (proxied / turuncu bulut) en saglikli SSL
# yontemi budur: CF'den 15 yillik ucretsiz "Origin Certificate" alip origin'e
# kurarsiniz; CF SSL modunu "Full (strict)" yaparsiniz. Uctan uca sifrelidir
# ve yenileme gerektirmez.
#
# ADIMLAR:
#   1) Cloudflare Dashboard > SSL/TLS > Origin Server > "Create Certificate"
#      (Let Cloudflare generate... -> Create). Iki kutu cikar:
#        - Origin Certificate  (-----BEGIN CERTIFICATE----- ...)
#        - Private Key         (-----BEGIN PRIVATE KEY----- ...)
#   2) Sunucuda bu iki kutuyu dosyaya kaydedin (DOMAIN'inize gore):
#        sudo mkdir -p /etc/ssl/cloudflare
#        sudo nano /etc/ssl/cloudflare/kbot.modago.com.tr.pem   # Origin Certificate yapistir
#        sudo nano /etc/ssl/cloudflare/kbot.modago.com.tr.key   # Private Key yapistir
#        sudo chmod 600 /etc/ssl/cloudflare/kbot.modago.com.tr.key
#   3) Bu betigi calistirin:
#        sudo DOMAIN=kbot.modago.com.tr bash deploy/cloudflare-ssl.sh
#   4) Cloudflare > SSL/TLS > Overview > sifreleme modunu "Full (strict)" yapin.
#
set -euo pipefail

PHP_VER="${PHP_VER:-8.3}"
DOMAIN="${DOMAIN:?DOMAIN gerekli. Ornek: sudo DOMAIN=kbot.modago.com.tr bash deploy/cloudflare-ssl.sh}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"
CERT="${CERT:-/etc/ssl/cloudflare/${DOMAIN}.pem}"
KEY="${KEY:-/etc/ssl/cloudflare/${DOMAIN}.key}"

if [[ "${EUID}" -ne 0 ]]; then
    echo "HATA: root ile calistirin (sudo)." >&2
    exit 1
fi

if [[ ! -f "$CERT" ]]; then
    echo "HATA: Origin sertifikasi bulunamadi: $CERT" >&2
    echo "  CF panelinden alip kaydedin (bkz. bu betigin basindaki ADIMLAR)." >&2
    exit 1
fi
if [[ ! -f "$KEY" ]]; then
    echo "HATA: Ozel anahtar bulunamadi: $KEY" >&2
    exit 1
fi

echo "==> Nginx 443 (Cloudflare Origin) yapilandiriliyor: $DOMAIN"
cat > /etc/nginx/sites-available/kbot <<NGINX
# HTTP -> HTTPS yonlendirme
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
}

# HTTPS (Cloudflare Origin Certificate)
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name ${DOMAIN};
    root ${APP_DIR}/public;

    ssl_certificate     ${CERT};
    ssl_certificate_key ${KEY};

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

# ufw aktifse 443'u ac
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow 'Nginx Full' || true
fi

nginx -t
systemctl reload nginx

echo ""
echo "============================================================"
echo " HTTPS hazir (Cloudflare Origin Certificate)."
echo " SON ADIM: Cloudflare > SSL/TLS > Overview > 'Full (strict)' secin."
echo " Site:  https://${DOMAIN}/install"
echo "============================================================"
