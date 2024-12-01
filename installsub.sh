#!/bin/bash

# Function to show errors and exit
show_error() {
    echo -e "\e[31m[ERROR] $1\e[0m"
    exit 1
}

read -p "Please enter your domain name (e.g., example.com): " DOMAIN_NAME

if [[ ! $DOMAIN_NAME =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*[a-zA-Z0-9]\.[a-zA-Z]{2,}$ ]]; then
    show_error "Invalid domain name format\nDomain name should be in format: example.com or sub.example.com"
fi

echo "Using domain: $DOMAIN_NAME"
echo "Installation will begin in 3 seconds... Press Ctrl+C to cancel"
sleep 3

WEB_ROOT="/var/www/html"
DB_DIR="/var/www/db"
CONFIG_DIR="/var/www/config"
DB_PATH="${DB_DIR}/subscriptions.db"
CONFIG_FILE_PATH="${CONFIG_DIR}/working_configs.txt"
BACKUP_CONFIG_FILE="${CONFIG_DIR}/backup_config.json"

sudo mkdir -p $WEB_ROOT $DB_DIR $CONFIG_DIR

# Create backup config file
if [ ! -f "$BACKUP_CONFIG_FILE" ]; then
    echo '{"telegram_bot_token":"","admin_ids":"","backup_interval":24,"last_backup":""}' > "$BACKUP_CONFIG_FILE"
fi

# Install required packages in one command to reduce apt calls
sudo apt update && sudo apt install -y nginx certbot python3-certbot-nginx php-fpm php-sqlite3 sqlite3 inotify-tools php-curl

PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"

# Combine all PHP configurations into one sed command
sed -i '
    s/memory_limit = .*/memory_limit = 256M/;
    s/post_max_size = .*/post_max_size = 100M/;
    s/upload_max_filesize = .*/upload_max_filesize = 100M/;
    s/max_execution_time = .*/max_execution_time = 300/;
    s/session.gc_maxlifetime = .*/session.gc_maxlifetime = 86400/;
    s/session.gc_probability = .*/session.gc_probability = 1/;
    s/session.gc_divisor = .*/session.gc_divisor = 100/
' /etc/php/${PHP_VERSION}/fpm/php.ini

if [ ! -f "$CONFIG_FILE_PATH" ]; then
    touch "$CONFIG_FILE_PATH"
    echo "# Add your configs here" > "$CONFIG_FILE_PATH"
fi

if [ ! -f "$DB_PATH" ]; then
    # Create database with all tables in one command
    sqlite3 "$DB_PATH" "
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        subscription_link TEXT NOT NULL,
        access_token TEXT UNIQUE NOT NULL,
        config_limit INTEGER NOT NULL DEFAULT 10,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        activated_at DATETIME DEFAULT NULL,
        duration INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS admin (
        id INTEGER PRIMARY KEY,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL
    );
    INSERT INTO admin (username, password) VALUES ('admin', 'admin123');
    "
fi

# Set permissions in one go
sudo chown -R www-data:www-data $WEB_ROOT $DB_DIR $CONFIG_DIR
sudo chmod -R 755 $WEB_ROOT
sudo chmod -R 775 $DB_DIR $CONFIG_DIR
sudo chmod 664 $DB_PATH $CONFIG_FILE_PATH
sudo chmod 777 /var/lib/php/sessions

CERT_PATH="/etc/letsencrypt/live/$DOMAIN_NAME/fullchain.pem"
KEY_PATH="/etc/letsencrypt/live/$DOMAIN_NAME/privkey.pem"

if [ ! -f "$CERT_PATH" ] || [ ! -f "$KEY_PATH" ]; then
    sudo certbot --nginx -d $DOMAIN_NAME --non-interactive --agree-tos --register-unsafely-without-email
fi

