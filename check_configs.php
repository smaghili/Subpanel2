<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/php_errors.log');

// تنظیم timezone
date_default_timezone_set('Asia/Tehran');
ini_set('date.timezone', 'Asia/Tehran');

// افزایش timeout
set_time_limit(300);
ini_set('max_execution_time', '300');

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

try {
    $db = new SQLite3('/var/www/db/subscriptions.db');
    
    // چک کردن وجود ستون‌های مورد نیاز
    $result = $db->query("PRAGMA table_info(config_checks)");
    $has_bot_id = false;
    $has_first_check_date = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === 'bot_id') {
            $has_bot_id = true;
        }
        if ($row['name'] === 'first_check_date') {
            $has_first_check_date = true;
        }
    }
    
    // اضافه کردن ستون‌های مورد نیاز
    if (!$has_bot_id) {
        $db->exec('ALTER TABLE config_checks ADD COLUMN bot_id TEXT');
    }
    if (!$has_first_check_date) {
        $db->exec('ALTER TABLE config_checks ADD COLUMN first_check_date DATETIME');
        // آپدیت رکوردهای موجود
        $db->exec('UPDATE config_checks SET first_check_date = (
            SELECT MIN(check_date) 
            FROM config_checks AS c2 
            WHERE c2.url = config_checks.url
        )');
    }
} catch (Exception $e) {
    die('Database Error: ' . $e->getMessage());
}

function checkConfigs($url) {
    $output = [];
    $return_var = 0;
    
    $script_path = '/var/www/scripts/v2raycheck.py';
    
    $cmd = "PATH=/usr/local/bin:/usr/bin:/bin python3 $script_path -config \"$url\" -save \"/tmp/valid_configs.txt\" -position start 2>&1";
    
    exec($cmd, $output, $return_var);
    
    $total = 0;
    $valid = 0;
    
    foreach ($output as $line) {
        if (strpos($line, "Total configs:") !== false) {
            $total = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
        }
        if (strpos($line, "Valid configs:") !== false) {
            $valid = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
        }
    }
    
    return [
        'total' => $total,
        'valid' => $valid,
        'invalid' => $total - $valid,
        'success' => ($return_var === 0)
    ];
}

$message = '';
$results = null;

