# Pasang AGC Video di shared hosting (cPanel)

## Syarat hosting

- PHP **8.1+** dengan ekstensi **pdo_sqlite**, **curl**, **mbstring** (disarankan **intl**). Atur di cPanel → *Select PHP Version*.
- Koneksi keluar ke `www.googleapis.com` tidak diblokir.
- Menu **Cron Jobs** (bila tidak ada, pakai cron lewat URL; lihat di bawah).
- Cek syarat layanan hosting: sebagian melarang situs yang isinya dibuat otomatis.

Halaman `/install` otomatis memeriksa syarat-syarat ini.

## Langkah

1. **Upload** `agc-video.zip` lewat *File Manager* ke folder di luar `public_html`, mis. `/home/USER/agc-video`, lalu **Extract**.
2. **Arahkan domain**:
   - **Addon domain / subdomain** (disarankan): saat menambah domain, isi *Document Root* = `agc-video/public`.
   - **Domain utama** (document root tidak bisa diubah): extract langsung ke `public_html`. File `.htaccess` dan `index.php` di folder utama sudah menangani routing dan memblokir folder `app/`, `cli/`, `storage/`.
3. Pastikan folder `storage` bisa ditulis (permission 755/775; bila perlu 777).
4. Buka `https://domain-anda/install` → buat password admin → buat situs → tambah channel → **Ambil video sekarang**.
5. **Cron Jobs** (cPanel → Cron Jobs, *Once Per Hour*):
   ```
   php /home/USER/agc-video/cli/fetch.php > /dev/null 2>&1
   ```
   Opsional AI tiap 30 menit: `php /home/USER/agc-video/cli/enrich.php > /dev/null 2>&1`
   Path persis ditampilkan di Dashboard admin.

## Situs kedua dan seterusnya

Tambahkan *Addon Domain* baru dengan **document root yang sama** (`agc-video/public`), lalu Admin → **Situs** → **+ Tambah situs baru**.
Satu instalasi dan satu cron melayani semua situs.

## Hosting tanpa Cron Jobs

Dashboard admin menampilkan URL rahasia `https://domain-anda/_cron/xxxxxxxx`. Panggil URL itu tiap jam dari layanan cron gratis (mis. cron-job.org).

## Keterbatasan shared hosting

- Batas CPU/proses: saat trafik ramai bisa muncul error 508. Cache halaman membantu; pasang Cloudflare di depan domain.
- Tombol *Ambil video sekarang* dibuat ringan agar tidak timeout; sisa video diambil bertahap oleh cron.
- Setelah trafik besar, pindah ke VPS (salin folder `storage/` beserta isinya).
