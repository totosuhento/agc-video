# AGC Video

Mesin AGC (Auto Generated Content) **video universal**: satu script untuk banyak situs video dengan niche, bahasa, dan iklan berbeda.
Video diambil otomatis dari **sumber resmi / legal** dan diputar dengan **player resmi platformnya** (tidak ada video yang di-download/di-host):

| Sumber | Butuh API key? | Cocok untuk |
|---|---|---|
| YouTube (channel resmi) | Ya (gratis) | semua niche |
| Dailymotion (akun resmi penerbit) | Tidak | berita, TV, olahraga, hiburan |
| Internet Archive (otomatis hanya *public domain*/CC0) | Tidak | film klasik, horor lawas, dokumenter sejarah |
| NASA Image & Video Library | Tidak | sains, luar angkasa, edukasi |
| TikTok (link video, embed resmi) | Tidak | pelengkap klip pendek dari akun resmi |

Dibangun dari dua AGC sebelumnya (Drama China & Tutorial Software Jerman) sebagai referensi.

## Fitur utama

- **Multi-situs**: satu instalasi melayani banyak domain. Tiap situs punya database, niche, bahasa, tema, dan iklan sendiri.
- **Panel admin `/admin`** (bahasa Indonesia): tanpa edit kode atau environment variable.
  - Wizard situs baru: domain, judul, **kategori video (preset)**, **bahasa**, **target negara**, **keyword**, mode iklan.
  - **Preset niche**: Drama pendek, Drama Korea, Horor, Anime, Tutorial software, Resep, Dokumenter, atau isi sendiri.
  - **Pencari channel YouTube** (kata kunci + negara + bahasa) → centang → tambah.
  - **Iklan**: Google AdSense (auto ads + unit), CPM (Adsterra, Monetag, dll.: banner, native, social bar, popunder), tombol penawaran **CPA** di bawah player, editor **ads.txt**, label "Iklan/Anzeige" otomatis.
  - **Mode iklan per situs**: *AdSense saja* (aman), *CPM/CPA*, atau *Campuran* (dengan peringatan).
- **10 bahasa tampilan**: Indonesia, Inggris, Jerman, Spanyol, Prancis, Belanda, Mandarin (sederhana), Korea, Jepang, Hindi — termasuk halaman About, Privacy, Disclaimer, Contact (Jerman: Datenschutz DSGVO + Impressum).
- **Target negara** (mis. Kanada `en-CA`/`fr-CA`, Swiss `de-CH`/`fr-CH`): atribut bahasa HTML, `og:locale`, format tanggal & angka lokal.
- **Halaman topik dari keyword** (`/topic/...`), sitemap, schema VideoObject/Breadcrumb/ItemList, canonical, robots.txt, `max-image-preview:large` (Google Discover).
- **Modul artikel** (`/articles`): editor di admin, format Markdown sederhana, sisip kartu video `[video:slug]`, jadwal terbit, slot iklan *in-article* otomatis, schema BlogPosting, draf AI opsional.
- **SEO untuk menghasilkan dolar**: halaman video tanpa deskripsi unik otomatis `noindex` dan tidak masuk sitemap (proteksi konten tipis), internal link video ↔ artikel, AI menyisipkan video yang relevan ke dalam artikel, kode verifikasi Google/Bing/Naver/Yandex, IndexNow otomatis, dan checklist **Siap menghasilkan dolar** di Dashboard.
- **Tulis cepat + jadwal terbit**: tempel banyak topik (boleh bahasa Indonesia) → AI menulis artikel lengkap dalam bahasa situs dan menyimpannya sebagai **draf**, lengkap dengan ringkasan bahasa Indonesia dan tombol *Terjemahkan ke Indonesia* untuk mengecek isi. Draf yang sudah dicek dijadwalkan sekaligus (mis. 1 artikel per hari mulai besok 08:00 WIB); artikel terbit otomatis tepat waktunya.
- **Situs saudara multi-bahasa**: isi nama grup yang sama di beberapa situs (mis. drakor EN/ES/DE) → otomatis `hreflang` + pemilih bahasa di footer.
- **Tombol share** ringan (WhatsApp, Telegram, Facebook, X, Pinterest, Reddit, LinkedIn, LINE, VK, Email, salin link, share bawaan HP) tanpa script pihak ketiga.
- **Auto-post ke channel Telegram** untuk video & artikel baru, dan **push notification** (OneSignal / Monetag / PropellerAds) dengan service worker yang dilayani langsung oleh script.
- Filter video: durasi minimal (buang Shorts), kata yang wajib/terlarang di judul, otomatis sembunyikan video privat/dihapus/tidak bisa di-embed/18+.
- Deskripsi unik dengan AI (opsional; Anthropic atau API kompatibel OpenAI).
- Cron otomatis (Docker), cron biasa (VPS/cPanel), atau **cron lewat URL** (hosting tanpa Cron Jobs).
- SQLite (tanpa MySQL), cache halaman, hemat kuota API.