cat << 'EOF' > "$WEB_ROOT/login.php"
<?php
if (session_status() === PHP_SESSION_NONE) {
   session_start();
}

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
   header('Location: index.php');
   exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   try {
       $db = new SQLite3('/var/www/db/subscriptions.db');
       $db->busyTimeout(5000);
       $db->exec('PRAGMA journal_mode = WAL');
       
       $username = filter_var($_POST['username'] ?? '', FILTER_SANITIZE_STRING);
       $password = $_POST['password'] ?? '';

       $stmt = $db->prepare('SELECT password FROM admin WHERE username = :username');
       $stmt->bindValue(':username', $username, SQLITE3_TEXT);
       $result = $stmt->execute();
       $row = $result->fetchArray(SQLITE3_ASSOC);

       if ($row && $password === $row['password']) {
           $_SESSION['admin_logged_in'] = true;
           header('Location: index.php');
           exit;
       } else {
           $error = 'Invalid username or password';
       }
   } catch (Exception $e) {
       $error = 'Database error: ' . $e->getMessage();
   }
}
?>
<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        h1 { 
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        input {
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        button {
            padding: 12px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #45a049;
        }
        .error {
            color: #ff4444;
            text-align: center;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Admin Login</h1>
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>
EOF

cat << 'EOF' > "$WEB_ROOT/logout.php"
<?php
session_start();
session_destroy();
header('Location: login.php');
exit;
EOF

cat << 'EOF' > "$WEB_ROOT/index.php"
<?php
session_start();
date_default_timezone_set('Asia/Tehran');
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
try {
    $db = new SQLite3('/var/www/db/subscriptions.db');
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = OFF');
    $db->exec('PRAGMA cache_size = 100000');
} catch (Exception $e) {
    die('Database Error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_configs'])) {
        $new_configs = trim($_POST['configs']);
        if (!empty($new_configs)) {
            $manual_config_file = '/var/www/config/manual_configs.txt';
            $working_config_file = '/var/www/config/working_configs.txt';

            // Ensure the files exist
            if (!file_exists($manual_config_file)) {
                touch($manual_config_file);
            }
            if (!file_exists($working_config_file)) {
                touch($working_config_file);
            }

            // Process and rename the configs
            $configs = explode("\n", $new_configs);
            $renamed_configs = [];
            foreach ($configs as $config) {
                $config = trim($config);
                if (!empty($config)) {
                    if (str_starts_with($config, 'vmess://')) {
                        // If it's vmess format, add a name after decoding
                        $decoded_config = base64_decode(substr($config, 8));
                        if ($decoded_config) {
                            $config_data = json_decode($decoded_config, true);
                            if ($config_data && isset($config_data['ps'])) {
                                $config_data['ps'] = $config_data['ps'] . '-Manual';
                            } else {
                                $config_data['ps'] = 'Manual';
                            }
                            $encoded_config = 'vmess://' . base64_encode(json_encode($config_data));
                            $renamed_configs[] = $encoded_config;
                        } else {
                            // If decoding fails, just append "-Manual"
                            $renamed_configs[] = $config . '#Manual';
                        }
                    } else {
                        // For other formats, handle normally
                        $base_config = explode('#', $config, 2)[0];
                        $name = isset(explode('#', $config, 2)[1]) ? explode('#', $config, 2)[1] : '';
                        $new_name = $name ? "{$name}-Manual" : "Manual";
                        $renamed_configs[] = "{$base_config}#{$new_name}";
                    }
                }
            }

            // Save configs to manual_configs.txt
            file_put_contents($manual_config_file, implode(PHP_EOL, $renamed_configs) . PHP_EOL, FILE_APPEND);

            // Add configs to the start of working_configs.txt
            $existing_working_configs = file_get_contents($working_config_file);
            $updated_working_configs = implode(PHP_EOL, $renamed_configs) . PHP_EOL . $existing_working_configs;
            file_put_contents($working_config_file, $updated_working_configs);

            echo "Configs added successfully!";
        } else {
            echo "Error: No configs provided.";
        }
    }
elseif (isset($_POST['edit_id'])) {
    $edit_id = filter_input(INPUT_POST, 'edit_id', FILTER_VALIDATE_INT);
    $new_name = trim(filter_input(INPUT_POST, 'edit_name', FILTER_SANITIZE_STRING));
    $new_config_limit = filter_input(INPUT_POST, 'edit_config_limit', FILTER_VALIDATE_INT);
    $is_onhold = isset($_POST['edit_is_onhold']) ? 1 : 0;

    if ($edit_id && $new_name && $new_config_limit > 0) {
        if ($is_onhold) {
            // کاربر می‌خواهد اشتراک از اولین اتصال شروع شود
            $new_duration = filter_input(INPUT_POST, 'edit_duration', FILTER_VALIDATE_INT);
            if ($new_duration <= 0) {
                $error = 'Invalid duration';
            } else {
                $stmt = $db->prepare('UPDATE users SET name = :name, duration = :duration, config_limit = :limit, activated_at = NULL WHERE id = :id');
                $stmt->bindValue(':id', $edit_id, SQLITE3_INTEGER);
                $stmt->bindValue(':name', $new_name, SQLITE3_TEXT);
                $stmt->bindValue(':duration', $new_duration, SQLITE3_INTEGER);
                $stmt->bindValue(':limit', $new_config_limit, SQLITE3_INTEGER);
                // 'activated_at' را به NULL تنظیم می‌کنیم
            }
        } else {
            // کاربر می‌خواهد تاریخ انقضا را دستی وارد کند
            $new_expiration = filter_input(INPUT_POST, 'edit_expiration', FILTER_SANITIZE_STRING);
            $current_activated_at = $db->querySingle('SELECT activated_at FROM users WHERE id = ' . $edit_id);

            if ($current_activated_at) {
                // 'activated_at' موجود است
                // محاسبه duration جدید بر اساس 'activated_at' موجود و تاریخ انقضای جدید
                $duration = ceil((strtotime($new_expiration) - strtotime($current_activated_at)) / (60 * 60 * 24));

                if ($duration <= 0) {
                    $error = 'Invalid expiration date';
                } else {
                    $stmt = $db->prepare('UPDATE users SET name = :name, duration = :duration, config_limit = :limit WHERE id = :id');
                    $stmt->bindValue(':id', $edit_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':name', $new_name, SQLITE3_TEXT);
                    $stmt->bindValue(':duration', $duration, SQLITE3_INTEGER);
                    $stmt->bindValue(':limit', $new_config_limit, SQLITE3_INTEGER);
                    // 'activated_at' بدون تغییر باقی می‌ماند
                }
            } else {
                // 'activated_at' خالی است
                // چون اشتراک در حالت "در انتظار" است و کاربر می‌خواهد تاریخ انقضا را دستی تنظیم کند
                // باید 'activated_at' را به تاریخ فعلی تنظیم کنیم
                $activated_at = date('Y-m-d H:i:s');
                // محاسبه duration جدید بر اساس 'activated_at' جدید و تاریخ انقضای وارد شده
                $duration = ceil((strtotime($new_expiration) - strtotime($activated_at)) / (60 * 60 * 24));

                if ($duration <= 0) {
                    $error = 'Invalid expiration date';
                } else {
                    $stmt = $db->prepare('UPDATE users SET name = :name, duration = :duration, config_limit = :limit, activated_at = :activated_at WHERE id = :id');
                    $stmt->bindValue(':id', $edit_id, SQLITE3_INTEGER);
                    $stmt->bindValue(':name', $new_name, SQLITE3_TEXT);
                    $stmt->bindValue(':duration', $duration, SQLITE3_INTEGER);
                    $stmt->bindValue(':limit', $new_config_limit, SQLITE3_INTEGER);
                    $stmt->bindValue(':activated_at', $activated_at, SQLITE3_TEXT);
                }
            }
        }

        if (isset($stmt) && $stmt->execute()) {
            header('Location: /?edited=1');
            exit;
        } else {
            if (!isset($error)) {
                $error = 'Failed to update user';
            }
        }
    }
}

    elseif (isset($_POST['delete_id'])) {
        $delete_id = filter_input(INPUT_POST, 'delete_id', FILTER_VALIDATE_INT);
        if ($delete_id) {
            $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
            $stmt->bindValue(':id', $delete_id, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                header('Location: /?deleted=1');
                exit;
            }
        }
    }
    else {
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING));
        $days = filter_input(INPUT_POST, 'days', FILTER_VALIDATE_INT);
        $config_limit = filter_input(INPUT_POST, 'config_limit', FILTER_VALIDATE_INT) ?: 10;
        $is_onhold = isset($_POST['is_onhold']) ? 1 : 0;
        
        if ($name && $days > 0 && $config_limit > 0) {
            try {
                $config_content = file_get_contents('/var/www/config/working_configs.txt');
                if ($config_content === false) {
                    throw new Exception('Unable to read config file');
                }
                
                $configs = explode("\n", trim($config_content));
                $limited_configs = array_slice($configs, 0, $config_limit);
                $encoded_config = base64_encode(implode("\n", $limited_configs));
                
                $expiration_date = date('Y-m-d', strtotime("{$result['activated_at']} +{$result['duration']} days"));

                $access_token = bin2hex(random_bytes(16));
                
                if ($is_onhold) {
                    $activated_at = null;
                } else {
                    $activated_at = date('Y-m-d H:i:s');
                }

                $stmt = $db->prepare('INSERT INTO users (name, subscription_link, access_token, config_limit, activated_at, duration) VALUES (:name, :link, :token, :limit, :activated_at, :duration)');
                $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt->bindValue(':link', $encoded_config, SQLITE3_TEXT);
                $stmt->bindValue(':token', $access_token, SQLITE3_TEXT);
                $stmt->bindValue(':limit', $config_limit, SQLITE3_INTEGER);
                if ($activated_at) {
                    $stmt->bindValue(':activated_at', $activated_at, SQLITE3_TEXT);
                } else {
                    $stmt->bindValue(':activated_at', null, SQLITE3_NULL);
                }
                $stmt->bindValue(':duration', $days, SQLITE3_INTEGER);

                if ($stmt->execute()) {
                    header('Location: /?success=1');
                    exit;
                }
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$success = isset($_GET['success']) ? '<div style="color: green; margin: 10px 0;">Subscription created successfully!</div>' : '';
$deleted = isset($_GET['deleted']) ? '<div style="color: green; margin: 10px 0;">Subscription deleted successfully!</div>' : '';
$edited = isset($_GET['edited']) ? '<div style="color: green; margin: 10px 0;">Subscription updated successfully!</div>' : '';
$config_added = isset($_GET['config_added']) ? '<div style="color: green; margin: 10px 0;">Configs added successfully!</div>' : '';
$users = $db->query('SELECT * FROM users ORDER BY created_at DESC');

# Updated function to handle expired subscriptions
function getDaysRemaining($expirationDate) {
    $expiration = strtotime($expirationDate);
    $today = strtotime('today');
    $daysRemaining = max(0, ceil((strtotime($expiration_date) - time()) / (60 * 60 * 24)));
    return max(0, $daysRemaining); // Never return negative days
}

# Function to format the days remaining text
function formatDaysRemaining($days, $is_active) {
    if (!$is_active) {
        return '(Expired)';
    }
    return "($days Days)";
}
?>
<!DOCTYPE html>
<html dir="ltr" lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subscription Management</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

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
        h1 { text-align: center; color: #333; }
        form {
            margin: 0 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        button {
            padding: 10px;
            background-color: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover { background-color: #45a049; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: center;
        }
        .active { color: green; }
        .expired { color: red; }
        a { color: #2196F3; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .delete-btn, .edit-btn {
            background-color: #ff4444;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin: 0 5px;
            width: 70px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.3s ease;
        }
        .edit-btn { background-color: #2196F3; }
        .delete-btn:hover { background-color: #cc0000; }
        .edit-btn:hover { background-color: #0b7dda; }
        .status-active {
            color: #4CAF50;
            font-weight: bold;
        }
        .status-expired {
            color: #ff4444;
            font-weight: bold;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
    background-color: white;
    margin: 10% auto;
    padding: 20px;
    border-radius: 8px;
    width: 400px;
    max-width: 90%;
    text-align: left;
    box-sizing: border-box;
}
    .checkbox-wrapper {
        margin-bottom: 15px;
        display: flex;
        align-items: center;
    }

    .checkbox-wrapper label {
        display: inline-flex;
        align-items: center;
        margin: 0;
        cursor: pointer;
    }
        #editModal .checkbox-wrapper {
        text-align: left;
    }

    #editModal .checkbox-wrapper label {
        display: inline-flex;
        align-items: center;
        white-space: nowrap;
    }

    #editModal .checkbox-wrapper input[type="checkbox"] {
        margin-right: 8px;
    }

    .checkbox-wrapper input[type="checkbox"] {
        margin: 0 8px 0 0;
        vertical-align: middle;
    }


.modal-content form {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.modal-content form input {
    display: block;
    width: calc(100% - 20px);
    margin: 0 auto;
    padding: 10px;
    font-size: 14px;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-sizing: border-box;
}

.modal-content form button {
    width: 100%;
    padding: 10px;
    background-color: #4CAF50;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 16px;
    cursor: pointer;
}

.modal-content form button:hover {
    background-color: #45a049;
}

@media (max-width: 600px) {
    .modal-content {
        width: 90%;
    }

    .modal-content form input {
        width: calc(100% - 10px);
    }
}

        .close {
            float: right;
            cursor: pointer;
            font-size: 24px;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .config-form {
            margin: 20px 0;
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 8px;
            border: 1px solid #ddd;
            box-sizing: border-box; 
        }
        .config-form form {
            padding: 0 10px;
        }
        .config-form textarea {
            width: 100%;
            min-height: 150px;
            margin-bottom: 10px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: monospace;
            box-sizing: border-box;

        }
        .section-title {
            margin: 20px 0 10px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }
        #qrcode {
    display: flex;
    justify-content: center;
    margin: 20px auto;
}

#qrcode img {
    margin: 0 auto;
    display: block;
}
        .qr-btn {
    background-color: #9c27b0;
    color: white;
    border: none;
    padding: 5px 10px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    margin: 0 5px;
    width: 70px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background-color 0.3s ease;
    }
    .qr-btn:hover { background-color: #7B1FA2; }
            .days-remaining {
            color: #666;
            font-size: 0.9em;
            margin-left: 5px;
        }
        .days-critical {
            color: #ff4444;
        }
        .days-expired {
            color: #ff4444;
            font-style: italic;
        }
                .copy-btn {
            background-color: #2196F3;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }
        .copy-btn:hover {
            background-color: #1976D2;
        }
        .copy-btn.copied {
            background-color: #4CAF50;
        }
        .status-onhold {
    color: #ffa500;
    font-weight: bold;
}

    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
    <div class="container">
        <h1>Subscription Management</h1>
        <div style="text-align: right;">
            <button type="button" onclick="openBackupModal()" style="background-color: #2196F3; margin-right: 10px;">Backup & Restore</button>
            <button type="button" onclick="window.location.href='backup_settings.php'" style="background-color: #9C27B0; margin-right: 10px;">Auto Backup Settings</button>
            <form method="POST" action="logout.php" style="display: inline;">
                <button type="submit" style="background-color: #f44336;">Logout</button>
            </form>
        </div>
        <?= $success ?>
        <?= $deleted ?>
        <?= $edited ?>
        <?= $config_added ?>
        <?= $imported ?>

        <h2 class="section-title">Create Subscription</h2>
        <!-- Your existing subscription form -->
<form method="POST">
    <input type="text" name="name" placeholder="User Name" required>
    <input type="number" name="days" placeholder="Days Valid" required min="1">
    <input type="number" name="config_limit" placeholder="Number of Configs" required min="1" value="10">
<div class="checkbox-wrapper">
    <label>
        <input type="checkbox" name="is_onhold" id="is_onhold" value="1">
        Start subscription from first connection
    </label>
</div>
    <button type="submit">Create Subscription</button>
</form>

        


        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Expiration Date</th>
                    <th>Status</th>
                    <th>Config Limit</th>
                    <th>Link</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($user = $users->fetchArray(SQLITE3_ASSOC)): ?>
<?php 
if ($user['activated_at']) {
    $expiration_date = date('Y-m-d', strtotime("{$user['activated_at']} +{$user['duration']} days"));
    $is_active = strtotime($expiration_date) > time();
    $days_remaining = ceil((strtotime($expiration_date) - time()) / (60 * 60 * 24));
    $days_text = formatDaysRemaining($days_remaining, $is_active);
} else {
    $expiration_date = "Not activated ({$user['duration']} days)";
    $is_active = false;
    $days_remaining = 'N/A';
    $days_text = '';
}
$is_valid = !$user['activated_at'] || $is_active;
?>

    <tr>
                        <td><?= htmlspecialchars($user['name']) ?></td>
<td>
    <?= htmlspecialchars($expiration_date) ?>
    <?php if ($user['activated_at']): ?>
        <span class="days-remaining <?= !$is_active ? 'days-expired' : ($days_remaining <= 3 ? 'days-critical' : '') ?>">
            <?= htmlspecialchars($days_text) ?>
        </span>
    <?php endif; ?>
</td>
        <td class="<?= $is_active ? 'status-active' : ($user['activated_at'] ? 'status-expired' : 'status-onhold') ?>">
            <?php if ($user['activated_at']): ?>
                <?= $is_active ? 'Active' : 'Expired' ?>
            <?php else: ?>
                On Hold
            <?php endif; ?>
        </td>
                        <td><?= $user['config_limit'] ?></td>
                        <td>
                            <?php if ($is_valid): ?>
                                <button class="copy-btn" onclick="copyLink(this, '<?= $_SERVER['REQUEST_SCHEME'] ?>://<?= $_SERVER['HTTP_HOST'] ?>/sub.php?token=<?= $user['access_token'] ?>')">Copy Link</button>
                            <?php else: ?>
                                Link Expired
                            <?php endif; ?>
                        </td>
                        <td class="action-buttons">
                            <button type="button" class="edit-btn" onclick="openEditModal(
    <?= $user['id'] ?>, 
    '<?= htmlspecialchars($user['name'], ENT_QUOTES) ?>', 
    <?= $user['activated_at'] ? "'{$user['activated_at']}'" : 'null' ?>, 
    <?= $user['duration'] ?>, 
    <?= $user['config_limit'] ?>
)">Edit</button>

                            <?php if ($is_valid): ?>
                                <button type="button" class="qr-btn" onclick="showQRCode('<?= $_SERVER['REQUEST_SCHEME'] ?>://<?= $_SERVER['HTTP_HOST'] ?>/sub.php?token=<?= $user['access_token'] ?>')">QR</button>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this subscription?')" style="display: inline;">
                                <input type="hidden" name="delete_id" value="<?= $user['id'] ?>">
                                <button type="submit" class="delete-btn">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
                <!-- New config management form -->
        <h2 class="section-title">Add Configs</h2>
        <div class="config-form">
            <form method="POST">
                <textarea name="configs" placeholder="Enter your configs here (one per line)" required></textarea>
                <button type="submit" name="add_configs" value="1">Add Configs</button>
            </form>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h2>Edit Subscription</h2>
            <form method="POST">
                <input type="hidden" id="edit_id" name="edit_id">
                <div style="margin-bottom: 15px;">
                    <label for="edit_name">Name:</label>
                    <input type="text" id="edit_name" name="edit_name" required style="width: 100%;">
                </div>
<div id="edit_expiration_container" style="margin-bottom: 15px;">
    <label for="edit_expiration">Expiration Date:</label>
    <input type="text" id="edit_expiration" name="edit_expiration" style="width: 100%;">

</div>
<div id="edit_duration_container" style="margin-bottom: 15px; display: none;">
    <label for="edit_duration">Duration (Days):</label>
    <input type="number" id="edit_duration" name="edit_duration" min="1" style="width: 100%;">
</div>

                <div style="margin-bottom: 15px;">
                    <label for="edit_config_limit">Config Limit:</label>
                    <input type="number" id="edit_config_limit" name="edit_config_limit" required min="1" style="width: 100%;">
                </div>
                <div class="checkbox-wrapper">
    <label>
        <input type="checkbox" id="edit_is_onhold" name="edit_is_onhold" value="1" onchange="toggleEditExpirationField()">
        Start from first connection
    </label>
</div>

                <button type="submit" style="width: 100%;">Save Changes</button>
            </form>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>


    <script>
    
    function toggleEditExpirationField() {
    const isOnHold = document.getElementById('edit_is_onhold').checked;
    if (isOnHold) {
        document.getElementById('edit_expiration_container').style.display = 'none';
        document.getElementById('edit_expiration').required = false;

        document.getElementById('edit_duration_container').style.display = 'block';
        document.getElementById('edit_duration').required = true;
    } else {
        document.getElementById('edit_expiration_container').style.display = 'block';
        document.getElementById('edit_expiration').required = true;

        document.getElementById('edit_duration_container').style.display = 'none';
        document.getElementById('edit_duration').required = false;
    }
}

        // Add this new function for copying links
    function copyLink(button, link) {
        navigator.clipboard.writeText(link).then(function() {
            // Change button text and style temporarily
            button.textContent = 'Copied!';
            button.classList.add('copied');
            
            // Reset button after 4 seconds
            setTimeout(function() {
                button.textContent = 'Copy Link';
                button.classList.remove('copied');
            }, 4000);
        }).catch(function(err) {
            console.error('Failed to copy text: ', err);
            alert('Failed to copy link');
        });
    }
        function openEditModal(id, name, activatedAt, duration, configLimit) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_config_limit').value = configLimit;

    const isOnHold = !activatedAt || activatedAt === 'null' || activatedAt === null;

    document.getElementById('edit_is_onhold').checked = isOnHold;
    toggleEditExpirationField();

    if (isOnHold) {
        document.getElementById('edit_duration').value = duration;
    } else {
        document.getElementById('edit_expiration').value = calculateExpirationDate(activatedAt, duration);
    }

    document.getElementById('editModal').style.display = 'block';
}


function calculateExpirationDate(activatedAt, duration) {
    const activationDate = new Date(activatedAt);
    activationDate.setDate(activationDate.getDate() + parseInt(duration));
    const year = activationDate.getFullYear();
    const month = ('0' + (activationDate.getMonth() + 1)).slice(-2);
    const day = ('0' + activationDate.getDate()).slice(-2);
    return `${year}-${month}-${day}`;
}

function formatDate(activatedAt, duration) {
    const activationDate = new Date(activatedAt);
    activationDate.setDate(activationDate.getDate() + parseInt(duration));
    const year = activationDate.getFullYear();
    const month = ('0' + (activationDate.getMonth() + 1)).slice(-2);
    const day = ('0' + activationDate.getDate()).slice(-2);
    return `${year}-${month}-${day}`;
}


        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('editModal')) {
                closeEditModal();
            }
        }
        let qrcode = null;
function showQRCode(url) {
    document.getElementById('qrModal').style.display = 'block';
    if (qrcode === null) {
        qrcode = new QRCode(document.getElementById("qrcode"), {
            text: url,
            width: 256,
            height: 256
        });
    } else {
        qrcode.clear();
        qrcode.makeCode(url);
    }
}

function closeQRModal() {
    document.getElementById('qrModal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == document.getElementById('editModal')) {
        closeEditModal();
    }
    if (event.target == document.getElementById('qrModal')) {
        closeQRModal();
    }
}
    document.addEventListener('DOMContentLoaded', function() {
        flatpickr("#edit_expiration", {
            dateFormat: "Y-m-d",
            allowInput: true
        });
    });
    </script>
    <div id="qrModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeQRModal()">&times;</span>
        <h2>Scan QR Code</h2>
        <div id="qrcode" style="text-align: center; margin: 20px;"></div>
    </div>
</div>

<!-- اضافه کردن مودال Backup & Restore -->
<div id="backupModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <span class="close" onclick="closeBackupModal()">&times;</span>
        <h2>Backup & Restore</h2>
        <div style="display: flex; flex-direction: column; gap: 15px; margin-top: 20px;">
            <form method="POST" action="export_db.php" style="margin: 0;">
                <button type="submit" class="backup-btn" style="width: 100%;">Backup Database</button>
            </form>
            
            <form method="POST" action="import_db.php" enctype="multipart/form-data" style="margin: 0;">
                <input type="file" name="db_file" accept=".db" required style="display: none;" id="db_file">
                <label for="db_file" class="restore-btn" style="width: 100%; text-align: center; box-sizing: border-box;">
                    Restore Database
                </label>
            </form>
        </div>
    </div>
</div>

<!-- اضافه کردن استایل‌های جدید -->
<style>
    .backup-btn, .restore-btn {
        padding: 12px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        font-weight: bold;
        transition: opacity 0.3s;
        display: block;
    }
    
    .backup-btn {
        background-color: #4CAF50;
        color: white;
    }
    
    .restore-btn {
        background-color: #2196F3;
        color: white;
        padding: 12px 20px;
        display: inline-block;
    }
    
    .backup-btn:hover, .restore-btn:hover {
        opacity: 0.9;
    }
</style>

<!-- اضافه کردن اسکریپت‌های جدید -->
<script>
    function openBackupModal() {
        document.getElementById('backupModal').style.display = 'block';
    }
    
    function closeBackupModal() {
        document.getElementById('backupModal').style.display = 'none';
    }
    
    // اضافه کردن به window.onclick موجود
    window.onclick = function(event) {
        if (event.target == document.getElementById('editModal')) {
            closeEditModal();
        }
        if (event.target == document.getElementById('qrModal')) {
            closeQRModal();
        }
        if (event.target == document.getElementById('backupModal')) {
            closeBackupModal();
        }
    }

    // اضافه کردن عملکرد آپلود خودکار
    document.getElementById('db_file').addEventListener('change', function() {
        this.closest('form').submit();
    });
</script>
</body>
</html>
EOF

cat << 'EOF' > "$WEB_ROOT/sub.php"
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
EOF

cat << 'EOF' > "$WEB_ROOT/update_configs.php"
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
EOF

cat << 'EOF' > "$WEB_ROOT/export_db.php"
<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$db_path = '/var/www/db/subscriptions.db';
$backup_name = 'subscription_backup_' . date('Y-m-d_H-i-s') . '.db';

if (file_exists($db_path)) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $backup_name . '"');
    header('Content-Length: ' . filesize($db_path));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    readfile($db_path);
    exit;
} else {
    die('Database file not found.');
}
EOF

cat << 'EOF' > "$WEB_ROOT/import_db.php"
<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['db_file'])) {
    $db_path = '/var/www/db/subscriptions.db';
    $uploaded_file = $_FILES['db_file'];
    $backup_path = $db_path . '.backup_' . date('Y-m-d_H-i-s');

    // Validate file
    if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
        die('Upload failed with error code ' . $uploaded_file['error']);
    }

    // Check file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $uploaded_file['tmp_name']);
    finfo_close($finfo);

    if ($mime_type !== 'application/x-sqlite3' && $mime_type !== 'application/octet-stream') {
        die('Invalid file type. Only SQLite database files are allowed.');
    }

    // Create backup of current database
    if (file_exists($db_path)) {
        if (!copy($db_path, $backup_path)) {
            die('Failed to create backup of current database.');
        }
    }

    // Import new database
    if (move_uploaded_file($uploaded_file['tmp_name'], $db_path)) {
        // Set correct permissions
        chmod($db_path, 0664);
        chown($db_path, 'www-data');
        chgrp($db_path, 'www-data');

        header('Location: index.php?imported=1');
        exit;
    } else {
        // Restore backup if import failed
        if (file_exists($backup_path)) {
            copy($backup_path, $db_path);
        }
        die('Failed to import database.');
    }
} else {
    header('Location: index.php');
    exit;
}
EOF

