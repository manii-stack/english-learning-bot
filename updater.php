<?php
/**
 * One-Click In-App Updater for English Learning Telegram Bot
 * Safely fetches latest releases from GitHub, applies updates without losing admin configurations,
 * and handles rollback backups.
 *
 * @author Mohammadreza Sarmadi
 */

if (!defined('BOT_APP')) {
    define('BOT_APP', true);
}

if (!defined('BOT_VERSION')) {
    define('BOT_VERSION', '1.0.0');
}

require_once __DIR__ . '/settings.php';

class AppUpdater
{
    const REPO_OWNER = 'manii-stack';
    const REPO_NAME = 'english-learning-bot';
    const VERSION_FILE_URL = 'https://raw.githubusercontent.com/manii-stack/english-learning-bot/main/version.json';
    const ARCHIVE_URL = 'https://github.com/manii-stack/english-learning-bot/archive/refs/heads/main.zip';

    /**
     * Check if a new version is available on GitHub
     *
     * @param bool $force Force fresh network check ignoring cache
     * @return array
     */
    public static function checkForUpdate($force = false)
    {
        $cached = SettingsManager::get('update_cache', null);
        $lastChecked = SettingsManager::get('update_last_checked', 0);
        $cacheDuration = 3600; // 1 hour

        if (!$force && $cached && (time() - $lastChecked < $cacheDuration)) {
            return $cached;
        }

        $res = [
            'has_update' => false,
            'current_version' => BOT_VERSION,
            'latest_version' => BOT_VERSION,
            'changelog' => '',
            'release_date' => '',
            'download_url' => self::ARCHIVE_URL,
            'last_checked' => time(),
            'error' => null
        ];

        // Fetch version info from raw GitHub version.json
        $ch = curl_init(self::VERSION_FILE_URL . '?t=' . time());
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'EnglishLearningBot-Updater/' . BOT_VERSION
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        @curl_close($ch);

        if ($httpCode === 200 && !empty($body)) {
            $data = json_decode($body, true);
            if (is_array($data) && !empty($data['version'])) {
                $latestVer = trim($data['version']);
                $res['latest_version'] = $latestVer;
                $res['changelog'] = $data['changelog'] ?? '';
                $res['release_date'] = $data['release_date'] ?? '';
                $res['download_url'] = $data['download_url'] ?? self::ARCHIVE_URL;
                $res['has_update'] = version_compare($latestVer, BOT_VERSION, '>');
            }
        } else {
            $res['error'] = !empty($curlErr) ? $curlErr : "HTTP code $httpCode while checking updates";
        }

        SettingsManager::set('update_cache', $res);
        SettingsManager::set('update_last_checked', time());

        return $res;
    }

