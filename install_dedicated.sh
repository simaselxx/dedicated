#!/bin/bash

#===============================================================================
#  DEDICATED BOT INSTALLER
#  Based on MirzaPro Install Script
#  GitHub: https://github.com/simaselxx/dedicated
#===============================================================================

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Configuration
GITHUB_RAW="https://raw.githubusercontent.com/simaselxx/dedicated/main"
GITHUB_ZIP="https://github.com/simaselxx/dedicated/archive/refs/heads/main.zip"
BOT_DIR="/var/www/html/mirzabot"
CONFIG_DIR="/var/www/html/mirzaprobotconfig"
MASTER_PATH="/root/install.sh"
BIN_LINK="/usr/local/bin/dedicated"

#===============================================================================
# CHECK ROOT
#===============================================================================
if [[ $EUID -ne 0 ]]; then
    echo -e "${RED}[ERROR]${NC} Please run this script as ${CYAN}root${NC}."
    exit 1
fi

#===============================================================================
# SELF UPDATE
#===============================================================================
function self_update_script() {
    local TEMP_FILE="/tmp/dedicated_update.sh"
    echo -e "${YELLOW}Checking for updates...${NC}"

    wget -q -O "$TEMP_FILE" "$GITHUB_RAW/install_dedicated.sh" 2>/dev/null

    if [ -s "$TEMP_FILE" ]; then
        if [ -f "$MASTER_PATH" ]; then
            LOCAL_HASH=$(md5sum "$MASTER_PATH" 2>/dev/null | awk '{print $1}')
        else
            LOCAL_HASH="not_installed"
        fi
        REMOTE_HASH=$(md5sum "$TEMP_FILE" | awk '{print $1}')

        if [ "$LOCAL_HASH" != "$REMOTE_HASH" ]; then
            if [ "$LOCAL_HASH" == "not_installed" ]; then
                echo -e "${GREEN}First run detected. Installing script to system...${NC}"
            else
                echo -e "${GREEN}New version found! Updating...${NC}"
            fi
            mv "$TEMP_FILE" "$MASTER_PATH"
            chmod +x "$MASTER_PATH"
            rm -f "$BIN_LINK"
            ln -s "$MASTER_PATH" "$BIN_LINK"
            chmod +x "$BIN_LINK"
            echo -e "${GREEN}Script updated. Restarting...${NC}"
            sleep 1
            exec bash "$MASTER_PATH" "$@"
        else
            echo -e "${GREEN}Script is up to date.${NC}"
            rm -f "$TEMP_FILE"
            if [ ! -f "$BIN_LINK" ]; then
                ln -s "$MASTER_PATH" "$BIN_LINK"
                chmod +x "$BIN_LINK"
            fi
        fi
    else
        echo -e "${YELLOW}Could not check for updates (offline mode).${NC}"
        rm -f "$TEMP_FILE"
    fi
}

# Run self-update
self_update_script

#===============================================================================
# CHECK SSL STATUS
#===============================================================================
check_ssl_status() {
    if [ -f "$CONFIG_DIR/config.php" ]; then
        domain=$(grep '^\$domainhosts' "$CONFIG_DIR/config.php" 2>/dev/null | cut -d"'" -f2 | cut -d'/' -f1)
        if [ -n "$domain" ] && [ -f "/etc/letsencrypt/live/$domain/cert.pem" ]; then
            expiry_date=$(openssl x509 -enddate -noout -in "/etc/letsencrypt/live/$domain/cert.pem" | cut -d= -f2)
            current_date=$(date +%s)
            expiry_timestamp=$(date -d "$expiry_date" +%s 2>/dev/null)
            if [ -n "$expiry_timestamp" ]; then
                days_remaining=$(( ($expiry_timestamp - $current_date) / 86400 ))
                if [ $days_remaining -gt 0 ]; then
                    echo -e "${GREEN}✅ SSL: $days_remaining days remaining ($domain)${NC}"
                else
                    echo -e "${RED}❌ SSL: Expired ($domain)${NC}"
                fi
            fi
        else
            echo -e "${YELLOW}⚠️ SSL: Not configured${NC}"
        fi
    fi
}