cat << 'EOF' > "$WEB_ROOT/backup_settings.php"
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
EOF

cat << 'EOF' > "$WEB_ROOT/auto_backup.php"
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
EOF

sed -i 's/;cgi.fix_pathinfo=1/cgi.fix_pathinfo=0/' /etc/php/${PHP_VERSION}/fpm/php.ini
sudo systemctl restart $PHP_FPM_SERVICE

cat << EOF > /etc/nginx/sites-available/$DOMAIN_NAME
server {
   listen 80;
   listen [::]:80;
   server_name $DOMAIN_NAME;
   return 301 https://\$server_name\$request_uri;
}

server {
   listen 443 ssl http2;
   listen [::]:443 ssl http2;
   server_name $DOMAIN_NAME;
   
   client_max_body_size 100M;
   fastcgi_read_timeout 300;

   root $WEB_ROOT;
   index index.php;

   ssl_certificate $CERT_PATH;
   ssl_certificate_key $KEY_PATH;

   # Add error logging
   error_log /var/log/nginx/error.log;
   access_log /var/log/nginx/access.log;

   location / {
      try_files \$uri \$uri/ /index.php?\$query_string;
      index index.php;
   }

   location ~ \.php$ {
       include snippets/fastcgi-php.conf;
       fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
       fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
       include fastcgi_params;
       fastcgi_buffers 16 16k;
       fastcgi_buffer_size 32k;
       fastcgi_intercept_errors on;
       fastcgi_connect_timeout 300;
       fastcgi_send_timeout 300;
       fastcgi_read_timeout 300;
   }
}
EOF

# Set correct permissions
sudo chown -R www-data:www-data $WEB_ROOT $DB_DIR $CONFIG_DIR
sudo chmod -R 755 $WEB_ROOT
sudo chmod -R 775 $DB_DIR $CONFIG_DIR
sudo chmod 664 $DB_PATH $CONFIG_FILE_PATH
sudo chmod 777 /var/lib/php/sessions

# Restart services
sudo systemctl restart nginx
sudo systemctl restart $PHP_FPM_SERVICE

# Final message
echo "Installation completed!"
echo "Web Panel URL: https://$DOMAIN_NAME"
echo -e "\nDatabase location: $DB_PATH"
echo "Config file location: $CONFIG_FILE_PATH"

# Create log files if they don't exist
sudo touch /var/log/nginx/error.log /var/log/nginx/access.log
sudo chown www-data:www-data /var/log/nginx/error.log /var/log/nginx/access.log
sudo chmod 644 /var/log/nginx/error.log /var/log/nginx/access.log

# Restart services
sudo systemctl restart php${PHP_VERSION}-fpm
sudo systemctl restart nginx
