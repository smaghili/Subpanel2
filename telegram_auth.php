<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['phone'])) {
        // ذخیره شماره تلفن در سشن
        $_SESSION['telegram_phone'] = $_POST['phone'];
        $cmd = "python3 /var/www/scripts/telegram_auth.py start " . escapeshellarg($_POST['phone']);
        $output = shell_exec($cmd);
        $result = json_decode($output, true);
        
        if ($result && isset($result['status']) && $result['status'] === 'code_needed') {
            echo json_encode(['status' => 'code_needed']);
            exit;
        }
    } 
    else if (isset($_POST['code'])) {
        // تایید کد
        $cmd = "python3 /var/www/scripts/telegram_auth.py verify_code " . escapeshellarg($_POST['code']);
        $output = shell_exec($cmd);
        $result = json_decode($output, true);
        
        if ($result && isset($result['status'])) {
            if ($result['status'] === 'password_needed') {
                echo json_encode(['status' => 'password_needed']);
                exit;
            } else if ($result['status'] === 'success') {
                echo json_encode(['status' => 'success']);
                exit;
            }
        }
    }
    else if (isset($_POST['password'])) {
        // تایید پسورد two-step
        $cmd = "python3 /var/www/scripts/telegram_auth.py verify_2fa " . escapeshellarg($_POST['password']);
        $output = shell_exec($cmd);
        $result = json_decode($output, true);
        
        if ($result && isset($result['status']) && $result['status'] === 'success') {
            echo json_encode(['status' => 'success']);
            exit;
        }
    }
    
    echo json_encode(['status' => 'error', 'message' => 'خطا در احراز هویت']);
    exit;
}
?>

<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
    <meta charset="UTF-8">
    <title>احراز هویت تلگرام</title>
    <style>
        body {
            font-family: Tahoma, Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
            direction: rtl;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            color: #666;
        }
        input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            direction: ltr;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
        }
        button:hover {
            background-color: #45a049;
        }
        .error {
            color: red;
            margin-bottom: 10px;
        }
        .success {
            color: green;
            margin-bottom: 10px;
        }
        #codeForm, #passwordForm {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>احراز هویت تلگرام</h1>
        
        <?php if ($message): ?>
            <p class="<?php echo strpos($message, 'خطا') !== false ? 'error' : 'success'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </p>
        <?php endif; ?>
        
        <form id="phoneForm">
            <div class="form-group">
                <label for="phone">شماره تلفن (با کد کشور):</label>
                <input type="text" id="phone" name="phone" placeholder="+98912..." required>
            </div>
            <button type="submit">ارسال کد تایید</button>
        </form>

        <form id="codeForm">
            <div class="form-group">
                <label for="code">کد تایید:</label>
                <input type="text" id="code" name="code" required>
            </div>
            <button type="submit">تایید کد</button>
        </form>

        <form id="passwordForm">
            <div class="form-group">
                <label for="password">رمز عبور تایید دو مرحله‌ای:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">تایید رمز عبور</button>
        </form>
    </div>

    <script>
        document.getElementById('phoneForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const response = await fetch('telegram_auth.php', {
                method: 'POST',
                body: new FormData(e.target)
            });
            const result = await response.json();
            if (result.status === 'code_needed') {
                document.getElementById('phoneForm').style.display = 'none';
                document.getElementById('codeForm').style.display = 'block';
            }
        });

        document.getElementById('codeForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const response = await fetch('telegram_auth.php', {
                method: 'POST',
                body: new FormData(e.target)
            });
            const result = await response.json();
            if (result.status === 'password_needed') {
                document.getElementById('codeForm').style.display = 'none';
                document.getElementById('passwordForm').style.display = 'block';
            } else if (result.status === 'success') {
                window.close();
            }
        });

        document.getElementById('passwordForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const response = await fetch('telegram_auth.php', {
                method: 'POST',
                body: new FormData(e.target)
            });
            const result = await response.json();
            if (result.status === 'success') {
                window.close();
            }
        });
    </script>
</body>
</html> 