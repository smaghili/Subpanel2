<?php
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
    
    // اجرای اسکریپت و نمایش خروجی در لحظه
    $cmd = "PATH=/usr/local/bin:$PATH python3 $script_path -config \"$url\" -save \"/tmp/valid_configs.txt\" -position start 2>&1";
    $handle = popen($cmd, 'r');
    
    echo "<pre>";
    while (!feof($handle)) {
        $buffer = fgets($handle);
        echo $buffer;
        flush();
        ob_flush();
        $output[] = $buffer;
    }
    echo "</pre>";
    
    pclose($handle);
    
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
        // ذخیره نتایج جدید در دیتابیس
        $stmt = $db->prepare('INSERT INTO config_checks (url, total_configs, valid_configs) VALUES (:url, :total, :valid)');
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
    if (filter_var($url, FILTER_VALIDATE_URL) || preg_match('/^https?:\/\/[\w\-\.\u4e00-\u9fa5]+/u', $url)) {
        $results = checkConfigs($url);
        if ($results['success']) {
            // ذخیره نتایج در دیتابیس
            $stmt = $db->prepare('INSERT INTO config_checks (url, total_configs, valid_configs) VALUES (:url, :total, :valid)');
            $stmt->bindValue(':url', $url, SQLITE3_TEXT);
            $stmt->bindValue(':total', $results['total'], SQLITE3_INTEGER);
            $stmt->bindValue(':valid', $results['valid'], SQLITE3_INTEGER);
            $stmt->execute();
            
            $message = '<div class="success">تست کانفیگ‌ها با موفقیت انجام شد.</div>';
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
        input[type="url"] {
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
                <input type="url" name="subscription_url" placeholder="Enter subscription URL" required>
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
                    <th>URL</th>
                    <th>Total Configs</th>
                    <th>Working Configs</th>
                    <th>Check Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $history->fetchArray(SQLITE3_ASSOC)): ?>
                <tr>
                    <td><?= htmlspecialchars($row['url']) ?></td>
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