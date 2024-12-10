<?php
try {
    $db = new SQLite3('/var/www/db/subscriptions.db');
    
    // Enable WAL mode for better concurrency
    $db->exec('PRAGMA journal_mode = WAL');
    
    // Create users table with all required fields including loadbalancer_config
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        subscription_link TEXT NOT NULL,
        loadbalancer_config TEXT,
        access_token TEXT NOT NULL,
        loadbalancer_token TEXT NOT NULL,
        config_limit INTEGER DEFAULT 10,
        activated_at DATETIME,
        duration INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    echo "Database and tables created successfully!";
} catch (Exception $e) {
    die("Error creating database: " . $e->getMessage());
}
?> 