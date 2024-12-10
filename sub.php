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
    
    // Create loadbalancer config
    $temp_config_file = tempnam(sys_get_temp_dir(), 'configs_');
    if ($temp_config_file === false) {
        throw new Exception('Failed to create temporary config file');
    }
    
    chmod($temp_config_file, 0664);
    chown($temp_config_file, 'www-data');
    
    if (file_put_contents($temp_config_file, implode("\n", $limited_configs)) === false) {
        unlink($temp_config_file);
        throw new Exception('Failed to write configs to temporary file');
    }
    
    $loadbalancer_output_file = tempnam(sys_get_temp_dir(), 'lb_');
    if ($loadbalancer_output_file === false) {
        unlink($temp_config_file);
        throw new Exception('Failed to create loadbalancer output file');
    }
    
    $command = "python3 /var/www/scripts/v2raycheck.py -file " . escapeshellarg($temp_config_file) . 
               " -loadbalancer -nocheck -count " . escapeshellarg($config_limit) .
               " -lb-output " . escapeshellarg($loadbalancer_output_file);
    
    exec($command . " 2>&1", $output, $return_var);
    $error_msg = implode("\n", $output);
    error_log("v2raycheck output: " . $error_msg);
    
    if ($return_var === 0 && file_exists($loadbalancer_output_file)) {
        $loadbalancer_content = file_get_contents($loadbalancer_output_file);
        error_log("Loadbalancer content: " . $loadbalancer_content);
        if ($loadbalancer_content && ($json = json_decode($loadbalancer_content, true)) !== null) {
            if (isset($json['inbounds']) && isset($json['outbounds']) && isset($json['routing'])) {
                $stmt = $db->prepare('UPDATE users SET subscription_link = :link, loadbalancer_config = :lb_config WHERE id = :id');
                $stmt->bindValue(':link', $encoded_config, SQLITE3_TEXT);
                $stmt->bindValue(':lb_config', $loadbalancer_content, SQLITE3_TEXT);
                $stmt->bindValue(':id', $user_id, SQLITE3_INTEGER);
                return $stmt->execute();
            } else {
                error_log("Invalid loadbalancer structure: " . print_r($json, true));
                throw new Exception('Invalid loadbalancer config structure');
            }
        } else {
            error_log("Invalid JSON: " . $loadbalancer_content);
            throw new Exception('Invalid JSON in loadbalancer config');
        }
    } else {
        $error_msg = implode("\n", $output);
        error_log("v2raycheck failed: " . $error_msg);
        throw new Exception('Failed to create loadbalancer config: ' . $error_msg);
    }
    
    // Clean up temporary files
    if (file_exists($temp_config_file)) {
        unlink($temp_config_file);
    }
    if (file_exists($loadbalancer_output_file)) {
        unlink($loadbalancer_output_file);
    }
    
    // Update both regular and loadbalancer configs
    $stmt = $db->prepare('UPDATE users SET subscription_link = :link, loadbalancer_link = :lb_link WHERE id = :id');
    $stmt->bindValue(':link', $encoded_config, SQLITE3_TEXT);
    $stmt->bindValue(':lb_link', $loadbalancer_encoded, SQLITE3_TEXT);
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

    // Check if this is a loadbalancer request
    $is_loadbalancer = isset($_GET['lb']) && $_GET['lb'] === '1';
    
    if ($is_loadbalancer) {
        $stmt = $db->prepare('SELECT id, loadbalancer_link as subscription_link, name, activated_at, duration, config_limit FROM users WHERE loadbalancer_token = :token');
    } else {
        $stmt = $db->prepare('SELECT id, subscription_link, name, activated_at, duration, config_limit FROM users WHERE access_token = :token');
    }
    
    $stmt->bindValue(':token', $_GET['token'], SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if (!$result) {
        http_response_code(404);
        exit('Invalid token');
    }
    
    if (!$result['activated_at']) {
        $activated_at = date('Y-m-d H:i:s');
        $updateStmt = $db->prepare('UPDATE users SET activated_at = :activated_at WHERE ' . 
            ($is_loadbalancer ? 'loadbalancer_token' : 'access_token') . ' = :token');
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
    if ($is_loadbalancer) {
        $stmt = $db->prepare('SELECT loadbalancer_config as subscription_link FROM users WHERE id = :id');
        $stmt->bindValue(':id', $result['id'], SQLITE3_INTEGER);
        $fresh_result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        // For loadbalancer, return the JSON directly
        header('Content-Type: application/json');
        echo $fresh_result['subscription_link'];
        exit;
    } else {
        $stmt = $db->prepare('SELECT subscription_link FROM users WHERE id = :id');
        $stmt->bindValue(':id', $result['id'], SQLITE3_INTEGER);
        $fresh_result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $configs = base64_decode($fresh_result['subscription_link']);
        
        // Continue with the normal subscription process...
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
        if ($is_loadbalancer) {
            $titleText = "⚖️ LoadBalancer | " . $titleText;
        }
        $titleConfig = createTitleConfig($titleText);
        
        $allConfigs = $titleConfig . "\n" . $configs;
        
        header('Profile-Title: ' . $result['name']);
        header('Subscription-UserInfo: upload=0; download=0; total=0; expire=' . $expiration_timestamp);
        header('Content-Type: text/plain');
        
        echo $allConfigs;
    }
    
} catch (Exception $e) {
    error_log("Error in sub.php: " . $e->getMessage());
    http_response_code(500);
    exit('Server Error: ' . $e->getMessage());
}
