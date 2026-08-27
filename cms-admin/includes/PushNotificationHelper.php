<?php
declare(strict_types=1);

/**
 * Push Notification (Firebase Cloud Messaging) — 27 Agu 2026.
 *
 * Lets sagagoal.com push a native notification to every subscribed
 * browser/PWA install when an article is published, using Firebase Cloud
 * Messaging's HTTP v1 API. No vendor SDK (this project has no Composer/
 * vendor dir at all — see CLAUDE.md/deploy workflow) — auth is a hand-
 * rolled Google service-account JWT (RS256, openssl_sign) exchanged for
 * an OAuth2 access token, same class of "just cURL + openssl" approach
 * already used for Turnstile (page.php) and the AI provider calls
 * (ai-helpers.php).
 *
 * Credential storage:
 *   - fcm_vapid_public_key / fcm_web_app_config_json: PUBLIC by design
 *     (Firebase's own web config is meant to ship inside client JS/SW —
 *     it identifies the project, it isn't a secret), stored plain.
 *   - fcm_service_account_json: SECRET (grants send-as-this-project
 *     power) — stored encrypted with cms_ai_encrypt()/cms_ai_decrypt()
 *     (ai-helpers.php), reusing CMS_AI_ENC_SECRET from config/app.php
 *     rather than inventing a second encryption scheme.
 *
 * Included from three very different execution contexts — an admin HTTP
 * request (site-settings.php, pages.php), a public HTTP request (api/
 * push-subscribe.php), and a CLI cron run with no $_SERVER request data
 * at all (growth-agent auto-publish, cron/growth_agent_maintenance.php)
 * — so nothing in this file may assume a browser request exists.
 * Deliberately does NOT require includes/site-bootstrap.php for its
 * wpm_site_url()/wpm_url_artikel() helpers: that file calls
 * session_start() and re-includes config/database.php as a side effect,
 * which is fine for a public page but wrong to drag into an admin/cron
 * request that already has its own session/DB state. cms_push_article_url()
 * below re-derives the same absolute-URL logic standalone instead.
 */

if (!function_exists('cms_push_ensure_schema')) {
    function cms_push_ensure_schema(PDO $pdo): void
    {
        cms_ensure_column($pdo, 'site_settings', 'fcm_vapid_public_key', 'TEXT NULL');
        cms_ensure_column($pdo, 'site_settings', 'fcm_service_account_json', 'LONGTEXT NULL');
        cms_ensure_column($pdo, 'site_settings', 'fcm_project_id', 'VARCHAR(255) NULL');
        // Firebase's small public "web app config" (apiKey/authDomain/
        // projectId/storageBucket/messagingSenderId/appId) — required by
        // firebase.initializeApp() on both the page and the service
        // worker side, distinct from the VAPID key pair and the secret
        // service account JSON. Not part of the original brief's schema
        // list but technically required for getToken()/messaging() to
        // work at all — see docblock above.
        cms_ensure_column($pdo, 'site_settings', 'fcm_web_app_config_json', 'TEXT NULL');
        cms_ensure_column($pdo, 'site_settings', 'push_notification_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');

        // Widened to VARCHAR(512) (brief specified 255) — real FCM
        // registration tokens are usually ~150-230 chars but have no hard
        // documented ceiling; 255 risked silent truncation corrupting a
        // token on the rare longer one. UNIQUE index on VARCHAR(512)
        // utf8mb4 is still well under MySQL 8/MariaDB 10's 3072-byte key
        // limit (512*4=2048 bytes), so no index-size tradeoff either.
        cms_ensure_table(
            $pdo,
            'push_subscribers',
            'id INT AUTO_INCREMENT PRIMARY KEY,
             fcm_token VARCHAR(512) NOT NULL,
             created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
             last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
             is_active TINYINT DEFAULT 1,
             UNIQUE KEY uniq_push_fcm_token (fcm_token)'
        );
    }
}

/**
 * Loads + decodes the FCM config from site_settings. Never throws —
 * every consumer just gets `enabled: false` / null fields on any
 * decode failure, which every call site already treats as "feature not
 * configured, skip".
 *
 * @return array{
 *   enabled: bool, vapid_public_key: string, project_id: string,
 *   web_app_config: array<string,mixed>|null,
 *   service_account: array<string,mixed>|null,
 * }
 */
