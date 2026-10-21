<?php
declare(strict_types=1);

/*
 * Panel admin & instalasi (bahasa Indonesia).
 * Satu password admin mengelola semua situs. Situs yang sedang diedit disimpan di sesi.
 */

require_once ROOT . '/app/cron.php';

/* ================= Sesi, keamanan, util ================= */

function admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ensure_dir(STORAGE . '/sessions');
    session_save_path(STORAGE . '/sessions');
    session_name('agc_admin');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => request_is_https(), 'path' => '/']);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token();
    }
    return (string)$_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    return is_string($_POST['_csrf'] ?? null) && hash_equals(csrf_token(), (string)$_POST['_csrf']);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin']) && ($_SESSION['admin_hash'] ?? '') === substr((string)gget('admin_hash', ''), -16);
}

function admin_login_as(): void
{
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_hash'] = substr((string)gget('admin_hash', ''), -16);
}

function post(string $path, mixed $default = ''): mixed
{
    $v = $_POST;
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return $default;
        }
        $v = $v[$k];
    }
    return $v;
}

function str_list(string $text): array
{
    $out = [];
    foreach (preg_split('/[\r\n,]+/u', $text) ?: [] as $w) {
        $w = trim($w);
        if ($w !== '' && !in_array($w, $out, true)) {
            $out[] = $w;
        }
    }
    return $out;
}

function clamp_int(mixed $v, int $min, int $max, int $default): int
{
    return is_numeric($v) ? max($min, min($max, (int)$v)) : $default;
}

/** Situs yang sedang diedit di panel admin. */
function admin_site(): ?string
{
    $sites = array_column(sites_all(), 'id');
    if (!$sites) {
        return null;
    }
    $id = (string)($_SESSION['site'] ?? '');
    if (!in_array($id, $sites, true)) {
        $id = site_id_for_host(request_host()) ?? $sites[0];
        $_SESSION['site'] = $id;
    }
    Site::activate($id);
    return $id;
}

/* ================= Router ================= */

function admin_dispatch(string $path): void
{
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    admin_session();

    if ($path === '/install' || str_starts_with($path, '/install/')) {
        page_install();
        return;
    }
    if (!is_installed()) {
        redirect('/install');
    }
    if ($path === '/admin/login') {
        page_login();
        return;
    }
    if (!admin_logged_in()) {
        redirect('/admin/login');
    }
    if (is_post() && !csrf_ok()) {
        flash('Sesi kedaluwarsa, silakan ulangi.', 'err');
        redirect($path);
    }
    if ($path === '/admin/logout') {
        session_destroy();
        redirect('/admin/login');
    }

    $routes = [
        '/admin' => 'page_dashboard', '/admin/settings' => 'page_settings', '/admin/channels' => 'page_channels',
        '/admin/ads' => 'page_ads', '/admin/sites' => 'page_sites', '/admin/sites/new' => 'page_site_new',
        '/admin/global' => 'page_global', '/admin/switch' => 'action_switch', '/admin/fetch' => 'action_fetch',
        '/admin/cache' => 'action_cache', '/admin/preset' => 'action_preset',
        '/admin/articles' => 'page_articles_admin', '/admin/articles/edit' => 'page_article_edit', '/admin/social' => 'page_social',
    ];
    if (!isset($routes[$path])) {
        http_response_code(404);
        echo admin_layout('Tidak ditemukan', '<p>Halaman admin tidak ditemukan.</p>');
        return;
    }
    if ($path !== '/admin/sites/new' && $path !== '/admin/sites' && $path !== '/admin/global' && admin_site() === null) {
        redirect('/admin/sites/new');
    }
    $routes[$path]();
}

/* ================= Instalasi ================= */

function requirements(): array
{
    $writable = is_dir(STORAGE) ? is_writable(STORAGE) : is_writable(dirname(STORAGE));
    return [
        ['PHP 8.1 atau lebih baru', PHP_VERSION_ID >= 80100, PHP_VERSION],
        ['Ekstensi pdo_sqlite', extension_loaded('pdo_sqlite'), ''],
        ['Ekstensi curl', extension_loaded('curl'), ''],
        ['Ekstensi mbstring', extension_loaded('mbstring'), ''],
        ['Ekstensi intl (disarankan)', extension_loaded('intl'), 'tanpa intl tanggal tampil sebagai 2026-10-21'],
        ['Folder storage bisa ditulis', $writable, STORAGE],
    ];
}

function page_install(): void
{
    if (is_installed()) {
        if (!admin_logged_in()) {
            redirect('/admin/login');
        }
        redirect(sites_all() ? '/admin' : '/admin/sites/new');
    }
    $req = requirements();
    $ok = !in_array(false, array_map(fn($r) => $r[1] || str_contains($r[0], 'disarankan'), $req), true);
    $err = '';
    if (is_post() && $ok) {
        if (!csrf_ok()) {
            $err = 'Sesi kedaluwarsa, silakan ulangi.';
        } elseif (strlen((string)post('password')) < 10) {
            $err = 'Password minimal 10 karakter.';
        } elseif (post('password') !== post('password2')) {
            $err = 'Konfirmasi password tidak sama.';
        } else {
            gset('admin_hash', password_hash((string)post('password'), PASSWORD_DEFAULT));
            $g = gget('global', []);
            $g['youtube_api_key'] = trim((string)post('youtube_api_key'));
            gset('global', $g);
            cron_key();
            admin_login_as();
            flash('Instalasi berhasil. Sekarang buat situs pertama Anda.');
            redirect('/admin/sites/new');
        }
    }
    ob_start(); ?>
    <p>Selamat datang! Isi formulir ini sekali saja. Password ini dipakai untuk mengelola <b>semua</b> situs.</p>
    <table class="tbl">
      <?php foreach ($req as [$label, $pass, $info]): ?>
        <tr><td><?= $pass ? '✅' : (str_contains($label, 'disarankan') ? '⚠️' : '❌') ?></td><td><?= e($label) ?></td><td class="muted"><?= e($info) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if (!$ok): ?>
      <p class="msg err">Perbaiki syarat bertanda ❌ di atas dulu (hubungi hosting Anda bila perlu), lalu muat ulang halaman ini.</p>
    <?php else: ?>
      <?php if ($err): ?><p class="msg err"><?= e($err) ?></p><?php endif; ?>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <label>Password admin (min. 10 karakter)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
        <label>Ulangi password<input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
        <label>YouTube Data API key <small>(boleh diisi nanti; dipakai semua situs)</small>
          <input type="text" name="youtube_api_key" value="<?= e(post('youtube_api_key')) ?>" placeholder="AIza..."></label>
        <p class="help">Cara membuat key: console.cloud.google.com → APIs &amp; Services → Library → <b>YouTube Data API v3</b> → Enable → Credentials → Create credentials → API key.</p>
        <button class="btn">Install</button>
      </form>
    <?php endif;
    echo admin_layout('Instalasi AGC Video', (string)ob_get_clean(), false);
}

function page_login(): void
{
    if (admin_logged_in()) {
        redirect('/admin');
    }
    $err = '';
    if (is_post()) {
        $fail = gget('login_fail', ['n' => 0, 't' => 0]);
        if ($fail['n'] >= 5 && time() - $fail['t'] < 900) {
            $err = 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.';
        } elseif (csrf_ok() && password_verify((string)post('password'), (string)gget('admin_hash', ''))) {
            gset('login_fail', ['n' => 0, 't' => 0]);
            admin_login_as();
            redirect('/admin');
        } else {
            gset('login_fail', ['n' => (time() - $fail['t'] < 900 ? $fail['n'] : 0) + 1, 't' => time()]);
            usleep(700000);
            $err = 'Password salah.';
        }
    }
    ob_start(); ?>
    <?php if ($err): ?><p class="msg err"><?= e($err) ?></p><?php endif; ?>
    <form method="post" class="form narrow">
      <?= csrf_field() ?>
      <label>Password admin<input type="password" name="password" required autofocus autocomplete="current-password"></label>
      <button class="btn">Masuk</button>
    </form>
    <p class="help">Lupa password? Jalankan di server: <code>php cli/setup.php admin --password=PasswordBaru123</code></p>
    <?php echo admin_layout('Masuk', (string)ob_get_clean(), false);
}

/* ================= Dashboard ================= */

