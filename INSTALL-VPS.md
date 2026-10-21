# Pasang Nexora Content Engine di VPS

Dua pilihan. **A (Docker + Caddy)** paling mudah: SSL otomatis untuk semua domain dan update satu perintah.
**B (Nginx + PHP)** cocok untuk VPS kecil tanpa Docker. VPS Ubuntu 22.04/24.04 atau Debian 12, RAM 1 GB cukup untuk beberapa situs.

Tips: pilih lokasi server dekat target audiens (situs Jerman → server Eropa, situs AS → server AS) dan pasang Cloudflare (proxied) di depannya.

---

## A. Docker + Caddy (disarankan)

```bash
# 1. Install Docker
curl -fsSL https://get.docker.com | sh

# 2. Ambil file compose & Caddyfile dari repo (atau upload dari zip)
mkdir -p /opt/agc && cd /opt/agc
#   salin docker-compose.yml dan Caddyfile ke folder ini

# 3. Tulis domain Anda di Caddyfile (baris pertama), lalu jalankan
nano Caddyfile
docker compose up -d
```

Pastikan DNS (A record) semua domain sudah mengarah ke IP VPS sebelum menjalankan, agar Caddy bisa membuat SSL.
Lalu buka `https://domain-anda/install`.

**Tambah situs baru:** tambahkan domain di `Caddyfile` → `docker compose restart caddy` → Admin → Situs → Tambah situs baru.

**Update:** `docker compose pull && docker compose up -d`

**Backup:** `docker run --rm -v agc_agc_storage:/data -v $PWD:/backup alpine tar czf /backup/agc-backup.tgz -C /data .`

---

## B. Nginx + PHP (tanpa Docker)

```bash
apt update && apt install -y nginx php-fpm php-sqlite3 php-curl php-mbstring php-intl unzip
unzip nexora-content-engine.zip -d /var/www/
chown -R www-data:www-data /var/www/nexora/storage
```

`/etc/nginx/sites-available/agc` (satu blok untuk SEMUA domain situs; ganti versi PHP sesuai `ls /run/php/`):

```nginx
server {
    listen 80;
    server_name domain-satu.com www.domain-satu.com domain-dua.com www.domain-dua.com;
    root /var/www/nexora/public;
    index index.php;
    location / { try_files $uri /index.php$is_args$args; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~* \.(css|js|png|jpg|svg|ico|woff2)$ { expires 30d; access_log off; }
    location ~ /\. { deny all; }
}
```

```bash
ln -s /etc/nginx/sites-available/agc /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
apt install -y certbot python3-certbot-nginx
certbot --nginx -d domain-satu.com -d www.domain-satu.com -d domain-dua.com -d www.domain-dua.com
```

Cron (`crontab -u www-data -e`):

```
7 * * * *    php /var/www/nexora/cli/fetch.php > /dev/null 2>&1
*/30 * * * * php /var/www/nexora/cli/enrich.php > /dev/null 2>&1
```

Buka `https://domain-satu.com/install`.

**VPS RAM 512 MB atau kurang:** tambah swap 1 GB dan set PHP-FPM ke `pm = ondemand`, `pm.max_children = 4`.
