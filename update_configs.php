<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    exit('Unauthorized');
}

try {
    $db = new SQLite3('/var/www/db/subscriptions.db');
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode = WAL');
    
    $config_file = '/var/www/config/working_configs.txt';
    if (!file_exists($config_file)) {
        throw new Exception('Config file not found');
    }
    
    $configs = file_get_contents($config_file);
    if ($configs === false) {
        throw new Exception('Unable to read config file');
    }
    
    $users = $db->query('SELECT id, config_limit FROM users WHERE activated_at IS NULL OR (activated_at IS NOT NULL AND datetime(activated_at, "+" || duration || " days") > datetime("now"))');
    
    $updated = 0;
    while ($user = $users->fetchArray(SQLITE3_ASSOC)) {
        $config_array = explode("\n", trim($configs));
        $limited_configs = array_slice($config_array, 0, $user['config_limit']);
        $encoded_config = base64_encode(implode("\n", $limited_configs));
        
        $stmt = $db->prepare('UPDATE users SET subscription_link = :link WHERE id = :id');
        $stmt->bindValue(':link', $encoded_config, SQLITE3_TEXT);
        $stmt->bindValue(':id', $user['id'], SQLITE3_INTEGER);
        
        if ($stmt->execute()) {
            $updated++;
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => "Successfully updated $updated users",
        'updated_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
