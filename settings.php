<?php
/**
 * Settings Manager
 * Manages reading, writing, and rotating API keys, model selections, and configuration in settings.json.
 *
 * @author Mohammadreza Sarmadi
 */

if (!defined('BOT_APP')) {
    define('BOT_APP', true);
}

class SettingsManager
{
    private static $filePath = __DIR__ . '/settings.json';
    private static $data = null;

    /**
     * Load settings from JSON storage or initialize with defaults
     *
     * @return array
     */
    public static function load()
    {
        if (self::$data !== null) {
            return self::$data;
        }

        if (file_exists(self::$filePath)) {
            $content = file_get_contents(self::$filePath);
            $json = json_decode($content, true);
            if (is_array($json)) {
                self::$data = $json;
                return self::$data;
            }
        } elseif (file_exists(__DIR__ . '/settings.sample.json')) {
            $content = file_get_contents(__DIR__ . '/settings.sample.json');
            $json = json_decode($content, true);
            if (is_array($json)) {
                self::$data = $json;
                self::save();
                return self::$data;
            }
        }

        // Initialize from config.php if settings.json does not exist yet
        if (file_exists(__DIR__ . '/config.php')) {
            require_once __DIR__ . '/config.php';
        }

        $defaultToken = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '';
        $defaultSecret = defined('TELEGRAM_SECRET_TOKEN') ? TELEGRAM_SECRET_TOKEN : '';
        $defaultGeminiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        $defaultModel = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.5-flash-lite';

        self::$data = [
            'admin_password' => 'admin123', // Admin panel access password
            'telegram_token' => $defaultToken,
            'telegram_secret_token' => $defaultSecret,
            'primary_model' => $defaultModel,
            'secondary_model' => 'gemini-3.1-flash-lite',
            'enable_secondary_model' => true,
            'explanation_language' => defined('EXPLANATION_LANGUAGE') ? EXPLANATION_LANGUAGE : 'fa',
            'welcome_message' => self::getDefaultWelcomeMessage(),
            'react_if_perfect' => defined('REACT_IF_PERFECT') ? REACT_IF_PERFECT : true,
            'enable_retry_button' => true,
            'min_text_length' => defined('MIN_TEXT_LENGTH') ? MIN_TEXT_LENGTH : 5,
            'debug_log' => true,
            'keys' => !empty($defaultGeminiKey) ? [
                [
                    'key' => $defaultGeminiKey,
                    'name' => 'Primary Key 1',
                    'status' => 'active', // 'active', 'rate_limited', or 'invalid'
                    'total_requests' => 0,
                    'total_success' => 0,
                    'last_error' => null,
                    'last_used' => null
                ]
            ] : [],
            'cached_models' => [
                'gemini-3.5-flash-lite',
                'gemini-3.5-flash',
                'gemini-2.5-flash',
                'gemini-2.5-flash-lite',
                'gemini-flash-lite-latest',
                'gemini-flash-latest',
                'gemini-2.5-pro'
            ]
        ];

        self::save();
        return self::$data;
    }