// بررسی درخواست حذف
if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    
    // حذف از جدول usage_data
    $stmt = $db->prepare('DELETE FROM usage_data WHERE config_id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    
    // حذف از جدول config_checks
    $stmt = $db->prepare('DELETE FROM config_checks WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->execute();
    
    $message = '<div class="success">مورد با موفقیت حذف شد.</div>';
}

// بررسی درخواست تست مجدد
if (isset($_POST['recheck']) && isset($_POST['url'])) {
    $url = $_POST['url'];
    $results = checkConfigs($url);
    if ($results['success']) {
        // بروزرسانی رکورد موجود
        $stmt = $db->prepare('UPDATE config_checks SET 
            total_configs = :total,
            valid_configs = :valid,
            check_date = datetime("now", "localtime")
            WHERE url = :url
            ORDER BY check_date DESC LIMIT 1');
        $stmt->bindValue(':url', $url, SQLITE3_TEXT);
        $stmt->bindValue(':total', $results['total'], SQLITE3_INTEGER);
        $stmt->bindValue(':valid', $results['valid'], SQLITE3_INTEGER);
        $stmt->execute();

        // اجرای مانیتور بات
        $config_id = $db->querySingle("SELECT id FROM config_checks WHERE url = '" . SQLite3::escapeString($url) . "' ORDER BY check_date DESC LIMIT 1");
        if ($config_id) {
            $bot_output = shell_exec("python3 /var/www/scripts/monitor-bot.py");
            $bot_data = json_decode($bot_output, true);
            
            if ($bot_data && !isset($bot_data['error'])) {
                $stmt = $db->prepare('INSERT INTO usage_data (config_id, total_volume, used_volume, days_left) 
                    VALUES (:config_id, :total_volume, :used_volume, :days_left)');
                $stmt->bindValue(':config_id', $config_id, SQLITE3_INTEGER);
                $stmt->bindValue(':total_volume', $bot_data['total_volume'], SQLITE3_FLOAT);
                $stmt->bindValue(':used_volume', $bot_data['used_volume'], SQLITE3_FLOAT);
                $stmt->bindValue(':days_left', $bot_data['days_left'], SQLITE3_INTEGER);
                $stmt->execute();
            }
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
    exit;
}

// نمایش پیام موفقیت فقط در بالای صفحه
$message = '';
if (isset($_GET['success'])) {
    $message = '<div class="success">تست کانفیگ‌ها با موفقیت انجام شد.</div>';
}

// بررسی اولیه URL
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscription_url'])) {
    $url = trim($_POST['subscription_url']);
    $bot_id = trim($_POST['bot_id']);
    
    // ذخیره نام بات در فایل
    file_put_contents('/var/www/config/bot_id.txt', $bot_id);
    
    if (preg_match('/^https?:\/\/[^\s\/$.?#].[^\s]*$/i', $url)) {
        // سپس کانفیگ‌ها را چک می‌کنیم
        $results = checkConfigs($url);
        if ($results['success']) {
            // ذخیره نتایج در دیتابیس
            $stmt = $db->prepare('INSERT INTO config_checks (name, url, bot_id, total_configs, valid_configs, check_date, first_check_date) 
                VALUES (:name, :url, :bot_id, :total, :valid, datetime("now", "localtime"), datetime("now", "localtime"))');
            $stmt->bindValue(':name', $_POST['config_name'], SQLITE3_TEXT);
            $stmt->bindValue(':url', $url, SQLITE3_TEXT);
            $stmt->bindValue(':bot_id', $_POST['bot_id'], SQLITE3_TEXT);
            $stmt->bindValue(':total', $results['total'], SQLITE3_INTEGER);
            $stmt->bindValue(':valid', $results['valid'], SQLITE3_INTEGER);
            $stmt->execute();
            
            $message = '<div class="success">تست کانفیگ‌ها با موفقیت انجام شد.</div>';

            // اگر نام بات وارد شده باشد، اطلاعات سرویس را هم چک می‌کنیم
            if (!empty($bot_id)) {
                $cmd = "python3 /var/www/scripts/monitor-bot.py 2>&1";
                $bot_output = shell_exec($cmd);
                error_log("Monitor Bot Output: " . $bot_output);
                $bot_data = json_decode($bot_output, true);
                
                if ($bot_data && !isset($bot_data['error'])) {
                    // ذخیره اطلاعات مصرف در دیتابیس
                    $stmt = $db->prepare('INSERT INTO usage_data (config_id, total_volume, used_volume, days_left) 
                        SELECT id, :total_volume, :used_volume, :days_left 
                        FROM config_checks 
                        WHERE url = :url 
                        ORDER BY check_date DESC LIMIT 1');
                    $stmt->bindValue(':url', $url, SQLITE3_TEXT);
                    $stmt->bindValue(':total_volume', $bot_data['total_volume'], SQLITE3_FLOAT);
                    $stmt->bindValue(':used_volume', $bot_data['used_volume'], SQLITE3_FLOAT);
                    $stmt->bindValue(':days_left', $bot_data['days_left'], SQLITE3_INTEGER);
                    $stmt->execute();

                    $message = '<div class="success">تست کانفیگ‌ها با موفقیت انجام شد.</div>';
                }
            }
        } else {
            $message = '<div class="error">خطا در اجرای تست کانفیگ‌ها</div>';
        }
    } else {
        $message = '<div class="error">لطفاً یک URL معتبر وارد کنید</div>';
    }
}
// دریافت تاریخچه تست‌ها
$history = $db->query('SELECT * FROM config_checks ORDER BY check_date DESC LIMIT 10');

// در ابتدای فایل بعد از error_reporting
date_default_timezone_set('Asia/Tehran');
require_once 'jdf.php';  // برای تبدیل تاریخ به شمسی

// تابع تبدیل اعداد انگلیسی به فارسی
function en2fa($string) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '.'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.'];
    return str_replace($english, $persian, $string);
}

// Add settings table if not exists
$db->exec('CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    check_interval INTEGER DEFAULT 6,
    last_check DATETIME
)');

// Insert default value if not exists
$db->exec('INSERT OR IGNORE INTO settings (id, check_interval, last_check) VALUES (1, 6, datetime("now"))');

// Handle auto-check settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_interval'])) {
    $interval = intval($_POST['check_interval']);
    if ($interval >= 1) {
        $stmt = $db->prepare('UPDATE settings SET check_interval = :interval WHERE id = 1');
        $stmt->bindValue(':interval', $interval, SQLITE3_INTEGER);
        $stmt->execute();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?settings_saved=1');
        exit;
    }
}

// Get current settings
$settings = $db->querySingle('SELECT * FROM settings WHERE id = 1', true);

// Handle interval update
if (isset($_POST['check_interval'])) {
    $interval = intval($_POST['check_interval']);
    if ($interval >= 1 && $interval <= 24) {
        // Update cron job
        $output = shell_exec("/var/www/html/auto_check.sh set " . escapeshellarg($interval));
        $success_message = "Auto-check interval updated successfully";
    }
}

?>

<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="UTF-8">
    <title>Check Subscription Configs</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            min-width: 900px;
        }
        h1 { 
            text-align: center; 
            color: #333; 
        }
        .form-group {
            margin-bottom: 20px;
        }
        input[type="url"], input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        .results {
            margin-top: 20px;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .success {
            color: #4CAF50;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            background-color: #e8f5e9;
        }
        .error {
            color: #f44336;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            background-color: #ffebee;
        }
        .back-button {
            background-color: #2196F3;
            margin-right: 10px;
        }
        .history-table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        .history-table th {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: center;
            background-color: #f8f9fa;
        }
        .history-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: right;
        }
        .recheck-btn {
            background-color: #FF9800;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .recheck-btn:hover {
            background-color: #F57C00;
        }
        .loading {
            display: none;
            text-align: center;
            margin: 20px 0;
        }
        .history-table a {
            color: #2196F3;
            text-decoration: none;
        }
        .history-table a:hover {
            text-decoration: underline;
        }
        .usage-chart {
            width: 200px;
            height: 200px;
            margin: 20px auto;
        }
        .stats-container {
            display: flex;
            justify-content: space-around;
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .stat-box {
            text-align: center;
        }
        .auth-button {
            background-color: #0088cc;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 10px;
            width: 100%;
        }
        .auth-button:hover {
            background-color: #006699;
        }
        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-group input:focus {
            border-color: #4CAF50;
            outline: none;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
        }
        button[type="submit"] {
            display: block;
            margin: 0 auto;
        }
        .service-stats {
            display: flex;
            justify-content: space-around;
            align-items: center;
            margin: 20px 0;
            padding: 20px;
            background-color: #2a2f4c;
            border-radius: 15px;
        }
        .stat-box {
            text-align: center;
            padding: 20px;
            color: white;
            position: relative;
        }
        .stat-header {
            font-size: 14px;
            color: #8e94b2;
            margin-bottom: 5px;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: white;
        }
        .stat-unit {
            font-size: 12px;
            color: #8e94b2;
            margin-bottom: 15px;
        }
        .progress-circle {
            position: relative;
            width: 160px;
            height: 160px;
            margin: 0 auto;
        }
        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 20px;
            font-weight: bold;
            color: white;
        }
        .progress-ring-circle {
            transition: stroke-dashoffset 0.35s;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }
        .mini-progress-circle {
            position: relative;
            width: 40px;
            height: 40px;
            flex-shrink: 0;
        }
        .usage-text {
            font-size: 12px;
            color: #666;
            line-height: 1.4;
            white-space: nowrap;
        }
        .mini-progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 10px;
            font-weight: bold;
            color: #000;
        }
        .usage-info {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
        }
        .usage-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .delete-btn {
            background-color: #dc3545;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 5px;
        }
        .delete-btn:hover {
            background-color: #c82333;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        .settings-form {
            margin-bottom: 1rem;
        }
        .settings-form .form-group {
            margin-bottom: 0.5rem;
        }
        .settings-form label {
            display: block;
            margin-bottom: 0.5rem;
        }
        .alert {
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            border-radius: 0.25rem;
        }
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        .interval-form {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .interval-form label {
            display: block;
            margin-bottom: 5px;
        }
        .interval-form input[type="number"] {
            width: 100px;
            padding: 5px;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Check Subscription Configs</h1>
        
        <div style="text-align: right; margin-bottom: 20px;">
            <button class="back-button" onclick="window.location.href='index.php'">Back to Dashboard</button>
        </div>

        <?= $message ?>

        <form method="POST" onsubmit="showLoading()">
            <div class="form-group">
                <input type="text" name="config_name" placeholder="Config Name" required style="margin-bottom: 10px;">
            </div>
            <div class="form-group">
                <input type="url" name="subscription_url" placeholder="Subscription URL" required style="margin-bottom: 10px;">
            </div>
            <div class="form-group">
                <input type="text" name="bot_id" placeholder="Bot ID" required style="margin-bottom: 10px;">
            </div>
            <button type="submit">Check Configs</button>
        </form>

        <div id="loading" class="loading">
            <p>Checking configs, please wait...</p>
            <div id="live-output"></div>
        </div>

        <?php if ($results): ?>
        <div class="results">
            <div class="success" style="direction: rtl;">تست کانفیگ‌ها با موفقیت انجام شد.</div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">
                <h3>تنظیمات بررسی خودکار</h3>
            </div>
            <div class="card-body">
                <?php if (isset($_GET['settings_saved'])): ?>
                <div class="alert alert-success">تنظیمات با موفقیت ذخیره شد</div>
                <?php endif; ?>

                <form method="POST" class="settings-form">
                    <div class="form-group">
                        <label for="check_interval">فاصله زمانی بررسی خودکار (ساعت):</label>
                        <div class="input-group" style="max-width: 200px;">
                            <input type="number" id="check_interval" name="check_interval" 
                                   value="<?php echo $settings['check_interval']; ?>" 
                                   min="1" required class="form-control">
                            <div class="input-group-append">
                                <button type="submit" class="btn btn-primary">ذخیره</button>
                            </div>
                        </div>
                        <small class="form-text text-muted">
                            حداقل زمان مجاز 1 ساعت است
                            <?php if ($settings['last_check']): ?>
                            <br>آخرین بررسی: <?php echo $settings['last_check']; ?>
                            <?php endif; ?>
                        </small>
                    </div>
                </form>
            </div>
        </div>

        <h2>Recent Checks</h2>
        <table class="history-table">
            <thead>
                <tr>
                    <th>نام</th>
                    <th>کانفیگ‌های فعال</th>
                    <th>تاریخ بررسی</th>
                    <th>مصرف</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $history->fetchArray(SQLITE3_ASSOC)):
                    $usage = $db->querySingle("SELECT * FROM usage_data WHERE config_id = {$row['id']} ORDER BY check_date DESC LIMIT 1", true);
                    $first_check = $db->querySingle("SELECT check_date FROM config_checks WHERE url = '" . SQLite3::escapeString($row['url']) . "' ORDER BY check_date ASC LIMIT 1");
                    
                    if ($usage) {
                        // محاسبه درصد روزهای باقیمانده
                        $start_date = new DateTime($first_check);
                        $current_date = new DateTime();
                        $total_days = $usage['days_left']; // کل روزهای باقیمانده از زمان اولین چک
                        
                        $days_passed = $current_date->diff($start_date)->days;
                        $days_percentage = 100 - (($usage['days_left'] / $total_days) * 100);
                        
                        // محاسبه درصد حجم مصرفی (بدون تغییر)
                        $volume_used_percentage = ($usage['used_volume'] / $usage['total_volume']) * 100;
                        $volume_remaining = $usage['total_volume'] - $usage['used_volume'];
                    ?>
                    <tr>
                        <td><a href="<?= htmlspecialchars($row['url']) ?>" target="_blank"><?= htmlspecialchars($row['name']) ?></a></td>
                        <td style="text-align: center; direction: rtl;">
                        <?php
                            $valid = en2fa($row['valid_configs']);
                            $total = en2fa($row['total_configs']);
                            echo "{$valid} از {$total}";
                        ?>
                        </td>
                        <td style="text-align: center; direction: rtl;">
                        <?php 
                            $timestamp = strtotime($row['check_date']);
                            $tehran_timestamp = $timestamp + (3.5 * 3600);
                            echo en2fa(jdate("Y/m/d H:i", $tehran_timestamp));
                        ?>
                        </td>
                        <td>
                            <div class="usage-info">
                                <div class="usage-item" style="direction: rtl;">
                                    <div class="mini-progress-circle" data-percentage="<?= $days_percentage ?>">
                                        <span class="mini-progress-text"><?= round($days_percentage) ?>%</span>
                                    </div>
                                    <div class="usage-text">
                                        <?= en2fa($usage['days_left']) ?> روز باقیمانده
                                    </div>
                                </div>
                                <div class="usage-item">
                                    <div class="mini-progress-circle" data-percentage="<?= $volume_used_percentage ?>">
                                        <span class="mini-progress-text"><?= round($volume_used_percentage) ?>%</span>
                                    </div>
                                    <div class="usage-text">
                                        حجم کل: <?= en2fa($usage['total_volume']) ?> گیگابایت<br>
                                        باقیمانده: <?= en2fa(number_format($volume_remaining, 1)) ?> گیگابایت
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <form method="POST" style="display: inline;" onsubmit="return confirm('آیا از حذف این مورد اطمینان دارید؟');">
                                    <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                    <button type="submit" class="delete-btn">حذف</button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="showLoading()">
                                    <input type="hidden" name="url" value="<?= htmlspecialchars($row['url']) ?>">
                                    <button type="submit" name="recheck" class="recheck-btn">بررسی مجدد</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php
                    }
                ?>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script>
        function showLoading() {
            document.getElementById('loading').style.display = 'block';
        }

        document.addEventListener("DOMContentLoaded", function() {
            // تابع رسم دایره پیشرفت
            function drawProgressCircle(circle, isMini = false) {
                var percentage = circle.getAttribute("data-percentage");
                var color = percentage < 50 ? "#2ecc71" : percentage < 80 ? "#f1c40f" : "#e74c3c";
                var size = isMini ? 40 : 160;
                var strokeWidth = isMini ? 3 : 8;
                var radius = isMini ? 16 : 70;
                var circumference = 2 * Math.PI * radius;
                var offset = circumference - (percentage / 100) * circumference;
                
                circle.innerHTML = `
                    <svg class="progress-ring" width="${size}" height="${size}">
                        <circle class="progress-ring-circle-bg" 
                            stroke="#eee"
                            stroke-width="${strokeWidth}"
                            fill="transparent"
                            r="${radius}"
                            cx="${size/2}"
                            cy="${size/2}"/>
                        <circle class="progress-ring-circle"
                            stroke="${color}"
                            stroke-width="${strokeWidth}"
                            fill="transparent"
                            r="${radius}"
                            cx="${size/2}"
                            cy="${size/2}"
                            style="stroke-dasharray: ${circumference} ${circumference}; 
                                   stroke-dashoffset: ${offset};"/>
                    </svg>
                    <span class="mini-progress-text">${Math.round(percentage)}%</span>
                `;
            }

            // رسم همه دایره‌های کوچک
            document.querySelectorAll(".mini-progress-circle").forEach(function(circle) {
                drawProgressCircle(circle, true);
            });
        });
    </script>
</body>
</html> 