<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// تابع اجرای تست کانفیگ‌ها
function checkConfigs($url) {
    $output = [];
    $return_var = 0;
    
    exec("python3 v2raycheck.py -config \"$url\" -save \"/tmp/valid_configs.txt\" -position start 2>&1", $output, $return_var);
    
    // پردازش خروجی برای دریافت تعداد کانفیگ‌های معتبر و نامعتبر
    $total = 0;
    $valid = 0;
    $invalid = 0;
    
    foreach ($output as $line) {
        if (strpos($line, "Total configs:") !== false) {
            $total = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
        }
        if (strpos($line, "Valid configs:") !== false) {
            $valid = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
        }
    }
    
    $invalid = $total - $valid;
    
    return [
        'total' => $total,
        'valid' => $valid,
        'invalid' => $invalid,
        'success' => ($return_var === 0)
    ];
}

$message = '';
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscription_url'])) {
    $url = trim($_POST['subscription_url']);
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        $results = checkConfigs($url);
        if ($results['success']) {
            $message = '<div class="success">تست کانفیگ‌ها با موفقیت انجام شد.</div>';
        } else {
            $message = '<div class="error">خطا در اجرای تست کانفیگ‌ها</div>';
        }
    } else {
        $message = '<div class="error">لطفاً یک URL معتبر وارد کنید</div>';
    }
}
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
    </style>
</head>
<body>
    <div class="container">
        <h1>Check Subscription Configs</h1>
        
        <div style="text-align: right; margin-bottom: 20px;">
            <button class="back-button" onclick="window.location.href='index.php'">Back to Dashboard</button>
        </div>

        <?= $message ?>

        <form method="POST">
            <div class="form-group">
                <input type="url" name="subscription_url" placeholder="Enter subscription URL" required>
            </div>
            <button type="submit">Check Configs</button>
        </form>

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
    </div>
</body>
</html> 