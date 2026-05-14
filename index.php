<?php
session_start();
$message = '';
$error = '';
$results = [];

// Configuration
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('OUTPUT_DIR', __DIR__ . '/output/');
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50MB
define('ALLOWED_TYPES', [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/gif' => 'gif',
    'image/bmp' => 'bmp',
    'image/tiff' => 'tiff',
    'image/webp' => 'webp',
]);
define('OUTPUT_FORMATS', ['jpg', 'png']);

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
if (!is_dir(OUTPUT_DIR))  mkdir(OUTPUT_DIR, 0755, true);

// Handle ZIP download
if (isset($_GET['download_zip']) && !empty($_GET['prefix'])) {
    $prefix  = preg_replace('/[^a-zA-Z0-9_.\-]/', '', $_GET['prefix']);
    $zipName = $prefix . '_images.zip';
    $files   = array_unique(array_merge(
        glob(OUTPUT_DIR . $prefix . '_page*.jpg') ?: [],
        glob(OUTPUT_DIR . $prefix . '_page*.png') ?: []
    ));
    natsort($files);
    $files = array_values($files);
    if (!$files) {
        http_response_code(404);
        exit('ZIP gagal: file tidak ditemukan.');
    }
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('ZIP gagal: php-zip belum aktif.');
    }
    $tmpZip = OUTPUT_DIR . 'tmp_' . $prefix . '.zip';
    $zip    = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('ZIP gagal.');
    }
    foreach ($files as $f) if (file_exists($f)) $zip->addFile($f, basename($f));
    $zip->close();
    if (!file_exists($tmpZip) || filesize($tmpZip) <= 0) {
        @unlink($tmpZip);
        http_response_code(500);
        exit('ZIP kosong.');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($tmpZip);
    @unlink($tmpZip);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['documents'])) {
    $files   = $_FILES['documents'];
    $quality = max(10, min(100, (int)($_POST['quality'] ?? 90)));
    $dpi     = max(72, min(300, (int)($_POST['dpi'] ?? 150)));

    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($files['size'][$i] > MAX_FILE_SIZE) {
            $error .= "File {$files['name'][$i]} terlalu besar (maks 50MB).<br>";
            continue;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $files['tmp_name'][$i]);
        finfo_close($finfo);

        if (!array_key_exists($mime, ALLOWED_TYPES)) {
            $fileExt = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            $extMap  = ['pdf' => 'pdf', 'doc' => 'doc', 'docx' => 'docx', 'ppt' => 'ppt', 'pptx' => 'pptx', 'xls' => 'xls', 'xlsx' => 'xlsx', 'png' => 'png', 'jpg' => 'jpg', 'jpeg' => 'jpg', 'gif' => 'gif', 'bmp' => 'bmp', 'tiff' => 'tiff', 'tif' => 'tiff', 'webp' => 'webp'];
            if (!array_key_exists($fileExt, $extMap)) {
                $error .= "❌ <b>{$files['name'][$i]}</b>: tipe file tidak didukung.<br>";
                continue;
            }
            $ext = $extMap[$fileExt];
        } else {
            $ext = ALLOWED_TYPES[$mime];
        }

        $filePrefix  = uniqid('doc_', true);
        $originName  = substr(preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($files['name'][$i], PATHINFO_FILENAME)), 0, 50);
        $uploadPath  = UPLOAD_DIR . $filePrefix . '.' . $ext;
        move_uploaded_file($files['tmp_name'][$i], $uploadPath);

        $outputFormat = in_array($_POST['output_format'] ?? 'jpg', ['jpg', 'png']) ? ($_POST['output_format'] ?? 'jpg') : 'jpg';
        $enableOcr    = isset($_POST['enable_ocr']) && $_POST['enable_ocr'] === '1';
        $ocrLang      = $_POST['ocr_lang'] ?? 'ind';
        $mergePages   = isset($_POST['merge_pages']) && $_POST['merge_pages'] === '1';

        $outputImages = convertToImage($uploadPath, $filePrefix, $quality, $dpi, $ext, $outputFormat, $originName);

        $ocrFile = null;
        if ($enableOcr && !empty($outputImages))
            $ocrFile = runOcr($outputImages, $originName ?: $filePrefix, $ocrLang);

        $mergedFile = null;
        if ($mergePages && count($outputImages) > 1)
            $mergedFile = mergeImages($outputImages, $originName ?: $filePrefix, $outputFormat, $quality);

        if (!empty($outputImages)) {
            $results[] = ['original' => $files['name'][$i], 'images' => $outputImages, 'count' => count($outputImages), 'prefix' => $filePrefix, 'origin_name' => $originName, 'output_format' => $outputFormat, 'ocr_file' => $ocrFile, 'merged_file' => $mergedFile];
        } else {
            $error .= "Gagal mengkonversi {$files['name'][$i]}.<br>";
        }
    }
    if (!empty($results)) $message = "Konversi berhasil!";

    $_SESSION['convert_results'] = $results;
    $_SESSION['convert_message'] = $message;
    $_SESSION['convert_error']   = $error;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
