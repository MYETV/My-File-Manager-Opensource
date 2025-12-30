<?php
/**
 * Public Download Page - Standalone
 * No external dependencies
 */

// FILE PATH OF THE JSON FILE (MUST BE THE SAME OF THE PLUGIN)
define('PUBLIC_LINKS_DIR', '/files/public_links');

// DATE/TIME TIMEZONE
define('DISPLAY_TIMEZONE', 'UTC');

// Start session
session_start();

/**
 * Find link file by token
 */
function findLinkFile($token) {
    $linkFile = PUBLIC_LINKS_DIR . '/' . $token . '.json';
    return file_exists($linkFile) ? $linkFile : null;
}

/**
 * Load link data by token
 */
function loadLinkData($token) {
    $linkFile = findLinkFile($token);
    
    if (!$linkFile) {
        error_log("❌ Token not found: {$token}");
        return null;
    }
    
    error_log("✅ Found link file: {$linkFile}");
    
    if (!is_readable($linkFile)) {
        error_log("❌ Link file not readable");
        return null;
    }
    
    $content = file_get_contents($linkFile);
    if ($content === false) {
        error_log("❌ Failed to read file");
        return null;
    }
    
    $data = json_decode($content, true);
    if ($data === null) {
        error_log("❌ JSON decode error: " . json_last_error_msg());
        return null;
    }
    
    return $data;
}

/**
 * Increment download counter
 */
function incrementDownload($token, $linkData) {
    $linkFile = findLinkFile($token);
    
    if (!$linkFile) {
        error_log("❌ Cannot increment: file not found");
        return false;
    }
    
    $linkData['download_count']++;
    $linkData['last_download_at'] = time();
    
    $result = file_put_contents($linkFile, json_encode($linkData, JSON_PRETTY_PRINT));
    
    if ($result === false) {
        error_log("❌ Failed to update download count");
        return false;
    }
    
    error_log("✅ Download count incremented to: " . $linkData['download_count']);
    return true;
}

/**
 * Check if user is authenticated (for registered-only links)
 */
function isUserAuthenticated() {
    // This is a placeholder. Replace with your authentication check, if needed.
    return isset($_SESSION['user_id']) || 
           isset($_SESSION['logged_in']) || 
           isset($_COOKIE['auth_token']);
}

// ============================================================================
// MAIN LOGIC
// ============================================================================

$token = $_GET['t'] ?? '';
$action = $_GET['action'] ?? 'view';

if (empty($token)) {
    die('❌ Invalid download link');
}

// Load link data
$link = loadLinkData($token);

if (!$link) {
    die('❌ Link not found or expired');
}

// Check expiration
if ($link['expires_at'] < time()) {
    die('❌ Link has expired on ' . date('Y-m-d H:i:s', $link['expires_at']));
}

// Check max downloads
if ($link['max_downloads'] > 0 && $link['download_count'] >= $link['max_downloads']) {
    die('❌ Download limit reached (' . $link['max_downloads'] . ' downloads)');
}

// Check if registered users only
if ($link['link_type'] === 'registered') {
    if (!isUserAuthenticated()) {
        die('🔒 This link requires login. Please <a href="/login.php">login</a> first.');
    }
}

// ============================================================================
// DOWNLOAD ACTION
// ============================================================================

if ($action === 'download') {
    // Verify wait time has passed
    $waitKey = 'wait_' . $token;
    if (!isset($_SESSION[$waitKey]) || (time() - $_SESSION[$waitKey]) < $link['wait_seconds']) {
        die('⏳ Please wait the required time before downloading');
    }
    
    // Construct file path
    $fileHash = $link['file_hash'];
    $filePath = base64_decode($fileHash);
    $rootPath = rtrim($link['root_path'], '/');
    $fullPath = $rootPath . '/' . $filePath;
    
    error_log("📁 Attempting to download: {$fullPath}");
    
    if (!file_exists($fullPath) || !is_file($fullPath)) {
        error_log("❌ File not found: {$fullPath}");
        die('❌ File not found on server');
    }
    
    // Increment download counter
    incrementDownload($token, $link);
    
    // Clean all output buffers
    while (ob_get_level()) ob_end_clean();
    
    // Disable compression
    @ini_set('zlib.output_compression', 0);
    @ini_set('memory_limit', '-1');
    @ini_set('max_execution_time', '0');
    
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', 1);
    }
    
    // Set headers
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($link['file_name']) . '"');
    header('Content-Length: ' . filesize($fullPath));
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Stream file
    $fp = fopen($fullPath, 'rb');
    if ($fp === false) {
        die('❌ Cannot open file');
    }
    
    while (!feof($fp)) {
        echo fread($fp, 8192);
        flush();
    }
    fclose($fp);
    
    error_log("✅ Download completed for: {$link['file_name']}");
    exit;
}

