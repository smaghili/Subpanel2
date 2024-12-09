#!/bin/bash

# Function to check if panel is already installed
check_installation() {
    if [ -f "/etc/nginx/sites-available/$DOMAIN_NAME" ] || [ -d "$WEB_ROOT" ]; then
        return 0
    else
        return 1
    fi
}

# Function to show errors and exit
show_error() {
    echo -e "\e[31m[ERROR] $1\e[0m"
    exit 1
}

# Function to update panel
update_panel() {
    echo "Updating SubPanel..."
    
    # Define repository URL
    repo_url="https://github.com/smaghili/SubPanel2.git"
    
    # Clone and update files
    git clone "$repo_url" temp_dir
    if [ $? -ne 0 ]; then
        show_error "Failed to clone repository"
    fi
    
    # Update files while preserving data
    cd temp_dir
    if [ $? -ne 0 ]; then
        show_error "Failed to enter temp directory"
    fi
    for file in *; do
        if [[ "$file" == *.py ]]; then
            cp "$file" "$SCRIPTS_DIR/"
            chmod +x "$SCRIPTS_DIR/$file"
        elif [ "$file" != "installsub.sh" ]; then
            cp "$file" "$WEB_ROOT/"
        fi
    done
    cd ..
    rm -rf temp_dir
    
    # Fix permissions
    sudo chown -R www-data:www-data $WEB_ROOT $SCRIPTS_DIR
    sudo chmod -R 755 $WEB_ROOT
    sudo chmod -R 775 $SCRIPTS_DIR
    
    # Restart services
    systemctl restart monitor-bot.service
    systemctl restart v2raycheck.service
    systemctl restart nginx
    systemctl restart $PHP_FPM_SERVICE
    
    echo "Update completed successfully!"
}