// Ambil hasil dari session setelah redirect
if (!empty($_SESSION['convert_results'])) {
    $results = $_SESSION['convert_results'];
    $message = $_SESSION['convert_message'] ?? '';
    $error   = $_SESSION['convert_error']   ?? '';
    unset($_SESSION['convert_results'], $_SESSION['convert_message'], $_SESSION['convert_error']);
}

function convertToImage($filePath, $filePrefix, $quality, $dpi, $ext, $outputFormat = 'jpg', $originName = '')
{
    $outputImages = [];
    $baseName     = $originName !== '' ? $originName : $filePrefix;
    $outputBase   = OUTPUT_DIR . $baseName;
    $imageTypes   = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'tiff', 'tif', 'webp'];
    $outExt       = $outputFormat === 'png' ? 'png' : 'jpg';

    if (in_array(strtolower($ext), $imageTypes)) {
        $outFile = $outputBase . '_page1.' . $outExt;
        exec(sprintf('convert %s -quality %d -background white -flatten %s 2>&1', escapeshellarg($filePath), $quality, escapeshellarg($outFile)), $log, $ret);
        if ($ret === 0 && file_exists($outFile)) $outputImages[] = basename($outFile);
    } elseif ($ext === 'pdf') {
        if ($outputFormat === 'png') {
            exec(sprintf('gs -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r%d -sOutputFile=%s %s 2>&1', $dpi, escapeshellarg($outputBase . '_page%d.png'), escapeshellarg($filePath)));
        } else {
            exec(sprintf('gs -dNOPAUSE -dBATCH -dSAFER -sDEVICE=jpeg -r%d -dJPEGQ=%d -sOutputFile=%s %s 2>&1', $dpi, $quality, escapeshellarg($outputBase . '_page%d.jpg'), escapeshellarg($filePath)));
        }
        $found = glob($outputBase . '_page*.' . $outExt);
        if ($found) {
            natsort($found);
            foreach ($found as $f) $outputImages[] = basename($f);
        }
    } else {
        $loProfile = sys_get_temp_dir() . '/lo_profile_' . $filePrefix;
        $tmpOut    = sys_get_temp_dir() . '/lo_out_' . $filePrefix;
        mkdir($loProfile, 0755, true);
        mkdir($tmpOut, 0755, true);
        exec(sprintf('HOME=%s libreoffice --headless --norestore --nofirststartwizard -env:UserInstallation=file://%s --convert-to pdf --outdir %s %s 2>&1', escapeshellarg(sys_get_temp_dir()), escapeshellarg($loProfile), escapeshellarg($tmpOut), escapeshellarg($filePath)));
        $pdfFiles = glob($tmpOut . '/*.pdf');
        if (empty($pdfFiles)) {
            $fb = OUTPUT_DIR . pathinfo($filePath, PATHINFO_FILENAME) . '.pdf';
            if (file_exists($fb)) $pdfFiles = [$fb];
        }
        if (!empty($pdfFiles)) {
            $pdfDest = $outputBase . '.pdf';
            if ($pdfFiles[0] !== $pdfDest) rename($pdfFiles[0], $pdfDest);
            if ($outputFormat === 'png') {
                exec(sprintf('gs -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r%d -sOutputFile=%s %s 2>&1', $dpi, escapeshellarg($outputBase . '_page%d.png'), escapeshellarg($pdfDest)));
            } else {
                exec(sprintf('gs -dNOPAUSE -dBATCH -dSAFER -sDEVICE=jpeg -r%d -dJPEGQ=%d -sOutputFile=%s %s 2>&1', $dpi, $quality, escapeshellarg($outputBase . '_page%d.jpg'), escapeshellarg($pdfDest)));
            }
            $found = glob($outputBase . '_page*.' . $outExt);
            if ($found) {
                natsort($found);
                foreach ($found as $f) $outputImages[] = basename($f);
            }
        }
        // Cleanup LibreOffice profile
        foreach (glob($loProfile . '/*') ?: [] as $item) {
            if (is_file($item)) {
                @unlink($item);
            } elseif (is_dir($item)) {
                exec('rm -rf ' . escapeshellarg($item));
            }
        }
        @rmdir($loProfile);

        // Cleanup temporary output
        foreach (glob($tmpOut . '/*') ?: [] as $item) {
            if (is_file($item)) {
                @unlink($item);
            } elseif (is_dir($item)) {
                exec('rm -rf ' . escapeshellarg($item));
            }
        }
        @rmdir($tmpOut);
    }
    return $outputImages;
}