function page_dashboard(): void
{
    $st = stats();
    $checks = [
        ['YouTube API key terisi (bila memakai YouTube)', trim((string)cfg('youtube.api_key', '')) !== '' || !array_filter((array)cfg('youtube.channels', [])), '/admin/settings#sumber'],
        ['Minimal satu channel / sumber video', (bool)array_filter((array)cfg('youtube.channels', [])) || site_uses_other_sources(), '/admin/channels'],
        ['Minimal 3 artikel orisinal (penting untuk approval AdSense)', articles_count() >= 3, '/admin/articles'],
        ['Email kontak terisi', trim((string)cfg('site.contact_email', '')) !== '', '/admin/settings'],
        ['Keyword situs (halaman topik)', (bool)cfg('site.keywords'), '/admin/settings'],
        ['Kode iklan / AdSense', adsense_client() !== '' || trim((string)cfg('ads.top') . cfg('ads.below_player') . cfg('ads.head') . cfg('ads.body_end')) !== '', '/admin/ads'],
    ];
    if (site_lang() === 'de') {
        $checks[] = ['Impressum: nama & alamat (wajib di Jerman)', trim((string)cfg('legal.name')) !== '' && trim((string)cfg('legal.address')) !== '', '/admin/settings#legal'];
    }
    $base = rtrim((string)cfg('site.url'), '/');
    ob_start(); ?>
    <div class="cards">
      <div class="stat"><b><?= number_format($st['videos']) ?></b><span>video tampil</span></div>
      <div class="stat"><b><?= number_format($st['series']) ?></b><span>playlist</span></div>
      <div class="stat"><b><?= number_format($st['channels']) ?></b><span>channel</span></div>
      <div class="stat"><b><?= number_format($st['ai']) ?></b><span>deskripsi AI</span></div>
      <div class="stat"><b><?= quota_used() ?></b><span>kuota API hari ini / <?= (int)cfg('youtube.daily_quota') ?></span></div>
    </div>
    <p class="muted">Fetch terakhir: <?= e(meta_get('last_fetch', 'belum pernah')) ?> UTC · video disembunyikan (Shorts, tidak bisa di-embed, dihapus, terfilter): <?= $st['hidden'] ?></p>

    <div class="row">
      <form method="post" action="/admin/fetch"><?= csrf_field() ?><button class="btn">Ambil video sekarang</button></form>
      <form method="post" action="/admin/cache"><?= csrf_field() ?><button class="btn ghost">Kosongkan cache</button></form>
      <a class="btn ghost" href="<?= e($base) ?>/" target="_blank" rel="noopener">Lihat situs ↗</a>
    </div>

    <h2>Checklist</h2>
    <ul class="checks">
      <?php foreach ($checks as [$label, $done, $link]): ?>
        <li><?= $done ? '✅' : '⬜' ?> <a href="<?= e($link) ?>"><?= e($label) ?></a></li>
      <?php endforeach; ?>
    </ul>

    <h2>Jadwal otomatis (cron)</h2>
    <p class="help">Docker/Nusapod: sudah otomatis, tidak perlu apa-apa. VPS / shared hosting: pasang salah satu:</p>
    <pre>7 * * * *    php <?= e(ROOT) ?>/cli/fetch.php > /dev/null 2>&amp;1
*/30 * * * * php <?= e(ROOT) ?>/cli/enrich.php > /dev/null 2>&amp;1</pre>
    <p class="help">Hosting tanpa menu Cron Jobs: panggil URL ini tiap jam dari layanan cron gratis (mis. cron-job.org):<br>
      <code><?= e((request_is_https() ? 'https://' : 'http://') . request_host()) ?>/_cron/<?= e(cron_key()) ?></code></p>

    <h2>Log terakhir</h2>
    <pre class="log"><?= e(log_tail(40) ?: 'Belum ada log.') ?></pre>
    <?php echo admin_layout('Dashboard', (string)ob_get_clean());
}

function action_fetch(): void
{
    if (!is_post()) {
        redirect('/admin');
    }
    @set_time_limit(300);
    ignore_user_abort(true);
    // Pengambilan manual dibuat ringan agar tidak timeout di shared hosting; sisanya dilanjutkan cron
    $msg = run_fetch_site(current_site()->id, ['youtube' => [
        'initial_pages' => min(3, (int)cfg('youtube.initial_pages')),
        'series_per_run' => min(5, (int)cfg('youtube.series_per_run')),
        'refresh_per_run' => min(200, (int)cfg('youtube.refresh_per_run')),
    ]]);
    flash($msg);
    redirect('/admin');
}

function action_cache(): void
{
    cache_clear();
    flash('Cache dikosongkan.');
    redirect('/admin');
}

function action_switch(): void
{
    $id = (string)($_GET['site'] ?? '');
    if (in_array($id, array_column(sites_all(), 'id'), true)) {
        $_SESSION['site'] = $id;
    }
    redirect('/admin');
}

/* ================= Pengaturan situs ================= */