# Function to completely reinstall panel
reinstall_panel() {
    echo "WARNING: This will delete all data including SSL certificates!"
    read -p "Are you sure you want to continue? (y/n): " confirm
    if [ "$confirm" != "y" ]; then
        echo "Reinstallation cancelled."
        exit 0
    fi
    
    # Stop services
    systemctl stop nginx || true
    systemctl stop monitor-bot.service || true
    systemctl stop v2raycheck.service || true
    
    # Remove all SSL certificates
    rm -rf /etc/letsencrypt/live/* || true
    rm -rf /etc/letsencrypt/archive/* || true
    rm -rf /etc/letsencrypt/renewal/* || true
    
    # Remove all panel files and directories
    rm -rf $WEB_ROOT || true
    rm -rf $DB_DIR || true
    rm -rf $CONFIG_DIR || true
    rm -rf $SCRIPTS_DIR || true
    rm -rf $SESSIONS_DIR || true
    rm -rf /etc/nginx/sites-available || true
    rm -rf /etc/nginx/sites-enabled || true
    mkdir -p /etc/nginx/sites-available
    mkdir -p /etc/nginx/sites-enabled
    
    echo "All panel data has been removed. Starting fresh installation..."
    sleep 2
}

LOG_FILE="/var/log/subpanel_install.log"
exec 1> >(tee -a "$LOG_FILE") 2>&1
echo "Installation started at $(date)"

# Define paths
WEB_ROOT="/var/www/html"
DB_DIR="/var/www/db"
CONFIG_DIR="/var/www/config"

# First check if panel is already installed
if [ -d "$WEB_ROOT" ] || [ -d "$DB_DIR" ] || [ -d "$CONFIG_DIR" ]; then
    echo "SubPanel is already installed!"
    echo "Please choose an option:"
    echo "1) Update panel (preserves all data)"
    echo "2) Reinstall panel (deletes everything)"
    echo "3) Exit"
    
    read -p "Enter your choice (1-3): " choice
    
    case $choice in
        1)
            update_panel
            exit 0
            ;;
        2)
            reinstall_panel
            # Continue with fresh installation after cleanup
            ;;
        3)
            echo "Exiting..."
            exit 0
            ;;
        *)
            show_error "Invalid choice"
            ;;
    esac
fi

# Ask for domain name after checking installation status
read -p "Please enter your domain name (e.g., example.com): " DOMAIN_NAME

if [[ ! $DOMAIN_NAME =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*[a-zA-Z0-9]\.[a-zA-Z]{2,}$ ]]; then
    show_error "Invalid domain name format\nDomain name should be in format: example.com or sub.example.com"
fi

echo "Using domain: $DOMAIN_NAME"
echo "Installation will begin in 3 seconds... Press Ctrl+C to cancel"
sleep 3

DB_DIR="/var/www/db"
CONFIG_DIR="/var/www/config"
DB_PATH="${DB_DIR}/subscriptions.db"
CONFIG_FILE_PATH="${CONFIG_DIR}/working_configs.txt"
BACKUP_CONFIG_FILE="${CONFIG_DIR}/backup_config.json"
SCRIPTS_DIR="/var/www/scripts"
SESSIONS_DIR="/var/www/sessions"
LOADBALANCER_DIR="/var/www/html/loadbalancer"

sudo mkdir -p $WEB_ROOT $DB_DIR $CONFIG_DIR $SCRIPTS_DIR $SESSIONS_DIR $LOADBALANCER_DIR

# Set permissions for loadbalancer directory
sudo chown -R www-data:www-data $LOADBALANCER_DIR
sudo chmod 755 $LOADBALANCER_DIR

# Create backup config file
if [ ! -f "$BACKUP_CONFIG_FILE" ]; then
    echo '{"telegram_bot_token":"","admin_ids":"","backup_interval":24,"last_backup":""}' > "$BACKUP_CONFIG_FILE"
fi

# Install required packages in one command to reduce apt calls
sudo apt update && sudo apt install -y nginx certbot python3-certbot-nginx php-fpm php-sqlite3 sqlite3 inotify-tools php-curl python3-pip

# Install Python packages
pip3 install aiohttp telethon requests python-dotenv

# Install Xray
if ! command -v xray &> /dev/null; then
    echo "Installing Xray..."
    bash -c "$(curl -L https://github.com/XTLS/Xray-install/raw/main/install-release.sh)" @ install -u root
else
    echo "Xray is already installed"
fi

# Check if installations were successful
if ! command -v xray &> /dev/null; then
    show_error "Xray installation failed"
fi

if ! python3 -c "import aiohttp" &> /dev/null; then
    show_error "aiohttp installation failed"
fi

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

sed -i 's/max_input_time = .*/max_input_time = 300/' /etc/php/${PHP_VERSION}/fpm/php.ini

if [ ! -f "$CONFIG_FILE_PATH" ]; then
    touch "$CONFIG_FILE_PATH"
    echo "# Add your configs here" > "$CONFIG_FILE_PATH"
fi

if [ ! -f "$DB_PATH" ]; then
    # Create database with all tables in one command
    # Set proper permissions before creating database
    touch "$DB_PATH"
    chown www-data:www-data "$DB_PATH"
    chmod 664 "$DB_PATH"
    
    sqlite3 "$DB_PATH" "
    PRAGMA journal_mode = WAL;
    PRAGMA synchronous = NORMAL;
    PRAGMA busy_timeout = 5000;
    
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        subscription_link TEXT NOT NULL,
        loadbalancer_link TEXT NOT NULL,
        access_token TEXT UNIQUE NOT NULL,
        loadbalancer_token TEXT UNIQUE NOT NULL,
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
    CREATE TABLE IF NOT EXISTS config_checks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        url TEXT NOT NULL,
        bot_id TEXT,
        total_configs INTEGER,
        valid_configs INTEGER,
        check_date DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS usage_data (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        config_id INTEGER,
        total_volume REAL,
        used_volume REAL,
        days_left INTEGER,
        check_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (config_id) REFERENCES config_checks(id)
    );
    INSERT INTO admin (username, password) VALUES ('admin', 'admin123');
    "
fi

# Set correct permissions for database directory
sudo chown -R www-data:www-data $DB_DIR
sudo chmod -R 775 $DB_DIR
sudo chmod 664 "$DB_PATH"

# Set permissions in one go
sudo chown -R www-data:www-data $WEB_ROOT $DB_DIR $CONFIG_DIR
sudo chown -R www-data:www-data $SCRIPTS_DIR
sudo chown -R www-data:www-data $SESSIONS_DIR
sudo chmod -R 755 $WEB_ROOT
sudo chmod -R 775 $DB_DIR $CONFIG_DIR $SESSIONS_DIR
sudo chmod 664 $DB_PATH $CONFIG_FILE_PATH
sudo chmod 777 /var/lib/php/sessions

CERT_PATH="/etc/letsencrypt/live/$DOMAIN_NAME/fullchain.pem"
KEY_PATH="/etc/letsencrypt/live/$DOMAIN_NAME/privkey.pem"

# Create and run Telegram session
if [ ! -f "$SCRIPTS_DIR/telegram-session.py" ]; then
    show_error "telegram-session.py not found in $SCRIPTS_DIR"
fi

python3 $SCRIPTS_DIR/telegram-session.py

# Remove default nginx config and create symlink
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/$DOMAIN_NAME /etc/nginx/sites-enabled/

# Verify nginx config and restart
nginx -t && systemctl restart nginx

# Now get SSL certificate
if [ ! -f "$CERT_PATH" ] || [ ! -f "$KEY_PATH" ]; then
    # Stop nginx temporarily to free port 80
    systemctl stop nginx
    
    # Get certificate
    certbot certonly --standalone -d $DOMAIN_NAME --non-interactive --agree-tos --register-unsafely-without-email
    
    # Start nginx again
    systemctl start nginx
fi

# Clone the GitHub repository
repo_url="https://github.com/smaghili/SubPanel2.git"
git clone "$repo_url" temp_dir

# Move files to appropriate directories
cd temp_dir
for file in *; do
    if [[ "$file" == *.py ]]; then
        mv "$file" "$SCRIPTS_DIR/"
        chmod +x "$SCRIPTS_DIR/$file"  # Make Python files executable
    elif [ "$file" != "installsub.sh" ]; then
        mv "$file" "$WEB_ROOT/"
    fi
done
cd ..
rm -rf temp_dir

find "$SCRIPTS_DIR" -type f -name "*.py" -exec chmod +x {} \;

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
sudo chown -R www-data:www-data $SCRIPTS_DIR
sudo chown -R www-data:www-data $SESSIONS_DIR
sudo chmod -R 755 $WEB_ROOT
sudo chmod -R 775 $DB_DIR $CONFIG_DIR $SESSIONS_DIR
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


required_files=("v2raycheck.py" "telegram-session.py" "api.php" "check_configs.php" "sub.php" "monitor-bot.py" "index.php" "check_telegram_service.py")

for file in "${required_files[@]}"; do
    if [[ "$file" == *.py ]]; then
        if [ ! -f "$SCRIPTS_DIR/$file" ]; then
            show_error "Required file $file not found in $SCRIPTS_DIR"
        fi
    else
        if [ ! -f "$WEB_ROOT/$file" ]; then
            show_error "Required file $file not found in $WEB_ROOT"
        fi
    fi
done

# Create systemd service for monitor-bot
cat << EOF > /etc/systemd/system/monitor-bot.service
[Unit]
Description=Telegram Monitor Bot Service
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=$SCRIPTS_DIR
ExecStart=/usr/bin/python3 $SCRIPTS_DIR/monitor-bot.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# Create systemd service for v2ray checker
cat << EOF > /etc/systemd/system/v2raycheck.service
[Unit]
Description=V2Ray Config Checker Service
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=$SCRIPTS_DIR
ExecStart=/usr/bin/python3 $SCRIPTS_DIR/v2raycheck.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# Enable and start services
systemctl enable monitor-bot.service
systemctl enable v2raycheck.service
systemctl start monitor-bot.service
systemctl start v2raycheck.service