#===============================================================================
# CHECK BOT STATUS
#===============================================================================
check_bot_status() {
    if [ -f "$CONFIG_DIR/config.php" ] || [ -f "$BOT_DIR/config.php" ]; then
        echo -e "${GREEN}✅ Bot is installed${NC}"
        check_ssl_status
    else
        echo -e "${RED}❌ Bot is not installed${NC}"
    fi
}

#===============================================================================
# LOGO
#===============================================================================
function show_logo() {
    clear
    echo -e "${BLUE}"
    echo "╔═══════════════════════════════════════════════════════════════════╗"
    echo "║                                                                   ║"
    echo "║      ██████╗ ███████╗██████╗ ██╗ ██████╗ █████╗ ████████╗███████╗ ║"
    echo "║      ██╔══██╗██╔════╝██╔══██╗██║██╔════╝██╔══██╗╚══██╔══╝██╔════╝ ║"
    echo "║      ██║  ██║█████╗  ██║  ██║██║██║     ███████║   ██║   █████╗   ║"
    echo "║      ██║  ██║██╔══╝  ██║  ██║██║██║     ██╔══██║   ██║   ██╔══╝   ║"
    echo "║      ██████╔╝███████╗██████╔╝██║╚██████╗██║  ██║   ██║   ███████╗ ║"
    echo "║      ╚═════╝ ╚══════╝╚═════╝ ╚═╝ ╚═════╝╚═╝  ╚═╝   ╚═╝   ╚══════╝ ║"
    echo "║                                                                   ║"
    echo "║                    Telegram Bot Installer v1.0                    ║"
    echo "╚═══════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
    echo ""
    check_bot_status
    echo ""
}

#===============================================================================
# MENU
#===============================================================================
function show_menu() {
    show_logo
    echo -e "${CYAN}╔═══════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC}           ${YELLOW}Select an option${NC}            ${CYAN}║${NC}"
    echo -e "${CYAN}╠═══════════════════════════════════════╣${NC}"
    echo -e "${CYAN}║${NC}  ${GREEN}1)${NC} Install from GitHub (Online)     ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}  ${GREEN}2)${NC} Install from Local Files         ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}  ${GREEN}3)${NC} Update Bot                        ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}  ${GREEN}4)${NC} Setup/Renew SSL Certificate       ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}  ${GREEN}5)${NC} Set Webhook                        ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}  ${GREEN}6)${NC} Restart Services                   ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}  ${GREEN}7)${NC} Remove Bot                         ${CYAN}║${NC}"
    echo -e "${CYAN}║${NC}  ${GREEN}8)${NC} Exit                               ${CYAN}║${NC}"
    echo -e "${CYAN}╚═══════════════════════════════════════╝${NC}"
    echo ""
    read -p "Select option [1-8]: " option

    case $option in
        1) install_from_github ;;
        2) install_from_local ;;
        3) update_bot ;;
        4) setup_ssl ;;
        5) set_webhook ;;
        6) restart_services ;;
        7) remove_bot ;;
        8) echo -e "${GREEN}Goodbye!${NC}"; exit 0 ;;
        *) echo -e "${RED}Invalid option${NC}"; sleep 1; show_menu ;;
    esac
}

#===============================================================================
# FIX UPDATE ISSUES (MIRRORS)
#===============================================================================
function fix_update_issues() {
    echo -e "${YELLOW}Trying alternative mirrors...${NC}"
    cp /etc/apt/sources.list /etc/apt/sources.list.backup

    if [ -f /etc/os-release ]; then
        UBUNTU_CODENAME=$(grep UBUNTU_CODENAME /etc/os-release | cut -d '=' -f2)
    fi

    MIRRORS=("archive.ubuntu.com" "us.archive.ubuntu.com" "mirrors.digitalocean.com")

    for mirror in "${MIRRORS[@]}"; do
        echo -e "${YELLOW}Trying mirror: $mirror${NC}"
        cat > /etc/apt/sources.list << EOF
deb http://$mirror/ubuntu/ $UBUNTU_CODENAME main restricted universe multiverse
deb http://$mirror/ubuntu/ $UBUNTU_CODENAME-updates main restricted universe multiverse
deb http://$mirror/ubuntu/ $UBUNTU_CODENAME-security main restricted universe multiverse
EOF
        if apt-get update 2>/dev/null; then
            echo -e "${GREEN}Mirror $mirror works!${NC}"
            return 0
        fi
    done

    mv /etc/apt/sources.list.backup /etc/apt/sources.list
    return 1
}