    /**
     * Download and safely apply latest update from GitHub
     * Protects settings.json, config.php, and logs from being overwritten.
     *
     * @return array ['ok' => bool, 'message' => string]
     */
    public static function applyUpdate()
    {
        if (!class_exists('ZipArchive')) {
            return [
                'ok' => false,
                'message' => 'PHP ZipArchive extension is not enabled on this server.'
            ];
        }

        $rootDir = realpath(__DIR__);
        if (!$rootDir || !is_writable($rootDir)) {
            return [
                'ok' => false,
                'message' => "Application root directory is not writable. Please set write permissions (chmod 755 or 775) on $rootDir."
            ];
        }

        // 1. Fetch download url
        $check = self::checkForUpdate(true);
        $zipUrl = $check['download_url'] ?? self::ARCHIVE_URL;

        // 2. Download ZIP to temporary file
        $tempZip = tempnam(sys_get_temp_dir(), 'bot_upd_') . '.zip';
        $fp = fopen($tempZip, 'w+');
        if (!$fp) {
            return [
                'ok' => false,
                'message' => 'Cannot create temporary download file in ' . sys_get_temp_dir()
            ];
        }

        $ch = curl_init($zipUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'EnglishLearningBot-Updater/' . BOT_VERSION
        ]);
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        @curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode < 200 || $httpCode >= 400 || filesize($tempZip) < 1000) {
            @unlink($tempZip);
            return [
                'ok' => false,
                'message' => "Failed to download update package (HTTP $httpCode): " . ($curlErr ?: 'Empty file')
            ];
        }

        // 3. Extract ZIP to temporary folder
        $extractDir = sys_get_temp_dir() . '/bot_upd_extracted_' . time();
        if (!mkdir($extractDir, 0755, true)) {
            @unlink($tempZip);
            return [
                'ok' => false,
                'message' => 'Failed to create temporary extraction folder.'
            ];
        }

        $zip = new ZipArchive();
        if ($zip->open($tempZip) !== true) {
            @unlink($tempZip);
            self::delTree($extractDir);
            return [
                'ok' => false,
                'message' => 'Downloaded file is not a valid ZIP archive.'
            ];
        }

        $zip->extractTo($extractDir);
        $zip->close();
        @unlink($tempZip);

        // Locate inner directory (GitHub main branch ZIPs extract into english-learning-bot-main/)
        $sourceDir = $extractDir;
        $items = scandir($extractDir);
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($extractDir . '/' . $item)) {
                $sourceDir = $extractDir . '/' . $item;
                break;
            }
        }

        // 4. Create backup of critical files before replacement
        $backupDir = $rootDir . '/backups/backup_' . date('Ymd_His') . '_v' . BOT_VERSION;
        if (!file_exists($rootDir . '/backups')) {
            @mkdir($rootDir . '/backups', 0755, true);
        }
        @mkdir($backupDir, 0755, true);

        // 5. Files and folders that MUST NOT be overwritten or touched
        $protected = [
            'settings.json',
            'config.php',
            'bot_log.txt',
            'backups',
            '.git',
            '.gitignore'
        ];

        // 6. Copy updated files recursively
        $copiedCount = 0;
        $copyResult = self::copyDirectory($sourceDir, $rootDir, $protected, $backupDir, $copiedCount);

        // Cleanup temporary extraction folder
        self::delTree($extractDir);

        if (!$copyResult['ok']) {
            return [
                'ok' => false,
                'message' => 'Error during file update: ' . $copyResult['error']
            ];
        }

        // Clear update cache to force refresh
        SettingsManager::set('update_cache', null);
        SettingsManager::set('update_last_checked', 0);

        return [
            'ok' => true,
            'message' => "Application updated successfully! ($copiedCount files updated). All your API keys and custom settings were preserved.",
            'backup' => $backupDir
        ];
    }

    /**
     * Recursively copy files and directories, skipping protected files
     */
    private static function copyDirectory($src, $dst, $protected, $backupDir, &$count)
    {
        $dir = opendir($src);
        if (!$dir) {
            return ['ok' => false, 'error' => "Cannot open source directory $src"];
        }

        if (!file_exists($dst)) {
            @mkdir($dst, 0755, true);
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            // Strictly protect critical configuration and user data files
            if (in_array($file, $protected, true)) {
                continue;
            }

            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;

            if (is_dir($srcPath)) {
                $res = self::copyDirectory($srcPath, $dstPath, $protected, $backupDir . '/' . $file, $count);
                if (!$res['ok']) {
                    closedir($dir);
                    return $res;
                }
            } else {
                // Backup existing file if present
                if (file_exists($dstPath)) {
                    @copy($dstPath, $backupDir . '/' . $file);
                }
                if (@copy($srcPath, $dstPath)) {
                    $count++;
                } else {
                    closedir($dir);
                    return ['ok' => false, 'error' => "Cannot write to $dstPath. Check file permissions."];
                }
            }
        }
        closedir($dir);

        return ['ok' => true];
    }

    /**
     * Recursively delete directory
     */
    private static function delTree($dir)
    {
        if (!file_exists($dir)) {
            return true;
        }
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            if (!self::delTree($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        return rmdir($dir);
    }
}
