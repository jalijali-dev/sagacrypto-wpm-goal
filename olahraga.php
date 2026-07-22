<?php
declare(strict_types=1);

/**
 * Sagagoal — multi-sport selector (/olahraga). Replaces the old "Liga"
 * hub: Sagagoal pivoted from a football-only livescore site to a
 * multi-sport one, so this page is now the discovery point for every
 * sport we cover (active) or plan to cover ("Segera Hadir" placeholder).
 * Sepak Bola reuses the existing livescore.php untouched — only how
 * visitors *get there* changed, not the football feature itself.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$sports = wpm_all_sports($pdo);
$activeSportCount = count(array_filter($sports, static fn (array $sport): bool => (int) $sport['is_active'] === 1));

/** Maps an active sport's key to its real destination page — only football exists so far. */
$sportUrl = static function (string $key): string {
    return match ($key) {
        'football' => wpm_url_livescore(),
        'basketball' => wpm_url_nba(),
        'motorsport' => wpm_url_f1(),
        default => '#',
    };
};

/** Copy follows how many sports are actually live in `sports`, not a hardcoded assumption — updates itself the moment a new branch is activated. */
$heroTagline = $activeSportCount > 1
    ? 'Nggak cuma bola. Sepak bola, basket, sampai balapan F1 — semua update real-time di satu tempat.'
    : 'Livescore sepak bola sudah aktif penuh. Cabang lain menyusul.';

$pageTitle = 'Sport — Sagagoal';
$pageDescription = 'Pilih cabang sport: livescore sepak bola aktif penuh, basket (NBA) dan Formula 1 segera hadir.';
$activeNav = 'olahraga';
$canonicalUrl = wpm_site_url(wpm_url_olahraga());

require __DIR__ . '/includes/site-header.php';
?>

    <section class="page-hero">
        <div class="crypto-container">
            <nav class="breadcrumb" aria-label="Breadcrumb"><a href="index.php">Beranda</a> <span>/</span> Sport</nav>
            <span class="section-kicker">Sport</span>
            <h1>Pilih Sport</h1>
            <p><?= wpm_esc($heroTagline) ?></p>
        </div>
    </section>

    <div class="crypto-container">
        <div class="sport-grid">
            <?php foreach ($sports as $sport) : ?>
                <?php $isActive = (int) $sport['is_active'] === 1; ?>
                <?php if ($isActive) : ?>
                    <a class="sport-card sport-card--active" href="<?= wpm_esc($sportUrl((string) $sport['key'])) ?>">
                <?php else : ?>
                    <div class="sport-card sport-card--soon">
                <?php endif; ?>
                        <div class="sport-card__icon"><?= wpm_icon((string) ($sport['icon'] ?? 'trophy')) ?></div>
                        <div class="sport-card__body">
                            <span class="sport-card__name"><?= wpm_esc((string) $sport['name']) ?></span>
                            <?php if (!$isActive) : ?>
                                <span class="sport-card__badge">Segera Hadir</span>
                            <?php endif; ?>
                        </div>
                <?php if ($isActive) : ?>
                    </a>
                <?php else : ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