#===============================================================================
# INSTALL DEPENDENCIES
#===============================================================================
function install_dependencies() {
    echo -e "\n${YELLOW}[1/6] Installing dependencies...${NC}\n"

    # Update system
    if ! (apt update && apt upgrade -y); then
        echo -e "${YELLOW}Update failed. Trying alternative mirrors...${NC}"
        if fix_update_issues; then
            apt update && apt upgrade -y
        else
            echo -e "${RED}[ERROR] Failed to update system.${NC}"
            exit 1
        fi
    fi
    echo -e "${GREEN}System updated.${NC}\n"

    # Add PHP PPA
    apt-get install -y software-properties-common || exit 1
    add-apt-repository -y ppa:ondrej/php 2>/dev/null || LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php
    apt update

    # Install packages
    echo -e "${YELLOW}Installing required packages...${NC}"

    PACKAGES=(
        apache2
        mysql-server
        php8.2
        php8.2-fpm
        php8.2-mysql
        php8.2-mbstring
        php8.2-zip
        php8.2-gd
        php8.2-curl
        php8.2-soap
        php8.2-ssh2
        libapache2-mod-php8.2
        libssh2-1
        libssh2-1-dev
        git
        unzip
        curl
        wget
        jq
        ufw
        certbot
        python3-certbot-apache
    )

    for pkg in "${PACKAGES[@]}"; do
        echo -ne "  Installing ${pkg}..."
        if dpkg -s $pkg &>/dev/null; then
            echo -e " ${GREEN}already installed${NC}"
        else
            if DEBIAN_FRONTEND=noninteractive apt install -y $pkg &>/dev/null; then
                echo -e " ${GREEN}OK${NC}"
            else
                echo -e " ${YELLOW}skipped${NC}"
            fi
        fi
    done

    # phpMyAdmin
    echo -e "\n${YELLOW}Installing phpMyAdmin...${NC}"
    echo 'phpmyadmin phpmyadmin/dbconfig-install boolean true' | debconf-set-selections
    echo 'phpmyadmin phpmyadmin/app-password-confirm password mirzahipass' | debconf-set-selections
    echo 'phpmyadmin phpmyadmin/mysql/admin-pass password mirzahipass' | debconf-set-selections
    echo 'phpmyadmin phpmyadmin/mysql/app-pass password mirzahipass' | debconf-set-selections
    echo 'phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2' | debconf-set-selections
    DEBIAN_FRONTEND=noninteractive apt-get install -y phpmyadmin

    # phpMyAdmin symlink
    if [ -f /etc/apache2/conf-available/phpmyadmin.conf ]; then
        rm -f /etc/apache2/conf-available/phpmyadmin.conf
    fi
    ln -sf /etc/phpmyadmin/apache.conf /etc/apache2/conf-available/phpmyadmin.conf
    a2enconf phpmyadmin 2>/dev/null

    # Enable services
    systemctl enable mysql apache2
    systemctl start mysql apache2

    # Firewall
    ufw allow 'Apache Full'
    ufw allow 22
    ufw allow 80
    ufw allow 443

    echo -e "\n${GREEN}[OK] Dependencies installed.${NC}\n"
}

