<?php
/**
 * Public Links Plugin - Standalone
 * All links stored in a single directory
 */

class PubliclinksPlugin {
    private $config;
    private $linksDir;
    
    // FILE PATH OF THE JSON FILE (MUST BE THE SAME OF THE DOWNLOAD.PHP FILE)
    const PUBLIC_LINKS_DIR = '/files/public_links';
    
    public function __construct($config) {
        $this->config = $config;
        
        // Initialize links directory
        $this->linksDir = $this->initDirectory();
        
        //error_log("✅ PublicLinks plugin initialized. Directory: {$this->linksDir}");
    }
    
    /**
     * Initialize public links directory
     */
    private function initDirectory() {
        $dir = self::PUBLIC_LINKS_DIR;
        
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
            
            // Proteggi da accessi HTTP diretti
            $htaccessContent = <<<HTACCESS
# block http access to json files
<FilesMatch "\.json$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# no directory listing
Options -Indexes
HTACCESS;
            
            file_put_contents($dir . '/.htaccess', $htaccessContent);
            error_log("✅ Created public links directory: {$dir}");
        }
        
        return $dir;
    }
    
    /**
     * Find link file by token
     */
    private function findLinkFile($token) {
        $linkFile = $this->linksDir . '/' . $token . '.json';
        return file_exists($linkFile) ? $linkFile : null;
    }
    
    /**
     * Handle plugin commands
     */
    public function handleCommand($cmd, $params, $user) {
        switch ($cmd) {
            case 'publiclink_create':
                return $this->createLink($params, $user);
            case 'publiclink_list':
                return $this->listLinks($user);
            case 'publiclink_delete':
                return $this->deleteLink($params, $user);
            default:
                throw new Exception('Unknown publiclinks command', 400);
        }
    }
    
    /**
     * Create new public link
     */
    private function createLink($params, $user) {
        $fileHash = $params['file_hash'] ?? '';
        $fileName = $params['file_name'] ?? '';
        $fileSize = intval($params['file_size'] ?? 0);
        $linkType = $params['link_type'] ?? 'public';
        $expirationMinutes = intval($params['expiration_minutes'] ?? 30);
        $waitSeconds = intval($params['wait_seconds'] ?? 30);
        $maxDownloads = intval($params['max_downloads'] ?? 0);
        
        if (empty($fileHash) || empty($fileName)) {
            throw new Exception('Invalid file data', 400);
        }
        
        // Generate secure unique token (64 chars)
        $token = bin2hex(random_bytes(32));
        $createdAt = time();
        $expiresAt = $createdAt + ($expirationMinutes * 60);
        
        // Link data
        $linkData = [
            'token' => $token,
            'user_id' => $user['id'],
            'user_name' => $user['username'] ?? 'Unknown',
            'file_hash' => $fileHash,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'link_type' => $linkType,
            'wait_seconds' => $waitSeconds,
            'max_downloads' => $maxDownloads,
            'download_count' => 0,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
            'last_download_at' => null,
            'root_path' => $this->config['rootPath']
        ];
        
        // Save link as JSON file
        $linkFile = $this->linksDir . '/' . $token . '.json';
        file_put_contents($linkFile, json_encode($linkData, JSON_PRETTY_PRINT));
        
        error_log("✅ Created link: {$token} for file: {$fileName}");
        
        // Clean expired links
        $this->cleanExpiredLinks();
        
        return [
            'success' => true,
            'token' => $token,
            'download_url' => '/download.php?t=' . $token,
            'expires_at' => $expiresAt
        ];
    }
    
    /**
     * List user's links
     */
    private function listLinks($user) {
        $links = [];
        
        if (!is_dir($this->linksDir)) {
            return ['success' => true, 'links' => []];
        }
        
        $files = glob($this->linksDir . '/*.json');
        
        foreach ($files as $file) {
            $linkData = json_decode(file_get_contents($file), true);
            
            // Skip links from other users
            if ($linkData['user_id'] !== $user['id']) {
                continue;
            }
            
            // Skip expired links
            if ($linkData['expires_at'] < time()) {
                unlink($file); // Auto-cleanup
                continue;
            }
            
            $links[] = $linkData;
        }
        
        // Sort by creation date (newest first)
        usort($links, function($a, $b) {
            return $b['created_at'] - $a['created_at'];
        });
        
        return ['success' => true, 'links' => $links];
    }
    
    /**
     * Delete a link
     */
    private function deleteLink($params, $user) {
        $token = $params['link_token'] ?? '';
        
        if (empty($token)) {
            throw new Exception('Invalid token', 400);
        }
        
        $linkFile = $this->findLinkFile($token);
        
        if (!$linkFile) {
            throw new Exception('Link not found', 404);
        }
        
        // Verify ownership
        $linkData = json_decode(file_get_contents($linkFile), true);
        if ($linkData['user_id'] !== $user['id']) {
            throw new Exception('Permission denied', 403);
        }
        
        unlink($linkFile);
        
        error_log("✅ Deleted link: {$token}");
        
        return ['success' => true];
    }
    
    /**
     * Clean expired links
     */
    private function cleanExpiredLinks() {
        if (!is_dir($this->linksDir)) {
            return;
        }
        
        $files = glob($this->linksDir . '/*.json');
        $now = time();
        $cleaned = 0;
        
        foreach ($files as $file) {
            $linkData = json_decode(file_get_contents($file), true);
            if ($linkData && $linkData['expires_at'] < $now) {
                unlink($file);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            error_log("🧹 Cleaned {$cleaned} expired links");
        }
    }
}