<?php
/**
 * Telegram Bot API Helper
 * Provides wrapper methods for Telegram Bot API: messaging, replies, inline keyboards, reactions, and webhooks.
 *
 * @author Mohammadreza Sarmadi
 */

if (!defined('BOT_APP')) {
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/settings.php';

class TelegramBot
{
    private $token;
    private $apiUrl;

    public function __construct($token = null)
    {
        $this->token = $token ?? SettingsManager::get('telegram_token', defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '');
        $this->apiUrl = "https://api.telegram.org/bot" . $this->token . "/";
    }

    /**
     * Send a message to a chat with optional reply and inline keyboard
     *
     * @param int|string $chatId
     * @param string $text
     * @param int|null $replyToMessageId
     * @param string $parseMode
     * @param array|null $replyMarkup
     * @return array
     */
    public function sendMessage($chatId, $text, $replyToMessageId = null, $parseMode = 'HTML', $replyMarkup = null)
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true
        ];

        if ($replyToMessageId) {
            $params['reply_to_message_id'] = $replyToMessageId;
            $params['allow_sending_without_reply'] = true;
        }

        if ($replyMarkup) {
            $params['reply_markup'] = is_array($replyMarkup) ? json_encode($replyMarkup) : $replyMarkup;
        }

        $res = $this->request('sendMessage', $params);
        // Fallback to plain text if Telegram fails due to malformed HTML entities
        if (empty($res['ok']) && $parseMode === 'HTML' && isset($res['description']) && stripos($res['description'], "can't parse entities") !== false) {
            unset($params['parse_mode']);
            $res = $this->request('sendMessage', $params);
        }
        return $res;
    }

    /**
     * Edit an existing message text (used for Retry callback response)
     *
     * @param int|string $chatId
     * @param int $messageId
     * @param string $text
     * @param string $parseMode
     * @param array|null $replyMarkup
     * @return array
     */
    public function editMessageText($chatId, $messageId, $text, $parseMode = 'HTML', $replyMarkup = null)
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true
        ];

        if ($replyMarkup) {
            $params['reply_markup'] = is_array($replyMarkup) ? json_encode($replyMarkup) : $replyMarkup;
        }

        return $this->request('editMessageText', $params);
    }

    /**
     * Answer an incoming callback query to dismiss loading state
     *
     * @param string $callbackQueryId
     * @param string $text
     * @param bool $showAlert
     * @return array
     */
    public function answerCallbackQuery($callbackQueryId, $text = '', $showAlert = false)
    {
        $params = [
            'callback_query_id' => $callbackQueryId,
            'show_alert' => $showAlert
        ];
        if (!empty($text)) {
            $params['text'] = $text;
        }
        return $this->request('answerCallbackQuery', $params);
    }

    /**
     * Build the inline keyboard markup for the Retry action
     *
     * @return array
     */
    public static function getRetryKeyboard()
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 بررسی مجدد (پیشنهاد جدید)', 'callback_data' => 'retry_analysis']
                ]
            ]
        ];
    }

    /**
     * Set an emoji reaction on a message
     *
     * @param int|string $chatId
     * @param int $messageId
     * @param string $emoji
     * @return array
     */
    public function setMessageReaction($chatId, $messageId, $emoji = '👍')
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reaction' => json_encode([
                [
                    'type' => 'emoji',
                    'emoji' => $emoji
                ]
            ])
        ];

        return $this->request('setMessageReaction', $params);
    }

    /**
     * Set the Telegram webhook URL and subscribe to message & callback_query updates
     *
     * @param string $url
     * @param string $secretToken
     * @return array
     */
    public function setWebhook($url, $secretToken = '')
    {
        $params = [
            'url' => $url,
            'allowed_updates' => json_encode(['message', 'callback_query'])
        ];
        if (!empty($secretToken)) {
            $params['secret_token'] = $secretToken;
        }
        return $this->request('setWebhook', $params);
    }

    /**
     * Delete active webhook
     *
     * @return array
     */
    public function deleteWebhook()
    {
        return $this->request('deleteWebhook', ['drop_pending_updates' => false]);
    }

    /**
     * Retrieve webhook info and status
     *
     * @return array
     */
    public function getWebhookInfo()
    {
        return $this->request('getWebhookInfo');
    }

    /**
     * Get basic bot info for connection testing
     *
     * @return array
     */
    public function getMe()
    {
        return $this->request('getMe');
    }

    /**
     * Format Gemini analysis into an aesthetic Telegram HTML message
     *
     * @param array $analysis
     * @return string
     */
    public static function formatCorrectionMessage($analysis)
    {
        $corrected = htmlspecialchars($analysis['corrected'] ?? '', ENT_QUOTES, 'UTF-8');
        $betterWay = htmlspecialchars($analysis['better_way'] ?? '', ENT_QUOTES, 'UTF-8');
        $explanation = htmlspecialchars($analysis['explanation'] ?? '', ENT_QUOTES, 'UTF-8');
        $mistakes = $analysis['mistakes'] ?? [];

        $msg = "✍️ <b>فرم اصلاح‌شده:</b>\n";
        $msg .= "<code>" . $corrected . "</code>\n\n";

        if (!empty($betterWay) && strtolower(trim($betterWay)) !== strtolower(trim($corrected))) {
            $msg .= "🌟 <b>بیان طبیعی‌تر (Native):</b>\n";
            $msg .= "<i>" . $betterWay . "</i>\n\n";
        }

        if (!empty($mistakes) && is_array($mistakes)) {
            $msg .= "🔍 <b>اشکالات مشخص‌شده:</b>\n";
            foreach ($mistakes as $m) {
                $mClean = preg_replace('/^[•\-\*❌\s]+/u', '', trim($m));
                $mClean = htmlspecialchars($mClean, ENT_QUOTES, 'UTF-8');
                $msg .= "❌ " . $mClean . "\n";
            }
            $msg .= "\n";
        }

        if (!empty($explanation)) {
            $msg .= "💡 <b>توضیح کوتاه:</b>\n";
            $msg .= $explanation;
        }

        return trim($msg);
    }

    /**
     * Execute HTTP POST cURL request to Telegram API
     *
     * @param string $method
     * @param array $params
     * @return array
     */
    private function request($method, $params = [])
    {
        $url = $this->apiUrl . $method;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);

        if ($error) {
            bot_log("Telegram cURL Error ($method): " . $error);
            return ['ok' => false, 'description' => $error];
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['ok']) || !$result['ok']) {
            bot_log("Telegram API Error ($method): " . ($response ?: 'Empty response'));
        }

        return $result;
    }
}
