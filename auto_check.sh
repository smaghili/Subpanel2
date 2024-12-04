#!/bin/bash

# Set cron job based on interval
if [ "$1" = "set" ] && [ -n "$2" ]; then
    hours=$2
    
    # Validate input
    if ! [[ "$hours" =~ ^[0-9]+$ ]] || [ "$hours" -lt 1 ] || [ "$hours" -gt 24 ]; then
        echo "Error: Please enter a number between 1 and 24"
        exit 1
    fi
    
    # Remove old cron job and add new one
    (crontab -l | grep -v "auto_check.sh"; echo "0 */$hours * * * /var/www/html/auto_check.sh check") | crontab -
    echo "Cron job set successfully. Script will run every $hours hours."
    exit 0
fi

# Check configurations
if [ "$1" = "check" ] || [ -z "$1" ]; then
    # Set working directory
    cd /var/www/html

    # Configure logging
    exec 1> >(logger -s -t $(basename $0)) 2>&1

    # Get active URLs
    URLS=$(sqlite3 /var/www/db/subscriptions.db "SELECT url FROM config_checks WHERE active = 1;")

    # Check if URLs exist
    if [ -z "$URLS" ]; then
        echo "No active URLs found"
        exit 0
    fi

    # Process each URL
    for URL in $URLS; do
        echo "Checking URL: $URL"
        
        # Run config check
        php check_configs.php "$URL"
        if [ $? -ne 0 ]; then
            echo "Warning: Config check failed for $URL"
            continue
        fi
        
        # Run monitor bot
        python3 /var/www/scripts/monitor-bot.py
        if [ $? -ne 0 ]; then
            echo "Warning: Monitor bot failed for $URL"
        fi
        
        # Delay between checks
        sleep 2
    done

    echo "Auto-check completed successfully"
    exit 0
fi

# Usage instructions
echo "Usage:"
echo "  For setting cron job: $0 set [hours]"
echo "  For manual check: $0 check"