function runOcr($imagePaths, $uniqueId, $lang = 'ind')
{
    $maxPages   = 5;
    $imagePaths = array_slice($imagePaths, 0, $maxPages);
    $outputBase = OUTPUT_DIR . $uniqueId . '_ocr';
    $allText    = '';
    foreach ($imagePaths as $img) {
        $imgPath  = OUTPUT_DIR . $img;
        $tmpOcr   = sys_get_temp_dir() . '/ocr_tmp_' . basename($img);
        exec(sprintf('convert %s -resize 1200x -quality 85 %s 2>&1', escapeshellarg($imgPath), escapeshellarg($tmpOcr)));
        $ocrTarget = file_exists($tmpOcr) ? $tmpOcr : $imgPath;
        $outBase   = $outputBase . '_' . pathinfo($img, PATHINFO_FILENAME);
        exec(sprintf('nice -n 19 tesseract %s %s -l %s --oem 1 --psm 3 2>&1', escapeshellarg($ocrTarget), escapeshellarg($outBase), escapeshellarg($lang)));
        @unlink($tmpOcr);
        $txtFile = $outBase . '.txt';
        if (file_exists($txtFile)) {
            $allText .= trim(file_get_contents($txtFile)) . "\n\n";
            @unlink($txtFile);
        }
    }
    $finalTxt = OUTPUT_DIR . $uniqueId . '_ocr.txt';
    file_put_contents($finalTxt, trim($allText));
    return file_exists($finalTxt) ? $uniqueId . '_ocr.txt' : null;
}

