<?php

/**
 * cleanup.php — Bersihkan file upload & output yang sudah kedaluwarsa.
 *
 * Jalankan via cron:
 *   0 2 * * * php /path/to/converter/cleanup.php >> /var/log/docjpg_cleanup.log 2>&1
 *
 * Bisa juga dijalankan manual:
 *   php cleanup.php
 *   php cleanup.php --max-age=7200   (override batas usia file, dalam detik)
 *   php cleanup.php --dry-run        (simulasi tanpa benar-benar hapus)
 */

// ── Keamanan: hanya boleh dijalankan dari CLI ─────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Akses ditolak. Script ini hanya boleh dijalankan dari command line.\n");
}

// ── Konfigurasi default ───────────────────────────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('OUTPUT_DIR', __DIR__ . '/output/');
define('DEFAULT_MAX_AGE', 3600);   // 1 jam dalam detik
define('LOG_FILE', __DIR__ . '/cleanup.log');

// ── Parse argumen CLI ─────────────────────────────────────────────────────────
$options = getopt('', ['max-age::', 'dry-run', 'help', 'no-log']);
$dryRun = isset($options['dry-run']);
$noLog = isset($options['no-log']);
$maxAge = isset($options['max-age']) ? (int) $options['max-age'] : DEFAULT_MAX_AGE;
$maxAge = max(60, $maxAge); // minimum 60 detik agar tidak bahaya

if (isset($options['help'])) {
    echo <<<HELP
Penggunaan: php cleanup.php [opsi]

Opsi:
  --max-age=<detik>   Usia maksimum file sebelum dihapus (default: 3600 = 1 jam)
  --dry-run           Simulasi: tampilkan file yang akan dihapus tanpa benar-benar menghapus
  --no-log            Jangan tulis ke file log
  --help              Tampilkan bantuan ini

HELP;
    exit(0);
}

// ── Fungsi utilitas ───────────────────────────────────────────────────────────
function formatBytes(int $bytes): string
{
    if ($bytes >= 1024 * 1024)
        return round($bytes / 1024 / 1024, 2) . ' MB';
    if ($bytes >= 1024)
        return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function logLine(string $line, bool $noLog): void
{
    $ts = date('Y-m-d H:i:s');
    $message = "[{$ts}] {$line}";
    echo $message . PHP_EOL;
    if (!$noLog) {
        file_put_contents(LOG_FILE, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

// ── Pastikan direktori ada ────────────────────────────────────────────────────
$dirs = [UPLOAD_DIR, OUTPUT_DIR];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (!$dryRun) {
            mkdir($dir, 0755, true);
            logLine("Direktori dibuat: {$dir}", $noLog);
        } else {
            logLine("[DRY-RUN] Direktori tidak ada (akan dibuat): {$dir}", $noLog);
        }
    }
}

// ── Jalankan cleanup ──────────────────────────────────────────────────────────
$label = $dryRun ? '[DRY-RUN] ' : '';
$now = time();
$deletedCount = 0;
$deletedBytes = 0;
$skippedCount = 0;
$errorCount = 0;

logLine("{$label}Memulai cleanup. Max-age: {$maxAge} detik (" . round($maxAge / 60, 1) . " menit).", $noLog);

foreach ($dirs as $dir) {
    $files = glob($dir . '/*');
    if ($files === false) {
        logLine("ERROR: Gagal membaca direktori {$dir}", $noLog);
        $errorCount++;
        continue;
    }

    foreach ($files as $file) {
        if (!is_file($file))
            continue;

        $age = $now - filemtime($file);
        $size = filesize($file);

        if ($age >= $maxAge) {
            if ($dryRun) {
                logLine("[DRY-RUN] Akan dihapus: " . basename($file) . " (usia: " . round($age / 60) . " menit, ukuran: " . formatBytes($size) . ")", $noLog);
                $deletedCount++;
                $deletedBytes += $size;
            } else {
                if (unlink($file)) {
                    logLine("Dihapus: " . basename($file) . " (usia: " . round($age / 60) . " menit, ukuran: " . formatBytes($size) . ")", $noLog);
                    $deletedCount++;
                    $deletedBytes += $size;
                } else {
                    logLine("ERROR: Gagal menghapus " . basename($file), $noLog);
                    $errorCount++;
                }
            }
        } else {
            $skippedCount++;
        }
    }
}

// ── Ringkasan ─────────────────────────────────────────────────────────────────
$summary = sprintf(
    "%sSelesai. %s: %d file (%s) | Dilewati: %d | Error: %d",
    $label,
    $dryRun ? 'Akan dihapus' : 'Dihapus',
    $deletedCount,
    formatBytes($deletedBytes),
    $skippedCount,
    $errorCount
);
logLine($summary, $noLog);

exit($errorCount > 0 ? 1 : 0);
