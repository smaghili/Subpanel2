<?php
function update_user_configs($db, $user_id, $config_limit) {
    $config_file = '/var/www/config/working_configs.txt';
    
    // Read fresh configs
    $configs = file_get_contents($config_file);
    if ($configs === false) {
        throw new Exception('Unable to read config file');
    }
    
    // Split configs and limit them
    $config_array = array_filter(explode("\n", trim($configs)));
    $limited_configs = array_slice($config_array, 0, $config_limit);
    
    // Encode configs
    $encoded_config = base64_encode(implode("\n", $limited_configs));
    
    // Update database
    $stmt = $db->prepare('UPDATE users SET subscription_link = :link WHERE id = :id');
    $stmt->bindValue(':link', $encoded_config, SQLITE3_TEXT);
    $stmt->bindValue(':id', $user_id, SQLITE3_INTEGER);
    return $stmt->execute();
}

try {
    if (!isset($_GET['token'])) {
        http_response_code(400);
        exit('Invalid request');
    }

    date_default_timezone_set('Asia/Tehran');

    $db = new SQLite3('/var/www/db/subscriptions.db');
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode = WAL');

    $stmt = $db->prepare('SELECT id, subscription_link, name, activated_at, duration, config_limit FROM users WHERE access_token = :token');
    $stmt->bindValue(':token', $_GET['token'], SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$result) {
        http_response_code(404);
        exit('Invalid token');
    }

    if (!$result['activated_at']) {
        $activated_at = date('Y-m-d H:i:s');
        $updateStmt = $db->prepare('UPDATE users SET activated_at = :activated_at WHERE access_token = :token');
        $updateStmt->bindValue(':activated_at', $activated_at, SQLITE3_TEXT);
        $updateStmt->bindValue(':token', $_GET['token'], SQLITE3_TEXT);
        $updateStmt->execute();
        $result['activated_at'] = $activated_at;
    }

    // Calculate expiration
    $expiration_date = date('Y-m-d', strtotime("{$result['activated_at']} +{$result['duration']} days"));
    $expiration_timestamp = strtotime($expiration_date);
    $daysRemaining = max(0, ceil(($expiration_timestamp - strtotime('today')) / (60 * 60 * 24)));

    if (time() > $expiration_timestamp) {
        http_response_code(403);
        exit('Subscription expired');
    }

    // Force update configs
    update_user_configs($db, $result['id'], $result['config_limit']);
    
    // Get fresh configs
    $stmt = $db->prepare('SELECT subscription_link FROM users WHERE id = :id');
    $stmt->bindValue(':id', $result['id'], SQLITE3_INTEGER);
    $fresh_result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $configs = base64_decode($fresh_result['subscription_link']);

    function createTitleConfig($title) {
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $vmessConfig = [
            "v" => "2",
            "ps" => $title,
            "add" => "127.0.0.1",
            "port" => "1080",
            "id" => $uuid,
            "aid" => "0",
            "net" => "tcp",
            "type" => "none",
            "host" => "",
            "path" => "",
            "tls" => "",
            "sni" => "",
            "scy" => "auto"
        ];

        return 'vmess://' . base64_encode(json_encode($vmessConfig));
    }
    
    $configFile = '/var/www/config/working_configs.txt';
    $lastUpdateTime = date('Y-m-d H:i', filemtime($configFile));
    
    $titleText = "🔄 Update: {$lastUpdateTime} | 👤 {$result['name']} | ⏳ Days: {$daysRemaining}";
    $titleConfig = createTitleConfig($titleText);
    
    $allConfigs = $titleConfig . "\n" . $configs;
    
    header('Profile-Title: ' . $result['name']);
    header('Subscription-UserInfo: upload=0; download=0; total=0; expire=' . $expiration_timestamp);
    header('Content-Type: text/plain');
    
    echo $allConfigs;

} catch (Exception $e) {
    error_log("Error in sub.php: " . $e->getMessage());
    http_response_code(500);
    exit('Server Error: ' . $e->getMessage());
}