function mergeImages($imagePaths, $originName, $outputFormat = 'jpg', $quality = 90)
{
    if (count($imagePaths) <= 1) return null;
    $outExt   = $outputFormat === 'png' ? 'png' : 'jpg';
    $outFile  = OUTPUT_DIR . $originName . '_merged.' . $outExt;
    $inputs   = array_map(fn($img) => escapeshellarg(OUTPUT_DIR . $img), $imagePaths);
    exec(sprintf('convert %s -append -quality %d %s 2>&1', implode(' ', $inputs), $quality, escapeshellarg($outFile)), $log, $ret);
    return ($ret === 0 && file_exists($outFile)) ? $originName . '_merged.' . $outExt : null;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DocToIMG — Konverter Dokumen</title>
    <link rel="icon" type="image/png" href="logo.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #080b10;
            --bg2: #0e1219;
            --surface: #131720;
            --surface2: #1a1f2e;
            --surface3: #212840;
            --border: #252d42;
            --border2: #2e3855;
            --accent: #4f8ef7;
            --accent-g: #6c63ff;
            --amber: #f5c842;
            --green: #3ecf8e;
            --red: #f56565;
            --text: #e2e8f8;
            --text2: #8896b3;
            --text3: #555f7a;
            --radius: 14px;
            --radius-sm: 8px;
        }

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Space Grotesk', sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Subtle grid bg */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(79, 142, 247, .04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(79, 142, 247, .04) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none;
            z-index: 0;
        }

        /* Glow blobs */
        body::after {
            content: '';
            position: fixed;
            width: 600px;
            height: 600px;
            top: -200px;
            right: -200px;
            background: radial-gradient(circle, rgba(79, 142, 247, .08) 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        .container {
            position: relative;
            z-index: 1;
            max-width: 860px;
            margin: 0 auto;
            padding: 48px 24px 100px;
        }

        /* ── HEADER ── */
        header {
            text-align: center;
            margin-bottom: 52px;
            animation: fadeDown .5s ease both;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--surface2);
            border: 1px solid var(--border2);
            border-radius: 100px;
            padding: 5px 14px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            font-weight: 500;
            color: var(--accent);
            letter-spacing: .06em;
            margin-bottom: 22px;
        }

        .badge-dot {
            width: 6px;
            height: 6px;
            background: var(--green);
            border-radius: 50%;
            box-shadow: 0 0 6px var(--green);
            animation: blink 2.4s ease-in-out infinite;
        }

        h1 {
            font-size: clamp(2rem, 5.5vw, 3.4rem);
            font-weight: 700;
            line-height: 1.1;
            letter-spacing: -.02em;
            margin-bottom: 12px;
        }

        h1 .hi {
            color: var(--accent);
        }

        .subtitle {
            color: var(--text2);
            font-size: .95rem;
            font-weight: 400;
        }

        /* format pills */
        .format-pills {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 6px;
            margin-top: 18px;
        }

        .pill {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 3px 9px;
            font-size: 10px;
            font-family: 'JetBrains Mono', monospace;
            font-weight: 500;
            color: var(--text3);
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        /* ── ALERTS ── */
        .alert {
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            margin-bottom: 18px;
            font-size: .875rem;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            animation: fadeDown .3s ease;
        }

        .alert-success {
            background: rgba(62, 207, 142, .08);
            border: 1px solid rgba(62, 207, 142, .25);
            color: var(--green);
        }

        .alert-error {
            background: rgba(245, 101, 101, .08);
            border: 1px solid rgba(245, 101, 101, .25);
            color: var(--red);
        }

        /* ── CARD ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 32px;
            margin-bottom: 20px;
        }

        /* ── DROP ZONE ── */
        .drop-zone {
            border: 1.5px dashed var(--border2);
            border-radius: var(--radius);
            padding: 44px 24px;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            position: relative;
        }

        .drop-zone:hover,
        .drop-zone.dragging {
            border-color: var(--accent);
            background: rgba(79, 142, 247, .04);
        }

        .drop-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
            z-index: 10;
        }

        .drop-icon {
            font-size: 2.8rem;
            margin-bottom: 14px;
            display: block;
            animation: float 3.5s ease-in-out infinite;
        }

        .drop-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .drop-sub {
            color: var(--text2);
            font-size: .82rem;
            font-family: 'JetBrains Mono', monospace;
            line-height: 1.6;
        }

        /* ── FILE LIST ── */
        #fileList {
            margin-top: 14px;
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .file-item {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 9px 13px;
            animation: slideIn .18s ease;
        }

        .file-item-icon {
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        .file-item-name {
            font-size: .85rem;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .file-item-size {
            font-size: .72rem;
            color: var(--text3);
            font-family: 'JetBrains Mono', monospace;
            flex-shrink: 0;
        }

        .file-remove {
            background: none;
            border: none;
            color: var(--text3);
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 5px;
            font-size: .9rem;
            transition: color .15s, background .15s;
            flex-shrink: 0;
        }

        .file-remove:hover {
            color: var(--red);
            background: rgba(245, 101, 101, .1);
        }

        /* ── SETTINGS GRID ── */
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 24px;
        }

        /* ── SETTING BLOCK ── */
        .setting-block {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .setting-label {
            font-size: .72rem;
            font-family: 'JetBrains Mono', monospace;
            font-weight: 500;
            color: var(--text3);
            text-transform: uppercase;
            letter-spacing: .08em;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .setting-label .val {
            color: var(--accent);
            font-weight: 600;
        }

        /* Range slider */
        input[type="range"] {
            -webkit-appearance: none;
            width: 100%;
            height: 3px;
            background: var(--border2);
            border-radius: 2px;
            outline: none;
        }

        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            background: var(--accent);
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 0 0 3px rgba(79, 142, 247, .2);
            transition: transform .15s, box-shadow .15s;
        }

        input[type="range"]::-webkit-slider-thumb:hover {
            transform: scale(1.25);
            box-shadow: 0 0 0 5px rgba(79, 142, 247, .25);
        }

        /* Divider */
        .divider {
            height: 1px;
            background: var(--border);
            margin: 22px 0;
        }

        /* ── TOGGLE OPTIONS ROW ── */
        .options-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .option-card {
            background: var(--surface2);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .option-card:hover {
            border-color: var(--border2);
        }

        .option-card.active {
            border-color: var(--accent);
            background: rgba(79, 142, 247, .06);
        }

        .option-title {
            font-size: .82rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .option-desc {
            font-size: .73rem;
            color: var(--text2);
            line-height: 1.5;
        }

        /* Format toggle */
        .fmt-toggle {
            display: flex;
            gap: 8px;
        }

        .fmt-btn {
            flex: 1;
            padding: 8px;
            background: var(--surface3);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            color: var(--text2);
            font-family: 'JetBrains Mono', monospace;
            font-size: .8rem;
            font-weight: 500;
            cursor: pointer;
            text-align: center;
            transition: all .18s;
        }

        .fmt-btn:hover {
            border-color: var(--border2);
            color: var(--text);
        }

        .fmt-btn.active {
            background: rgba(79, 142, 247, .12);
            border-color: var(--accent);
            color: var(--accent);
        }

        /* Checkbox styling */
        .check-row {
            display: flex;
            align-items: center;
            gap: 9px;
            cursor: pointer;
        }

        .check-row input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--accent);
            flex-shrink: 0;
            cursor: pointer;
        }

        .check-row span {
            font-size: .85rem;
            font-weight: 500;
            color: var(--text);
        }

        /* Select */
        .styled-select {
            background: var(--surface3);
            border: 1px solid var(--border2);
            color: var(--text);
            border-radius: var(--radius-sm);
            padding: 7px 10px;
            font-family: 'JetBrains Mono', monospace;
            font-size: .78rem;
            width: 100%;
            outline: none;
            cursor: pointer;
        }

        /* OCR warning */
        .ocr-warn {
            background: rgba(245, 200, 66, .06);
            border: 1px solid rgba(245, 200, 66, .2);
            border-radius: 6px;
            padding: 8px 12px;
            font-size: .75rem;
            color: #c9a830;
            line-height: 1.5;
            margin-top: 8px;
        }

        /* ── SUBMIT BUTTON ── */
        .btn-convert {
            width: 100%;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-g) 100%);
            color: #fff;
            border: none;
            border-radius: var(--radius);
            padding: 16px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 24px;
            letter-spacing: .02em;
            transition: opacity .2s, transform .2s, box-shadow .2s;
            box-shadow: 0 4px 20px rgba(79, 142, 247, .25);
        }

        .btn-convert:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(79, 142, 247, .38);
        }

        .btn-convert:active {
            transform: translateY(0);
        }

        .btn-convert.loading {
            opacity: .5;
            pointer-events: none;
        }

        /* ── RESULTS ── */
        .results-section {
            animation: fadeUp .4s ease both;
        }

        .results-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .results-title {
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .results-count {
            font-family: 'JetBrains Mono', monospace;
            font-size: .75rem;
            color: var(--text2);
            background: var(--surface2);
            border: 1px solid var(--border);
            padding: 3px 10px;
            border-radius: 100px;
        }

        .doc-result {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 18px;
        }

        .doc-result-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .doc-icon {
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .doc-name {
            font-weight: 600;
            font-size: .9rem;
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .page-badge {
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 100px;
            padding: 3px 10px;
            color: var(--text2);
            flex-shrink: 0;
        }

        /* Download buttons */
        .dl-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .btn-dl {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 8px;
            padding: 7px 13px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: .78rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all .18s;
            white-space: nowrap;
            border: 1.5px solid;
        }

        .btn-dl-primary {
            background: rgba(79, 142, 247, .1);
            border-color: var(--accent);
            color: var(--accent);
        }

        .btn-dl-primary:hover {
            background: var(--accent);
            color: #fff;
        }

        .btn-dl-orange {
            background: rgba(245, 200, 66, .08);
            border-color: var(--amber);
            color: var(--amber);
        }

        .btn-dl-orange:hover {
            background: var(--amber);
            color: #111;
        }

        .btn-dl-green {
            background: rgba(62, 207, 142, .08);
            border-color: var(--green);
            color: var(--green);
        }

        .btn-dl-green:hover {
            background: var(--green);
            color: #0e1219;
        }

        /* Images grid */
        .images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
        }

        .image-card {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            transition: border-color .2s, transform .2s, box-shadow .2s;
            display: flex;
            flex-direction: column;
        }

        .image-card:hover {
            border-color: var(--accent);
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .4);
        }

        .image-thumb-wrap {
            position: relative;
            overflow: hidden;
        }

        .image-thumb {
            width: 100%;
            aspect-ratio: 3/4;
            object-fit: cover;
            display: block;
            transition: transform .2s;
        }

        .image-card:hover .image-thumb {
            transform: scale(1.04);
        }

        .image-overlay {
            position: absolute;
            inset: 0;
            background: rgba(8, 11, 16, .6);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity .18s;
        }

        .image-card:hover .image-overlay {
            opacity: 1;
        }

        .overlay-dl {
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 7px;
            padding: 7px 14px;
            font-family: 'Space Grotesk', sans-serif;
            font-size: .78rem;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: transform .12s;
        }

        .overlay-dl:hover {
            transform: scale(1.05);
        }

        .image-footer {
            padding: 7px 9px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .image-label {
            font-family: 'JetBrains Mono', monospace;
            font-size: 10px;
            color: var(--text3);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
        }

        .img-dl-btn {
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            background: var(--surface3);
            border-radius: 5px;
            color: var(--accent);
            text-decoration: none;
            font-size: 13px;
            transition: all .15s;
            margin-left: 5px;
        }

        .img-dl-btn:hover {
            background: var(--accent);
            color: #fff;
            transform: scale(1.1);
        }

        /* OCR result box */
        .ocr-box {
            margin-top: 14px;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .ocr-box-header {
            padding: 11px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
            background: var(--surface3);
        }

        .ocr-box-title {
            font-size: .82rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .ocr-box pre {
            font-family: 'JetBrains Mono', monospace;
            font-size: .73rem;
            color: var(--text2);
            white-space: pre-wrap;
            max-height: 180px;
            overflow-y: auto;
            line-height: 1.65;
            padding: 14px 16px;
        }

        /* ── ANIMATIONS ── */
        @keyframes fadeDown {
            from {
                opacity: 0;
                transform: translateY(-12px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(14px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-8px)
            }

            to {
                opacity: 1;
                transform: translateX(0)
            }
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0)
            }

            50% {
                transform: translateY(-7px)
            }
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .2
            }
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 600px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }

            .options-row {
                grid-template-columns: 1fr;
            }

            .images-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .doc-result-header {
                gap: 8px;
            }

            .card {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
    <div class="container">

        <!-- HEADER -->
        <header>
            <div class="badge"><span class="badge-dot"></span> DOC TO IMAGE CONVERTER</div>
            <h1>Konversi <span class="hi">Dokumen</span><br>ke Gambar Instan</h1>
            <p class="subtitle">PDF, Word, Excel, PowerPoint — semua jadi JPG/PNG dalam hitungan detik</p>
            <div class="format-pills">
                <span class="pill">PDF</span><span class="pill">DOCX</span><span class="pill">DOC</span>
                <span class="pill">PPTX</span><span class="pill">PPT</span><span class="pill">XLSX</span>
                <span class="pill">XLS</span><span class="pill">PNG</span><span class="pill">JPG</span>
                <span class="pill">TIFF</span><span class="pill">BMP</span><span class="pill">WEBP</span>
            </div>
        </header>

        <!-- ALERTS -->
        <?php if ($message): ?>
            <div class="alert alert-success">
                <span>✓</span>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <span>⚠</span>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <!-- UPLOAD CARD -->
        <div class="card">
            <form method="POST" enctype="multipart/form-data" id="convertForm">

                <!-- Drop zone -->
                <div class="drop-zone" id="dropZone">
                    <input type="file" name="documents[]" id="fileInput" multiple
                        accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.bmp,.tiff,.webp">
                    <span class="drop-icon">📂</span>
                    <div class="drop-title">Seret file ke sini atau klik untuk pilih</div>
                    <div class="drop-sub">Maks 50MB per file · bisa pilih beberapa sekaligus</div>
                    <div class="drop-sub" style="margin-top:4px;">Bisa juga untuk resize foto biar ukurannya lebih kecil</div>
                </div>

                <!-- File list preview -->
                <div id="fileList"></div>

                <div class="divider"></div>

                <!-- Sliders -->
                <div class="settings-grid">
                    <div class="setting-block">
                        <div class="setting-label">
                            <span>Kualitas</span>
                            <span class="val" id="qualityVal">90%</span>
                        </div>
                        <input type="range" name="quality" id="qualitySlider" min="10" max="100" value="90">
                        <div style="font-size:.72rem;color:var(--text3);">Makin tinggi = file makin besar</div>
                    </div>
                    <div class="setting-block">
                        <div class="setting-label">
                            <span>Resolusi</span>
                            <span class="val" id="dpiVal">150 DPI</span>
                        </div>
                        <input type="range" name="dpi" id="dpiSlider" min="72" max="300" value="150" step="6">
                        <div style="font-size:.72rem;color:var(--text3);">Makin tinggi = makin tajam & besar</div>
                    </div>
                </div>

                <div class="divider"></div>

                <!-- Options -->
                <div class="options-row">

                    <!-- Format output -->
                    <div class="setting-block">
                        <div class="setting-label"><span>Format Output</span></div>
                        <div class="fmt-toggle" id="fmtToggle">
                            <div class="fmt-btn active" data-val="jpg">JPG</div>
                            <div class="fmt-btn" data-val="png">PNG</div>
                        </div>
                        <input type="hidden" name="output_format" id="outputFormat" value="jpg">
                        <div style="font-size:.72rem;color:var(--text3);margin-top:4px;">JPG = lebih kecil · PNG = transparan</div>
                    </div>

                    <!-- Gabung halaman -->
                    <div class="setting-block">
                        <div class="setting-label"><span>Gabung Halaman</span></div>
                        <label class="check-row" style="margin-top:2px;">
                            <input type="checkbox" name="merge_pages" value="1" id="mergePages">
                            <span>Jadikan 1 foto utuh (vertikal)</span>
                        </label>
                        <div style="font-size:.72rem;color:var(--text3);margin-top:4px;">Semua halaman digabung jadi satu gambar panjang</div>
                    </div>

                    <!-- OCR -->
                    <div class="setting-block" style="grid-column: span 2;">
                        <div class="setting-label"><span>OCR — Ekstrak Teks dari Gambar</span></div>
                        <label class="check-row">
                            <input type="checkbox" name="enable_ocr" value="1" id="enableOcr">
                            <span>Aktifkan OCR</span>
                        </label>
                        <div id="ocrLangWrap" style="display:none;margin-top:10px;">
                            <select name="ocr_lang" class="styled-select">
                                <option value="ind">🇮🇩 Indonesia</option>
                                <option value="eng">🇬🇧 English</option>
                                <option value="ind+eng">🌐 Indonesia + English</option>
                            </select>
                        </div>
                        <div class="ocr-warn" style="display:none;" id="ocrWarn">
                            ⚠ OCR cukup berat di server. Disarankan hanya untuk dokumen dengan sedikit teks, dan max 5 halaman pertama yang diproses.
                        </div>
                    </div>

                </div>

                <button type="submit" class="btn-convert" id="submitBtn">
                    ⚡&nbsp; Konversi Sekarang
                </button>

            </form>
        </div>

        <!-- RESULTS -->
        <?php if (!empty($results)): ?>
            <div class="results-section" id="hasil">
                <div class="results-header">
                    <div class="results-title">🖼 Hasil Konversi</div>
                    <span class="results-count"><?= count($results) ?> file diproses</span>
                </div>

                <?php foreach ($results as $doc): ?>
                    <div class="doc-result">
                        <div class="doc-result-header">
                            <span class="doc-icon">📁</span>
                            <div class="doc-name"><?= htmlspecialchars($doc['original']) ?></div>
                            <span class="page-badge"><?= $doc['count'] ?> hal</span>
                            <div class="dl-group">
                                <?php if ($doc['count'] > 1): ?>
                                    <a href="?download_zip=1&prefix=<?= urlencode($doc['origin_name']) ?>" class="btn-dl btn-dl-primary">
                                        📦 ZIP Semua
                                    </a>
                                <?php else: ?>
                                    <a href="output/<?= urlencode($doc['images'][0]) ?>"
                                        download="<?= htmlspecialchars($doc['origin_name'] . '.' . $doc['output_format']) ?>"
                                        class="btn-dl btn-dl-primary">
                                        ⬇ Download
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($doc['merged_file'])): ?>
                                    <a href="output/<?= urlencode($doc['merged_file']) ?>"
                                        download="<?= htmlspecialchars($doc['merged_file']) ?>"
                                        class="btn-dl btn-dl-orange">
                                        🖼 1 Foto Utuh
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($doc['ocr_file'])): ?>
                                    <a href="output/<?= urlencode($doc['ocr_file']) ?>"
                                        download="<?= htmlspecialchars($doc['ocr_file']) ?>"
                                        class="btn-dl btn-dl-green">
                                        📝 TXT OCR
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="images-grid">
                            <?php foreach ($doc['images'] as $idx => $img): ?>
                                <?php $dlName = $doc['origin_name'] . '_hal' . ($idx + 1) . '.' . $doc['output_format']; ?>
                                <div class="image-card">
                                    <div class="image-thumb-wrap">
                                        <img src="output/<?= urlencode($img) ?>" class="image-thumb" loading="lazy" alt="Halaman <?= $idx + 1 ?>">
                                        <div class="image-overlay">
                                            <a href="output/<?= urlencode($img) ?>" download="<?= htmlspecialchars($dlName) ?>" class="overlay-dl">
                                                ⬇ Download
                                            </a>
                                        </div>
                                    </div>
                                    <div class="image-footer">
                                        <span class="image-label">Hal. <?= $idx + 1 ?></span>
                                        <a href="output/<?= urlencode($img) ?>" download="<?= htmlspecialchars($dlName) ?>" class="img-dl-btn">⬇</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($doc['ocr_file'])): ?>
                            <div class="ocr-box">
                                <div class="ocr-box-header">
                                    <div class="ocr-box-title">📝 Hasil OCR</div>
                                </div>
                                <pre><?= htmlspecialchars(file_get_contents(OUTPUT_DIR . $doc['ocr_file'])) ?></pre>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div><!-- /container -->

    <!-- FOOTER -->
    <footer style="
        position: relative;
        z-index: 1;
        text-align: center;
        padding: 20px 24px 36px;
        font-size: .8rem;
        color: var(--text3);
        font-family: 'JetBrains Mono', monospace;
        letter-spacing: .04em;
    ">
        made with <span style="color: #f56565;">❤</span> by Rifki hehe
    </footer>

    <script>
        // Sliders
        const qualitySlider = document.getElementById('qualitySlider');
        const dpiSlider = document.getElementById('dpiSlider');
        qualitySlider.addEventListener('input', () => document.getElementById('qualityVal').textContent = qualitySlider.value + '%');
        dpiSlider.addEventListener('input', () => document.getElementById('dpiVal').textContent = dpiSlider.value + ' DPI');

        // Format toggle
        document.querySelectorAll('.fmt-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.fmt-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById('outputFormat').value = btn.dataset.val;
            });
        });

        // File list
        const fileInput = document.getElementById('fileInput');
        const fileListEl = document.getElementById('fileList');
        let selectedFiles = new DataTransfer();

        function formatSize(b) {
            if (b < 1024) return b + ' B';
            if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
            return (b / 1048576).toFixed(1) + ' MB';
        }

        function getIcon(name) {
            const e = name.split('.').pop().toLowerCase();
            return {
                pdf: '📕',
                doc: '📘',
                docx: '📘',
                ppt: '📙',
                pptx: '📙',
                xls: '📗',
                xlsx: '📗',
                png: '🖼',
                jpg: '🖼',
                jpeg: '🖼',
                gif: '🖼',
                bmp: '🖼',
                tiff: '🖼',
                webp: '🖼'
            } [e] || '📄';
        }

        function renderFileList() {
            fileListEl.innerHTML = '';
            Array.from(selectedFiles.files).forEach((f, i) => {
                const d = document.createElement('div');
                d.className = 'file-item';
                d.innerHTML = `<span class="file-item-icon">${getIcon(f.name)}</span>
            <span class="file-item-name">${f.name}</span>
            <span class="file-item-size">${formatSize(f.size)}</span>
            <button class="file-remove" data-i="${i}" type="button">✕</button>`;
                fileListEl.appendChild(d);
            });
            fileInput.files = selectedFiles.files;
        }
        fileInput.addEventListener('change', () => {
            Array.from(fileInput.files).forEach(f => selectedFiles.items.add(f));
            renderFileList();
        });
        fileListEl.addEventListener('click', e => {
            if (e.target.classList.contains('file-remove')) {
                const idx = +e.target.dataset.i;
                const dt = new DataTransfer();
                Array.from(selectedFiles.files).forEach((f, i) => {
                    if (i !== idx) dt.items.add(f);
                });
                selectedFiles = dt;
                renderFileList();
            }
        });

        // Drag & drop
        const dropZone = document.getElementById('dropZone');
        ['dragenter', 'dragover'].forEach(ev => dropZone.addEventListener(ev, e => {
            e.preventDefault();
            dropZone.classList.add('dragging');
        }));
        ['dragleave', 'drop'].forEach(ev => dropZone.addEventListener(ev, e => {
            e.preventDefault();
            dropZone.classList.remove('dragging');
        }));
        dropZone.addEventListener('drop', e => {
            Array.from(e.dataTransfer.files).forEach(f => selectedFiles.items.add(f));
            renderFileList();
        });

        // OCR toggle
        document.getElementById('enableOcr').addEventListener('change', function() {
            document.getElementById('ocrLangWrap').style.display = this.checked ? 'block' : 'none';
            document.getElementById('ocrWarn').style.display = this.checked ? 'block' : 'none';
        });

        // Submit → loading + scroll
        document.getElementById('convertForm').addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            btn.textContent = '⏳  Memproses...';
            btn.classList.add('loading');
            sessionStorage.setItem('scrollToResult', '1');
        });

        // Auto-scroll ke hasil
        window.addEventListener('DOMContentLoaded', () => {
            if (sessionStorage.getItem('scrollToResult') === '1') {
                sessionStorage.removeItem('scrollToResult');
                const el = document.getElementById('hasil');
                if (el) setTimeout(() => el.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                }), 150);
            }
        });
    </script>
</body>

</html>