if (!function_exists('cms_push_get_settings')) {
    function cms_push_get_settings(PDO $pdo): array
    {
        $out = [
            'enabled' => false,
            'vapid_public_key' => '',
            'project_id' => '',
            'web_app_config' => null,
            'service_account' => null,
        ];

        try {
            $row = $pdo->query(
                'SELECT push_notification_enabled, fcm_vapid_public_key, fcm_project_id, fcm_web_app_config_json, fcm_service_account_json FROM site_settings LIMIT 1'
            )->fetch();
        } catch (Throwable $e) {
            return $out;
        }
        if (!is_array($row)) {
            return $out;
        }

        $out['enabled'] = (int) ($row['push_notification_enabled'] ?? 0) === 1;
        $out['vapid_public_key'] = trim((string) ($row['fcm_vapid_public_key'] ?? ''));
        $out['project_id'] = trim((string) ($row['fcm_project_id'] ?? ''));

        $webConfigRaw = trim((string) ($row['fcm_web_app_config_json'] ?? ''));
        if ($webConfigRaw !== '') {
            $decoded = json_decode($webConfigRaw, true);
            $out['web_app_config'] = is_array($decoded) ? $decoded : null;
        }

        $serviceAccountEnc = trim((string) ($row['fcm_service_account_json'] ?? ''));
        if ($serviceAccountEnc !== '') {
            try {
                require_once __DIR__ . '/ai-helpers.php';
                $plain = cms_ai_decrypt($serviceAccountEnc);
                $decoded = $plain !== '' ? json_decode($plain, true) : null;
                $out['service_account'] = is_array($decoded) ? $decoded : null;
            } catch (Throwable $e) {
                $out['service_account'] = null;
            }
        }

        return $out;
    }
}

/** RFC 4648 base64url, no padding — what both JWT segments and the JWT signature need. */
if (!function_exists('cms_push_base64url')) {
    function cms_push_base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

/**
 * Builds + RS256-signs a Google service-account JWT assertion, scoped
 * for FCM send access. Exchanged for a real OAuth2 access token by
 * cms_push_get_access_token() below — this function only produces the
 * assertion, it doesn't call the network.
 */
if (!function_exists('cms_push_build_jwt')) {
    function cms_push_build_jwt(array $serviceAccount): ?string
    {
        $clientEmail = (string) ($serviceAccount['client_email'] ?? '');
        $privateKey  = (string) ($serviceAccount['private_key'] ?? '');
        if ($clientEmail === '' || $privateKey === '') {
            return null;
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $signingInput = cms_push_base64url(json_encode($header, JSON_UNESCAPED_SLASHES))
            . '.' . cms_push_base64url(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$ok || $signature === '') {
            return null;
        }

        return $signingInput . '.' . cms_push_base64url($signature);
    }
}

/**
 * Exchanges a signed service-account JWT for a short-lived OAuth2 access
 * token via Google's token endpoint. One call per notification-send
 * batch (not cached across requests) — this feature's send volume
 * (once per article publish) is far too low to justify persisting/
 * refreshing a cached token across requests.
 *
 * @return array{ok: bool, access_token: string, error: string}
 */
if (!function_exists('cms_push_get_access_token')) {
    function cms_push_get_access_token(array $serviceAccount): array
    {
        $jwt = cms_push_build_jwt($serviceAccount);
        if ($jwt === null) {
            return ['ok' => false, 'access_token' => '', 'error' => 'Service account JSON tidak valid (client_email/private_key hilang atau JWT gagal ditandatangani).'];
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'access_token' => '', 'error' => $curlError ?: 'cURL request to oauth2.googleapis.com failed.'];
        }

        $decoded = json_decode($response, true);
        if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
            $errMsg = is_array($decoded) ? ($decoded['error_description'] ?? $decoded['error'] ?? $response) : $response;
            return ['ok' => false, 'access_token' => '', 'error' => (string) $errMsg];
        }

        return ['ok' => true, 'access_token' => (string) $decoded['access_token'], 'error' => ''];
    }
}

/**
 * Sends one data-only FCM v1 message to one token. Data-only (no
 * top-level "notification" key) is deliberate: it puts display fully
 * under our own control via the service worker's onBackgroundMessage
 * handler (sw.js) — title/body/image/click-through link are exactly
 * what we choose, not whatever FCM's default auto-display would pick.
 *
 * @param array{title:string,body:string,image?:string,url:string} $notification
 * @return array{ok: bool, invalid_token: bool, error: string}
 */
