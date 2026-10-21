# Pasang AGC Video di Docker / Nusapod

Image siap pakai dibuat otomatis oleh GitHub Actions dan diuji sebelum dipublikasikan:

```
ghcr.io/totosuhento/agc-video:latest
```

Image ini berjalan sebagai user 1000 di port **8080** (sesuai Nusapod) dan juga bisa dijalankan sebagai root / port lain.

## 1. Image Docker

Jika repo `agc-video` sudah ada di GitHub Anda, image dibuat otomatis setiap ada perubahan (tab **Actions** harus hijau ✅).
Jadikan package **Public** sekali saja: profil GitHub → **Packages** → `agc-video` → **Package settings** → **Change visibility** → **Public**.

## 2. Buat container di Nusapod

| Pengaturan | Isi |
|---|---|
| Image | `ghcr.io/totosuhento/agc-video:latest` |
| Port container | `8080` |
| **Persistent volume** | mount ke **`/var/www/html/storage`** (wajib; berisi semua situs & pengaturan) |
| RAM | 512 MB (cukup untuk beberapa situs) |
| Domain | domain-domain situs Anda + SSL |

**Environment variables tidak diperlukan.** Semua pengaturan dilakukan di panel admin.
Opsional: `PORT` (bila hosting mewajibkan port lain), `CRON_ENABLED=0` (matikan cron internal), `FETCH_INTERVAL` (detik, default 3600).

## 3. Instalasi

1. Buka `https://domain-anda/install` → buat password admin (+ YouTube API key).
2. Buat situs pertama → tambah channel → **Ambil video sekarang**.
3. Situs berikutnya: tambahkan domain baru di container yang **sama**, lalu Admin → **Situs** → **+ Tambah situs baru**.

Lakukan langkah 1 segera setelah container jalan (siapa pun yang membuka `/install` pertama kali bisa membuat password admin).

## Update

Ubah file di repo GitHub → Actions membuat & menguji image baru → **Redeploy / pull latest** di Nusapod. Data di volume tetap aman.

## Masalah umum

| Gejala | Solusi |
|---|---|
| Gagal pull image | Package belum **Public** |
| Data hilang setelah redeploy | Volume belum di-mount ke `/var/www/html/storage` |
| 502 / tidak bisa diakses | Port container di panel harus `8080` (atau sama dengan `PORT`) |
| "This website is not set up yet" | Domain itu belum ditambahkan di Admin → Situs |
