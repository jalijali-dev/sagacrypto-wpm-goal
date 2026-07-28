<?php
declare(strict_types=1);

/**
 * Football (API-Football) settings form — extracted from the old
 * standalone livescore-api-settings.php so the consolidated settings hub
 * (cms-admin/pages/livescore-api-settings.php) can include it inside an
 * accordion section. Expects $settings (LivescoreSettings::load()) and
 * $trackedNames (league id => display name map) already in scope from
 * the parent, plus $selfUrl.
 */
?>
<form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>" id="livescore-api-form">
    <?= cms_csrf_field() ?>
    <input type="hidden" name="sport" value="football">

    <label class="field">Provider
        <input type="text" name="provider" value="<?= cms_esc($settings['provider']) ?>">
    </label>
    <label class="field">Base URL
        <input type="text" name="base_url" value="<?= cms_esc($settings['base_url']) ?>">
    </label>
    <label class="field">API Key Header
        <input type="text" name="api_key_header" value="<?= cms_esc($settings['api_key_header']) ?>">
    </label>
    <label class="field">API Key
        <div style="display:flex;gap:8px;">
            <input type="password" name="api_key" id="livescore-api-key" placeholder="<?= $settings['api_key'] !== '' ? '•••••••••••••• (kosongkan untuk tetap pakai yang lama)' : 'Masukkan API key dari API-Football' ?>" autocomplete="off" style="flex:1;">
            <button type="button" class="admin-btn admin-btn--secondary" id="livescore-api-key-toggle">Show</button>
        </div>
    </label>

    <label class="field field--checkbox">
        <input type="checkbox" name="is_active" value="1"<?= $settings['is_active'] ? ' checked' : '' ?>>
        <span class="field--checkbox__text">
            <span class="field--checkbox__title">Fitur Livescore aktif</span>
            <span class="field--checkbox__desc">Kalau nonaktif, halaman /livescore tetap ada tapi datanya berhenti diperbarui.</span>
        </span>
    </label>
    <label class="field field--checkbox">
        <input type="checkbox" name="auto_sync_enabled" value="1"<?= $settings['auto_sync_enabled'] ? ' checked' : '' ?>>
        <span class="field--checkbox__text">
            <span class="field--checkbox__title">Auto-sync (cron) diizinkan jalan</span>
            <span class="field--checkbox__desc">Matikan ini untuk jeda sinkronisasi otomatis sementara tanpa mematikan fitur yang sudah tampil ke publik.</span>
        </span>
    </label>

    <label class="field">Interval sync fixtures (menit)
        <input type="number" name="sync_fixtures_interval_minutes" min="1" value="<?= (int) round($settings['sync_fixtures_interval'] / 60) ?>">
    </label>
    <label class="field">Interval sync liga &amp; tim (jam)
        <input type="number" name="sync_leagues_teams_interval_hours" min="1" value="<?= (int) round(($settings['sync_secondary_interval'] ?? $settings['sync_fixtures_interval']) / 3600) ?>">
        <span class="field__hint">Throttle untuk <code>sync_leagues_teams.php</code> (18 request/run untuk 9 liga) — data liga &amp; roster tim jarang berubah, tidak perlu sesering fixtures.</span>
    </label>
    <label class="field">Cache duration — live (detik)
        <input type="number" name="cache_duration_live" min="10" value="<?= (int) $settings['cache_duration_live'] ?>">
    </label>

    <div class="field" style="grid-column: 1 / -1;">
        <span style="display:block;margin-bottom:8px;">Test Connection</span>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <button type="button" class="admin-btn admin-btn--secondary" id="livescore-test-btn"
                    data-test-action="<?= cms_esc(cms_action_href('livescore-api-test.php')) ?>"
                    data-csrf-token="<?= cms_esc(cms_csrf_token()) ?>">Test Connection</button>
            <span id="livescore-test-badge">
                <?php if ($settings['last_test_status'] === 'success') : ?>
                    <span class="pill pill--ok">✓ Terhubung</span>
                <?php elseif ($settings['last_test_status'] === 'failed') : ?>
                    <span class="pill pill--warn">✕ Gagal</span>
                <?php else : ?>
                    <span class="pill pill--muted">Belum pernah dites</span>
                <?php endif; ?>
            </span>
        </div>
        <p id="livescore-test-message" style="margin-top:8px;font-size:13px;opacity:.8;">
            <?= cms_esc((string) ($settings['last_test_message'] ?? '')) ?>
            <?php if (!empty($settings['last_test_at'])) : ?>
                <span style="opacity:.6;"> — <?= cms_esc(date('d M Y, H:i', strtotime($settings['last_test_at']))) ?></span>
            <?php endif; ?>
        </p>
    </div>

    <div class="field" style="grid-column: 1 / -1;">
        <span style="display:block;margin-bottom:8px;">Sync Sekarang (manual)</span>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <button type="button" class="admin-btn admin-btn--secondary" id="livescore-sync-now-btn"
                    data-sync-action="<?= cms_esc(cms_action_href('livescore-sync-now.php')) ?>"
                    data-csrf-token="<?= cms_esc(cms_csrf_token()) ?>"
                    <?= !$settings['is_active'] ? 'disabled' : '' ?>>Sync Sekarang</button>
            <?php if (!$settings['is_active']) : ?>
                <span style="font-size:12.5px;opacity:.7;">Centang &amp; simpan "Fitur Livescore aktif" dulu untuk memakai tombol ini.</span>
            <?php endif; ?>
        </div>
        <p style="margin-top:8px;font-size:12.5px;opacity:.7;">Menjalankan 2 tahap secara berurutan: leagues &amp; teams → fixtures — sama persis dengan yang dijalankan cron.</p>
        <div id="livescore-sync-now-progress" style="margin-top:10px;display:flex;flex-direction:column;gap:6px;font-size:13px;"></div>
    </div>

    <div class="field" style="grid-column: 1 / -1;">
        <span style="display:block;margin-bottom:8px;">Liga yang di-track (sync_fixtures.php hanya jalan untuk liga yang dicentang)</span>
        <div style="margin-bottom:10px;">
            <button type="button" class="admin-btn admin-btn--secondary" id="livescore-load-leagues-btn"
                    data-leagues-action="<?= cms_esc(cms_action_href('livescore-api-leagues.php')) ?>"
                    data-csrf-token="<?= cms_esc(cms_csrf_token()) ?>">Muat Daftar Liga dari API</button>
            <span id="livescore-leagues-status" style="font-size:12.5px;opacity:.7;margin-left:8px;"></span>
        </div>
        <div id="livescore-leagues-checklist" style="display:flex;flex-direction:column;gap:6px;max-height:320px;overflow-y:auto;padding:12px;border:1px solid var(--border,#333);border-radius:8px;">
            <?php if ($settings['tracked_league_ids'] === []) : ?>
                <em style="opacity:.6;font-size:13px;">Belum ada liga dipilih. Klik "Muat Daftar Liga dari API" (butuh API key valid &amp; test connection sukses) untuk memilih.</em>
            <?php else : ?>
                <?php foreach ($settings['tracked_league_ids'] as $leagueId) : ?>
                    <label style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="tracked_league_ids[]" value="<?= (int) $leagueId ?>" checked>
                        <span><?= cms_esc($trackedNames[$leagueId] ?? ('League #' . $leagueId)) ?></span>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <label class="field">Penempatan menu
        <select name="nav_placement">
            <option value="menu" <?= $settings['nav_placement'] === 'menu' ? 'selected' : '' ?>>Menu header</option>
            <option value="footer" <?= $settings['nav_placement'] === 'footer' ? 'selected' : '' ?>>Footer saja</option>
            <option value="hidden" <?= $settings['nav_placement'] === 'hidden' ? 'selected' : '' ?>>Sembunyikan</option>
        </select>
    </label>
    <label class="field">Urutan tampil
        <input type="number" name="sort_order" value="<?= (int) $settings['sort_order'] ?>">
    </label>
    <label class="field">Judul Halaman
        <input type="text" name="page_title" placeholder="Jadwal &amp; Skor Pertandingan" value="<?= cms_esc((string) ($settings['page_title'] ?? '')) ?>">
        <span class="field__hint">Kosongkan untuk pakai judul default.</span>
    </label>
    <label class="field">Subtitle Halaman
        <input type="text" name="page_subtitle" placeholder="Live score, jadwal hari ini dan besok, dikelompokkan per liga." value="<?= cms_esc((string) ($settings['page_subtitle'] ?? '')) ?>">
        <span class="field__hint">Kosongkan untuk pakai subtitle default.</span>
    </label>

    <div class="form-grid__actions">
        <button type="submit" class="admin-btn admin-btn--primary">Save settings</button>
    </div>