function page_settings(): void
{
    $site = current_site();
    if (is_post()) {
        $c = $site->cfg;
        $c['site']['name'] = trim((string)post('site.name')) ?: $c['site']['name'];
        $url = rtrim(trim((string)post('site.url')), '/');
        $c['site']['url'] = preg_match('~^https?://[^\s/]+$~i', $url) ? $url : $c['site']['url'];
        $c['site']['tagline'] = trim((string)post('site.tagline'));
        $c['site']['lang'] = isset(LANGS[post('site.lang')]) ? (string)post('site.lang') : $c['site']['lang'];
        $c['site']['country'] = isset(COUNTRIES[post('site.country')]) ? (string)post('site.country') : '';
        $c['site']['niche'] = trim((string)post('site.niche'));
        $c['site']['item'] = trim((string)post('site.item'));
        $c['site']['keywords'] = array_slice(str_list((string)post('site.keywords')), 0, 50);
        $c['site']['intro'] = trim((string)post('site.intro'));
        $c['site']['group'] = (string)preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string)post('site.group'))));
        $c['site']['theme'] = post('site.theme') === 'light' ? 'light' : 'dark';
        $c['site']['accent'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string)post('site.accent')) ? (string)post('site.accent') : $c['site']['accent'];
        $c['site']['contact_email'] = filter_var(trim((string)post('site.contact_email')), FILTER_VALIDATE_EMAIL) ?: '';
        $c['site']['per_page'] = clamp_int(post('site.per_page'), 6, 60, 24);
        $c['site']['cache_ttl'] = clamp_int(post('site.cache_ttl'), 0, 86400, 600);

        $c['youtube']['api_key'] = trim((string)post('youtube.api_key'));
        $c['youtube']['include_keywords'] = str_list((string)post('youtube.include_keywords'));
        $c['youtube']['exclude_keywords'] = str_list((string)post('youtube.exclude_keywords'));
        $c['youtube']['min_duration'] = clamp_int(post('youtube.min_duration'), 0, 36000, 120);
        $c['youtube']['initial_pages'] = clamp_int(post('youtube.initial_pages'), 1, 200, 20);
        $c['youtube']['daily_quota'] = clamp_int(post('youtube.daily_quota'), 100, 1000000, 9000);

        $c['ai']['enabled'] = (bool)post('ai.enabled', false);
        $c['ai']['provider'] = in_array(post('ai.provider'), ['', 'anthropic', 'openai'], true) ? (string)post('ai.provider') : '';
        $c['ai']['api_key'] = trim((string)post('ai.api_key'));
        $c['ai']['model'] = trim((string)post('ai.model'));
        $c['ai']['endpoint'] = trim((string)post('ai.endpoint'));
        $c['ai']['per_run'] = clamp_int(post('ai.per_run'), 1, 500, 30);

        $c['legal']['name'] = trim((string)post('legal.name'));
        $c['legal']['address'] = trim((string)post('legal.address'));
        $c['analytics'] = (string)post('analytics');
        $site->save($c);
        cache_clear();
        flash('Pengaturan disimpan.');
        redirect('/admin/settings');
    }
    $c = $site->cfg;
    $g = gget('global', []);
    ob_start(); ?>
    <form method="post" action="/admin/preset" class="preset-bar">
      <?= csrf_field() ?>
      <label>Terapkan preset niche
        <select name="preset"><?php foreach (PRESETS as $id => $p): ?><option value="<?= e($id) ?>" <?= $c['site']['preset'] === $id ? 'selected' : '' ?>><?= e($p['label']) ?></option><?php endforeach; ?></select>
      </label>
      <button class="btn ghost" onclick="return confirm('Preset akan mengganti teks niche, tema, dan filter video. Lanjutkan?')">Terapkan</button>
    </form>

    <form method="post" class="form">
      <?= csrf_field() ?>
      <fieldset><legend>Situs</legend>
        <div class="grid2">
          <label>Judul website<input name="site[name]" value="<?= e($c['site']['name']) ?>" required></label>
          <label>URL utama<input name="site[url]" value="<?= e($c['site']['url']) ?>" placeholder="https://domain.com"></label>
          <label>Bahasa<select name="site[lang]"><?php foreach (LANGS as $k => $v): ?><option value="<?= $k ?>" <?= $c['site']['lang'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
          <label>Target negara / audiens<select name="site[country]"><?php foreach (COUNTRIES as $k => [$n]): ?><option value="<?= $k ?>" <?= $c['site']['country'] === $k ? 'selected' : '' ?>><?= e($n . ($k ? " ($k)" : '')) ?></option><?php endforeach; ?></select></label>
          <label>Kategori video – sebutan jamak <small>(mis. "Korean dramas")</small><input name="site[niche]" value="<?= e($c['site']['niche']) ?>"></label>
          <label>Sebutan satu video <small>(mis. "K-drama")</small><input name="site[item]" value="<?= e($c['site']['item']) ?>"></label>
        </div>
        <label>Tagline <small>(kosong = otomatis)</small><input name="site[tagline]" value="<?= e($c['site']['tagline']) ?>" placeholder="<?= e(tagline()) ?>"></label>
        <label>Keyword website <small>(satu per baris; tiap keyword jadi halaman topik /topic/... bila ada video yang cocok)</small>
          <textarea name="site[keywords]" rows="4"><?= e(implode("\n", (array)$c['site']['keywords'])) ?></textarea></label>
        <label>Grup situs saudara <small>(opsional; isi nama grup yang SAMA di situs lain dengan niche sama tapi bahasa berbeda, mis. "kdrama" → otomatis hreflang + pemilih bahasa)</small>
          <input name="site[group]" value="<?= e($c['site']['group']) ?>" placeholder="kdrama"></label>
        <label>Teks pembuka beranda <small>(opsional, tulis sendiri agar situs punya konten orisinal)</small>
          <textarea name="site[intro]" rows="3"><?= e($c['site']['intro']) ?></textarea></label>
        <div class="grid3">
          <label>Tema<select name="site[theme]"><option value="dark" <?= $c['site']['theme'] === 'dark' ? 'selected' : '' ?>>Gelap</option><option value="light" <?= $c['site']['theme'] === 'light' ? 'selected' : '' ?>>Terang</option></select></label>
          <label>Warna aksen<input type="color" name="site[accent]" value="<?= e($c['site']['accent']) ?>"></label>
          <label>Email kontak<input type="email" name="site[contact_email]" value="<?= e($c['site']['contact_email']) ?>"></label>
          <label>Video per halaman<input type="number" name="site[per_page]" value="<?= (int)$c['site']['per_page'] ?>" min="6" max="60"></label>
          <label>Cache halaman (detik)<input type="number" name="site[cache_ttl]" value="<?= (int)$c['site']['cache_ttl'] ?>" min="0"></label>
        </div>
      </fieldset>

      <fieldset id="sumber"><legend>Sumber video</legend>
        <label>YouTube API key khusus situs ini <small>(kosong = pakai key global<?= !empty($g['youtube_api_key']) ? ' ✅' : ' — belum diisi di menu Global' ?>)</small>
          <input name="youtube[api_key]" value="<?= e($c['youtube']['api_key']) ?>" placeholder="AIza..."></label>
        <div class="grid2">
          <label>Hanya ambil judul yang memuat salah satu kata ini <small>(kosong = semua)</small><textarea name="youtube[include_keywords]" rows="3"><?= e(implode("\n", (array)$c['youtube']['include_keywords'])) ?></textarea></label>
          <label>Buang video yang judulnya memuat kata ini<textarea name="youtube[exclude_keywords]" rows="3"><?= e(implode("\n", (array)$c['youtube']['exclude_keywords'])) ?></textarea></label>
        </div>
        <div class="grid3">
          <label>Durasi minimal (detik)<input type="number" name="youtube[min_duration]" value="<?= (int)$c['youtube']['min_duration'] ?>" min="0"></label>
          <label>Halaman awal per channel (×50 video)<input type="number" name="youtube[initial_pages]" value="<?= (int)$c['youtube']['initial_pages'] ?>" min="1"></label>
          <label>Batas kuota harian<input type="number" name="youtube[daily_quota]" value="<?= (int)$c['youtube']['daily_quota'] ?>" min="100"></label>
        </div>
        <p class="help">Channel diatur di menu <a href="/admin/channels">Channel</a>.</p>
      </fieldset>

      <fieldset><legend>Deskripsi unik dengan AI (opsional, biaya API terpisah)</legend>
        <label class="check"><input type="checkbox" name="ai[enabled]" value="1" <?= $c['ai']['enabled'] ? 'checked' : '' ?>> Aktifkan</label>
        <div class="grid2">
          <label>Provider<select name="ai[provider]"><option value="">(pakai global)</option><option value="anthropic" <?= $c['ai']['provider'] === 'anthropic' ? 'selected' : '' ?>>Anthropic (Claude)</option><option value="openai" <?= $c['ai']['provider'] === 'openai' ? 'selected' : '' ?>>OpenAI / kompatibel</option></select></label>
          <label>Model<input name="ai[model]" value="<?= e($c['ai']['model']) ?>" placeholder="(pakai global)"></label>
          <label>API key<input name="ai[api_key]" value="<?= e($c['ai']['api_key']) ?>" placeholder="(pakai global)"></label>
          <label>Endpoint <small>(khusus API kompatibel OpenAI)</small><input name="ai[endpoint]" value="<?= e($c['ai']['endpoint']) ?>"></label>
          <label>Deskripsi per jalan<input type="number" name="ai[per_run]" value="<?= (int)$c['ai']['per_run'] ?>" min="1"></label>
        </div>
      </fieldset>

      <fieldset id="legal"><legend>Identitas pemilik (Impressum)</legend>
        <p class="help">Wajib untuk situs berbahasa Jerman (DE/AT/CH). Bila diisi, halaman /impressum juga tampil di bahasa lain.</p>
        <div class="grid2">
          <label>Nama lengkap / nama usaha<input name="legal[name]" value="<?= e($c['legal']['name']) ?>"></label>
          <label>Alamat <small>(pisahkan baris dengan " | ")</small><input name="legal[address]" value="<?= e($c['legal']['address']) ?>" placeholder="Jalan 1 | Kota | Negara"></label>
        </div>
      </fieldset>

      <fieldset><legend>Analytics & verifikasi</legend>
        <label>Kode di &lt;head&gt; <small>(Google Analytics, meta verifikasi Search Console, dll.)</small>
          <textarea name="analytics" rows="3" class="code"><?= e($c['analytics']) ?></textarea></label>
      </fieldset>
      <button class="btn">Simpan pengaturan</button>
    </form>
    <?php echo admin_layout('Pengaturan situs', (string)ob_get_clean());
}

function action_preset(): void
{
    $site = current_site();
    $id = (string)post('preset');
    if (isset(PRESETS[$id])) {
        $site->save(apply_preset($site->cfg, $id));
        cache_clear();
        flash('Preset "' . PRESETS[$id]['label'] . '" diterapkan. Periksa lagi teks dan filter di bawah.');
    }
    redirect('/admin/settings');
}

/* ================= Channel ================= */

