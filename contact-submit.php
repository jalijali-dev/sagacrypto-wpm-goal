<?php
declare(strict_types=1);

/**
 * Public "Kontak" form handler for kontak.php.
 *
 * Reuses the existing `contact_messages` table (same one already managed
 * from cms-admin/pages/contact-messages.php), so submissions show up in
 * the admin panel immediately — no schema changes needed.
 *
 * Intentionally standalone: does NOT require cms-admin/includes/auth.php
 * (that would force a login redirect for public visitors). Only the DB
 * connection is reused.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: kontak.php', true, 302);
    exit;
}

// Honeypot: real visitors never fill this hidden field. Bots that do get
// a fake "success" redirect so they don't retry, but nothing is stored.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    header('Location: kontak.php?contact=success', true, 302);
    exit;
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email    = trim((string) ($_POST['email'] ?? ''));
$subject  = trim((string) ($_POST['subject'] ?? ''));
$message  = trim((string) ($_POST['message'] ?? ''));

$isValid = $fullName !== ''
    && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
    && $message !== '';

if (!$isValid) {
    header('Location: kontak.php?contact=error', true, 302);
    exit;
}

try {
    require_once __DIR__ . '/cms-admin/config/database.php';

    $stmt = $pdo->prepare(
        'INSERT INTO contact_messages (full_name, email, phone, subject, message, is_read, created_at)
         VALUES (:full_name, :email, :phone, :subject, :message, 0, NOW())'
    );
    $stmt->execute([
        'full_name' => mb_substr($fullName, 0, 120),
        'email'     => mb_substr($email, 0, 160),
        'phone'     => null,
        'subject'   => $subject !== '' ? mb_substr($subject, 0, 160) : 'Pesan dari website',
        'message'   => mb_substr($message, 0, 4000),
    ]);

    header('Location: kontak.php?contact=success', true, 302);
    exit;
} catch (Throwable $e) {
    header('Location: kontak.php?contact=error', true, 302);
    exit;
}
