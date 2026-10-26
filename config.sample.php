<?php
/**
 * Configuration Template for English Learning Telegram Bot
 * Copy this file to config.php and enter your credentials, or manage them via the web admin panel.
 *
 * @author Mohammadreza Sarmadi
 */

// Prevent direct script access
if (!defined('BOT_APP')) {
    define('BOT_APP', true);
}

// -------------------------------------------------------------
// 1. Telegram Settings
// -------------------------------------------------------------
// Bot API Token from @BotFather
define('TELEGRAM_BOT_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN_HERE');

// Secret token for webhook verification (optional but recommended)
define('TELEGRAM_SECRET_TOKEN', '');

// -------------------------------------------------------------
// 2. Google Gemini Settings
// -------------------------------------------------------------
// API Key from Google AI Studio (aistudio.google.com)
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE');

// Primary Gemini Model (Fast & Efficient)
define('GEMINI_MODEL', 'gemini-3.5-flash-lite');

// -------------------------------------------------------------
// 3. Bot Behavior Settings
// -------------------------------------------------------------
// Feedback explanation language: 'fa', 'en', 'tr', 'fr', 'de', 'es', 'ar'
define('EXPLANATION_LANGUAGE', 'fa');

// Add a thumbs-up reaction (👍) if the message is grammatically perfect and natural
define('REACT_IF_PERFECT', true);

// Minimum message character length required for evaluation
define('MIN_TEXT_LENGTH', 5);

// Error and debug logging
define('DEBUG_LOG', true);
define('LOG_FILE', __DIR__ . '/bot_log.txt');

/**
 * Simple file logger helper
 *
 * @param mixed $message
 * @return void
 */
function bot_log($message)
{
    if (defined('DEBUG_LOG') && DEBUG_LOG) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] " . (is_array($message) || is_object($message) ? json_encode($message, JSON_UNESCAPED_UNICODE) : $message) . PHP_EOL;
        file_put_contents(LOG_FILE, $logEntry, FILE_APPEND);
    }
}
