<?php
declare(strict_types=1);

/**
 * NBA (API-Basketball) settings form — extracted from the old standalone
 * basketball-api-settings.php for the consolidated settings hub. Expects
 * $settings (BasketballSettings::load()) and $selfUrl in scope.
 */
?>
<form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>" id="basketball-api-form">
    <?= cms_csrf_field() ?>
    <input type="hidden" name="sport" value="basketball">

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
            <input type="password" name="api_key" id="basketball-api-key" placeholder="<?= $settings['api_key'] !== '' ? '•••••••••••••• (kosongkan untuk tetap pakai yang lama)' : 'Masukkan API key dari API-Basketball' ?>" autocomplete="off" style="flex:1;">
            <button type="button" class="admin-btn admin-btn--secondary" id="basketball-api-key-toggle">Show</button>
        </div>
    </label>

    <label class="field field--checkbox">
        <input type="checkbox" name="is_active" value="1"<?= $settings['is_active'] ? ' checked' : '' ?>>
        <span class="field--checkbox__text">
            <span class="field--checkbox__title">Fitur NBA aktif</span>
            <span class="field--checkbox__desc">Mengontrol dua hal sekaligus: apakah cron sync boleh jalan, dan apakah card "NBA" di /olahraga tampil aktif (bukan "Segera Hadir"). Aktifkan setelah API key valid &amp; sync pertama berhasil.</span>
        </span>
    </label>
    <label class="field field--checkbox">
        <input type="checkbox" name="auto_sync_enabled" value="1"<?= $settings['auto_sync_enabled'] ? ' checked' : '' ?>>
        <span class="field--checkbox__text">
            <span class="field--checkbox__title">Auto-sync (cron) diizinkan jalan</span>
            <span class="field--checkbox__desc">Matikan ini untuk jeda sinkronisasi otomatis sementara tanpa mematikan fitur yang sudah tampil ke publik.</span>
        </span>
    </label>

    <label class="field">Interval sync games (menit)
        <input type="number" name="sync_games_interval_minutes" min="1" value="<?= (int) round($settings['sync_games_interval'] / 60) ?>">
    </label>
    <label class="field">Cache duration — live (detik)
        <input type="number" name="cache_duration_live" min="10" value="<?= (int) $settings['cache_duration_live'] ?>">
    </label>

    <div class="field" style="grid-column: 1 / -1;">
        <span style="display:block;margin-bottom:8px;">Test Connection</span>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <button type="button" class="admin-btn admin-btn--secondary" id="basketball-test-btn"
                    data-test-action="<?= cms_esc(cms_action_href('basketball-api-test.php')) ?>"
                    data-csrf-token="<?= cms_esc(cms_csrf_token()) ?>">Test Connection</button>
            <span id="basketball-test-badge">
                <?php if ($settings['last_test_status'] === 'success') : ?>
                    <span class="pill pill--ok">✓ Terhubung</span>
                <?php elseif ($settings['last_test_status'] === 'failed') : ?>
                    <span class="pill pill--warn">✕ Gagal</span>
                <?php else : ?>
                    <span class="pill pill--muted">Belum pernah dites</span>
                <?php endif; ?>
            </span>
        </div>
        <p id="basketball-test-message" style="margin-top:8px;font-size:13px;opacity:.8;">
            <?= cms_esc((string) ($settings['last_test_message'] ?? '')) ?>
            <?php if (!empty($settings['last_test_at'])) : ?>
                <span style="opacity:.6;"> — <?= cms_esc(date('d M Y, H:i', strtotime($settings['last_test_at']))) ?></span>
            <?php endif; ?>
        </p>
    </div>

    <div class="field" style="grid-column: 1 / -1;">
        <span style="display:block;margin-bottom:8px;">Sync Sekarang (manual)</span>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <button type="button" class="admin-btn admin-btn--secondary" id="basketball-sync-now-btn"
                    data-sync-action="<?= cms_esc(cms_action_href('basketball-sync-now.php')) ?>"
                    data-csrf-token="<?= cms_esc(cms_csrf_token()) ?>"
                    <?= !$settings['is_active'] ? 'disabled' : '' ?>>Sync Sekarang</button>
            <?php if (!$settings['is_active']) : ?>
                <span style="font-size:12.5px;opacity:.7;">Centang &amp; simpan "Fitur NBA aktif" dulu untuk memakai tombol ini.</span>
            <?php endif; ?>
        </div>
        <p style="margin-top:8px;font-size:12.5px;opacity:.7;">Sync games (+ teams ter-embed) untuk hari ini &amp; besok — sama persis dengan yang dijalankan cron.</p>
        <div id="basketball-sync-now-progress" style="margin-top:10px;display:flex;flex-direction:column;gap:6px;font-size:13px;"></div>
    </div>

    <div class="form-grid__actions">
        <button type="submit" class="admin-btn admin-btn--primary">Save settings</button>
    </div>
</form>

<script>
(function () {
    var keyInput = document.getElementById('basketball-api-key');
    var keyToggle = document.getElementById('basketball-api-key-toggle');
    if (keyToggle) {
        keyToggle.addEventListener('click', function () {
            var isPassword = keyInput.getAttribute('type') === 'password';
            keyInput.setAttribute('type', isPassword ? 'text' : 'password');
            keyToggle.textContent = isPassword ? 'Hide' : 'Show';
        });
    }

    var testBtn = document.getElementById('basketball-test-btn');
    var testBadge = document.getElementById('basketball-test-badge');
    var testMessage = document.getElementById('basketball-test-message');
    if (testBtn) {
        testBtn.addEventListener('click', function () {
            var action = testBtn.dataset.testAction;
            var token = testBtn.dataset.csrfToken;
            var form = document.getElementById('basketball-api-form');
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

    var syncNowBtn = document.getElementById('basketball-sync-now-btn');
    var syncNowProgress = document.getElementById('basketball-sync-now-progress');
    if (syncNowBtn) {
        syncNowBtn.addEventListener('click', function () {
            var action = syncNowBtn.dataset.syncAction;
            var token = syncNowBtn.dataset.csrfToken;

            syncNowBtn.disabled = true;
            syncNowBtn.textContent = 'Menjalankan sync...';
            syncNowProgress.innerHTML = '<span style="opacity:.7;">Menjalankan sync games...</span>';

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
