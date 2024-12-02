<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

while (true) {
    $output = @file_get_contents('/tmp/check_output.txt');
    if ($output) {
        echo "data: $output\n\n";
        flush();
    }
    sleep(1);
} 