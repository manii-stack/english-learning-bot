<?php
/**
 * Gemini Service
 * Handles multi-key rotation, failover, secondary fallback model, and Google Gemini API communication.
 *
 * @author Mohammadreza Sarmadi
 */

if (!defined('BOT_APP')) {
    define('BOT_APP', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';

class GeminiService
{
    private $explanationLang;
    private $primaryModel;
    private $secondaryModel;
    private $enableSecondaryModel;

    public function __construct()
    {
        $this->explanationLang = SettingsManager::get('explanation_language', 'fa');
        $this->primaryModel = SettingsManager::get('primary_model', 'gemini-3.5-flash-lite');
        $this->secondaryModel = SettingsManager::get('secondary_model', 'gemini-3.1-flash-lite');
        $this->enableSecondaryModel = SettingsManager::get('enable_secondary_model', true);
    }

    /**
     * Analyze user sentence with intelligent multi-key rotation and model fallback
     *
     * @param string $userText User message to analyze
     * @param bool $isRetry True if triggered from the Retry callback button
     * @return array|null Structured analysis result, or null if no reply needed
     */
    public function analyzeSentence($userText, $isRetry = false)
    {
        $userText = trim($userText);
        $minLength = SettingsManager::get('min_text_length', 5);
        if (mb_strlen($userText) < $minLength) {
            return null;
        }

        // Retrieve active API keys
        $availableKeys = SettingsManager::getAvailableKeys();
        if (empty($availableKeys)) {
            bot_log("CRITICAL: No active Gemini API keys available!");
            return null;
        }

        // Configure system prompt instructions
        $langInstruction = ($this->explanationLang === 'fa')
            ? "Write 'mistakes' labels, parenthetical hints in 'better_way', and 'explanation' in concise, friendly Persian."
            : "Write 'mistakes' labels, parenthetical hints in 'better_way', and 'explanation' in simple, clear English.";

        $systemPrompt = <<<'PROMPT'
You are a friendly, encouraging, and highly accurate English grammar and conversational tutor in a Telegram group chat.
Your job is to analyze English messages (single-line or multi-line) sent by users, detect any grammar errors, typos, text-speak ("u", "ur", "r", lowercase "i"), missing punctuation, or unnatural word choices, and provide concise suggestions.

Strict Rules:
1. If the message is NOT in English (e.g. Persian, Arabic, random symbols, numbers, emojis only), respond with:
{"is_english": false, "has_errors": false, "is_perfect": false, "should_reply": false}

2. If the message is just a basic casual greeting/ack with no errors (like "hi", "hello everyone", "ok", "yes sure", "thanks", "good night"), respond with:
{"is_english": true, "has_errors": false, "is_perfect": true, "should_reply": false}

3. If the message is grammatically correct AND sounds completely natural, respond with:
{"is_english": true, "has_errors": false, "is_perfect": true, "should_reply": false}

4. If the message contains ANY grammar error, typo, text-speak ("u" instead of "you", lowercase "i"), missing words, punctuation error, or stiff phrasing:
   - "should_reply": true
   - "has_errors": true
   - "is_perfect": false
   - "corrected": Fix the grammar, spelling, capitalization, and punctuation of the user's message.
   - "better_way": Provide 2 natural native alternatives separated by \n (bullet points starting with • ). Option 1: natural everyday spoken English. Option 2: casual/idiomatic phrasing. Add a short note in parentheses after each option.
   - "mistakes": An array of specific fixes (e.g. "نگارش و حروف بزرگ: 'i' ← 'I' و 'u' ← 'you'").
   - "explanation": A brief, friendly explanation (1-2 lines).

Always return ONLY a valid JSON object matching this exact schema:
{
  "is_english": true,
  "has_errors": true,
  "is_perfect": false,
  "should_reply": true,
  "corrected": "<corrected version of user message>",
  "better_way": "• <Natural Option 1> (<hint>)\n• <Casual/Idiomatic Option 2> (<hint>)",
  "mistakes": [
    "<category>: '<wrong>' ← '<right>'"
  ],
  "explanation": "<friendly brief explanation>"
}
PROMPT;

        $systemPrompt .= "\n\nLanguage Rule:\n" . $langInstruction;

        if ($isRetry) {
            $systemPrompt .= "\nNote: The user requested a re-evaluation. Provide fresh, creative, and different native alternatives in 'better_way' compared to standard textbook answers.";
        }

        // Key rotation loop
        foreach ($availableKeys as $keyItem) {
            $apiKey = $keyItem['key'];
            $keyName = $keyItem['name'] ?? 'Key';

            // 1. Attempt with primary model
            $result = $this->callGeminiApi($apiKey, $this->primaryModel, $userText, $systemPrompt, $isRetry);

            if ($result['status'] === 'success') {
                SettingsManager::recordKeyUsage($apiKey, true);
                $result['data']['_meta'] = [
                    'key_name' => $keyName,
                    'model' => $this->primaryModel
                ];
                return $result['data'];
            }

            // 2. If rate limited (HTTP 429) and secondary fallback model is enabled on this key
            if ($result['http_code'] === 429 && $this->enableSecondaryModel && !empty($this->secondaryModel) && $this->secondaryModel !== $this->primaryModel) {
                bot_log("Key [$keyName] quota reached for {$this->primaryModel}. Trying fallback model: {$this->secondaryModel}...");

                $fallbackResult = $this->callGeminiApi($apiKey, $this->secondaryModel, $userText, $systemPrompt, $isRetry);
                if ($fallbackResult['status'] === 'success') {
                    SettingsManager::recordKeyUsage($apiKey, true);
                    $fallbackResult['data']['_meta'] = [
                        'key_name' => $keyName,
                        'model' => $this->secondaryModel,
                        'fallback' => true
                    ];
                    return $fallbackResult['data'];
                }
            }

            // 3. Mark key status if rate limited or invalid
            if ($result['http_code'] === 429) {
                bot_log("Key [$keyName] quota fully exhausted (429). Rotating to next key...");
                SettingsManager::recordKeyUsage($apiKey, false, 'rate_limited', 'Rate limit reached (HTTP 429)');
            } elseif ($result['http_code'] === 400 || $result['http_code'] === 403) {
                bot_log("Key [$keyName] is INVALID ({$result['http_code']}). Disabling key...");
                SettingsManager::recordKeyUsage($apiKey, false, 'invalid', 'Invalid or expired API key (HTTP ' . $result['http_code'] . ')');
            } else {
                bot_log("Key [$keyName] failed with HTTP {$result['http_code']}: {$result['error']}. Trying next key...");
            }
        }

        bot_log("ALL Gemini API keys failed or exhausted!");
        return null;
    }

    /**
     * Direct HTTP POST call to Gemini API endpoint
     *
     * @param string $apiKey
     * @param string $model
     * @param string $userText
     * @param string $systemPrompt
     * @param bool $isRetry
     * @return array
     */
    private function callGeminiApi($apiKey, $model, $userText, $systemPrompt, $isRetry = false)
    {
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => "Analyze this user message:\n" . $userText]
                    ]
                ]
            ],
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemPrompt]
                ]
            ],
            'generationConfig' => [
                'temperature' => $isRetry ? 0.65 : 0.35,
                'maxOutputTokens' => 1000,
                'responseMimeType' => 'application/json'
            ]
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($error) {
            return ['status' => 'error', 'http_code' => 0, 'error' => $error];
        }

        if ($httpCode !== 200) {
            return ['status' => 'error', 'http_code' => $httpCode, 'error' => $response];
        }

        $resData = json_decode($response, true);
        $textOutput = $resData['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$textOutput) {
            return ['status' => 'error', 'http_code' => $httpCode, 'error' => 'Empty text output in response'];
        }

        // Clean markdown backticks and parse JSON
        $cleanJson = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($textOutput));
        $parsed = json_decode($cleanJson, true);

        // Fallback fix for unescaped newlines inside strings
        if (!$parsed) {
            $fixedJson = preg_replace_callback('/"(?:\\\\.|[^"\\\\])*"/s', function ($matches) {
                return str_replace(["\r\n", "\r", "\n"], "\\n", $matches[0]);
            }, $cleanJson);
            $parsed = json_decode($fixedJson, true);
        }

        if (!$parsed) {
            return ['status' => 'error', 'http_code' => $httpCode, 'error' => 'Failed to parse JSON: ' . $textOutput];
        }

        return ['status' => 'success', 'http_code' => 200, 'data' => $parsed];
    }

    /**
     * Fetch all generateContent-supported models from Google API
     *
     * @param string|null $apiKey
     * @return array
     */
    public static function fetchAvailableModels($apiKey = null)
    {
        if (empty($apiKey)) {
            $keys = SettingsManager::getAvailableKeys();
            if (empty($keys)) {
                return ['ok' => false, 'error' => 'No active API keys found to fetch models.'];
            }
            $apiKey = $keys[0]['key'];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($error || $httpCode !== 200) {
            return ['ok' => false, 'error' => $error ?: "Google API Error (HTTP $httpCode)"];
        }

        $data = json_decode($response, true);
        if (!isset($data['models']) || !is_array($data['models'])) {
            return ['ok' => false, 'error' => 'Invalid model response payload from Google.'];
        }

        $extracted = [];
        foreach ($data['models'] as $m) {
            $methods = $m['supportedGenerationMethods'] ?? [];
            if (in_array('generateContent', $methods)) {
                $id = str_replace('models/', '', $m['name']);
                // Filter out TTS and embeddings
                if (stripos($id, 'tts') === false && stripos($id, 'embedding') === false && stripos($id, 'aqa') === false) {
                    $extracted[] = [
                        'id' => $id,
                        'name' => $m['displayName'] ?? $id,
                        'description' => $m['description'] ?? ''
                    ];
                }
            }
        }

        // Cache model IDs in settings
        $modelIds = array_column($extracted, 'id');
        SettingsManager::set('cached_models', $modelIds);

        return ['ok' => true, 'models' => $extracted];
    }

    /**
     * Test a specific key with a sample query
     *
     * @param string $apiKey
     * @param string|null $model
     * @return array
     */
    public static function testSpecificKey($apiKey, $model = null)
    {
        $model = $model ?? SettingsManager::get('primary_model', 'gemini-3.5-flash-lite');
        $instance = new self();
        $res = $instance->callGeminiApi($apiKey, $model, "She do not likes apples.", "You are a grammar tutor. Return JSON with should_reply=true, has_errors=true, corrected='She does not like apples.', better_way='• She doesn\\'t like apples.', mistakes=['verb mismatch'], explanation='Use does for singular subject.'");
        return $res;
    }
}