</form>

<script>
(function () {
    var keyInput = document.getElementById('livescore-api-key');
    var keyToggle = document.getElementById('livescore-api-key-toggle');
    if (keyToggle) {
        keyToggle.addEventListener('click', function () {
            var isPassword = keyInput.getAttribute('type') === 'password';
            keyInput.setAttribute('type', isPassword ? 'text' : 'password');
            keyToggle.textContent = isPassword ? 'Hide' : 'Show';
        });
    }

    var testBtn = document.getElementById('livescore-test-btn');
    var testBadge = document.getElementById('livescore-test-badge');
    var testMessage = document.getElementById('livescore-test-message');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            var action = testBtn.dataset.testAction;
            var token = testBtn.dataset.csrfToken;
            var form = document.getElementById('livescore-api-form');
            testBtn.disabled = true;
            testBtn.textContent = 'Testing...';

            var body = new URLSearchParams();
            body.set('base_url', form.base_url.value);
            body.set('api_key_header', form.api_key_header.value);
            body.set('api_key', keyInput.value);

            fetch(action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': token || ''
                },
                body: body.toString(),
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    testBadge.innerHTML = data.ok
                        ? '<span class="pill pill--ok">✓ Terhubung</span>'
                        : '<span class="pill pill--warn">✕ Gagal</span>';
                    testMessage.textContent = data.message + ' — ' + data.tested_at;
                })
                .catch(function () {
                    testBadge.innerHTML = '<span class="pill pill--warn">✕ Gagal</span>';
                    testMessage.textContent = 'Request test connection gagal (jaringan/server error).';
                })
                .finally(function () {
                    testBtn.disabled = false;
                    testBtn.textContent = 'Test Connection';
                });
        });
    }

    var loadLeaguesBtn = document.getElementById('livescore-load-leagues-btn');
    var leaguesStatus = document.getElementById('livescore-leagues-status');
    var checklist = document.getElementById('livescore-leagues-checklist');
    if (loadLeaguesBtn) {
        loadLeaguesBtn.addEventListener('click', function () {
            var action = loadLeaguesBtn.dataset.leaguesAction;
            var token = loadLeaguesBtn.dataset.csrfToken;
            var currentlyChecked = Array.prototype.slice.call(checklist.querySelectorAll('input:checked')).map(function (i) { return i.value; });

            loadLeaguesBtn.disabled = true;
            leaguesStatus.textContent = 'Memuat...';

            fetch(action, {
                method: 'POST',
                headers: { 'X-CSRF-Token': token || '' },
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        leaguesStatus.textContent = data.error || 'Gagal memuat daftar liga.';
                        return;
                    }
                    checklist.innerHTML = '';
                    data.leagues.forEach(function (league) {
                        var label = document.createElement('label');
                        label.style.display = 'flex';
                        label.style.alignItems = 'center';
                        label.style.gap = '8px';
                        var isChecked = currentlyChecked.indexOf(String(league.id)) !== -1;
                        label.innerHTML = '<input type="checkbox" name="tracked_league_ids[]" value="' + league.id + '"' + (isChecked ? ' checked' : '') + '>' +
                            '<span>' + league.name + (league.country ? ' (' + league.country + ')' : '') + '</span>';
                        checklist.appendChild(label);
                    });
                    leaguesStatus.textContent = data.leagues.length + ' liga dimuat.';
                })
                .catch(function () {
                    leaguesStatus.textContent = 'Request gagal (jaringan/server error).';
                })
                .finally(function () {
                    loadLeaguesBtn.disabled = false;
                });
        });
    }

    var syncNowBtn = document.getElementById('livescore-sync-now-btn');
    var syncNowProgress = document.getElementById('livescore-sync-now-progress');
    if (syncNowBtn) {
        syncNowBtn.addEventListener('click', function () {
            var action = syncNowBtn.dataset.syncAction;
            var token = syncNowBtn.dataset.csrfToken;

            syncNowBtn.disabled = true;
            syncNowBtn.textContent = 'Menjalankan sync...';
            syncNowProgress.innerHTML = '<span style="opacity:.7;">Menjalankan 2 tahap: leagues &amp; teams → fixtures...</span>';

            fetch(action, {
                method: 'POST',
                headers: { 'X-CSRF-Token': token || '' },
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        syncNowProgress.innerHTML = '<span class="pill pill--warn">✕ ' + (data.error || 'Sync gagal.') + '</span>';
                        return;
                    }
                    syncNowProgress.innerHTML = '';
                    data.stages.forEach(function (stage) {
                        var line = document.createElement('div');
                        var badge = stage.skipped_reason !== null
                            ? '<span class="pill pill--muted">⊘</span>'
                            : (stage.ok ? '<span class="pill pill--ok">✓</span>' : '<span class="pill pill--warn">✕</span>');
                        line.innerHTML = badge + ' <strong>' + stage.label + '</strong>... ' + stage.summary;
                        syncNowProgress.appendChild(line);
                    });
                    var finishedNote = document.createElement('div');
                    finishedNote.style.opacity = '.6';
                    finishedNote.style.fontSize = '12px';
                    finishedNote.style.marginTop = '4px';
                    finishedNote.textContent = 'Selesai — ' + data.finished_at;
                    syncNowProgress.appendChild(finishedNote);
                })
                .catch(function () {
                    syncNowProgress.innerHTML = '<span class="pill pill--warn">✕ Request sync gagal (jaringan/server error).</span>';
                })
                .finally(function () {
                    syncNowBtn.disabled = false;
                    syncNowBtn.textContent = 'Sync Sekarang';
                });
        });
    }
})();
</script>
