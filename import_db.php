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
