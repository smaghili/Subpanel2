#!/bin/bash

# Function to show errors and exit
show_error() {
    echo -e "\e[31m[ERROR] $1\e[0m"
    exit 1
}

read -p "Please enter your domain name (e.g., example.com): " DOMAIN_NAME

if [[ ! $DOMAIN_NAME =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*[a-zA-Z0-9]\.[a-zA-Z]{2,}$ ]]; then
    show_error "Invalid domain name format\nDomain name should be in format: example.com or sub.example.com"
fi

echo "Using domain: $DOMAIN_NAME"
echo "Installation will begin in 3 seconds... Press Ctrl+C to cancel"
sleep 3

WEB_ROOT="/var/www/html"
DB_DIR="/var/www/db"
CONFIG_DIR="/var/www/config"
DB_PATH="${DB_DIR}/subscriptions.db"
CONFIG_FILE_PATH="${CONFIG_DIR}/working_configs.txt"
BACKUP_CONFIG_FILE="${CONFIG_DIR}/backup_config.json"
SCRIPTS_DIR="/var/www/scripts"

sudo mkdir -p $WEB_ROOT $DB_DIR $CONFIG_DIR $SCRIPTS_DIR

# Create backup config file
if [ ! -f "$BACKUP_CONFIG_FILE" ]; then
    echo '{"telegram_bot_token":"","admin_ids":"","backup_interval":24,"last_backup":""}' > "$BACKUP_CONFIG_FILE"
fi

# Install required packages in one command to reduce apt calls
sudo apt update && sudo apt install -y nginx certbot python3-certbot-nginx php-fpm php-sqlite3 sqlite3 inotify-tools php-curl python3-pip

# Install Python packages
pip3 install aiohttp

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
        access_token TEXT UNIQUE NOT NULL,
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
        total_configs INTEGER,
        valid_configs INTEGER,
        check_date DATETIME DEFAULT CURRENT_TIMESTAMP
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
sudo chmod -R 755 $WEB_ROOT
sudo chmod -R 775 $DB_DIR $CONFIG_DIR
sudo chmod 664 $DB_PATH $CONFIG_FILE_PATH
sudo chmod 777 /var/lib/php/sessions

CERT_PATH="/etc/letsencrypt/live/$DOMAIN_NAME/fullchain.pem"
KEY_PATH="/etc/letsencrypt/live/$DOMAIN_NAME/privkey.pem"

if [ ! -f "$CERT_PATH" ] || [ ! -f "$KEY_PATH" ]; then
    sudo certbot --nginx -d $DOMAIN_NAME --non-interactive --agree-tos --register-unsafely-without-email
fi
# Clone the GitHub repository
repo_url="https://github.com/smaghili/SubPanel2.git"
git clone "$repo_url" temp_dir

# Move files to appropriate directories
cd temp_dir
for file in *; do
    if [ "$file" = "v2raycheck.py" ]; then
        mv "$file" "$SCRIPTS_DIR/"
    elif [ "$file" != "installsub.sh" ]; then
        mv "$file" "$WEB_ROOT/"
    fi
done
cd ..
rm -rf temp_dir


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
sudo chmod -R 755 $WEB_ROOT
sudo chmod -R 775 $DB_DIR $CONFIG_DIR
sudo chmod -R 755 $SCRIPTS_DIR
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