// ============================================================================
// WAIT PAGE
// ============================================================================

$_SESSION['wait_' . $token] = time();

// Reload link data
$link = loadLinkData($token);

$expiresIn = $link['expires_at'] - time();
$expiresMinutes = ceil($expiresIn / 60);
$fileSizeMB = number_format($link['file_size'] / 1024 / 1024, 2);

// Format expiration date
$timezone = DISPLAY_TIMEZONE;
$expiresDate = new DateTime('@' . $link['expires_at']);
$expiresDate->setTimezone(new DateTimeZone($timezone));
$expiresFormatted = $expiresDate->format('Y-m-d H:i');

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Download: <?= htmlspecialchars($link['file_name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #daa520 0%, #b8860b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            max-width: 600px;
            width: 100%;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 { color: #333; margin-bottom: 10px; font-size: 28px; }
        .file-info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 25px 0;
            border-left: 4px solid #daa520;
        }
        .file-info div { margin: 8px 0; color: #555; }
        .file-info strong { color: #333; }
        .timer {
            font-size: 72px;
            color: #daa520;
            text-align: center;
            margin: 40px 0;
            font-weight: bold;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }
        .message { text-align: center; color: #666; margin: 20px 0; font-size: 16px; }
        .download-btn {
            display: block;
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #daa520 0%, #b8860b 100%);
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: bold;
            margin-top: 20px;
            transition: transform 0.2s;
            box-shadow: 0 4px 15px rgba(218, 165, 32, 0.4);
        }
        .download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(218, 165, 32, 0.6);
            background: linear-gradient(135deg, #b8860b 0%, #daa520 100%);
        }
        .ad-container {
            margin: 30px 0;
            padding: 20px;
            background: #fff;
            border: 2px dashed #daa520;
            border-radius: 10px;
            text-align: center;
            min-height: 250px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #daa520 0%, #b8860b 100%);
            width: 0%;
            transition: width 1s linear;
        }
        .footer { 
            text-align: center; 
            margin-top: 20px; 
            color: #666; 
            font-size: 12px; 
        }
        .footer .timezone {
            color: #999;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📥 Download File</h1>
        
        <div class="file-info">
            <div><strong>📄 File:</strong> <?= htmlspecialchars($link['file_name']) ?></div>
            <div><strong>💾 Size:</strong> <?= $fileSizeMB ?> MB</div>
            <div><strong>📊 Downloads:</strong> <?= $link['download_count'] ?><?= $link['max_downloads'] > 0 ? ' / ' . $link['max_downloads'] : '' ?></div>
            <div><strong>⏰ Expires in:</strong> <?= $expiresMinutes ?> minutes</div>
        </div>
        
        <p class="message">⏳ Please wait while we prepare your download...</p>
        
        <div class="progress-bar">
            <div class="progress-fill" id="progress"></div>
        </div>
        
        <div class="timer" id="timer"><?= $link['wait_seconds'] ?></div>
        
        <a href="?t=<?= htmlspecialchars($token) ?>&action=download" 
           class="download-btn" 
           id="download-btn" 
           style="display: none;">
            ⬇️ Download Now
        </a>
        
        <div class="footer">
            Secure download • Expires <strong><?= $expiresFormatted ?></strong> <span class="timezone">(<?= $timezone ?>)</span>
        </div>
    </div>
    
    <script>
        let timeLeft = <?= $link['wait_seconds'] ?>;
        const totalTime = timeLeft;
        const timerEl = document.getElementById('timer');
        const downloadBtn = document.getElementById('download-btn');
        const progressFill = document.getElementById('progress');
        
        const countdown = setInterval(() => {
            timeLeft--;
            timerEl.textContent = timeLeft;
            progressFill.style.width = ((totalTime - timeLeft) / totalTime * 100) + '%';
            
            if (timeLeft <= 0) {
                clearInterval(countdown);
                timerEl.textContent = '✅';
                timerEl.style.color = '#28a745';
                progressFill.style.width = '100%';
                downloadBtn.style.display = 'block';
                document.querySelector('.message').textContent = '✅ Ready to download!';
            }
        }, 1000);
    </script>
</body>
</html>