function page_channels(): void
{
    $site = current_site();
    $apiKey = trim((string)cfg('youtube.api_key', ''));
    $results = null;
    $query = (string)($_POST['q'] ?? preset_search_query((string)cfg('site.preset'), site_lang()));
    $region = (string)($_POST['region'] ?? cfg('site.country', ''));

    if (is_post()) {
        $act = (string)post('act');
        $c = $site->cfg;
        try {
            if ($act === 'src_add') {
                $type = (string)post('type');
                $value = trim(str_replace("\r", '', (string)post('value')));
                if (!isset(Sources::TYPES[$type]) || $value === '') {
                    throw new RuntimeException('Pilih jenis sumber dan isi nilainya.');
                }
                if ($type === 'tiktok' && !preg_match('~tiktok\.com/@[\w.]+/video/\d+~', $value)) {
                    throw new RuntimeException('Isi link video TikTok lengkap, mis. https://www.tiktok.com/@akun/video/123...');
                }
                $c['sources'][] = ['type' => $type, 'value' => $value];
                $site->save($c);
                flash('Sumber ' . Sources::NAMES[$type] . ' ditambahkan. Klik "Ambil video sekarang" di Dashboard.');
                redirect('/admin/channels');
            }
            if ($act === 'src_remove') {
                $i = (int)post('i', -1);
                if (isset($c['sources'][$i])) {
                    array_splice($c['sources'], $i, 1);
                    $site->save($c);
                    flash('Sumber dihapus. Videonya akan disembunyikan pada pengambilan berikutnya.');
                }
                redirect('/admin/channels');
            }
            if ($act === 'remove') {
                $c['youtube']['channels'] = array_values(array_diff((array)$c['youtube']['channels'], [(string)post('ref')]));
                $site->save($c);
                flash('Channel dihapus. Videonya akan disembunyikan pada pengambilan berikutnya.');
                redirect('/admin/channels');
            }
            if ($act === 'add' || $act === 'add_selected') {
                $refs = $act === 'add' ? str_list((string)post('refs')) : (array)post('ids', []);
                $added = [];
                $bad = [];
                $yt = $apiKey !== '' ? new YouTube($apiKey) : null;
                foreach ($refs as $r) {
                    $ref = normalize_channel_ref((string)$r);
                    if ($ref === '') {
                        $bad[] = (string)$r;
                        continue;
                    }
                    if ($yt && $act === 'add') {
                        try {
                            if (!$yt->channel($ref)) {
                                $bad[] = (string)$r;
                                continue;
                            }
                        } catch (Throwable $e) {
                            $unchecked = $e->getMessage();   // API tidak bisa dihubungi: tetap simpan, dicek saat fetch
                        }
                    }
                    if (!in_array($ref, (array)$c['youtube']['channels'], true)) {
                        $c['youtube']['channels'][] = $ref;
                        $added[] = $ref;
                    }
                }
                $site->save($c);
                if ($added) {
                    flash(count($added) . ' channel ditambahkan: ' . implode(', ', $added) . '. Klik "Ambil video sekarang" di Dashboard atau tunggu cron.');
                }
                if ($bad) {
                    flash('Tidak ditemukan / format salah: ' . implode(', ', $bad), 'err');
                }
                if (isset($unchecked)) {
                    flash('Channel belum bisa dicek ke YouTube (' . $unchecked . '). Tetap disimpan; akan dicek saat pengambilan video.', 'err');
                }
                redirect('/admin/channels');
            }
            if ($act === 'search') {
                if (!$apiKey) {
                    throw new RuntimeException('Isi YouTube API key dulu (menu Global atau Pengaturan).');
                }
                $results = (new YouTube($apiKey))->searchChannels(trim($query), site_lang(), $region, 20);
            }
        } catch (Throwable $e) {
            flash('Gagal: ' . $e->getMessage(), 'err');
            if ($act !== 'search') {
                redirect('/admin/channels');
            }
        }
    }

    $known = [];
    foreach (q('SELECT id, handle, title, subscribers, backfill_state FROM channels')->fetchAll() as $r) {
        $known[$r['id']] = $r;
        if ($r['handle']) {
            $known[strtolower((string)$r['handle'])] = $r;
        }
    }
    $counts = [];
    foreach (q('SELECT channel_id, COUNT(*) n FROM videos WHERE is_active = 1 GROUP BY channel_id')->fetchAll() as $r) {
        $counts[$r['channel_id']] = (int)$r['n'];
    }
    $current = (array)cfg('youtube.channels', []);
    ob_start(); ?>
    <p class="help">Gunakan hanya <b>channel resmi</b> milik pemegang hak (studio, stasiun TV, brand, kreator aslinya). Channel reupload berisiko klaim hak cipta dan merusak reputasi situs.</p>

    <h2>YouTube – channel aktif (<?= count($current) ?>)</h2>
    <?php if (!$current): ?><p class="muted">Belum ada channel. Tambahkan di bawah.</p><?php else: ?>
    <table class="tbl">
      <tr><th>Channel</th><th>Judul</th><th>Video tampil</th><th>Status</th><th></th></tr>
      <?php foreach ($current as $ref):
          $k = str_starts_with($ref, 'UC') ? $ref : strtolower($ref);
          $info = $known[$k] ?? null; ?>
        <tr>
          <td><a href="https://www.youtube.com/<?= e(str_starts_with($ref, 'UC') ? 'channel/' . $ref : $ref) ?>" target="_blank" rel="noopener"><?= e($ref) ?></a></td>
          <td><?= e($info['title'] ?? '—') ?></td>
          <td><?= $info ? (int)($counts[$info['id']] ?? 0) : '—' ?></td>
          <td class="muted"><?= $info ? ['belum diambil', 'mengambil video lama', 'lengkap'][(int)$info['backfill_state']] : 'menunggu fetch' ?></td>
          <td><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="remove"><input type="hidden" name="ref" value="<?= e($ref) ?>"><button class="link danger" onclick="return confirm('Hapus channel ini?')">hapus</button></form></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <h2>Sumber lain (tanpa API key)</h2>
    <?php $srcs = (array)cfg('sources', []); ?>
    <?php if ($srcs): ?>
    <table class="tbl">
      <tr><th>Jenis</th><th>Nilai</th><th>Video tampil</th><th></th></tr>
      <?php foreach ($srcs as $i => $sr):
          $n = (int)q("SELECT COUNT(*) FROM videos WHERE is_active = 1 AND source = ?", [$sr['type']])->fetchColumn(); ?>
        <tr><td><?= e(Sources::NAMES[$sr['type']] ?? $sr['type']) ?></td><td><code><?= e(excerpt((string)$sr['value'], 90)) ?></code></td><td><?= $n ?></td>
          <td><form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="src_remove"><input type="hidden" name="i" value="<?= (int)$i ?>"><button class="link danger" onclick="return confirm('Hapus sumber ini?')">hapus</button></form></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="act" value="src_add">
      <div class="grid2">
        <label>Jenis sumber<select name="type" id="srctype"><?php foreach (Sources::TYPES as $k => $lbl): ?><option value="<?= $k ?>"><?= e($lbl) ?></option><?php endforeach; ?></select></label>
        <label>Nilai<textarea name="value" rows="2" required id="srcval"></textarea></label>
      </div>
      <ul class="help" id="srchelp">
        <li><b>Dailymotion</b>: nama akun atau link profil resmi, mis. <code>https://www.dailymotion.com/namaakun</code>. Gunakan hanya akun milik pemegang hak (stasiun TV, media, studio).</li>
        <li><b>Internet Archive</b>: kata kunci atau query, mis. <code>collection:feature_films AND subject:horror</code> atau <code>nosferatu</code>. Otomatis hanya mengambil film berlisensi <i>public domain</i>/CC0. Tetap cek ulang sebelum dimonetisasi.</li>
        <li><b>NASA</b>: kata kunci (bahasa Inggris), mis. <code>mars rover</code>, <code>apollo 11</code>. Konten bertanda hak cipta pihak ketiga dilewati otomatis. Jangan pakai logo NASA atau mengesankan dukungan NASA.</li>
        <li><b>TikTok</b>: satu atau beberapa link video (satu per baris) dari akun resmi. Tidak ada pengambilan otomatis; tambahkan link baru kapan saja.</li>
      </ul>
      <button class="btn">Tambah sumber</button>
    </form>

    <h2>YouTube – tambah manual</h2>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="act" value="add">
      <label>Link channel, @handle, atau ID (UC...) — satu per baris
        <textarea name="refs" rows="3" placeholder="https://www.youtube.com/@namachannel&#10;@channellain&#10;UCxxxxxxxxxxxxxxxxxxxxxx"></textarea></label>
      <button class="btn">Tambah</button>
    </form>

    <h2>YouTube – cari channel</h2>
    <form method="post" class="form inline">
      <?= csrf_field() ?><input type="hidden" name="act" value="search">
      <label>Kata kunci<input name="q" value="<?= e($query) ?>" required></label>
      <label>Negara<select name="region"><?php foreach (COUNTRIES as $k => [$n]): ?><option value="<?= $k ?>" <?= $region === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
      <button class="btn ghost">Cari</button>
    </form>
    <p class="help">Setiap pencarian memakai 100 unit kuota YouTube (kuota harian default 10.000). Bahasa hasil mengikuti bahasa situs.</p>

    <?php if (is_array($results)): ?>
      <?php if (!$results): ?><p class="muted">Tidak ada hasil.</p><?php else: ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="act" value="add_selected">
        <table class="tbl">
          <tr><th></th><th>Channel</th><th>Subscriber</th><th>Video</th><th>Negara</th></tr>
          <?php foreach ($results as $r): $already = in_array($r['id'], $current, true) || ($r['handle'] && in_array($r['handle'], $current, true)); ?>
            <tr>
              <td><input type="checkbox" name="ids[]" value="<?= e($r['id']) ?>" <?= $already ? 'disabled checked' : '' ?>></td>
              <td><div class="ch"><?php if ($r['thumb']): ?><img src="<?= e($r['thumb']) ?>" alt="" width="36" height="36"><?php endif; ?>
                <div><a href="https://www.youtube.com/channel/<?= e($r['id']) ?>" target="_blank" rel="noopener"><b><?= e($r['title']) ?></b></a> <span class="muted"><?= e($r['handle']) ?></span><br>
                <small class="muted"><?= e(excerpt($r['description'], 140)) ?></small></div></div></td>
              <td><?= number_format($r['subscribers']) ?></td>
              <td><?= number_format($r['videos']) ?></td>
              <td><?= e($r['country']) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <button class="btn">Tambahkan yang dicentang</button>
      </form>
      <?php endif; ?>
    <?php endif;
    echo admin_layout('Sumber video', (string)ob_get_clean());
}

/* ================= Iklan ================= */

