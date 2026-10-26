<?php
/**
 * Master Web Admin Panel for English Learning Telegram Bot
 * Comprehensive administrative dashboard for managing Telegram bot settings,
 * multi-key Gemini rotation, AI model selection, webhook lifecycle, and live logs.
 *
 * Developer: Mohammadreza Sarmadi
 */

define('BOT_APP', true);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/gemini.php';
require_once __DIR__ . '/telegram.php';

// Initialize internationalization (detects GET param ?lang=, session, or cookie)
I18n::init();

// Initialize settings
SettingsManager::load();

$msg = null;
$errMsg = null;

// Handle user logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Check admin authentication
$adminPassword = SettingsManager::get('admin_password', 'admin123');
$isLoggedIn = !empty($_SESSION['admin_logged_in']);

if (!$isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
    if ($_POST['login_password'] === $adminPassword) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $errMsg = '<i class="bi bi-exclamation-octagon-fill"></i> ' . __('password_err');
    }
}

// Session state check
if (!empty($_SESSION['admin_logged_in'])) {
    $isLoggedIn = true;
}

// Handle administrative form submissions
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Add new Gemini API keys (single or batch)
    if ($action === 'add_keys') {
        $rawKeys = trim($_POST['raw_keys'] ?? '');
        $keyName = trim($_POST['key_label'] ?? 'Key');
        $added = SettingsManager::addKeys($rawKeys, $keyName);
        if ($added > 0) {
            $msg = "<i class=\"bi bi-check-circle-fill\"></i> Successfully added $added new API key(s).";
        } else {
            $errMsg = "<i class=\"bi bi-exclamation-triangle-fill\"></i> No valid or new API keys found (duplicates are skipped).";
        }
    }

    // 2. Delete an API key
    elseif ($action === 'delete_key') {
        $keyToDelete = $_POST['key_to_delete'] ?? '';
        if (SettingsManager::deleteKey($keyToDelete)) {
            $msg = "<i class=\"bi bi-trash3-fill\"></i> Key deleted successfully.";
        }
    }

    // 3. Reset single key status
    elseif ($action === 'reset_key') {
        $keyToReset = $_POST['key_to_reset'] ?? '';
        if (SettingsManager::resetKeyStatus($keyToReset)) {
            $msg = "<i class=\"bi bi-arrow-repeat\"></i> Key status has been reset to Active.";
        }
    }

    // 4. Reset all keys status to active
    elseif ($action === 'reset_all_keys') {
        SettingsManager::resetAllKeyStatuses();
        $msg = "<i class=\"bi bi-arrow-repeat\"></i> " . __('btn_reset_all_keys') . " - Done.";
    }

    // 5. Test specific API key connectivity
    elseif ($action === 'test_key') {
        $keyToTest = $_POST['key_to_test'] ?? '';
        $testRes = GeminiService::testSpecificKey($keyToTest);
        if ($testRes['status'] === 'success') {
            $msg = "<i class=\"bi bi-check-circle-fill\"></i> Key test was successful! Model responded normally.";
        } else {
            $errMsg = "<i class=\"bi bi-exclamation-octagon-fill\"></i> Key test failed (HTTP {$testRes['http_code']}): " . htmlspecialchars($testRes['error']);
        }
    }

    // 6. Fetch all available Gemini models from Google API
    elseif ($action === 'fetch_models') {
        $modelsRes = GeminiService::fetchAvailableModels();
        if ($modelsRes['ok']) {
            $count = count($modelsRes['models']);
            $msg = "<i class=\"bi bi-check-circle-fill\"></i> Successfully retrieved and cached $count AI models from Google.";
        } else {
            $errMsg = "<i class=\"bi bi-exclamation-octagon-fill\"></i> Error fetching models: " . htmlspecialchars($modelsRes['error']);
        }
    }

    // 7. Save primary and secondary fallback models
    elseif ($action === 'save_models') {
        SettingsManager::set('primary_model', trim($_POST['primary_model'] ?? 'gemini-3.5-flash-lite'));
        SettingsManager::set('secondary_model', trim($_POST['secondary_model'] ?? 'gemini-3.1-flash-lite'));
        SettingsManager::set('enable_secondary_model', !empty($_POST['enable_secondary_model']));
        $msg = "<i class=\"bi bi-floppy-fill\"></i> " . __('btn_save_models') . " - OK.";
    }

    // 8. Save general bot settings and update admin password if provided
    elseif ($action === 'save_settings') {
        SettingsManager::set('telegram_token', trim($_POST['telegram_token'] ?? ''));
        SettingsManager::set('telegram_secret_token', trim($_POST['telegram_secret_token'] ?? ''));
        SettingsManager::set('explanation_language', $_POST['explanation_language'] ?? 'fa');
        SettingsManager::set('min_text_length', max(2, intval($_POST['min_text_length'] ?? 5)));
        SettingsManager::set('react_if_perfect', !empty($_POST['react_if_perfect']));
        SettingsManager::set('enable_retry_button', !empty($_POST['enable_retry_button']));
        SettingsManager::set('debug_log', !empty($_POST['debug_log']));

        // Update admin password if non-empty
        $newPass = trim($_POST['new_password'] ?? '');
        if (!empty($newPass)) {
            SettingsManager::set('admin_password', $newPass);
            $msg = "<i class=\"bi bi-floppy-fill\"></i> General settings and new password updated successfully.";
        } else {
            $msg = "<i class=\"bi bi-floppy-fill\"></i> " . __('btn_save_settings') . " - OK.";
        }
    }

    // 8.1 Save or restore bot welcome message
    elseif ($action === 'save_welcome') {
        if (!empty($_POST['restore_default'])) {
            SettingsManager::set('welcome_message', SettingsManager::getDefaultWelcomeMessage());
            $msg = "<i class=\"bi bi-arrow-repeat\"></i> " . __('btn_reset_welcome') . " - OK.";
        } else {
            $wText = trim($_POST['welcome_message'] ?? '');
            SettingsManager::set('welcome_message', $wText);
            $msg = "<i class=\"bi bi-floppy-fill\"></i> " . __('btn_save_welcome') . " - OK.";
        }
    }

    // 9. Register webhook with Telegram
    elseif ($action === 'set_webhook') {
        $bot = new TelegramBot();
        $whUrl = trim($_POST['webhook_url'] ?? '');
        $sec = SettingsManager::get('telegram_secret_token', '');
        $res = $bot->setWebhook($whUrl, $sec);
        if (!empty($res['ok'])) {
            $msg = "<i class=\"bi bi-check-circle-fill\"></i> Webhook successfully registered:<br><code>$whUrl</code>";
        } else {
            $errMsg = "<i class=\"bi bi-exclamation-octagon-fill\"></i> Webhook setup error: " . ($res['description'] ?? 'Unknown error');
        }
    }

    // 10. Delete active Telegram webhook
    elseif ($action === 'delete_webhook') {
        $bot = new TelegramBot();
        $res = $bot->deleteWebhook();
        if (!empty($res['ok'])) {
            $msg = "<i class=\"bi bi-trash3-fill\"></i> Telegram webhook deleted successfully.";
        } else {
            $errMsg = "<i class=\"bi bi-exclamation-octagon-fill\"></i> Webhook deletion error: " . ($res['description'] ?? 'Unknown error');
        }
    }

    // 11. Clear log file
    elseif ($action === 'clear_log') {
        if (file_exists(LOG_FILE)) {
            file_put_contents(LOG_FILE, '');
            $msg = "<i class=\"bi bi-eraser-fill\"></i> Log file has been cleared.";
        }
    }
}