    /**
     * Persist settings to JSON file with exclusive lock
     *
     * @return bool
     */
    public static function save()
    {
        if (self::$data === null) {
            return false;
        }
        $json = json_encode(self::$data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents(self::$filePath, $json, LOCK_EX) !== false;
    }

    /**
     * Get a setting value by key
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $data = self::load();
        return $data[$key] ?? $default;
    }

    /**
     * Set a setting value by key
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public static function set($key, $value)
    {
        self::load();
        self::$data[$key] = $value;
        return self::save();
    }

    /**
     * Get all configured Gemini API keys
     *
     * @return array
     */
    public static function getKeys()
    {
        return self::get('keys', []);
    }

    /**
     * Add single or batch API keys
     *
     * @param string|array $newKeys
     * @param string $defaultName
     * @return int Number of newly added keys
     */
    public static function addKeys($newKeys, $defaultName = 'Key')
    {
        self::load();
        if (!is_array($newKeys)) {
            $lines = preg_split('/[\r\n]+/', trim($newKeys));
            $newKeys = array_filter(array_map('trim', $lines));
        }

        $existingKeys = array_column(self::$data['keys'], 'key');
        $addedCount = 0;

        foreach ($newKeys as $key) {
            $key = trim($key);
            if (empty($key) || in_array($key, $existingKeys)) {
                continue;
            }

            self::$data['keys'][] = [
                'key' => $key,
                'name' => $defaultName . ' ' . (count(self::$data['keys']) + 1),
                'status' => 'active',
                'total_requests' => 0,
                'total_success' => 0,
                'last_error' => null,
                'last_used' => null
            ];
            $existingKeys[] = $key;
            $addedCount++;
        }

        self::save();
        return $addedCount;
    }

    /**
     * Delete an API key by its key string
     *
     * @param string $keyToDelete
     * @return bool
     */
    public static function deleteKey($keyToDelete)
    {
        self::load();
        $filtered = [];
        foreach (self::$data['keys'] as $item) {
            if ($item['key'] !== $keyToDelete) {
                $filtered[] = $item;
            }
        }
        self::$data['keys'] = $filtered;
        return self::save();
    }

    /**
     * Reset all key statuses back to 'active'
     *
     * @return bool
     */
    public static function resetAllKeyStatuses()
    {
        self::load();
        foreach (self::$data['keys'] as &$k) {
            $k['status'] = 'active';
            $k['last_error'] = null;
        }
        return self::save();
    }

    /**
     * Reset a single key status back to 'active'
     *
     * @param string $key
     * @return bool
     */
    public static function resetKeyStatus($key)
    {
        self::load();
        foreach (self::$data['keys'] as &$k) {
            if ($k['key'] === $key) {
                $k['status'] = 'active';
                $k['last_error'] = null;
                break;
            }
        }
        return self::save();
    }

    /**
     * Record API key usage, increment metrics, and update status
     *
     * @param string $key
     * @param bool $success
     * @param string $status
     * @param string|null $errorMsg
     * @return bool
     */
    public static function recordKeyUsage($key, $success = true, $status = 'active', $errorMsg = null)
    {
        self::load();
        foreach (self::$data['keys'] as &$k) {
            if ($k['key'] === $key) {
                $k['total_requests'] = ($k['total_requests'] ?? 0) + 1;
                $k['last_used'] = date('Y-m-d H:i:s');
                if ($success) {
                    $k['total_success'] = ($k['total_success'] ?? 0) + 1;
                    $k['status'] = 'active';
                } else {
                    $k['status'] = $status;
                    $k['last_error'] = $errorMsg;
                }
                break;
            }
        }
        return self::save();
    }

    /**
     * Get active/available API keys for request handling
     *
     * @return array
     */
    public static function getAvailableKeys()
    {
        $keys = self::getKeys();
        $available = [];
        foreach ($keys as $k) {
            if ($k['status'] === 'active') {
                $available[] = $k;
            }
        }
        // If all keys are currently rate-limited, fallback to non-invalid keys to re-test
        if (empty($available)) {
            foreach ($keys as $k) {
                if ($k['status'] !== 'invalid') {
                    $available[] = $k;
                }
            }
        }
        return $available;
    }

    /**
     * Get default welcome message for the bot
     *
     * @return string
     */
    public static function getDefaultWelcomeMessage()
    {
        return "👋 <b>سلام! من ربات هوشمند اصلاح زبان انگلیسی هستم.</b>\n\n"
            . "🔹 من با مدل‌های پیشرفته <b>Google Gemini</b> کار می‌کنم تا خطاهای گرامری، املایی و لحن جملات را سریع تصحیح کنم.\n\n"
            . "👥 <b>نحوه استفاده در گروه:</b>\n"
            . "1. من را به گروه چت انگلیسی خود اضافه کنید.\n"
            . "2. حتماً در @BotFather تنظیمات <b>Privacy Mode</b> من را <b>Disable</b> کنید.\n"
            . "3. به انگلیسی چت کنید؛ اگر جمله‌ای اشکال داشته باشد، فوراً آن را تصحیح کرده و راه‌های طبیعی‌تر گفتن آن را پیشنهاد می‌دهم!\n\n"
            . "✨ همچنین می‌توانید همین‌جا یک جمله انگلیسی بفرستید تا برایتان تست کنم!";
    }
}
