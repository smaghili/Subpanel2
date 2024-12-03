<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/php_errors.log');

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

// بررسی درخواست تست مجدد
if (isset($_POST['recheck']) && isset($_POST['url'])) {
    $url = $_POST['url'];
    $results = checkConfigs($url);
    if ($results['success']) {
        // بروزرسانی رکورد موجود
        $stmt = $db->prepare('UPDATE config_checks SET 
            total_configs = :total,
            valid_configs = :valid,
            check_date = CURRENT_TIMESTAMP
            WHERE url = :url
            ORDER BY check_date DESC LIMIT 1');
        $stmt->bindValue(':url', $url, SQLITE3_TEXT);
        $stmt->bindValue(':total', $results['total'], SQLITE3_INTEGER);
        $stmt->bindValue(':valid', $results['valid'], SQLITE3_INTEGER);
        $stmt->execute();
        
        $message = '<div class="success">تست کانفیگ‌ها با موفقیت انجام شد.</div>';
    }
}
// بررسی اولیه URL
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscription_url'])) {
    $url = trim($_POST['subscription_url']);
    $bot_id = trim($_POST['bot_id']);
    
    // ذخی��ه نام بات در فایل
    file_put_contents('/var/www/config/bot_id.txt', $bot_id);
    
    if (preg_match('/^https?:\/\/[^\s\/$.?#].[^\s]*$/i', $url)) {
        // سپس کانفیگ‌ها را چک می‌کنیم
        $results = checkConfigs($url);
        if ($results['success']) {
            // ذخیره نتایج در دیتابیس
            $stmt = $db->prepare('INSERT INTO config_checks (name, url, total_configs, valid_configs) VALUES (:name, :url, :total, :valid)');
            $stmt->bindValue(':name', $_POST['config_name'], SQLITE3_TEXT);
            $stmt->bindValue(':url', $url, SQLITE3_TEXT);
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
                    // محاسبه روزهای باقی‌مانده
                    $expiry = new DateTime($bot_data['expiry_date']);
                    $now = new DateTime();
                    $days_left = $now->diff($expiry)->days;
                    
                    // محاسبه درصدها
                    $days_percentage = min(100, ($days_left / 30) * 100); // فرض 30 روزه
                    $volume_percentage = min(100, ($bot_data['used_volume'] / $bot_data['total_volume']) * 100);
                    
                    // اضافه کردن به پیام موفقیت
                    $message .= '
                    <div class="service-stats">
                        <div class="stat-box">
                            <div class="stat-header">زمان اولیه شما</div>
                            <div class="stat-value">30</div>
                            <div class="stat-unit">روز</div>
                            <div class="stat-header">زمان باقیمانده شما</div>
                            <div class="stat-value">' . $days_left . '</div>
                            <div class="stat-unit">روز</div>
                            <div class="progress-circle" data-percentage="' . $days_percentage . '">
                                <span class="progress-text">' . round($days_percentage) . '%</span>
                            </div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-header">حجم اولیه شما</div>
                            <div class="stat-value">' . $bot_data['total_volume'] . '</div>
                            <div class="stat-unit">گیگابایت</div>
                            <div class="stat-header">حجم باقیمانده شما</div>
                            <div class="stat-value">' . ($bot_data['total_volume'] - $bot_data['used_volume']) . '</div>
                            <div class="stat-unit">گیگابایت</div>
                            <div class="progress-circle" data-percentage="' . $volume_percentage . '">
                                <span class="progress-text">' . round($volume_percentage) . '%</span>
                            </div>
                        </div>
                    </div>
                    <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        document.querySelectorAll(".progress-circle").forEach(function(circle) {
                            var percentage = circle.getAttribute("data-percentage");
                            var color = percentage < 50 ? "#2ecc71" : percentage < 80 ? "#f1c40f" : "#e74c3c";
                            var radius = 70;
                            var circumference = 2 * Math.PI * radius;
                            var offset = circumference - (percentage / 100) * circumference;
                            
                            circle.innerHTML = `
                                <svg class="progress-ring" width="160" height="160">
                                    <circle class="progress-ring-circle-bg" 
                                        stroke="#eee"
                                        stroke-width="8"
                                        fill="transparent"
                                        r="${radius}"
                                        cx="80"
                                        cy="80"/>
                                    <circle class="progress-ring-circle"
                                        stroke="${color}"
                                        stroke-width="8"
                                        fill="transparent"
                                        r="${radius}"
                                        cx="80"
                                        cy="80"
                                        style="stroke-dasharray: ${circumference} ${circumference}; 
                                               stroke-dashoffset: ${offset};"/>
                                </svg>
                                <span class="progress-text">${Math.round(percentage)}%</span>
                            `;
                        });
                    });
                    </script>';
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
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
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
        .stat {
            display: flex;
            align-items: center;
            margin: 10px 0;
        }
        .stat-label {
            width: 150px;
            font-weight: bold;
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
        .valid-icon {
            color: #4CAF50;
            margin-right: 10px;
        }
        .invalid-icon {
            color: #f44336;
            margin-right: 10px;
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
        .history-table th, .history-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
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
            <div class="stat">
                <span class="stat-label">Total Configs:</span>
                <span><?= $results['total'] ?></span>
            </div>
            <div class="stat">
                <span class="stat-label">Working Configs:</span>
                <span class="valid-icon">✓</span>
                <span><?= $results['valid'] ?></span>
            </div>
            <div class="stat">
                <span class="stat-label">Not Working Configs:</span>
                <span class="invalid-icon">✗</span>
                <span><?= $results['invalid'] ?></span>
            </div>
        </div>
        <?php endif; ?>

        <h2>Recent Checks</h2>
        <table class="history-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Total Configs</th>
                    <th>Working Configs</th>
                    <th>Check Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $history->fetchArray(SQLITE3_ASSOC)): ?>
                <tr>
                    <td><a href="<?= htmlspecialchars($row['url']) ?>" target="_blank"><?= htmlspecialchars($row['name']) ?></a></td>
                    <td><?= $row['total_configs'] ?></td>
                    <td><?= $row['valid_configs'] ?> / <?= $row['total_configs'] ?></td>
                    <td><?= $row['check_date'] ?></td>
                    <td>
                        <form method="POST" style="display: inline;" onsubmit="showLoading()">
                            <input type="hidden" name="url" value="<?= htmlspecialchars($row['url']) ?>">
                            <button type="submit" name="recheck" class="recheck-btn">Recheck</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script>
        function showLoading() {
            document.getElementById('loading').style.display = 'block';
        }
    </script>
</body>
</html> 