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