#===============================================================================
# SETUP MYSQL
#===============================================================================
function setup_mysql() {
    echo -e "${YELLOW}[2/6] Setting up MySQL...${NC}\n"

    # Check if already configured
    if [ -f "/root/confmirza/dbrootmirza.txt" ]; then
        echo -e "${GREEN}MySQL already configured. Using existing credentials.${NC}"
        ROOT_PASSWORD=$(grep '\$pass' /root/confmirza/dbrootmirza.txt | cut -d"'" -f2)
        ROOT_USER=$(grep '\$user' /root/confmirza/dbrootmirza.txt | cut -d"'" -f2)
        return 0
    fi

    # Generate random password
    ROOT_PASSWORD=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | cut -c1-12)
    ROOT_USER="root"

    # Save credentials
    mkdir -p /root/confmirza
    cat > /root/confmirza/dbrootmirza.txt << EOF
\$user = 'root';
\$pass = '${ROOT_PASSWORD}';
EOF
    chmod 600 /root/confmirza/dbrootmirza.txt

    # Set MySQL root password
    mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${ROOT_PASSWORD}'; FLUSH PRIVILEGES;" 2>/dev/null || {
        echo -e "${YELLOW}Attempting MySQL recovery...${NC}"
        sed -i '$ a skip-grant-tables' /etc/mysql/mysql.conf.d/mysqld.cnf
        systemctl restart mysql
        mysql <<EOF
DROP USER IF EXISTS 'root'@'localhost';
CREATE USER 'root'@'localhost' IDENTIFIED BY '${ROOT_PASSWORD}';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOF
        sed -i '/skip-grant-tables/d' /etc/mysql/mysql.conf.d/mysqld.cnf
        systemctl restart mysql
    }

    echo -e "${GREEN}[OK] MySQL configured.${NC}"
    echo -e "${CYAN}    Root Password: ${ROOT_PASSWORD}${NC}\n"
}

#===============================================================================
# CREATE DATABASE
#===============================================================================
function create_database() {
    echo -e "${YELLOW}[3/6] Creating database...${NC}\n"

    ROOT_PASSWORD=$(grep '\$pass' /root/confmirza/dbrootmirza.txt | cut -d"'" -f2)
    ROOT_USER="root"

    # Test MySQL connection
    if ! mysql -u"$ROOT_USER" -p"$ROOT_PASSWORD" -e "SELECT 1" &>/dev/null; then
        echo -e "${RED}[ERROR] MySQL connection failed.${NC}"
        exit 1
    fi

    # Check if database exists
    if mysql -u"$ROOT_USER" -p"$ROOT_PASSWORD" -e "SHOW DATABASES LIKE 'mirzabot'" | grep -q mirzabot; then
        echo -e "${GREEN}Database 'mirzabot' already exists.${NC}"
        DB_NAME="mirzabot"
        DB_USER="$ROOT_USER"
        DB_PASS="$ROOT_PASSWORD"
        return 0
    fi

    # Generate credentials
    DB_NAME="mirzabot"
    DB_USER_DEFAULT=$(openssl rand -base64 8 | tr -dc 'a-zA-Z' | cut -c1-8)
    DB_PASS_DEFAULT=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | cut -c1-10)

    echo -e "${GREEN}Enter database credentials (or press Enter for defaults):${NC}"
    printf "  Database username [${CYAN}${DB_USER_DEFAULT}${NC}]: "
    read DB_USER
    DB_USER=${DB_USER:-$DB_USER_DEFAULT}

    printf "  Database password [${CYAN}${DB_PASS_DEFAULT}${NC}]: "
    read DB_PASS
    DB_PASS=${DB_PASS:-$DB_PASS_DEFAULT}

    # Create database and user
    mysql -u"$ROOT_USER" -p"$ROOT_PASSWORD" <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED WITH mysql_native_password BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED WITH mysql_native_password BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
EOF

    echo -e "${GREEN}[OK] Database created.${NC}\n"
}