function page_ads(): void
{
    $site = current_site();
    $slots = [
        'head' => ['Di dalam <head>', 'Script verifikasi/loader jaringan iklan (mis. Adsterra/Monetag verification).'],
        'top' => ['Atas halaman', 'Banner 728×90 / responsif.'],
        'below_player' => ['Di bawah player video', 'Posisi dengan CTR tertinggi.'],
        'in_list' => ['Di tengah daftar video', 'Native / in-feed.'],
        'in_article' => ['Di dalam artikel', 'Muncul setelah paragraf ke-2 setiap artikel (in-article).'],
        'sidebar' => ['Sidebar halaman video', '300×250 / 300×600.'],
        'footer' => ['Bawah halaman', 'Banner.'],
    ];
    if (is_post()) {
        $c = $site->cfg;
        $c['ads']['mode'] = in_array(post('ads.mode'), ['adsense', 'network', 'custom'], true) ? (string)post('ads.mode') : 'adsense';
        $client = trim((string)post('ads.adsense_client'));
        $c['ads']['adsense_client'] = preg_match('/^(ca-)?pub-\d{10,20}$/', $client) ? $client : '';
        if ($client !== '' && $c['ads']['adsense_client'] === '') {
            flash('Format AdSense publisher ID salah (contoh: ca-pub-1234567890123456).', 'err');
        }
        $c['ads']['label'] = (bool)post('ads.label', false);
        foreach (array_keys($slots) as $s) {
            $c['ads'][$s] = (string)post('ads.' . $s);
        }
        $c['ads']['body_end'] = (string)post('ads.body_end');
        $c['ads']['cta_enabled'] = (bool)post('ads.cta_enabled', false);
        $c['ads']['cta_text'] = trim((string)post('ads.cta_text'));
        $c['ads']['cta_note'] = trim((string)post('ads.cta_note'));
        $cta = trim((string)post('ads.cta_url'));
        $c['ads']['cta_url'] = preg_match('~^https?://\S+$~i', $cta) ? $cta : '';
        $c['ads']['ads_txt'] = trim(str_replace("\r", '', (string)post('ads.ads_txt')));
        $site->save($c);
        cache_clear();
        flash('Pengaturan iklan disimpan.');
        redirect('/admin/ads');
    }
    $a = $site->cfg['ads'];
    $base = rtrim((string)cfg('site.url'), '/');
    ob_start(); ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <fieldset><legend>Mode iklan</legend>
        <label class="radio"><input type="radio" name="ads[mode]" value="adsense" <?= $a['mode'] === 'adsense' ? 'checked' : '' ?>>
          <span><b>AdSense saja (aman)</b> — auto ads + unit AdSense di slot. Popunder/smartlink & tombol CPA <u>dimatikan</u>.</span></label>
        <label class="radio"><input type="radio" name="ads[mode]" value="network" <?= $a['mode'] === 'network' ? 'checked' : '' ?>>
          <span><b>CPM / CPA</b> (Adsterra, Monetag, PropellerAds, jaringan CPA, dll.) — semua slot, popunder/social bar, dan tombol CPA aktif. Auto ads AdSense mati.</span></label>
        <label class="radio"><input type="radio" name="ads[mode]" value="custom" <?= $a['mode'] === 'custom' ? 'checked' : '' ?>>
          <span><b>Campuran</b> — semua aktif. ⚠️ AdSense sering menolak atau menutup akun bila situs juga memakai popunder/redirect.</span></label>
      </fieldset>

      <fieldset><legend>Google AdSense</legend>
        <label>Publisher ID<input name="ads[adsense_client]" value="<?= e($a['adsense_client']) ?>" placeholder="ca-pub-1234567890123456"></label>
        <p class="help">Bila diisi (mode AdSense/Campuran): kode auto ads otomatis dipasang di semua halaman dan baris AdSense otomatis masuk ke <a href="<?= e($base) ?>/ads.txt" target="_blank">/ads.txt</a>. Unit iklan manual bisa ditempel di slot di bawah. Untuk pengunjung EU/UK, aktifkan pesan persetujuan cookie (CMP) di AdSense → <i>Privasi &amp; pesan</i>.</p>
      </fieldset>

      <fieldset><legend>Slot iklan (CPM banner / native / unit AdSense)</legend>
        <label class="check"><input type="checkbox" name="ads[label]" value="1" <?= $a['label'] ? 'checked' : '' ?>> Tampilkan label "Iklan" (sesuai bahasa situs) di atas slot — wajib di Jerman ("Anzeige")</label>
        <?php foreach ($slots as $s => [$label, $help]): ?>
          <label><?= e($label) ?> <small><?= e($help) ?></small><textarea name="ads[<?= $s ?>]" rows="3" class="code"><?= e($a[$s]) ?></textarea></label>
        <?php endforeach; ?>
        <label>Akhir &lt;body&gt; — popunder / social bar / smartlink <small>(hanya aktif di mode CPM/CPA & Campuran)</small>
          <textarea name="ads[body_end]" rows="3" class="code"><?= e($a['body_end']) ?></textarea></label>
      </fieldset>

      <fieldset><legend>Tombol penawaran CPA (di bawah player)</legend>
        <p class="help">Untuk link offer CPA/afiliasi (mis. aplikasi streaming resmi, VPN, langganan). Selalu diberi label "Sponsor" dan rel="sponsored". Hanya aktif di mode CPM/CPA & Campuran. <b>Bukan</b> content locker: video tetap bisa ditonton tanpa mengklik.</p>
        <label class="check"><input type="checkbox" name="ads[cta_enabled]" value="1" <?= $a['cta_enabled'] ? 'checked' : '' ?>> Tampilkan tombol</label>
        <div class="grid2">
          <label>Teks tombol<input name="ads[cta_text]" value="<?= e($a['cta_text']) ?>" placeholder="<?= e(t('cta_default')) ?>"></label>
          <label>URL offer<input name="ads[cta_url]" value="<?= e($a['cta_url']) ?>" placeholder="https://..."></label>
        </div>
        <label>Keterangan kecil di bawah tombol <small>(opsional; tulis jujur apa yang didapat pengunjung)</small><input name="ads[cta_note]" value="<?= e($a['cta_note']) ?>"></label>
      </fieldset>

      <fieldset><legend>ads.txt</legend>
        <label>Baris tambahan dari jaringan iklan (Adsterra, Monetag, dll.)<textarea name="ads[ads_txt]" rows="5" class="code"><?= e($a['ads_txt']) ?></textarea></label>
        <p class="help">Hasil akhir: <a href="<?= e($base) ?>/ads.txt" target="_blank" rel="noopener"><?= e($base) ?>/ads.txt</a></p>
      </fieldset>
      <button class="btn">Simpan iklan</button>
    </form>
    <?php echo admin_layout('Iklan (AdSense, CPM, CPA)', (string)ob_get_clean());
}

/* ================= Multi-situs ================= */