## Pilih cara pasang

| Cara | Cocok untuk | Panduan |
|---|---|---|
| **Docker / Nusapod** | Hosting container, paling mudah di-update | [INSTALL-DOCKER.md](INSTALL-DOCKER.md) |
| **VPS** | Banyak situs, trafik besar | [INSTALL-VPS.md](INSTALL-VPS.md) |
| **Shared hosting (cPanel)** | Mulai murah 1–3 situs | [INSTALL-SHARED-HOSTING.md](INSTALL-SHARED-HOSTING.md) |

Setelah terpasang, semua cara sama: buka **`https://domain-anda/install`** → buat password admin → buat situs → tambah channel → klik **Ambil video sekarang**.

## Alur membuat situs niche baru (mis. drama Korea, horor)

1. Arahkan DNS domain baru ke server yang sama.
2. Tambahkan domain di server (Docker+Caddy: `Caddyfile`; cPanel: *Addon Domain* ke folder yang sama; Nusapod: tambah domain di container).
3. Admin → **Situs** → **+ Tambah situs baru** → isi domain, judul, kategori (preset), bahasa, target negara, keyword, mode iklan.
4. Admin → **Sumber video** → cari / tempel channel YouTube **resmi**, atau tambah sumber Dailymotion / Internet Archive / NASA / TikTok.
5. Admin → **Iklan** → tempel kode AdSense / CPM / CPA.
6. Dashboard → **Ambil video sekarang**. Selanjutnya cron mengambil video baru tiap jam.
7. Admin → **Artikel** → tulis minimal 3–5 artikel orisinal sebelum mendaftar AdSense.
8. Admin → **Sosial** → atur tombol share, Telegram, push.
9. Daftarkan `https://domain/sitemap.xml` di Google Search Console.

## Perintah terminal (opsional)

```bash
php cli/fetch.php [domain]      # ambil video (semua situs atau satu)
php cli/enrich.php [domain]     # deskripsi AI
php cli/status.php              # ringkasan semua situs
php cli/setup.php admin --password=PasswordBaru123        # reset password admin
php cli/setup.php site --host=domain.com --name="Nama" --lang=ko --country=KR --preset=kdrama
php cli/setup.php alias --site=domain.com --host=domain-lain.com
```

## Struktur folder

```
app/        logika: inti multi-situs, fetcher YouTube, admin, bahasa (app/lang), preset (app/presets.php)
cli/        perintah terminal & cron
public/     root web (index.php, assets/)
storage/    main.sqlite (admin & daftar situs) + sites/<domain>/ (database, cache, log) — wajib bisa ditulis
index.php + .htaccess   cadangan untuk shared hosting bila document root tidak bisa diarahkan ke public/
```

## Menambah niche atau bahasa sendiri

- **Niche baru**: tambah satu blok di `app/presets.php` (sebutan niche dalam 10 bahasa, filter, tema, kata kunci pencarian channel).
- **Bahasa baru**: salin `app/lang/en.php` menjadi `app/lang/xx.php`, terjemahkan, lalu tambahkan kode bahasanya di konstanta `LANGS` (`app/helpers.php`).

## Aturan main yang menjaga situs tetap aman

- Pakai hanya **channel resmi** pemegang hak. Channel reupload berisiko klaim hak cipta.
- **Tidak ada content locker**: tombol CPA hanya tawaran tambahan, video tetap bisa ditonton tanpa klik.
- Jangan gabung AdSense dengan popunder/redirect bila ingin akun AdSense aman (gunakan mode *AdSense saja*).
- Tambahkan konten orisinal (teks pembuka, keyword topik, deskripsi AI) supaya situs tidak dianggap *thin content*.
- Situs berbahasa Jerman wajib Impressum (nama & alamat asli). Teks privasi bawaan adalah template, bukan nasihat hukum.
- YouTube & AdSense tidak tersedia di China daratan: situs berbahasa Mandarin sebaiknya menargetkan Taiwan, Hong Kong, Singapura, atau Malaysia.
