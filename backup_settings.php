<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$config_file = '/var/www/config/backup_config.json';
$config = json_decode(file_get_contents($config_file), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bot_token = trim($_POST['bot_token']);
    $admin_ids = trim($_POST['admin_ids']);
    $backup_interval = intval($_POST['backup_interval']);

    if (empty($bot_token) || empty($admin_ids) || $backup_interval < 1) {
        $error = 'All fields are required and backup interval must be greater than 0';
    } else {
        $config['telegram_bot_token'] = $bot_token;
        $config['admin_ids'] = $admin_ids;
        $config['backup_interval'] = $backup_interval;
        
        if (file_put_contents($config_file, json_encode($config))) {
            // Setup or update crontab
            $output = [];
            $cronCmd = "crontab -l | grep -v '/var/www/html/auto_backup.php'";
            exec($cronCmd, $output);
            
            $newCron = implode("\n", $output) . "\n";
            $newCron .= "0 */{$backup_interval} * * * php /var/www/html/auto_backup.php\n";
            
            file_put_contents('/tmp/crontab.txt', $newCron);
            exec('crontab /tmp/crontab.txt');
            unlink('/tmp/crontab.txt');
            
            // Send immediate backup after saving settings
            $db_path = '/var/www/db/subscriptions.db';
            
            // Create backup of database
            $backup_name = 'subscription_backup_' . date('Y-m-d_H-i-s') . '.db';
            copy($db_path, "/tmp/$backup_name");
            
            // Function to send file to Telegram
            function sendTelegramFile($bot_token, $chat_id, $file_path, $caption = '') {
                $url = "https://api.telegram.org/bot$bot_token/sendDocument";
                $post_fields = [
                    'chat_id' => $chat_id,
                    'document' => new CURLFile($file_path),
                    'caption' => $caption
                ];
            
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $result = curl_exec($ch);
                curl_close($ch);
                return $result;
            }
            
            // Send immediate backup to all admin IDs
            $admin_ids_array = array_map('trim', explode(',', $admin_ids));
            foreach ($admin_ids_array as $admin_id) {
                // Send database backup
                sendTelegramFile($bot_token, $admin_id, "/tmp/$backup_name", "Database Backup - Settings Updated - " . date('Y-m-d H:i:s'));
            }
            
            // Clean up temporary files
            unlink("/tmp/$backup_name");
            
            // Update last backup time
            $config['last_backup'] = date('Y-m-d H:i:s');
            file_put_contents($config_file, json_encode($config));
            
            $success = 'Backup settings updated successfully and initial backup sent!';
        } else {
            $error = 'Failed to save settings';
        }
    }
}
?>
<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="UTF-8">
    <title>Automatic Backup Settings</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
        }
        .container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 { text-align: center; color: #333; }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }
        button:hover {
            background-color: #45a049;
        }
        .success { color: green; }
        .error { color: red; }
        .back-link {
            display: block;
            margin-top: 20px;
            text-align: center;
            color: #666;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Automatic Backup Settings</h1>
        
        <?php if (isset($success)): ?>
            <p class="success"><?= htmlspecialchars($success) ?></p>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="bot_token">Telegram Bot Token:</label>
                <input type="text" id="bot_token" name="bot_token" value="<?= htmlspecialchars($config['telegram_bot_token']) ?>" required>
            </div>
            
            <div class="form-group">
                <label for="admin_ids">Admin IDs (comma-separated):</label>
                <input type="text" id="admin_ids" name="admin_ids" value="<?= htmlspecialchars($config['admin_ids']) ?>" required>
            </div>
            
            <div class="form-group">
                <label for="backup_interval">Backup Interval (hours):</label>
                <input type="number" id="backup_interval" name="backup_interval" value="<?= htmlspecialchars($config['backup_interval']) ?>" min="1" required>
            </div>
            
            <button type="submit">Save Settings</button>
        </form>
        
        <a href="index.php" class="back-link">Back to Dashboard</a>
    </div>
</body>
</html>