function page_sites(): void
{
    if (is_post()) {
        $act = (string)post('act');
        $id = (string)post('site');
        try {
            if (!in_array($id, array_column(sites_all(), 'id'), true)) {
                throw new RuntimeException('Situs tidak ditemukan');
            }
            if ($act === 'add_host') {
                site_add_host($id, (string)post('host'));
                flash('Domain ditambahkan. Pastikan DNS domain ini mengarah ke server yang sama.');
            } elseif ($act === 'remove_host') {
                site_remove_host($id, (string)post('host'));
                flash('Domain dilepas.');
            } elseif ($act === 'detach') {
                if (normalize_host((string)post('confirm')) !== (string)mq('SELECT primary_host FROM sites WHERE id = ?', [$id])->fetchColumn()) {
                    throw new RuntimeException('Konfirmasi domain tidak cocok.');
                }
                site_detach($id);
                unset($_SESSION['site']);
                flash('Situs dilepas. Datanya dipindah ke folder storage/trash.');
            }
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
        redirect('/admin/sites');
    }
    $sites = sites_all();
    ob_start(); ?>
    <p><a class="btn" href="/admin/sites/new">+ Tambah situs baru</a></p>
    <p class="help">Mode multi-situs: satu instalasi melayani banyak domain. Arahkan DNS domain baru ke server ini (VPS: tambah domain di Caddy/Nginx; shared hosting: tambah <i>addon domain</i> dengan folder yang sama), lalu tambahkan di sini.</p>
    <table class="tbl">
      <tr><th>Situs</th><th>Domain</th><th>Bahasa</th><th>Niche</th><th>Video</th><th></th></tr>
      <?php foreach ($sites as $s):
          Site::activate($s['id']);
          $hosts = mq('SELECT host FROM hosts WHERE site_id = ? ORDER BY host', [$s['id']])->fetchAll(PDO::FETCH_COLUMN); ?>
        <tr>
          <td><b><?= e(cfg('site.name')) ?></b><br><small class="muted"><?= e($s['id']) ?></small></td>
          <td>
            <?php foreach ($hosts as $h): ?>
              <div class="host"><?= e($h) ?><?= $h === $s['primary_host'] ? ' <small class="muted">(utama)</small>' : '' ?>
                <?php if ($h !== $s['primary_host']): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="remove_host"><input type="hidden" name="site" value="<?= e($s['id']) ?>"><input type="hidden" name="host" value="<?= e($h) ?>"><button class="link danger">lepas</button></form><?php endif; ?>
              </div>
            <?php endforeach; ?>
            <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="add_host"><input type="hidden" name="site" value="<?= e($s['id']) ?>"><input name="host" placeholder="domain-alias.com" size="18"><button class="link">+ domain</button></form>
          </td>
          <td><?= e(LANGS[site_lang()] ?? '') ?><?= cfg('site.country') ? ' · ' . e(cfg('site.country')) : '' ?></td>
          <td><?= e(cfg('site.niche') ?: '—') ?></td>
          <td><?= number_format(stats()['videos']) ?></td>
          <td>
            <a class="link" href="/admin/switch?site=<?= e(rawurlencode($s['id'])) ?>">kelola</a>
            <details><summary class="link danger">lepas situs</summary>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="detach"><input type="hidden" name="site" value="<?= e($s['id']) ?>">
                <input name="confirm" placeholder="ketik <?= e($s['primary_host']) ?>" size="18"><button class="link danger">lepas</button></form></details>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php
    if (isset($_SESSION['site'])) {
        admin_site();
    }
    echo admin_layout('Semua situs', (string)ob_get_clean());
}

function page_site_new(): void
{
    $err = '';
    $host = normalize_host((string)($_POST['host'] ?? (sites_all() ? '' : request_host())));
    if (is_post()) {
        try {
            $lang = isset(LANGS[post('lang')]) ? (string)post('lang') : 'en';
            $country = isset(COUNTRIES[post('country')]) ? (string)post('country') : '';
            $c = default_config();
            $c['site']['lang'] = $lang;
            $c['site']['country'] = $country;
            $c = apply_preset($c, (string)post('preset'));
            if (trim((string)post('niche')) !== '') {
                $c['site']['niche'] = trim((string)post('niche'));
                $c['site']['item'] = trim((string)post('item')) ?: $c['site']['item'];
            }
            $c['site']['name'] = trim((string)post('name')) ?: $host;
            $c['site']['url'] = (request_is_https() || $host !== request_host() ? 'https://' : 'http://') . $host;
            $c['site']['keywords'] = array_slice(str_list((string)post('keywords')), 0, 50);
            $c['site']['contact_email'] = filter_var(trim((string)post('email')), FILTER_VALIDATE_EMAIL) ?: '';
            $c['ads']['mode'] = in_array(post('ads_mode'), ['adsense', 'network', 'custom'], true) ? (string)post('ads_mode') : 'adsense';
            $id = site_create($host, $c);
            $_SESSION['site'] = $id;
            flash('Situs "' . $c['site']['name'] . '" dibuat. Langkah berikutnya: tambahkan channel YouTube resmi.');
            redirect('/admin/channels');
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
    $lang = (string)($_POST['lang'] ?? 'en');
    ob_start(); ?>
    <?php if ($err): ?><p class="msg err"><?= e($err) ?></p><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <div class="grid2">
        <label>Domain situs <small>(tanpa https://, tanpa www)</small><input name="host" value="<?= e($host) ?>" required placeholder="domainanda.com"></label>
        <label>Judul website<input name="name" value="<?= e(post('name')) ?>" required placeholder="mis. K-Drama Hub"></label>
        <label>Kategori video (preset)<select name="preset"><?php foreach (PRESETS as $id => $p): ?><option value="<?= e($id) ?>" <?= post('preset') === $id ? 'selected' : '' ?>><?= e($p['label']) ?></option><?php endforeach; ?></select></label>
        <label>Bahasa situs<select name="lang"><?php foreach (LANGS as $k => $v): ?><option value="<?= $k ?>" <?= $lang === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
        <label>Target negara / audiens<select name="country" id="country"><?php foreach (COUNTRIES as $k => [$n, $l]): ?><option value="<?= $k ?>" data-lang="<?= $l ?>" <?= post('country') === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
        <label>Mode iklan<select name="ads_mode"><option value="adsense">AdSense saja (aman)</option><option value="network" <?= post('ads_mode') === 'network' ? 'selected' : '' ?>>CPM / CPA (Adsterra, Monetag, dll.)</option><option value="custom" <?= post('ads_mode') === 'custom' ? 'selected' : '' ?>>Campuran</option></select></label>
      </div>
      <details><summary>Kategori bukan dari preset? Isi sendiri</summary>
        <div class="grid2">
          <label>Sebutan jamak <small>(dalam bahasa situs, mis. "películas de terror")</small><input name="niche" value="<?= e(post('niche')) ?>"></label>
          <label>Sebutan satu video<input name="item" value="<?= e(post('item')) ?>"></label>
        </div>
      </details>
      <label>Keyword website <small>(satu per baris, dalam bahasa situs)</small><textarea name="keywords" rows="4"><?= e(post('keywords')) ?></textarea></label>
      <label>Email kontak<input type="email" name="email" value="<?= e(post('email')) ?>" placeholder="admin@domainanda.com"></label>
      <button class="btn">Buat situs</button>
    </form>
    <script>
    document.getElementById('country').addEventListener('change', function () {
      var l = this.options[this.selectedIndex].dataset.lang, sel = document.querySelector('select[name=lang]');
      if (l && sel) sel.value = l;
    });
    </script>
    <?php echo admin_layout('Tambah situs', (string)ob_get_clean());
}

/* ================= Global ================= */

function page_global(): void
{
    if (is_post()) {
        $act = (string)post('act');
        if ($act === 'password') {
            if (!password_verify((string)post('old'), (string)gget('admin_hash', ''))) {
                flash('Password lama salah.', 'err');
            } elseif (strlen((string)post('new')) < 10) {
                flash('Password baru minimal 10 karakter.', 'err');
            } else {
                gset('admin_hash', password_hash((string)post('new'), PASSWORD_DEFAULT));
                admin_login_as();
                flash('Password diganti.');
            }
        } else {
            $g = gget('global', []);
            $g['youtube_api_key'] = trim((string)post('youtube_api_key'));
            $g['ai_provider'] = in_array(post('ai_provider'), ['anthropic', 'openai'], true) ? (string)post('ai_provider') : 'anthropic';
            $g['ai_api_key'] = trim((string)post('ai_api_key'));
            $g['ai_model'] = trim((string)post('ai_model'));
            $g['ai_endpoint'] = trim((string)post('ai_endpoint'));
            gset('global', $g);
            flash('Pengaturan global disimpan.');
        }
        redirect('/admin/global');
    }
    $g = gget('global', []) + ['youtube_api_key' => '', 'ai_provider' => 'anthropic', 'ai_api_key' => '', 'ai_model' => '', 'ai_endpoint' => ''];
    ob_start(); ?>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="act" value="global">
      <fieldset><legend>Default untuk semua situs</legend>
        <label>YouTube Data API key<input name="youtube_api_key" value="<?= e($g['youtube_api_key']) ?>" placeholder="AIza..."></label>
        <p class="help">Kuota 10.000 unit/hari per key dibagi semua situs. Situs besar bisa memakai key sendiri di Pengaturan situs.</p>
        <div class="grid2">
          <label>AI provider<select name="ai_provider"><option value="anthropic" <?= $g['ai_provider'] === 'anthropic' ? 'selected' : '' ?>>Anthropic (Claude)</option><option value="openai" <?= $g['ai_provider'] === 'openai' ? 'selected' : '' ?>>OpenAI / kompatibel</option></select></label>
          <label>AI model<input name="ai_model" value="<?= e($g['ai_model']) ?>" placeholder="claude-haiku-5-5"></label>
          <label>AI API key<input name="ai_api_key" value="<?= e($g['ai_api_key']) ?>"></label>
          <label>AI endpoint <small>(opsional)</small><input name="ai_endpoint" value="<?= e($g['ai_endpoint']) ?>"></label>
        </div>
        <p class="help">Biaya API AI ditagih terpisah oleh penyedianya (tidak termasuk langganan chat). AI hanya berjalan di situs yang mengaktifkannya.</p>
      </fieldset>
      <button class="btn">Simpan</button>
    </form>

    <form method="post" class="form narrow">
      <?= csrf_field() ?><input type="hidden" name="act" value="password">
      <fieldset><legend>Ganti password admin</legend>
        <label>Password lama<input type="password" name="old" required autocomplete="current-password"></label>
        <label>Password baru (min. 10 karakter)<input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
      </fieldset>
      <button class="btn ghost">Ganti password</button>
    </form>
    <p class="muted">AGC Video <?= AGC_VERSION ?> · PHP <?= PHP_VERSION ?> · data: <?= e(STORAGE) ?></p>
    <?php
    if (isset($_SESSION['site'])) {
        admin_site();
    }
    echo admin_layout('Pengaturan global', (string)ob_get_clean());
}

/* ================= Artikel ================= */

function page_articles_admin(): void
{
    if (is_post() && post('act') === 'delete') {
        q('DELETE FROM articles WHERE id = ?', [(int)post('id')]);
        cache_clear();
        flash('Artikel dihapus.');
        redirect('/admin/articles');
    }
    $rows = q('SELECT id, slug, title, status, published_at, updated_at, length(body) AS len FROM articles ORDER BY COALESCE(published_at, created_at) DESC')->fetchAll();
    $base = rtrim((string)cfg('site.url'), '/');
    ob_start(); ?>
    <p><a class="btn" href="/admin/articles/edit">+ Tulis artikel</a></p>
    <p class="help">Artikel orisinal adalah pembeda utama dari situs AGC biasa: AdSense, Mediavine, dan Raptive menilai keaslian konten.
      Tulis panduan, daftar rekomendasi, urutan menonton, penjelasan ending, atau "cara nonton legal". Draf AI boleh dipakai sebagai awal, tapi <b>sunting dan tambah pendapat Anda sendiri</b> sebelum terbit.</p>
    <?php if (!$rows): ?><p class="muted">Belum ada artikel.</p><?php else: ?>
    <table class="tbl">
      <tr><th>Judul</th><th>Status</th><th>Terbit</th><th>Panjang</th><th></th></tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="/admin/articles/edit?id=<?= (int)$r['id'] ?>"><b><?= e($r['title']) ?></b></a><br><small class="muted">/article/<?= e($r['slug']) ?></small></td>
          <td><?= $r['status'] === 'published' ? (strtotime($r['published_at'] . ' UTC') > time() ? '⏰ terjadwal' : '✅ terbit') : '📝 draf' ?></td>
          <td><?= e((string)$r['published_at']) ?></td>
          <td><?= number_format((int)$r['len']) ?> karakter</td>
          <td>
            <?php if ($r['status'] === 'published'): ?><a class="link" href="<?= e($base) ?>/article/<?= e($r['slug']) ?>" target="_blank" rel="noopener">lihat</a> · <?php endif; ?>
            <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="link danger" onclick="return confirm('Hapus artikel ini?')">hapus</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php endif;
    echo admin_layout('Artikel', (string)ob_get_clean());
}

function page_article_edit(): void
{
    $id = (int)($_GET['id'] ?? post('id', 0));
    $a = $id ? (q('SELECT * FROM articles WHERE id = ?', [$id])->fetch() ?: null) : null;
    $form = [
        'title' => (string)($a['title'] ?? ''), 'slug' => (string)($a['slug'] ?? ''), 'excerpt' => (string)($a['excerpt'] ?? ''),
        'body' => (string)($a['body'] ?? ''), 'cover' => (string)($a['cover'] ?? ''), 'keyword' => (string)($a['keyword'] ?? ''),
        'status' => (string)($a['status'] ?? 'draft'),
        'published_at' => (string)($a['published_at'] ?? ''), 'notes' => '',
    ];
    if (is_post()) {
        foreach (['title', 'slug', 'excerpt', 'body', 'cover', 'keyword', 'status', 'published_at', 'notes'] as $k) {
            $form[$k] = trim(str_replace("\r", '', (string)post($k)));
        }
        if (post('act') === 'ai') {
            try {
                if ($form['title'] === '') {
                    throw new RuntimeException('Isi judul dulu.');
                }
                $titles = $form['keyword'] !== '' ? array_column(videos_search($form['keyword'], 1, 15)[0], 'title') : [];
                $form['body'] = AI::article($form['title'], $form['keyword'], $titles, $form['notes']);
                if ($form['excerpt'] === '') {
                    $form['excerpt'] = excerpt((string)preg_replace('/[#*>\[\]]/', '', $form['body']), 155);
                }
                flash('Draf AI dibuat. Baca, perbaiki, dan tambahkan pengalaman/pendapat Anda sebelum menerbitkan.');
            } catch (Throwable $e) {
                flash('AI gagal: ' . $e->getMessage() . ' (atur AI di menu Global / Pengaturan)', 'err');
            }
        } else {
            try {
                if ($form['title'] === '' || $form['body'] === '') {
                    throw new RuntimeException('Judul dan isi wajib diisi.');
                }
                $slug = slugify($form['slug'] !== '' ? $form['slug'] : $form['title'], 90);
                $dup = q('SELECT id FROM articles WHERE slug = ? AND id != ?', [$slug, $id])->fetchColumn();
                if ($dup) {
                    $slug .= '-' . substr(md5((string)microtime(true)), 0, 4);
                }
                $status = $form['status'] === 'published' ? 'published' : 'draft';
                $pub = $form['published_at'] !== '' && strtotime($form['published_at']) ? gmdate('Y-m-d H:i:s', (int)strtotime($form['published_at'] . ' UTC')) : null;
                if ($status === 'published' && !$pub) {
                    $pub = now();
                }
                $cover = preg_match('~^https://\S+$~i', $form['cover']) ? $form['cover'] : '';
                if ($id && $a) {
                    q('UPDATE articles SET slug = ?, title = ?, excerpt = ?, body = ?, cover = ?, keyword = ?, status = ?, published_at = ?, updated_at = ? WHERE id = ?',
                        [$slug, $form['title'], $form['excerpt'], $form['body'], $cover, $form['keyword'], $status, $pub, now(), $id]);
                } else {
                    q('INSERT INTO articles (slug, title, excerpt, body, cover, keyword, status, published_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$slug, $form['title'], $form['excerpt'], $form['body'], $cover, $form['keyword'], $status, $pub, now(), now()]);
                    $id = (int)db()->lastInsertId();
                }
                cache_clear();
                flash($status === 'published' ? 'Artikel diterbitkan.' : 'Draf disimpan.');
                redirect('/admin/articles/edit?id=' . $id);
            } catch (Throwable $e) {
                flash($e->getMessage(), 'err');
            }
        }
    }
    $base = rtrim((string)cfg('site.url'), '/');
    ob_start(); ?>
    <p><a href="/admin/articles">← Semua artikel</a><?php if ($a && $a['status'] === 'published'): ?> · <a href="<?= e($base) ?>/article/<?= e($a['slug']) ?>" target="_blank" rel="noopener">Lihat di situs ↗</a><?php endif; ?></p>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
      <label>Judul<input name="title" value="<?= e($form['title']) ?>" required placeholder="mis. 10 Drama Korea Balas Dendam Terbaik untuk Ditonton"></label>
      <div class="grid2">
        <label>Kata kunci video terkait <small>(video yang cocok tampil di bawah artikel & dipakai AI)</small><input name="keyword" value="<?= e($form['keyword']) ?>"></label>
        <label>Slug URL <small>(kosong = otomatis dari judul)</small><input name="slug" value="<?= e($form['slug']) ?>"></label>
      </div>
      <label>Ringkasan / meta description <small>(1–2 kalimat, tampil di Google)</small><textarea name="excerpt" rows="2"><?= e($form['excerpt']) ?></textarea></label>
      <label>Isi artikel <small>Format: <code>## Subjudul</code>, <code>**tebal**</code>, <code>*miring*</code>, <code>- daftar</code>, <code>[teks](https://link)</code>, <code>![alt](https://gambar.jpg)</code>, baris <code>[video:slug-video]</code> untuk menyisipkan video. Iklan in-article otomatis setelah paragraf ke-2.</small>
        <textarea name="body" rows="22" class="code"><?= e($form['body']) ?></textarea></label>
      <div class="grid3">
        <label>Gambar sampul (URL https, opsional)<input name="cover" value="<?= e($form['cover']) ?>"></label>
        <label>Status<select name="status"><option value="draft">Draf</option><option value="published" <?= $form['status'] === 'published' ? 'selected' : '' ?>>Terbitkan</option></select></label>
        <label>Tanggal terbit (UTC, kosong = sekarang)<input type="datetime-local" name="published_at" value="<?= e($form['published_at'] ? str_replace(' ', 'T', substr($form['published_at'], 0, 16)) : '') ?>"></label>
      </div>
      <div class="row"><button class="btn">Simpan</button></div>
      <fieldset><legend>Bantuan AI (opsional)</legend>
        <label>Catatan untuk AI <small>(sudut pandang, poin wajib, gaya bahasa)</small><textarea name="notes" rows="2"><?= e($form['notes']) ?></textarea></label>
        <button class="btn ghost" name="act" value="ai" onclick="return !this.form.body.value || confirm('Isi artikel akan diganti dengan draf AI. Lanjutkan?')">Buat draf dengan AI</button>
        <p class="help">Memakai pengaturan AI (menu Global). Biaya API ditagih penyedia AI. Google tidak menghukum tulisan berbantuan AI, tapi menghukum konten massal tanpa nilai tambah — jadi sunting dulu.</p>
      </fieldset>
    </form>
    <?php echo admin_layout($a ? 'Edit artikel' : 'Tulis artikel', (string)ob_get_clean());
}

/* ================= Sosial: share, Telegram, push ================= */

function page_social(): void
{
    $site = current_site();
    if (is_post()) {
        $c = $site->cfg;
        if (post('act') === 'tg_test') {
            try {
                telegram_api('sendMessage', ['chat_id' => (string)cfg('social.telegram_chat'), 'text' => 'Tes dari ' . cfg('site.name') . ' (' . cfg('site.url') . ') ✅']);
                flash('Pesan tes terkirim ke Telegram.');
            } catch (Throwable $e) {
                flash('Gagal: ' . $e->getMessage(), 'err');
            }
            redirect('/admin/social');
        }
        $c['share']['enabled'] = (bool)post('share.enabled', false);
        $c['share']['networks'] = array_values(array_intersect(array_keys(SHARE_NETWORKS), (array)post('share.networks', [])));
        $wasOn = (bool)$c['social']['telegram_enabled'];
        $c['social']['telegram_enabled'] = (bool)post('social.telegram_enabled', false);
        $c['social']['telegram_token'] = trim((string)post('social.telegram_token'));
        $c['social']['telegram_chat'] = trim((string)post('social.telegram_chat'));
        $c['social']['telegram_per_run'] = clamp_int(post('social.telegram_per_run'), 1, 30, 5);
        $c['push']['head'] = (string)post('push.head');
        $name = trim((string)post('push.sw_name'));
        $c['push']['sw_name'] = preg_match('/^[A-Za-z0-9_.-]{1,60}\.js$/', $name) ? $name : '';
        $c['push']['sw_code'] = (string)post('push.sw_code');
        $site->save($c);
        if (!$wasOn && $c['social']['telegram_enabled']) {
            meta_set('tg_since', now());   // hanya video/artikel baru setelah ini yang dikirim
        }
        cache_clear();
        flash('Pengaturan sosial disimpan.');
        redirect('/admin/social');
    }
    $c = $site->cfg;
    $base = rtrim((string)cfg('site.url'), '/');
    ob_start(); ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <fieldset><legend>Tombol share (video & artikel)</legend>
        <label class="check"><input type="checkbox" name="share[enabled]" value="1" <?= $c['share']['enabled'] ? 'checked' : '' ?>> Tampilkan tombol share</label>
        <div class="grid3">
          <?php foreach (SHARE_NETWORKS as $k => [$label]): ?>
            <label class="check"><input type="checkbox" name="share[networks][]" value="<?= $k ?>" <?= in_array($k, (array)$c['share']['networks'], true) ? 'checked' : '' ?>> <?= e($label) ?></label>
          <?php endforeach; ?>
        </div>
        <p class="help">Tombol berupa link biasa (tanpa script Facebook/X), jadi tidak memperlambat halaman dan tidak menambah cookie pihak ketiga. Tombol "Share" bawaan HP dan "Salin link" selalu ada.
          Saran: Indonesia/India → WhatsApp & Telegram; AS/Eropa → Facebook, Pinterest, Reddit, X; Jepang/Taiwan → LINE.</p>
      </fieldset>

      <fieldset><legend>Auto-post ke channel Telegram</legend>
        <label class="check"><input type="checkbox" name="social[telegram_enabled]" value="1" <?= $c['social']['telegram_enabled'] ? 'checked' : '' ?>> Kirim video & artikel baru otomatis (setiap cron)</label>
        <div class="grid3">
          <label>Token bot <small>(dari @BotFather)</small><input name="social[telegram_token]" value="<?= e($c['social']['telegram_token']) ?>" placeholder="123456:ABC..."></label>
          <label>Channel <small>(@namachannel atau ID -100...)</small><input name="social[telegram_chat]" value="<?= e($c['social']['telegram_chat']) ?>" placeholder="@channelanda"></label>
          <label>Maks. posting per jalan<input type="number" name="social[telegram_per_run]" value="<?= (int)$c['social']['telegram_per_run'] ?>" min="1" max="30"></label>
        </div>
        <p class="help">Cara: buat bot di @BotFather → salin token → buat channel → tambahkan bot sebagai <b>admin</b> channel → simpan → klik "Kirim tes". Hanya konten yang masuk <i>setelah</i> fitur diaktifkan yang dikirim (tidak membanjiri channel).</p>
      </fieldset>

      <fieldset><legend>Push notification (OneSignal, Monetag, PropellerAds, dll.)</legend>
        <label>Script di &lt;head&gt; <small>(dari penyedia push)</small><textarea name="push[head]" rows="3" class="code"><?= e($c['push']['head']) ?></textarea></label>
        <div class="grid2">
          <label>Nama file service worker <small>(mis. <code>sw.js</code> atau <code>OneSignalSDKWorker.js</code>)</small><input name="push[sw_name]" value="<?= e($c['push']['sw_name']) ?>"></label>
          <label>Isi file service worker <small>(salin dari penyedia)</small><textarea name="push[sw_code]" rows="3" class="code"><?= e($c['push']['sw_code']) ?></textarea></label>
        </div>
        <?php if (push_sw_name()): ?><p class="help">File tersedia di: <a href="<?= e($base . '/' . push_sw_name()) ?>" target="_blank" rel="noopener"><?= e($base . '/' . push_sw_name()) ?></a></p><?php endif; ?>
        <p class="help">Push dari jaringan iklan (Monetag/PropellerAds) adalah produk iklan: aman dipakai bersama AdSense selama tidak berupa popunder/redirect di halaman, tapi tetap minta izin pengunjung secara jujur.</p>
      </fieldset>
      <button class="btn">Simpan</button>
    </form>
    <?php if ($c['social']['telegram_token'] && $c['social']['telegram_chat']): ?>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="act" value="tg_test"><button class="btn ghost">Kirim tes ke Telegram</button></form>
    <?php endif;
    echo admin_layout('Sosial & push', (string)ob_get_clean());
}

/* ================= Layout admin ================= */

function admin_layout(string $title, string $body, bool $nav = true): string
{
    $flashes = take_flashes();
    $sites = $nav && admin_logged_in() ? sites_all() : [];
    $cur = Site::$current?->id;
    $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $links = ['/admin' => 'Dashboard', '/admin/settings' => 'Pengaturan', '/admin/channels' => 'Sumber video', '/admin/articles' => 'Artikel',
        '/admin/ads' => 'Iklan', '/admin/social' => 'Sosial', '/admin/sites' => 'Situs', '/admin/global' => 'Global'];
    ob_start(); ?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title><?= e($title) ?> · AGC Video</title>
<style>
:root{--bg:#f5f6fa;--card:#fff;--line:#e3e6ee;--text:#18203a;--muted:#667089;--accent:#1f5eff;--err:#c62828;--ok:#0a7f4f}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
a{color:var(--accent)}.wrap{max-width:1080px;margin:0 auto;padding:0 16px}
header{background:#141a33;color:#fff}header .wrap{display:flex;flex-wrap:wrap;gap:12px 20px;align-items:center;min-height:56px;padding-top:8px;padding-bottom:8px}
header .brand{font-weight:800;color:#fff;text-decoration:none}header nav{display:flex;flex-wrap:wrap;gap:4px 14px}header nav a{color:#c9d2ff;text-decoration:none;font-weight:600}
header nav a.on{color:#fff;text-decoration:underline}header select{max-width:220px;padding:6px;border-radius:6px}header .right{margin-left:auto;display:flex;gap:12px;align-items:center}
main{padding:24px 0 60px}h1{font-size:24px;margin:0 0 16px}h2{font-size:18px;margin:28px 0 10px}
.form{display:grid;gap:12px;max-width:900px}.form.narrow{max-width:420px}.form.inline{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
label{display:grid;gap:4px;font-weight:600;font-size:14px}label small,.help,.muted{color:var(--muted);font-weight:400}.help{font-size:13px;margin:0}
input,select,textarea{font:inherit;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;width:100%}input[type=color]{height:40px;padding:2px}
input[type=checkbox],input[type=radio]{width:auto}textarea.code{font-family:ui-monospace,Consolas,monospace;font-size:12.5px}
label.check,label.radio{display:flex;gap:8px;align-items:flex-start;font-weight:400}
fieldset{border:1px solid var(--line);border-radius:12px;background:var(--card);padding:14px 16px;display:grid;gap:12px;margin:0}legend{font-weight:800;padding:0 6px}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.grid3{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.btn{display:inline-block;border:0;border-radius:8px;background:var(--accent);color:#fff;padding:10px 18px;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:var(--accent);border:1px solid var(--accent)}.link{background:none;border:0;color:var(--accent);cursor:pointer;font:inherit;padding:0;text-decoration:underline}
.danger{color:var(--err)}.row{display:flex;flex-wrap:wrap;gap:10px;margin:14px 0}
.msg{padding:10px 14px;border-radius:8px;background:#e8f6ee;color:var(--ok);border:1px solid #bfe5cf}.msg.err{background:#fdeaea;color:var(--err);border-color:#f3c0c0}
.tbl{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:10px;overflow:hidden;font-size:14px}
.tbl th,.tbl td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}.tbl th{background:#f0f2f8}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}.stat{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px}
.stat b{display:block;font-size:26px}.stat span{color:var(--muted);font-size:13px}
pre{background:#141a33;color:#dfe6ff;padding:12px;border-radius:8px;overflow:auto;font-size:12.5px}pre.log{max-height:360px}
code{background:#eef0f7;padding:2px 6px;border-radius:5px;word-break:break-all}.checks{list-style:none;padding:0;display:grid;gap:4px}
.preset-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px;max-width:900px}.preset-bar label{flex:1;min-width:240px}
.ch{display:flex;gap:10px}.ch img{border-radius:50%}.inl{display:inline-flex;gap:6px;align-items:center;margin:2px 0}.inl input{width:auto;padding:4px 6px}
details summary{cursor:pointer}.host{display:flex;gap:6px;align-items:center}
@media(max-width:640px){header .right{margin-left:0}.tbl{display:block;overflow-x:auto}}
</style></head><body>
<header><div class="wrap">
  <a class="brand" href="/admin">▶ AGC Video</a>
  <?php if ($nav && admin_logged_in()): ?>
  <nav><?php foreach ($links as $href => $label): ?><a href="<?= $href ?>" class="<?= $path === $href ? 'on' : '' ?>"><?= $label ?></a><?php endforeach; ?></nav>
  <div class="right">
    <?php if (count($sites) > 1): ?>
      <select onchange="location='/admin/switch?site='+encodeURIComponent(this.value)" aria-label="Pilih situs">
        <?php foreach ($sites as $s): ?><option value="<?= e($s['id']) ?>" <?= $s['id'] === $cur ? 'selected' : '' ?>><?= e($s['primary_host']) ?></option><?php endforeach; ?>
      </select>
    <?php elseif ($cur): ?><span class="muted" style="color:#c9d2ff"><?= e($sites[0]['primary_host'] ?? '') ?></span><?php endif; ?>
    <a href="/admin/logout" style="color:#c9d2ff">Keluar</a>
  </div>
  <?php endif; ?>
</div></header>
<main class="wrap">
  <h1><?= e($title) ?><?php if ($nav && $cur && Site::$current && !in_array($path, ['/admin/sites', '/admin/global', '/admin/sites/new'], true)): ?> <small class="muted" style="font-size:15px">· <?= e(cfg('site.name')) ?></small><?php endif; ?></h1>
  <?php foreach ($flashes as [$type, $msg]): ?><p class="msg <?= $type === 'err' ? 'err' : '' ?>"><?= e($msg) ?></p><?php endforeach; ?>
  <?= $body ?>
</main>
</body></html>
<?php
    return (string)ob_get_clean();
}