if (!function_exists('cms_push_send_single')) {
    function cms_push_send_single(string $projectId, string $accessToken, string $fcmToken, array $notification): array
    {
        $data = [
            'title' => $notification['title'],
            'body'  => $notification['body'],
            'url'   => $notification['url'],
        ];
        if (!empty($notification['image'])) {
            $data['image'] = $notification['image'];
        }

        $body = [
            'message' => [
                'token' => $fcmToken,
                'data'  => $data,
                'webpush' => [
                    'fcm_options' => ['link' => $notification['url']],
                ],
            ],
        ];

        $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'invalid_token' => false, 'error' => $curlError ?: 'cURL request to fcm.googleapis.com failed.'];
        }
        if ($httpStatus >= 200 && $httpStatus < 300) {
            return ['ok' => true, 'invalid_token' => false, 'error' => ''];
        }

        $decoded = json_decode($response, true);
        $errorCode = '';
        if (is_array($decoded)) {
            $details = $decoded['error']['details'] ?? [];
            foreach ((is_array($details) ? $details : []) as $detail) {
                if (isset($detail['errorCode'])) {
                    $errorCode = (string) $detail['errorCode'];
                    break;
                }
            }
            if ($errorCode === '') {
                $errorCode = (string) ($decoded['error']['status'] ?? '');
            }
        }
        // FCM's own vocabulary for "this token will never work again"
        // (uninstalled app, expired subscription, wrong sender). Anything
        // else (QUOTA_EXCEEDED, UNAVAILABLE, INTERNAL, auth errors) is a
        // transient/config problem, not a dead token — must NOT delete
        // subscribers over those.
        $invalidToken = in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true);

        $errMsg = is_array($decoded) ? ($decoded['error']['message'] ?? $response) : $response;
        return ['ok' => false, 'invalid_token' => $invalidToken, 'error' => (string) $errMsg];
    }
}

/**
 * Absolute article URL, built standalone (no site-bootstrap.php
 * dependency — see this file's top docblock for why). Mirrors
 * includes/site-bootstrap.php's wpm_site_url()/wpm_url_artikel() host
 * resolution exactly (sagagoal.com/www.sagagoal.com -> forced https,
 * non-www; anything else, including no $_SERVER['HTTP_HOST'] at all in
 * a CLI cron run, falls back to the 'sagagoal.com' default) so a link
 * clicked from a push notification always lands on the same canonical
 * host the rest of the site uses.
 */
if (!function_exists('cms_push_article_url')) {
    function cms_push_article_url(string $slug): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'sagagoal.com';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (preg_match('/^(www\.)?sagagoal\.com$/i', $host) === 1) {
            $scheme = 'https';
            $host = 'sagagoal.com';
        }
        return $scheme . '://' . $host . '/artikel/' . rawurlencode($slug);
    }
}

/**
 * Main entry point — called right after an article's status genuinely
 * transitions to 'published' for the first time (see cms-admin/pages/
 * pages.php and cms_growth_agent_auto_publish_draft() in growth-agent-
 * service.php for the two call sites). No-ops silently (returns
 * ok=>true, sent=>0) whenever the feature isn't configured/enabled —
 * this must never be able to block or fail an article publish.
 *
 * @return array{ok: bool, sent: int, failed: int, error: string}
 */
if (!function_exists('cms_send_push_notification_for_article')) {
    function cms_send_push_notification_for_article(PDO $pdo, int $pageId): array
    {
        try {
            cms_push_ensure_schema($pdo);
            $settings = cms_push_get_settings($pdo);

            if (!$settings['enabled'] || $settings['service_account'] === null || $settings['project_id'] === '') {
                return ['ok' => true, 'sent' => 0, 'failed' => 0, 'error' => ''];
            }

            $articleStmt = $pdo->prepare('SELECT title, slug, excerpt, featured_image FROM pages WHERE page_id = :id LIMIT 1');
            $articleStmt->execute(['id' => $pageId]);
            $article = $articleStmt->fetch();
            if (!is_array($article)) {
                return ['ok' => false, 'sent' => 0, 'failed' => 0, 'error' => 'Article not found.'];
            }

            $tokenRows = $pdo->query('SELECT id, fcm_token FROM push_subscribers WHERE is_active = 1')->fetchAll();
            if ($tokenRows === []) {
                return ['ok' => true, 'sent' => 0, 'failed' => 0, 'error' => ''];
            }

            $tokenAuth = cms_push_get_access_token($settings['service_account']);
            if (!$tokenAuth['ok']) {
                return ['ok' => false, 'sent' => 0, 'failed' => 0, 'error' => 'Gagal ambil access token FCM: ' . $tokenAuth['error']];
            }

            require_once dirname(__DIR__) . '/config/app.php';
            $notification = [
                'title' => (string) $article['title'],
                'body'  => mb_substr(trim((string) ($article['excerpt'] ?? '')), 0, 150) ?: 'Baca artikel terbaru di Sagagoal.',
                'url'   => cms_push_article_url((string) $article['slug']),
            ];
            $featuredImage = trim((string) ($article['featured_image'] ?? ''));
            if ($featuredImage !== '') {
                $notification['image'] = app_asset_preview_url($featuredImage);
            }

            $deactivateStmt = $pdo->prepare('UPDATE push_subscribers SET is_active = 0 WHERE id = :id');
            $sent = 0;
            $failed = 0;
            foreach ($tokenRows as $row) {
                $result = cms_push_send_single($settings['project_id'], $tokenAuth['access_token'], (string) $row['fcm_token'], $notification);
                if ($result['ok']) {
                    $sent++;
                } else {
                    $failed++;
                    if ($result['invalid_token']) {
                        $deactivateStmt->execute(['id' => $row['id']]);
                    }
                }
            }

            return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'error' => ''];
        } catch (Throwable $e) {
            // Never let a push-notification failure surface as an article
            // publish failure — this whole function is best-effort from
            // the caller's point of view.
            error_log('[cms_send_push_notification_for_article] ' . $e->getMessage());
            return ['ok' => false, 'sent' => 0, 'failed' => 0, 'error' => $e->getMessage()];
        }
    }
}

