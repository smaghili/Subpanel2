<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

function executeCommand($command) {
    $output = [];
    $return_var = 0;
    exec($command, $output, $return_var);
    return [
        'output' => $output,
        'status' => $return_var
    ];
}

// Get request type from URL parameter
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'loadbalancer':
        // Get configs from POST request
        $configs = $_POST['configs'] ?? '';
        if (empty($configs)) {
            echo json_encode(['error' => 'No configs provided']);
            exit;
        }

        // Call v2raycheck.py with loadbalancer parameter
        $configs_str = escapeshellarg($configs);
        $result = executeCommand("python3 /var/www/scripts/v2raycheck.py --loadbalancer $configs_str");
        
        if ($result['status'] === 0) {
            echo json_encode([
                'success' => true,
                'data' => $result['output']
            ]);
        } else {
            echo json_encode([
                'error' => 'Failed to create loadbalancer config',
                'details' => $result['output']
            ]);
        }
        break;

    case 'check_config':
        $url = $_POST['url'] ?? '';
        if (empty($url)) {
            echo json_encode(['error' => 'No URL provided']);
            exit;
        }

        $url = escapeshellarg($url);
        $result = executeCommand("python3 /var/www/scripts/v2raycheck.py --check $url");
        
        echo json_encode([
            'success' => $result['status'] === 0,
            'data' => $result['output']
        ]);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
} 