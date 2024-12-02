<?php
$config_file = '/var/www/config/backup_config.json';
$config = json_decode(file_get_contents($config_file), true);

if (empty($config['telegram_bot_token']) || empty($config['admin_ids'])) {
    exit('Backup configuration is incomplete');
}

$bot_token = $config['telegram_bot_token'];
$admin_ids = array_map('trim', explode(',', $config['admin_ids']));
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

// Send files to all admin IDs
foreach ($admin_ids as $admin_id) {
    // Send database backup
    sendTelegramFile($bot_token, $admin_id, "/tmp/$backup_name", "Automatic Database Backup - " . date('Y-m-d H:i:s'));
}

// Clean up temporary files
unlink("/tmp/$backup_name");

// Update last backup time
$config['last_backup'] = date('Y-m-d H:i:s');
file_put_contents($config_file, json_encode($config));