/**
 * "Test Notification" button on the admin Push Notification Settings
 * panel — same send path as a real article publish, just with a fixed
 * title/body/link so an operator can verify setup before trusting it to
 * fire automatically on the next publish.
 *
 * @return array{ok: bool, sent: int, failed: int, error: string}
 */
if (!function_exists('cms_push_send_test_notification')) {
    function cms_push_send_test_notification(PDO $pdo): array
    {
        try {
            cms_push_ensure_schema($pdo);
            $settings = cms_push_get_settings($pdo);

            if ($settings['service_account'] === null || $settings['project_id'] === '') {
                return ['ok' => false, 'sent' => 0, 'failed' => 0, 'error' => 'Service account JSON / Project ID belum diisi atau gagal didekripsi.'];
            }

            $tokenRows = $pdo->query('SELECT id, fcm_token FROM push_subscribers WHERE is_active = 1')->fetchAll();
            if ($tokenRows === []) {
                return ['ok' => false, 'sent' => 0, 'failed' => 0, 'error' => 'Belum ada subscriber aktif — buka situs, klik "Aktifkan Notifikasi" dulu di minimal 1 browser/HP.'];
            }

            $tokenAuth = cms_push_get_access_token($settings['service_account']);
            if (!$tokenAuth['ok']) {
                return ['ok' => false, 'sent' => 0, 'failed' => 0, 'error' => 'Gagal ambil access token FCM: ' . $tokenAuth['error']];
            }

            $notification = [
                'title' => 'Test Notification — Sagagoal',
                'body'  => 'Kalau kamu lihat ini, setup push notification kamu jalan dengan benar.',
                'url'   => cms_push_article_url(''),
            ];

            $deactivateStmt = $pdo->prepare('UPDATE push_subscribers SET is_active = 0 WHERE id = :id');
            $sent = 0;
            $failed = 0;
            foreach ($tokenRows as $row) {
                $result = cms_push_send_single($settings['project_id'], $tokenAuth['access_token'], (string) $row['fcm_token'], $notification);
                if ($result['ok']) {
                    $sent++;
                } else {
                    $failed++;
                    if ($result['invalid_token']) {
                        $deactivateStmt->execute(['id' => $row['id']]);
                    }
                }
            }

            return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'error' => ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'sent' => 0, 'failed' => 0, 'error' => $e->getMessage()];
        }
    }
}

/**
 * Rewrites the FCM_WEB_CONFIG literal inside the site-root sw.js between
 * its two marker comments, whenever the admin saves Push Notification
 * settings — sw.js is a static file served directly (not through PHP),
 * so the only way to get the (public, non-secret) Firebase web config
 * into it is to physically regenerate that one delimited block on save,
 * same idea as how logo/favicon uploads regenerate files on disk
 * elsewhere in site-settings-update.php. Every other line of sw.js is
 * left untouched. Returns false (silently) if sw.js doesn't have the
 * markers yet (e.g. a production deploy that hasn't received the sw.js
 * update from this feature yet) — never fatal, push just won't work
 * until that file is deployed.
 */
if (!function_exists('cms_push_regenerate_sw_js_config')) {
    function cms_push_regenerate_sw_js_config(?array $webAppConfig): bool
    {
        $swPath = CMS_PROJECT_ROOT . '/sw.js';
        if (!is_file($swPath) || !is_writable($swPath)) {
            return false;
        }
        $contents = file_get_contents($swPath);
        if ($contents === false) {
            return false;
        }

        $startMarker = '/* FCM_WEB_CONFIG_START */';
        $endMarker = '/* FCM_WEB_CONFIG_END */';
        if (!str_contains($contents, $startMarker) || !str_contains($contents, $endMarker)) {
            return false;
        }

        $configLiteral = $webAppConfig !== null && $webAppConfig !== []
            ? json_encode($webAppConfig, JSON_UNESCAPED_SLASHES)
            : 'null';
        $replacement = $startMarker . "\nconst FCM_WEB_CONFIG = " . $configLiteral . ";\n" . $endMarker;

        $pattern = '#' . preg_quote($startMarker, '#') . '.*?' . preg_quote($endMarker, '#') . '#s';
        $updated = preg_replace($pattern, $replacement, $contents, 1);
        if (!is_string($updated)) {
            return false;
        }

        return file_put_contents($swPath, $updated) !== false;
    }
}