// Fetch current runtime status for UI presentation
$bot = new TelegramBot();
$botInfo = $bot->getMe();
$webhookInfo = $bot->getWebhookInfo();

$allKeys = SettingsManager::getKeys();
$primaryModel = SettingsManager::get('primary_model', 'gemini-3.5-flash-lite');
$secondaryModel = SettingsManager::get('secondary_model', 'gemini-3.1-flash-lite');
$enableSecondary = SettingsManager::get('enable_secondary_model', true);
$cachedModels = SettingsManager::get('cached_models', [
    'gemini-3.5-flash-lite',
    'gemini-3.1-flash-lite',
    'gemini-3.5-flash',
    'gemini-2.5-flash',
    'gemini-2.5-flash-lite',
    'gemini-flash-lite-latest',
    'gemini-flash-latest',
    'gemini-2.5-pro'
]);

// Determine default host webhook URL
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$defaultWebhookUrl = $proto . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['REQUEST_URI']), '/\\') . '/webhook.php';

// Read recent log lines
$logContent = file_exists(LOG_FILE) ? trim(file_get_contents(LOG_FILE)) : '';
$logLines = $logContent ? array_slice(explode("\n", $logContent), -60) : [];
$recentLogs = implode("\n", array_reverse($logLines));

$currentLang = I18n::getLang();
$currentDir = I18n::getDir();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLang) ?>" dir="<?= htmlspecialchars($currentDir) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__('app_title')) ?></title>

    <!-- Local Bootstrap Icons (zero external CDN dependency) -->
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.min.css">

    <style>
        /* Local IRANYekan typography for Persian/Arabic, standard modern sans for Latin */
        @font-face {
            font-family: 'IRANYekan';
            font-style: normal;
            font-weight: 300;
            src: url('assets/fonts/iranyekanweblight.woff2') format('woff2');
            font-display: swap;
        }
        @font-face {
            font-family: 'IRANYekan';
            font-style: normal;
            font-weight: 400;
            src: url('assets/fonts/iranyekanwebregular.woff2') format('woff2');
            font-display: swap;
        }
        @font-face {
            font-family: 'IRANYekan';
            font-style: normal;
            font-weight: 700;
            src: url('assets/fonts/iranyekanwebbold.woff2') format('woff2');
            font-display: swap;
        }

        * { box-sizing: border-box; }
        :root {
            --bg-main: #0b1120;
            --card-bg: #1e293b;
            --card-border: #334155;
            --accent: #38bdf8;
            --accent-hover: #0284c7;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }
        body {
            font-family: <?= ($currentDir === 'rtl') ? "'IRANYekan', 'IRANYekanX', 'Iran Yekan', " : "" ?>-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg-main);
            color: var(--text-main);
            margin: 0;
            padding: 20px;
            direction: <?= $currentDir ?>;
        }
        .container { max-width: 1080px; margin: 0 auto; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 20px;
            margin-bottom: 25px;
            border-bottom: 1px solid var(--card-border);
            flex-wrap: wrap;
            gap: 15px;
        }
        .header .brand {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .header h1 {
            margin: 0;
            font-size: 21px;
            color: var(--accent);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header .developer-tag {
            font-size: 12px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .lang-select {
            background: #0f172a;
            color: #f8fafc;
            border: 1px solid #475569;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            cursor: pointer;
            outline: none;
            direction: ltr;
        }
        .lang-select:focus { border-color: var(--accent); }
        .header .logout-btn {
            background: #334155;
            color: #cbd5e1;
            text-decoration: none;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .header .logout-btn:hover { background: var(--danger); color: #fff; }

        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            border-bottom: 2px solid var(--card-border);
            padding-bottom: 8px;
        }
        .tab-btn {
            background: transparent;
            color: var(--text-muted);
            border: none;
            padding: 10px 18px;
            font-size: 14px;
            font-family: inherit;
            font-weight: 600;
            cursor: pointer;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .tab-btn:hover { color: #fff; background: rgba(56, 189, 248, 0.1); }
        .tab-btn.active {
            color: var(--accent);
            background: rgba(56, 189, 248, 0.15);
            border-bottom: 2px solid var(--accent);
        }

        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.25s ease-in-out; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        }
        .card h2 {
            margin-top: 0;
            font-size: 17px;
            color: #e2e8f0;
            border-bottom: 1px solid var(--card-border);
            padding-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.6;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #064e3b; color: #a7f3d0; border: 1px solid var(--success); }
        .alert-danger { background: #7f1d1d; color: #fecaca; border: 1px solid var(--danger); }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            background: #0f172a;
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }
        .stat-box .num { font-size: 20px; font-weight: bold; color: var(--accent); margin-top: 6px; }
        .stat-box .title { font-size: 13px; color: var(--text-muted); display: flex; align-items: center; justify-content: center; gap: 6px; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 15px;
        }
        table th, table td {
            padding: 12px;
            border-bottom: 1px solid var(--card-border);
            text-align: <?= ($currentDir === 'rtl') ? 'right' : 'left' ?>;
        }
        table th { background: #0f172a; color: var(--text-muted); font-weight: 600; }
        table tr:hover { background: rgba(255, 255, 255, 0.02); }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge.active { background: #064e3b; color: #6ee7b7; border: 1px solid #059669; }
        .badge.rate_limited { background: #78350f; color: #fde68a; border: 1px solid #d97706; }
        .badge.invalid { background: #7f1d1d; color: #fca5a5; border: 1px solid #dc2626; }

        input[type="text"], input[type="url"], input[type="password"], input[type="number"], select, textarea {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #475569;
            background: #0f172a;
            color: #fff;
            direction: <?= $currentDir === 'rtl' ? 'rtl' : 'ltr' ?>;
            margin: 8px 0 16px 0;
            font-family: inherit;
            font-size: 14px;
        }
        input[type="password"], input[type="url"], textarea { direction: ltr; }
        textarea { resize: vertical; font-family: monospace; }
        select { cursor: pointer; }

        .btn {
            cursor: pointer;
            padding: 10px 18px;
            border-radius: 8px;
            border: none;
            font-family: inherit;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--accent); color: #0b1120; }
        .btn-primary:hover { background: var(--accent-hover); color: #fff; }
        .btn-success { background: var(--success); color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-secondary { background: #334155; color: #cbd5e1; }
        .btn-secondary:hover { background: #475569; color: #fff; }
        .btn-sm { padding: 6px 10px; font-size: 12px; }

        .login-box {
            max-width: 440px;
            margin: 60px auto;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            padding: 30px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        .login-box h2 {
            color: var(--accent);
            margin-bottom: 20px;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        pre {
            background: #0f172a;
            padding: 14px;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            overflow-x: auto;
            direction: ltr;
            text-align: left;
            font-size: 12px;
            color: #38bdf8;
            max-height: 400px;
        }
        .code-inline {
            background: #0f172a;
            padding: 3px 8px;
            border-radius: 4px;
            font-family: monospace;
            color: var(--accent);
            font-size: 12px;
            direction: ltr;
            display: inline-block;
        }

        .bi {
            line-height: 1;
            vertical-align: -0.15em;
        }
    </style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<!-- Authentication Login Box -->
<div class="login-box">
    <div style="display: flex; justify-content: flex-end; margin-bottom: 15px;">
        <select onchange="location.href='?lang='+this.value" class="lang-select" style="margin: 0; padding: 4px 8px; font-size: 12px;">
            <?php foreach (I18n::$languages as $code => $info): ?>
                <option value="<?= $code ?>" <?= $currentLang === $code ? 'selected' : '' ?>>
                    <?= $info['flag'] ?> <?= $info['native'] ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <h2><i class="bi bi-shield-lock-fill"></i> <?= htmlspecialchars(__('login_title')) ?></h2>
    <p style="color: var(--text-muted); font-size: 13px; margin-bottom: 20px;">
        <?= htmlspecialchars(__('login_prompt')) ?>
    </p>
    <?php if ($errMsg): ?>
        <div class="alert alert-danger" style="text-align: <?= ($currentDir === 'rtl') ? 'right' : 'left' ?>;"><?= $errMsg ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="password" name="login_password" placeholder="••••••••" required autofocus>
        <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
            <i class="bi bi-box-arrow-in-left"></i> <?= htmlspecialchars(__('login_btn')) ?>
        </button>
    </form>
    <div style="margin-top: 25px; font-size: 12px; color: var(--text-muted); border-top: 1px solid var(--card-border); padding-top: 15px;">
        <i class="bi bi-person-fill"></i> <?= htmlspecialchars(__('developer')) ?>
    </div>
</div>
<?php exit; endif; ?>

<div class="container">
    <!-- Top Header -->
    <div class="header">
        <div class="brand">
            <h1><i class="bi bi-robot"></i> <?= htmlspecialchars(__('header_title')) ?></h1>
            <div class="developer-tag">
                <i class="bi bi-person-badge-fill"></i> <?= htmlspecialchars(__('developer')) ?>
            </div>
        </div>
        <div class="header-actions">
            <div style="display: flex; align-items: center; gap: 6px;">
                <i class="bi bi-translate" style="color: var(--accent);"></i>
                <select onchange="location.href='?lang='+this.value" class="lang-select" style="margin: 0;">
                    <?php foreach (I18n::$languages as $code => $info): ?>
                        <option value="<?= $code ?>" <?= $currentLang === $code ? 'selected' : '' ?>>
                            <?= $info['flag'] ?> <?= $info['native'] ?> (<?= $info['name'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a href="?logout=1" class="logout-btn">
                <i class="bi bi-box-arrow-right"></i> <?= htmlspecialchars(__('logout')) ?>
            </a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success"><?= $msg ?></div>
    <?php endif; ?>
    <?php if ($errMsg): ?>
        <div class="alert alert-danger"><?= $errMsg ?></div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div class="tabs">
        <button class="tab-btn active" onclick="openTab('tab-overview')">
            <i class="bi bi-speedometer2"></i> <?= htmlspecialchars(__('tab_overview')) ?>
        </button>
        <button class="tab-btn" onclick="openTab('tab-keys')">
            <i class="bi bi-key-fill"></i> <?= htmlspecialchars(__('tab_keys')) ?> (<?= count($allKeys) ?>)
        </button>
        <button class="tab-btn" onclick="openTab('tab-models')">
            <i class="bi bi-cpu-fill"></i> <?= htmlspecialchars(__('tab_models')) ?>
        </button>
        <button class="tab-btn" onclick="openTab('tab-settings')">
            <i class="bi bi-sliders"></i> <?= htmlspecialchars(__('tab_settings')) ?>
        </button>
        <button class="tab-btn" onclick="openTab('tab-welcome')">
            <i class="bi bi-chat-heart-fill"></i> <?= htmlspecialchars(__('tab_welcome')) ?>
        </button>
        <button class="tab-btn" onclick="openTab('tab-test')">
            <i class="bi bi-chat-left-dots-fill"></i> <?= htmlspecialchars(__('tab_test')) ?>
        </button>
        <button class="tab-btn" onclick="openTab('tab-logs')">
            <i class="bi bi-journal-code"></i> <?= htmlspecialchars(__('tab_logs')) ?>
        </button>
    </div>

    <!-- ======================================================== -->
    <!-- Tab 1: System Overview -->
    <!-- ======================================================== -->
    <div id="tab-overview" class="tab-content active">
        <div class="stat-grid">
            <div class="stat-box">
                <div class="title"><i class="bi bi-telegram"></i> <?= htmlspecialchars(__('bot_identity')) ?></div>
                <div class="num" style="font-size: 15px; margin-top: 10px;">
                    <?= !empty($botInfo['ok']) ? htmlspecialchars($botInfo['result']['first_name']) . " (@" . htmlspecialchars($botInfo['result']['username']) . ")" : '<span style="color:var(--danger);"><i class="bi bi-x-circle"></i> ' . __('disconnected') . '</span>' ?>
                </div>
            </div>
            <div class="stat-box">
                <div class="title"><i class="bi bi-link-45deg"></i> <?= htmlspecialchars(__('webhook_status')) ?></div>
                <div class="num" style="font-size: 16px; margin-top: 10px; color: <?= !empty($webhookInfo['result']['url']) ? 'var(--success)' : 'var(--danger)' ?>;">
                    <?= !empty($webhookInfo['result']['url']) ? '<i class="bi bi-check-circle-fill"></i> ' . __('active') : '<i class="bi bi-x-circle-fill"></i> ' . __('inactive') ?>
                </div>
            </div>
            <div class="stat-box">
                <div class="title"><i class="bi bi-key"></i> <?= htmlspecialchars(__('active_keys')) ?></div>
                <?php
                    $activeCount = count(SettingsManager::getAvailableKeys());
                    $totalCount = count($allKeys);
                ?>
                <div class="num"><?= $activeCount ?> <span style="font-size: 14px; color: var(--text-muted);">/ <?= $totalCount ?></span></div>
            </div>
            <div class="stat-box">
                <div class="title"><i class="bi bi-cpu"></i> <?= htmlspecialchars(__('primary_model')) ?></div>
                <div class="num" style="font-size: 14px; margin-top: 10px;"><?= htmlspecialchars($primaryModel) ?></div>
            </div>
        </div>

        <!-- Webhook Status Card -->
        <div class="card">
            <h2>
                <span><i class="bi bi-globe"></i> <?= htmlspecialchars(__('webhook_card_title')) ?></span>
                <?php if (!empty($webhookInfo['result']['url'])): ?>
                    <span class="badge active"><i class="bi bi-check2"></i> <?= htmlspecialchars(__('connected_to_host')) ?></span>
                <?php else: ?>
                    <span class="badge invalid"><i class="bi bi-x"></i> <?= htmlspecialchars(__('not_set')) ?></span>
                <?php endif; ?>
            </h2>

            <div style="font-size: 14px; line-height: 1.8; color: var(--text-muted); margin-bottom: 15px;">
                <?= htmlspecialchars(__('current_wh_url')) ?> <span class="code-inline"><?= htmlspecialchars($webhookInfo['result']['url'] ?? __('not_set')) ?></span><br>
                <?= htmlspecialchars(__('pending_updates')) ?> <b><?= intval($webhookInfo['result']['pending_update_count'] ?? 0) ?></b>
                <?php if (!empty($webhookInfo['result']['last_error_message'])): ?>
                    <br><span style="color: var(--danger);"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars(__('last_wh_error')) ?> <?= htmlspecialchars($webhookInfo['result']['last_error_message']) ?></span>
                <?php endif; ?>
            </div>

            <form method="POST" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                <input type="hidden" name="action" value="set_webhook">
                <div style="flex: 1; min-width: 280px;">
                    <label style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars(__('wh_url_label')) ?></label>
                    <input type="url" name="webhook_url" value="<?= htmlspecialchars($defaultWebhookUrl) ?>" required style="margin-bottom: 0;">
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-cloud-arrow-up-fill"></i> <?= htmlspecialchars(__('btn_set_wh')) ?>
                </button>
            </form>

            <?php if (!empty($webhookInfo['result']['url'])): ?>
                <form method="POST" style="margin-top: 12px;">
                    <input type="hidden" name="action" value="delete_webhook">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('<?= htmlspecialchars(__('confirm_del_wh')) ?>');">
                        <i class="bi bi-trash3-fill"></i> <?= htmlspecialchars(__('btn_del_wh')) ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- Tab 2: Gemini Multi-Key Management -->
    <!-- ======================================================== -->
    <div id="tab-keys" class="tab-content">
        <!-- Add New Keys Card -->
        <div class="card">
            <h2><span><i class="bi bi-plus-circle-fill"></i> <?= htmlspecialchars(__('add_keys_title')) ?></span></h2>
            <p style="font-size: 13px; color: var(--text-muted); line-height: 1.7;">
                <?= htmlspecialchars(__('add_keys_desc')) ?>
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="add_keys">
                <label style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars(__('key_label_field')) ?></label>
                <input type="text" name="key_label" placeholder="Key Label..." value="Key">
                <label style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars(__('raw_keys_field')) ?></label>
                <textarea name="raw_keys" rows="4" placeholder="AIzaSy...&#10;AIzaSy..." required></textarea>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-plus-lg"></i> <?= htmlspecialchars(__('btn_add_keys')) ?>
                </button>
            </form>
        </div>

        <!-- Keys Table Card -->
        <div class="card">
            <h2>
                <span><i class="bi bi-list-ul"></i> <?= htmlspecialchars(__('keys_list_title')) ?> (<?= count($allKeys) ?>)</span>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="reset_all_keys">
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-repeat"></i> <?= htmlspecialchars(__('btn_reset_all_keys')) ?>
                    </button>
                </form>
            </h2>

            <?php if (empty($allKeys)): ?>
                <p style="color: var(--warning); text-align: center; padding: 20px;">
                    <i class="bi bi-info-circle"></i> <?= htmlspecialchars(__('no_keys')) ?>
                </p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(__('th_num')) ?></th>
                                <th><?= htmlspecialchars(__('th_name')) ?></th>
                                <th><?= htmlspecialchars(__('th_key')) ?></th>
                                <th><?= htmlspecialchars(__('th_status')) ?></th>
                                <th><?= htmlspecialchars(__('th_requests')) ?></th>
                                <th><?= htmlspecialchars(__('th_last_used')) ?></th>
                                <th><?= htmlspecialchars(__('th_actions')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allKeys as $idx => $k): ?>
                                <?php
                                    $rawKey = $k['key'];
                                    $masked = substr($rawKey, 0, 7) . '...' . substr($rawKey, -4);
                                    $st = $k['status'] ?? 'active';
                                    $badgeClass = ($st === 'active') ? 'active' : (($st === 'rate_limited') ? 'rate_limited' : 'invalid');
                                    $badgeIcon = ($st === 'active') ? 'bi-check2-circle' : (($st === 'rate_limited') ? 'bi-exclamation-triangle' : 'bi-x-circle');
                                    $badgeText = ($st === 'active') ? __('status_active') : (($st === 'rate_limited') ? __('status_rate_limited') : __('status_invalid'));
                                ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($k['name'] ?? 'Key') ?></td>
                                    <td><span class="code-inline"><?= $masked ?></span></td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?>"><i class="bi <?= $badgeIcon ?>"></i> <?= $badgeText ?></span>
                                        <?php if (!empty($k['last_error'])): ?>
                                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 3px; max-width: 170px;"><?= htmlspecialchars($k['last_error']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= intval($k['total_success'] ?? 0) ?> / <?= intval($k['total_requests'] ?? 0) ?></td>
                                    <td style="direction: ltr; font-size: 12px; color: var(--text-muted);"><?= $k['last_used'] ?? __('never_used') ?></td>
                                    <td>
                                        <div style="display: flex; gap: 4px;">
                                            <!-- Test key -->
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="test_key">
                                                <input type="hidden" name="key_to_test" value="<?= htmlspecialchars($rawKey) ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm" title="Test Key">
                                                    <i class="bi bi-play-circle"></i>
                                                </button>
                                            </form>

                                            <!-- Reset status -->
                                            <?php if ($st !== 'active'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="reset_key">
                                                    <input type="hidden" name="key_to_reset" value="<?= htmlspecialchars($rawKey) ?>">
                                                    <button type="submit" class="btn btn-success btn-sm" title="Reactivate">
                                                        <i class="bi bi-arrow-clockwise"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <!-- Delete key -->
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_key">
                                                <input type="hidden" name="key_to_delete" value="<?= htmlspecialchars($rawKey) ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this key?');" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- Tab 3: AI Model Selection & Management -->
    <!-- ======================================================== -->
    <div id="tab-models" class="tab-content">
        <div class="card">
            <h2>
                <span><i class="bi bi-cpu"></i> <?= htmlspecialchars(__('models_card_title')) ?></span>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="fetch_models">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-arrow-repeat"></i> <?= htmlspecialchars(__('btn_fetch_models')) ?>
                    </button>
                </form>
            </h2>

            <p style="font-size: 13px; color: var(--text-muted); line-height: 1.8;">
                <?= htmlspecialchars(__('models_desc')) ?>
            </p>

            <form method="POST">
                <input type="hidden" name="action" value="save_models">

                <label style="font-size: 14px; font-weight: 600; color: #e2e8f0;"><?= htmlspecialchars(__('primary_model_label')) ?></label>
                <select name="primary_model">
                    <?php foreach ($cachedModels as $mId): ?>
                        <option value="<?= htmlspecialchars($mId) ?>" <?= ($primaryModel === $mId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($mId) ?> <?= ($mId === 'gemini-3.5-flash-lite') ? __('fast_recommended') : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div style="margin: 18px 0;">
                    <label style="font-size: 14px; cursor: pointer; color: #cbd5e1; display: inline-flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="enable_secondary_model" value="1" <?= $enableSecondary ? 'checked' : '' ?> style="width: auto; margin: 0;">
                        <b><?= htmlspecialchars(__('enable_secondary_cb')) ?></b>
                    </label>
                </div>

                <label style="font-size: 14px; font-weight: 600; color: #e2e8f0;"><?= htmlspecialchars(__('secondary_model_label')) ?></label>
                <select name="secondary_model">
                    <?php foreach ($cachedModels as $mId): ?>
                        <option value="<?= htmlspecialchars($mId) ?>" <?= ($secondaryModel === $mId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($mId) ?> <?= ($mId === 'gemini-3.1-flash-lite') ? __('fast_recommended') : (($mId === 'gemini-2.5-flash') ? __('stable_recommended') : '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-success" style="margin-top: 15px;">
                    <i class="bi bi-floppy-fill"></i> <?= htmlspecialchars(__('btn_save_models')) ?>
                </button>
            </form>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- Tab 4: Bot Preferences & Configuration -->
    <!-- ======================================================== -->
    <div id="tab-settings" class="tab-content">
        <div class="card">
            <h2><span><i class="bi bi-sliders"></i> <?= htmlspecialchars(__('settings_card_title')) ?></span></h2>
            <form method="POST">
                <input type="hidden" name="action" value="save_settings">

                <label style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars(__('bot_token_label')) ?></label>
                <input type="text" name="telegram_token" value="<?= htmlspecialchars(SettingsManager::get('telegram_token', '')) ?>" required>

                <label style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars(__('secret_token_label')) ?></label>
                <input type="text" name="telegram_secret_token" value="<?= htmlspecialchars(SettingsManager::get('telegram_secret_token', '')) ?>">

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 15px;">
                    <div>
                        <label style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars(__('explanation_lang_label')) ?></label>
                        <select name="explanation_language">
                            <option value="fa" <?= SettingsManager::get('explanation_language') === 'fa' ? 'selected' : '' ?>>فارسی روان (Persian)</option>
                            <option value="en" <?= SettingsManager::get('explanation_language') === 'en' ? 'selected' : '' ?>>English (Simple)</option>
                            <option value="tr" <?= SettingsManager::get('explanation_language') === 'tr' ? 'selected' : '' ?>>Türkçe (Turkish)</option>
                            <option value="ar" <?= SettingsManager::get('explanation_language') === 'ar' ? 'selected' : '' ?>>العربية (Arabic)</option>
                            <option value="fr" <?= SettingsManager::get('explanation_language') === 'fr' ? 'selected' : '' ?>>Français (French)</option>
                            <option value="de" <?= SettingsManager::get('explanation_language') === 'de' ? 'selected' : '' ?>>Deutsch (German)</option>
                            <option value="es" <?= SettingsManager::get('explanation_language') === 'es' ? 'selected' : '' ?>>Español (Spanish)</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars(__('min_length_label')) ?></label>
                        <input type="number" name="min_text_length" min="2" max="30" value="<?= intval(SettingsManager::get('min_text_length', 5)) ?>">
                    </div>
                </div>

                <div style="margin: 15px 0; line-height: 2;">
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="enable_retry_button" value="1" <?= SettingsManager::get('enable_retry_button', true) ? 'checked' : '' ?> style="width: auto; margin: 0;">
                        <span><?= htmlspecialchars(__('enable_retry_cb')) ?></span>
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="react_if_perfect" value="1" <?= SettingsManager::get('react_if_perfect', true) ? 'checked' : '' ?> style="width: auto; margin: 0;">
                        <span><?= htmlspecialchars(__('react_perfect_cb')) ?></span>
                    </label>
                    <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="debug_log" value="1" <?= SettingsManager::get('debug_log', true) ? 'checked' : '' ?> style="width: auto; margin: 0;">
                        <span><?= htmlspecialchars(__('debug_log_cb')) ?></span>
                    </label>
                </div>

                <hr style="border: 0; border-top: 1px solid var(--card-border); margin: 20px 0;">

                <label style="font-size: 13px; color: var(--accent);"><?= htmlspecialchars(__('change_pass_label')) ?></label>
                <input type="password" name="new_password" placeholder="••••••••">

                <button type="submit" class="btn btn-success">
                    <i class="bi bi-floppy-fill"></i> <?= htmlspecialchars(__('btn_save_settings')) ?>
                </button>
            </form>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- Tab: Bot Welcome & Start Message -->
    <!-- ======================================================== -->
    <div id="tab-welcome" class="tab-content">
        <div class="card">
            <h2><span><i class="bi bi-chat-heart-fill"></i> <?= htmlspecialchars(__('welcome_card_title')) ?></span></h2>
            <p style="font-size: 13px; color: var(--text-muted); line-height: 1.8;">
                <?= htmlspecialchars(__('welcome_desc')) ?>
            </p>

            <form method="POST">
                <input type="hidden" name="action" value="save_welcome">

                <label style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars(__('welcome_label')) ?></label>
                <textarea name="welcome_message" id="welcome_message_input" rows="8" required style="font-family: inherit; font-size: 14px; line-height: 1.7;"><?= htmlspecialchars(SettingsManager::get('welcome_message', SettingsManager::getDefaultWelcomeMessage())) ?></textarea>

                <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 15px;">
                    <i class="bi bi-info-circle"></i> <?= __('welcome_tags_hint') ?>
                </div>

                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-floppy-fill"></i> <?= htmlspecialchars(__('btn_save_welcome')) ?>
                    </button>
                    <button type="submit" name="restore_default" value="1" class="btn btn-secondary" onclick="return confirm('<?= htmlspecialchars(__('btn_reset_welcome')) ?>?');">
                        <i class="bi bi-arrow-counterclockwise"></i> <?= htmlspecialchars(__('btn_reset_welcome')) ?>
                    </button>
                </div>
            </form>

            <div style="margin-top: 30px; border-top: 1px solid var(--card-border); padding-top: 20px;">
                <h3 style="font-size: 14px; color: var(--accent); margin-bottom: 12px;">
                    <i class="bi bi-phone"></i> <?= htmlspecialchars(__('welcome_preview_title')) ?>
                </h3>
                <div id="welcome_preview_box" style="background: #0f172a; border: 1px solid var(--card-border); border-radius: 10px; padding: 16px; line-height: 1.8; font-size: 14px; white-space: pre-wrap;"><?= nl2br(strip_tags(SettingsManager::get('welcome_message', SettingsManager::getDefaultWelcomeMessage()), '<b><strong><i><em><code><a><pre>')) ?></div>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- Tab 5: Live Test & Chat Simulator -->
    <!-- ======================================================== -->
    <div id="tab-test" class="tab-content">
        <div class="card">
            <h2><span><i class="bi bi-chat-left-dots-fill"></i> <?= htmlspecialchars(__('test_card_title')) ?></span></h2>
            <p style="font-size: 13px; color: var(--text-muted);">
                <?= htmlspecialchars(__('test_desc')) ?>
            </p>

            <form method="POST" id="testForm">
                <textarea name="test_sentence" id="test_sentence" rows="3" placeholder="English text..."><?= htmlspecialchars($_POST['test_sentence'] ?? "today i am so busy what about u\ncould u take school today") ?></textarea>
                <div style="margin-bottom: 15px;">
                    <label style="font-size: 13px; cursor: pointer; color: #cbd5e1; display: inline-flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="test_is_retry" id="test_is_retry" value="1" <?= !empty($_POST['test_is_retry']) ? 'checked' : '' ?> style="width: auto; margin: 0;">
                        <span><?= htmlspecialchars(__('test_retry_cb')) ?></span>
                    </label>
                </div>
                <button type="submit" name="run_live_test" value="1" class="btn btn-primary">
                    <i class="bi bi-send-fill"></i> <?= htmlspecialchars(__('btn_run_test')) ?>
                </button>
            </form>

            <?php if (!empty($_POST['run_live_test'])): ?>
                <?php
                    $testSentence = trim($_POST['test_sentence'] ?? '');
                    $isRetry = !empty($_POST['test_is_retry']);
                    $gemini = new GeminiService();
                    $testRes = $gemini->analyzeSentence($testSentence, $isRetry);
                ?>
                <div style="margin-top: 25px;">
                    <?php if ($testRes): ?>
                        <div style="background: #0f172a; padding: 12px; border-radius: 8px; margin-bottom: 15px; border-right: 4px solid var(--accent); border-left: 4px solid var(--accent); font-size: 13px;">
                            <i class="bi bi-shield-check"></i> <?= htmlspecialchars(__('processed_by')) ?> <b><?= htmlspecialchars($testRes['_meta']['key_name'] ?? 'Active Key') ?></b> |
                            <?= htmlspecialchars(__('used_model')) ?> <b><?= htmlspecialchars($testRes['_meta']['model'] ?? 'Primary Model') ?></b>
                            <?= !empty($testRes['_meta']['fallback']) ? ' <span class="badge rate_limited"><i class="bi bi-arrow-left-right"></i> (Fallback)</span>' : '' ?>
                        </div>

                        <?php if (!empty($testRes['should_reply'])): ?>
                            <h3 style="font-size: 15px; color: var(--accent);"><i class="bi bi-chat-square-quote-fill"></i> <?= htmlspecialchars(__('tg_preview')) ?></h3>
                            <div style="background: #0f172a; border: 1px solid var(--card-border); border-radius: 10px; padding: 16px; line-height: 1.8; direction: ltr; text-align: left;">
                                <?= nl2br(TelegramBot::formatCorrectionMessage($testRes)) ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars(__('perfect_notice')) ?>
                            </div>
                        <?php endif; ?>

                        <h3 style="font-size: 14px; color: var(--text-muted); margin-top: 20px;"><i class="bi bi-code-square"></i> <?= htmlspecialchars(__('raw_json')) ?></h3>
                        <pre><?= htmlspecialchars(json_encode($testRes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                    <?php else: ?>
                        <div class="alert alert-danger"><i class="bi bi-exclamation-octagon-fill"></i> Error receiving response from AI. Please check your keys and model settings.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- Tab 6: System Event Logs -->
    <!-- ======================================================== -->
    <div id="tab-logs" class="tab-content">
        <div class="card">
            <h2>
                <span><i class="bi bi-journal-code"></i> <?= htmlspecialchars(__('logs_card_title')) ?></span>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="clear_log">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Clear all logs?');">
                        <i class="bi bi-eraser-fill"></i> <?= htmlspecialchars(__('btn_clear_logs')) ?>
                    </button>
                </form>
            </h2>

            <?php if (empty($recentLogs)): ?>
                <p style="color: var(--text-muted); text-align: center; padding: 20px;"><i class="bi bi-info-circle"></i> <?= htmlspecialchars(__('no_logs')) ?></p>
            <?php else: ?>
                <pre><?= htmlspecialchars($recentLogs) ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer Attribution -->
    <footer style="margin-top: 40px; padding: 20px 0; text-align: center; border-top: 1px solid var(--card-border); color: var(--text-muted); font-size: 13px;">
        <p style="margin: 0;"><?= htmlspecialchars(__('footer_text')) ?></p>
    </footer>
</div>

<script>
function openTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

    const targetTab = document.getElementById(tabId);
    if (targetTab) {
        targetTab.classList.add('active');
    }

    if (window.event && window.event.currentTarget) {
        window.event.currentTarget.classList.add('active');
    }
    localStorage.setItem('active_bot_tab', tabId);
}

// Persist active tab selection across page refreshes
document.addEventListener('DOMContentLoaded', () => {
    const savedTab = localStorage.getItem('active_bot_tab');
    if (savedTab && document.getElementById(savedTab)) {
        const btn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.getAttribute('onclick') && b.getAttribute('onclick').includes(savedTab));
        if (btn) btn.click();
    }

    // Live preview for welcome message editor
    const welcomeInput = document.getElementById('welcome_message_input');
    const welcomePreview = document.getElementById('welcome_preview_box');
    if (welcomeInput && welcomePreview) {
        welcomeInput.addEventListener('input', () => {
            welcomePreview.innerHTML = welcomeInput.value.replace(/\n/g, '<br>');
        });
    }
});
</script>

</body>
</html>