#===============================================================================
# DOWNLOAD BOT FILES
#===============================================================================
function download_bot_files() {
    local SOURCE=$1  # "github" or path to local files

    echo -e "${YELLOW}[4/6] Downloading bot files...${NC}\n"

    # Remove old directories
    rm -rf "$BOT_DIR" "$CONFIG_DIR"
    mkdir -p "$BOT_DIR" "$CONFIG_DIR"

    if [ "$SOURCE" == "github" ]; then
        # Download from GitHub
        TEMP_DIR="/tmp/mirzabot_install"
        mkdir -p "$TEMP_DIR"

        echo -e "  Downloading from GitHub..."
        wget -q -O "$TEMP_DIR/bot.zip" "$GITHUB_ZIP" || {
            echo -e "${RED}[ERROR] Failed to download from GitHub.${NC}"
            exit 1
        }

        echo -e "  Extracting files..."
        unzip -q "$TEMP_DIR/bot.zip" -d "$TEMP_DIR"

        EXTRACTED_DIR=$(find "$TEMP_DIR" -mindepth 1 -maxdepth 1 -type d)
        mv "$EXTRACTED_DIR"/* "$BOT_DIR/"

        rm -rf "$TEMP_DIR"
    else
        # Copy from local path
        if [ ! -d "$SOURCE" ]; then
            echo -e "${RED}[ERROR] Directory not found: $SOURCE${NC}"
            exit 1
        fi

        echo -e "  Copying files from $SOURCE..."
        cp -r "$SOURCE"/* "$BOT_DIR/"
    fi

    # Set permissions
    chown -R www-data:www-data "$BOT_DIR" "$CONFIG_DIR"
    chmod -R 755 "$BOT_DIR" "$CONFIG_DIR"

    echo -e "${GREEN}[OK] Bot files installed.${NC}\n"
}

#===============================================================================
# SETUP SSL
#===============================================================================
function setup_ssl() {
    echo -e "${YELLOW}[5/6] Setting up SSL certificate...${NC}\n"

    if [ -z "$DOMAIN_NAME" ]; then
        printf "  Enter your domain (e.g., bot.example.com): "
        read DOMAIN_NAME
        while [[ ! "$DOMAIN_NAME" =~ ^[a-zA-Z0-9.-]+$ ]]; do
            echo -e "${RED}  Invalid domain format.${NC}"
            printf "  Enter your domain: "
            read DOMAIN_NAME
        done
    fi

    # Stop Apache for standalone mode
    systemctl stop apache2

    # Get SSL certificate
    certbot certonly --standalone --agree-tos --preferred-challenges http -d "$DOMAIN_NAME" --non-interactive --register-unsafely-without-email || {
        echo -e "${YELLOW}  Certbot standalone failed, trying with Apache...${NC}"
        systemctl start apache2
        certbot --apache --agree-tos -d "$DOMAIN_NAME" --non-interactive --register-unsafely-without-email || {
            echo -e "${RED}[WARNING] SSL certificate failed. Continuing without SSL...${NC}"
            systemctl start apache2
            return 1
        }
    }

    # Enable certbot timer for auto-renewal
    systemctl enable certbot.timer 2>/dev/null

    systemctl start apache2

    echo -e "${GREEN}[OK] SSL certificate installed.${NC}\n"
}

#===============================================================================
# SETUP APACHE
#===============================================================================
function setup_apache() {
    echo -e "${YELLOW}Setting up Apache VirtualHost...${NC}\n"

    # Create VirtualHost for port 80
    cat > /etc/apache2/sites-available/${DOMAIN_NAME}.conf << EOF
<VirtualHost *:80>
    ServerName $DOMAIN_NAME
    DocumentRoot $BOT_DIR
    <Directory $BOT_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Include /etc/apache2/conf-available/phpmyadmin.conf
    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-access.log combined
</VirtualHost>
EOF

    # Create VirtualHost for port 443 (HTTPS)
    cat > /etc/apache2/sites-available/${DOMAIN_NAME}-ssl.conf << EOF
<VirtualHost *:443>
    ServerName $DOMAIN_NAME
    DocumentRoot $BOT_DIR
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/$DOMAIN_NAME/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$DOMAIN_NAME/privkey.pem
    <Directory $BOT_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    Include /etc/apache2/conf-available/phpmyadmin.conf
    ErrorLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${DOMAIN_NAME}-access.log combined
</VirtualHost>
EOF

    # Enable sites and modules
    a2ensite "${DOMAIN_NAME}.conf" 2>/dev/null
    a2ensite "${DOMAIN_NAME}-ssl.conf" 2>/dev/null
    a2enmod ssl rewrite 2>/dev/null

    # Disable default sites
    a2dissite 000-default.conf 2>/dev/null
    a2dissite 000-default-le-ssl.conf 2>/dev/null
    rm -f /etc/apache2/sites-enabled/000-default* 2>/dev/null

    systemctl restart apache2

    echo -e "${GREEN}[OK] Apache configured.${NC}\n"
}

#===============================================================================
# CONFIGURE BOT
#===============================================================================
function configure_bot() {
    echo -e "${YELLOW}[6/6] Configuring bot...${NC}\n"

    # Get bot token
    printf "  Bot Token: "
    read BOT_TOKEN
    while [[ ! "$BOT_TOKEN" =~ ^[0-9]{8,10}:[a-zA-Z0-9_-]{35}$ ]]; do
        echo -e "${RED}  Invalid bot token format.${NC}"
        printf "  Bot Token: "
        read BOT_TOKEN
    done

    # Get admin chat ID
    printf "  Admin Chat ID: "
    read ADMIN_ID
    while [[ ! "$ADMIN_ID" =~ ^-?[0-9]+$ ]]; do
        echo -e "${RED}  Invalid chat ID format.${NC}"
        printf "  Admin Chat ID: "
        read ADMIN_ID
    done

    # Get bot username
    printf "  Bot Username (without @): "
    read BOT_USERNAME
    while [ -z "$BOT_USERNAME" ]; do
        echo -e "${RED}  Bot username cannot be empty.${NC}"
        printf "  Bot Username: "
        read BOT_USERNAME
    done

    # Generate secret token
    SECRET_TOKEN=$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | cut -c1-10)

    # Create config.php
    ASAS="$"
    cat > "$CONFIG_DIR/config.php" << EOF
<?php
${ASAS}dbname = '${DB_NAME}';
${ASAS}usernamedb = '${DB_USER}';
${ASAS}passworddb = '${DB_PASS}';
${ASAS}connect = mysqli_connect("localhost", ${ASAS}usernamedb, ${ASAS}passworddb, ${ASAS}dbname);
if (${ASAS}connect->connect_error) { die("error" . ${ASAS}connect->connect_error); }
mysqli_set_charset(${ASAS}connect, "utf8mb4");
${ASAS}options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, ];
${ASAS}dsn = "mysql:host=localhost;dbname=${ASAS}dbname;charset=utf8mb4";
try { ${ASAS}pdo = new PDO(${ASAS}dsn, ${ASAS}usernamedb, ${ASAS}passworddb, ${ASAS}options); } catch (\PDOException ${ASAS}e) { error_log("Database connection failed: " . ${ASAS}e->getMessage()); }
${ASAS}APIKEY = '${BOT_TOKEN}';
${ASAS}adminnumber = '${ADMIN_ID}';
${ASAS}domainhosts = '${DOMAIN_NAME}';
${ASAS}usernamebot = '${BOT_USERNAME}';
${ASAS}secrettoken = '${SECRET_TOKEN}';
?>
EOF

    # Create symlink
    ln -sf "$CONFIG_DIR/config.php" "$BOT_DIR/config.php"

    # Set permissions
    chown -R www-data:www-data "$CONFIG_DIR"
    chmod 644 "$CONFIG_DIR/config.php"

    echo -e "${GREEN}[OK] Bot configured.${NC}\n"

    # Initialize database tables
    echo -e "${YELLOW}Initializing database tables...${NC}"
    curl -sk "https://${DOMAIN_NAME}/table.php" > /dev/null 2>&1 || php "$BOT_DIR/table.php" 2>/dev/null
    echo -e "${GREEN}[OK] Database initialized.${NC}\n"

    # Set webhook
    echo -e "${YELLOW}Setting webhook...${NC}"
    WEBHOOK_RESULT=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?url=https://${DOMAIN_NAME}/index.php&secret_token=${SECRET_TOKEN}")
    if echo "$WEBHOOK_RESULT" | grep -q '"ok":true'; then
        echo -e "${GREEN}[OK] Webhook set successfully.${NC}"
    else
        echo -e "${RED}[WARNING] Webhook failed. Set it manually later.${NC}"
    fi

    # Send test message
    curl -s "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
        -d "chat_id=${ADMIN_ID}" \
        -d "text=✅ Bot installed successfully! Send /start to begin." > /dev/null
}

#===============================================================================
# INSTALL FROM GITHUB
#===============================================================================
function install_from_github() {
    show_logo
    echo -e "${GREEN}Installing from GitHub...${NC}\n"

    install_dependencies
    setup_mysql
    create_database
    download_bot_files "github"
    setup_ssl
    setup_apache
    configure_bot

    show_success
}

#===============================================================================
# INSTALL FROM LOCAL
#===============================================================================
function install_from_local() {
    show_logo
    echo -e "${GREEN}Installing from local files...${NC}\n"

    printf "Enter path to bot files: "
    read LOCAL_PATH

    if [ ! -d "$LOCAL_PATH" ]; then
        echo -e "${RED}[ERROR] Directory not found.${NC}"
        sleep 2
        show_menu
        return
    fi

    install_dependencies
    setup_mysql
    create_database
    download_bot_files "$LOCAL_PATH"
    setup_ssl
    setup_apache
    configure_bot

    show_success
}

#===============================================================================
# UPDATE BOT
#===============================================================================
function update_bot() {
    show_logo
    echo -e "${YELLOW}Updating bot from GitHub...${NC}\n"

    # Backup config
    if [ -f "$CONFIG_DIR/config.php" ]; then
        cp "$CONFIG_DIR/config.php" /tmp/config_backup.php
    elif [ -f "$BOT_DIR/config.php" ]; then
        cp "$BOT_DIR/config.php" /tmp/config_backup.php
    fi

    # Download new files
    TEMP_DIR="/tmp/mirzabot_update"
    rm -rf "$TEMP_DIR"
    mkdir -p "$TEMP_DIR"

    wget -q -O "$TEMP_DIR/bot.zip" "$GITHUB_ZIP" || {
        echo -e "${RED}[ERROR] Failed to download update.${NC}"
        read -p "Press Enter to continue..."
        show_menu
        return
    }

    unzip -q "$TEMP_DIR/bot.zip" -d "$TEMP_DIR"
    EXTRACTED_DIR=$(find "$TEMP_DIR" -mindepth 1 -maxdepth 1 -type d)

    # Copy new files (preserve config)
    rsync -av --exclude='config.php' "$EXTRACTED_DIR"/ "$BOT_DIR"/

    # Restore config
    if [ -f /tmp/config_backup.php ]; then
        cp /tmp/config_backup.php "$CONFIG_DIR/config.php"
        ln -sf "$CONFIG_DIR/config.php" "$BOT_DIR/config.php"
    fi

    # Set permissions
    chown -R www-data:www-data "$BOT_DIR"
    chmod -R 755 "$BOT_DIR"

    # Update database tables
    DOMAIN_NAME=$(grep '^\$domainhosts' "$CONFIG_DIR/config.php" 2>/dev/null | cut -d"'" -f2)
    if [ -n "$DOMAIN_NAME" ]; then
        curl -sk "https://${DOMAIN_NAME}/table.php" > /dev/null 2>&1
    fi

    # Cleanup
    rm -rf "$TEMP_DIR" /tmp/config_backup.php

    # Copy install.sh to /root
    if [ -f "$BOT_DIR/install_dedicated.sh" ]; then
        cp "$BOT_DIR/install_dedicated.sh" /root/install.sh
        chmod +x /root/install.sh
    fi

    echo -e "\n${GREEN}[OK] Bot updated successfully!${NC}"
    read -p "Press Enter to continue..."
    show_menu
}

#===============================================================================
# SET WEBHOOK
#===============================================================================
function set_webhook() {
    show_logo

    printf "Bot Token: "
    read BOT_TOKEN
    printf "Domain (e.g., bot.example.com): "
    read DOMAIN

    RESULT=$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?url=https://${DOMAIN}/index.php")

    if echo "$RESULT" | grep -q '"ok":true'; then
        echo -e "\n${GREEN}[OK] Webhook set successfully!${NC}"
    else
        echo -e "\n${RED}[ERROR] Failed to set webhook.${NC}"
        echo "$RESULT"
    fi

    read -p "Press Enter to continue..."
    show_menu
}

#===============================================================================
# RESTART SERVICES
#===============================================================================
function restart_services() {
    show_logo
    echo -e "${YELLOW}Restarting services...${NC}\n"

    systemctl restart apache2 && echo -e "  Apache:  ${GREEN}Restarted${NC}" || echo -e "  Apache:  ${RED}Failed${NC}"
    systemctl restart mysql && echo -e "  MySQL:   ${GREEN}Restarted${NC}" || echo -e "  MySQL:   ${RED}Failed${NC}"
    systemctl restart php8.2-fpm 2>/dev/null && echo -e "  PHP-FPM: ${GREEN}Restarted${NC}"

    echo -e "\n${GREEN}[OK] Services restarted.${NC}"
    read -p "Press Enter to continue..."
    show_menu
}

#===============================================================================
# REMOVE BOT
#===============================================================================
function remove_bot() {
    show_logo
    echo -e "${RED}WARNING: This will remove the bot and all data!${NC}\n"

    read -p "Are you sure? (y/n): " confirm
    if [[ "$confirm" != "y" ]]; then
        show_menu
        return
    fi

    echo -e "\n${YELLOW}Removing bot...${NC}"

    # Remove bot directory
    rm -rf "$BOT_DIR" "$CONFIG_DIR"
    echo -e "  Bot files: ${GREEN}Removed${NC}"

    # Remove Apache sites
    DOMAIN_NAME=$(ls /etc/apache2/sites-available/*.conf 2>/dev/null | head -1 | xargs basename 2>/dev/null | sed 's/.conf//')
    if [ -n "$DOMAIN_NAME" ]; then
        a2dissite "${DOMAIN_NAME}.conf" 2>/dev/null
        a2dissite "${DOMAIN_NAME}-ssl.conf" 2>/dev/null
        rm -f /etc/apache2/sites-available/${DOMAIN_NAME}*.conf
    fi
    echo -e "  Apache config: ${GREEN}Removed${NC}"

    # Drop database
    if [ -f /root/confmirza/dbrootmirza.txt ]; then
        ROOT_PASS=$(grep '\$pass' /root/confmirza/dbrootmirza.txt | cut -d"'" -f2)
        mysql -uroot -p"$ROOT_PASS" -e "DROP DATABASE IF EXISTS mirzabot;" 2>/dev/null
        echo -e "  Database: ${GREEN}Removed${NC}"
    fi

    systemctl restart apache2

    echo -e "\n${GREEN}[OK] Bot removed successfully.${NC}"
    read -p "Press Enter to continue..."
    show_menu
}

#===============================================================================
# SHOW SUCCESS
#===============================================================================
function show_success() {
    clear
    echo -e "${GREEN}"
    echo "╔═══════════════════════════════════════════════════════════════════╗"
    echo "║                                                                   ║"
    echo "║              ✅ INSTALLATION COMPLETED SUCCESSFULLY!              ║"
    echo "║                                                                   ║"
    echo "╚═══════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
    echo ""
    echo -e "${CYAN}Bot URL:${NC}        https://${DOMAIN_NAME}"
    echo -e "${CYAN}phpMyAdmin:${NC}     https://${DOMAIN_NAME}/phpmyadmin"
    echo -e "${CYAN}Database:${NC}       ${DB_NAME}"
    echo -e "${CYAN}DB Username:${NC}    ${DB_USER}"
    echo -e "${CYAN}DB Password:${NC}    ${DB_PASS}"
    echo ""
    echo -e "${YELLOW}Send /start to your bot to begin!${NC}"
    echo ""
    read -p "Press Enter to continue..."
    show_menu
}

#===============================================================================
# START
#===============================================================================
show_menu
