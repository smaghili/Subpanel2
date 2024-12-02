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
