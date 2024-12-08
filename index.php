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

                // Create temporary file for configs
                $temp_config_file = tempnam(sys_get_temp_dir(), 'configs_');
                if ($temp_config_file === false) {
                    throw new Exception('Failed to create temporary file');
                }
                
                if (file_put_contents($temp_config_file, implode("\n", $limited_configs)) === false) {
                    throw new Exception('Failed to write configs to temporary file');
                }

                // Create loadbalancer config using v2raycheck.py
                $loadbalancer_output_file = tempnam(sys_get_temp_dir(), 'lb_');
                if ($loadbalancer_output_file === false) {
                    throw new Exception('Failed to create loadbalancer output file');
                }

                $command = "python3 /var/www/scripts/v2raycheck.py -file " . escapeshellarg($temp_config_file) . 
                          " -loadbalancer -nocheck -count " . escapeshellarg($config_limit) .
                          " -lb-output " . escapeshellarg($loadbalancer_output_file) . " 2>&1";
                
                exec($command, $output, $return_var);

                // Read the loadbalancer config
                if ($return_var !== 0) {
                    throw new Exception('Failed to create loadbalancer config: ' . implode("\n", $output));
                }

                if (!file_exists($loadbalancer_output_file)) {
                    throw new Exception('Loadbalancer output file not created');
                }

                $loadbalancer_content = file_get_contents($loadbalancer_output_file);
                if ($loadbalancer_content === false) {
                    throw new Exception('Failed to read loadbalancer config');
                }

                $loadbalancer_encoded = base64_encode($loadbalancer_content);

                // Clean up temporary files
                @unlink($temp_config_file);
                @unlink($loadbalancer_output_file);

                $access_token = bin2hex(random_bytes(16));
                $loadbalancer_token = bin2hex(random_bytes(16));
                
                if ($is_onhold) {
                    $activated_at = null;
                } else {
                    $activated_at = date('Y-m-d H:i:s');
                }

                $stmt = $db->prepare('INSERT INTO users (name, subscription_link, loadbalancer_link, access_token, loadbalancer_token, config_limit, activated_at, duration) VALUES (:name, :link, :lb_link, :token, :lb_token, :limit, :activated_at, :duration)');
                $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt->bindValue(':link', $encoded_config, SQLITE3_TEXT);
                $stmt->bindValue(':lb_link', $loadbalancer_encoded, SQLITE3_TEXT);
                $stmt->bindValue(':token', $access_token, SQLITE3_TEXT);
                $stmt->bindValue(':lb_token', $loadbalancer_token, SQLITE3_TEXT);
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
                error_log("Error creating user: " . $e->getMessage());
                header('Location: /?error=' . urlencode($e->getMessage()));
                exit;
            }
        }
    }
}

$success = isset($_GET['success']) ? '<div style="color: green; margin: 10px 0;">Subscription created successfully!</div>' : '';
$deleted = isset($_GET['deleted']) ? '<div style="color: green; margin: 10px 0;">Subscription deleted successfully!</div>' : '';
$edited = isset($_GET['edited']) ? '<div style="color: green; margin: 10px 0;">Subscription updated successfully!</div>' : '';
$config_added = isset($_GET['config_added']) ? '<div style="color: green; margin: 10px 0;">Configs added successfully!</div>' : '';
$error = isset($_GET['error']) ? '<div style="color: red; margin: 10px 0;">' . htmlspecialchars($_GET['error']) . '</div>' : '';

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
    <script>
async function createLoadBalancer() {
    const selectedConfigs = getSelectedConfigs();
    if (selectedConfigs.length === 0) {
        showNotification('Please select at least one config', 'error');
        return;
    }

    fetch('api.php?action=loadbalancer', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            configs: selectedConfigs
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Loadbalancer config created successfully', 'success');
            // Handle the loadbalancer config data
            const configData = data.data;
            // Show config in dialog or handle as needed
            showConfigDialog(configData);
        } else {
            showNotification(data.error || 'Failed to create loadbalancer config', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Failed to create loadbalancer config', 'error');
    });
}

function closeQrDialog() {
    document.getElementById('qrDialog').style.display = 'none';
}

function copyConfigUrl() {
    const configUrl = document.getElementById('configUrl');
    configUrl.select();
    document.execCommand('copy');
}
</script>

<style>
.action-button {
    background-color: #4CAF50;
    color: white;
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    margin: 5px;
}

.action-button:hover {
    background-color: #45a049;
}

.qr-dialog {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    z-index: 1000;
}

.qr-dialog-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 999;
}

.url-input {
    width: 100%;
    padding: 8px;
    margin: 10px 0;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.button-group {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 10px;
}
</style>
</head>
<body>
    <div class="container">
        <h1>Subscription Management</h1>
        <div style="text-align: right;">
            <button type="button" onclick="window.location.href='check_configs.php'" style="background-color: #FF9800; margin-right: 10px;">Check Configs</button>
            <button type="button" onclick="window.location.href='backup_settings.php'" style="background-color: #9C27B0; margin-right: 10px;">Auto Backup Settings</button>
            <button type="button" onclick="openBackupModal()" style="background-color: #2196F3; margin-right: 10px;">Backup & Restore</button>
            <form method="POST" action="logout.php" style="display: inline;">
                <button type="submit" style="background-color: #f44336;">Logout</button>
            </form>
        </div>
        <?= $success ?>
        <?= $deleted ?>
        <?= $edited ?>
        <?= $config_added ?>
        <?= $error ?>

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
                                <button class="copy-btn" onclick="copyLink(this, '<?= $_SERVER['REQUEST_SCHEME'] ?>://<?= $_SERVER['HTTP_HOST'] ?>/sub.php?token=<?= $user['loadbalancer_token'] ?>&lb=1')">Copy LB Link</button>
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
                                <button type="button" class="qr-btn" onclick="showQRCode('<?= $_SERVER['REQUEST_SCHEME'] ?>://<?= $_SERVER['HTTP_HOST'] ?>/sub.php?token=<?= $user['loadbalancer_token'] ?>&lb=1')" style="background-color: #2196F3;">LB QR</button>
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

<button onclick="createLoadBalancer()" class="action-button">Create Load Balancer</button>

<div id="qrDialog" class="qr-dialog-overlay">
    <div class="qr-dialog">
        <h3>Load Balancer Config</h3>
        <div id="qrCode"></div>
        <input type="text" id="configUrl" class="url-input" readonly>
        <div class="button-group">
            <button onclick="copyConfigUrl()" class="action-button">Copy URL</button>
            <button onclick="closeQrDialog()" class="action-button">Close</button>
        </div>
    </div>
</div>
</body>
</html>
