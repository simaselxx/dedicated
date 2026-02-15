#!/bin/bash
# ========================================
# اسکریپت آپدیت فایل‌های SSH برای Mirzabot
# نسخه: 2.1 - تاریخ: 2026-02-09
# ========================================
# اجرا: bash /root/mirzabot/apply_ssh.sh

# رنگ‌ها برای خروجی
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# مسیر فایل‌های اصلی (از ویندوز کپی شده)
SRC="/root/mirzabot"
# مسیر نصب ربات
DEST="/var/www/html/mirzaprobotconfig"

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}   آپدیت فایل‌های SSH - Mirzabot${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""

# چک کردن مسیر مقصد
if [ ! -d "$DEST" ]; then
    echo -e "${RED}خطا: مسیر $DEST وجود نداره. اول install.sh رو اجرا کن.${NC}"
    exit 1
fi

# شمارنده‌ها
COPIED=0
FAILED=0

copy_file() {
    local src_file="$1"
    local dest_file="$2"
    local display_name="$3"

    if [ -f "$src_file" ]; then
        cp "$src_file" "$dest_file"
        echo -e "   ${GREEN}✓${NC} $display_name"
        COPIED=$((COPIED + 1))
    else
        echo -e "   ${RED}✗${NC} $display_name (پیدا نشد)"
        FAILED=$((FAILED + 1))
    fi
}

# ========================================
# 1. فایل‌های اصلی SSH (درایورها)
# ========================================
echo -e "${YELLOW}1. کپی فایل‌های SSH Drivers...${NC}"
for f in ssh_helpers.php shahan.php xpanel.php rocket_ssh.php dragon.php; do
    copy_file "$SRC/$f" "$DEST/$f" "$f"
done

# ========================================
# 1.1 اسکریپت‌های کمکی
# ========================================
echo ""
echo -e "${YELLOW}1.1. کپی اسکریپت‌های کمکی...${NC}"
copy_file "$SRC/migrate_balance.php" "$DEST/migrate_balance.php" "migrate_balance.php"

# ========================================
# 2. فایل‌های Core (تغییر یافته)
# ========================================
echo ""
echo -e "${YELLOW}2. کپی فایل‌های Core...${NC}"
CORE_FILES=(
    "panels.php"      # عملیات پنل‌ها
    "keyboard.php"    # کیبوردها
    "function.php"    # توابع کمکی
    "admin.php"       # پنل ادمین
    "index.php"       # بات اصلی
    "table.php"       # جداول
    "text.json"       # متن‌ها
)
for f in "${CORE_FILES[@]}"; do
    copy_file "$SRC/$f" "$DEST/$f" "$f"
done

# ========================================
# 3. فایل‌های API
# ========================================
echo ""
echo -e "${YELLOW}3. کپی فایل‌های API...${NC}"
mkdir -p "$DEST/api"
API_FILES=(
    "panels.php"
    "miniapp.php"
    "category.php"
)
for f in "${API_FILES[@]}"; do
    copy_file "$SRC/api/$f" "$DEST/api/$f" "api/$f"
done

# ========================================
# 4. فایل‌های vpnbot (تمپلیت‌ها)
# ========================================
echo ""
echo -e "${YELLOW}4. کپی تمپلیت‌های vpnbot...${NC}"
for d in update Default; do
    mkdir -p "$DEST/vpnbot/$d"
    # index.php - بات اصلی نماینده
    if [ -f "$SRC/vpnbot/$d/index.php" ]; then
        copy_file "$SRC/vpnbot/$d/index.php" "$DEST/vpnbot/$d/index.php" "vpnbot/$d/index.php"
    fi
    # admin.php - پنل ادمین نماینده
    if [ -f "$SRC/vpnbot/$d/admin.php" ]; then
        copy_file "$SRC/vpnbot/$d/admin.php" "$DEST/vpnbot/$d/admin.php" "vpnbot/$d/admin.php"
    fi
    # keyboard.php - کیبوردهای نماینده
    if [ -f "$SRC/vpnbot/$d/keyboard.php" ]; then
        copy_file "$SRC/vpnbot/$d/keyboard.php" "$DEST/vpnbot/$d/keyboard.php" "vpnbot/$d/keyboard.php"
    fi
done

# آپدیت بات‌های فعال
echo ""
echo -e "${YELLOW}4.1. آپدیت بات‌های فعال...${NC}"
BOT_COUNT=0
for bot_dir in "$DEST"/vpnbot/*/; do
    dir_name=$(basename "$bot_dir")
    if [ "$dir_name" = "Default" ] || [ "$dir_name" = "update" ]; then
        continue
    fi
    # آپدیت index.php
    if [ -f "$bot_dir/index.php" ] && [ -f "$DEST/vpnbot/Default/index.php" ]; then
        cp "$DEST/vpnbot/Default/index.php" "$bot_dir/index.php"
        echo -e "   ${GREEN}✓${NC} vpnbot/$dir_name/index.php"
    fi
    # آپدیت admin.php
    if [ -f "$bot_dir/admin.php" ] && [ -f "$DEST/vpnbot/Default/admin.php" ]; then
        cp "$DEST/vpnbot/Default/admin.php" "$bot_dir/admin.php"
        echo -e "   ${GREEN}✓${NC} vpnbot/$dir_name/admin.php"
    fi
    # آپدیت keyboard.php
    if [ -f "$bot_dir/keyboard.php" ] && [ -f "$DEST/vpnbot/Default/keyboard.php" ]; then
        cp "$DEST/vpnbot/Default/keyboard.php" "$bot_dir/keyboard.php"
        echo -e "   ${GREEN}✓${NC} vpnbot/$dir_name/keyboard.php"
    fi
    BOT_COUNT=$((BOT_COUNT + 1))
done
echo -e "   ${BLUE}تعداد بات‌های آپدیت شده: $BOT_COUNT${NC}"

# ========================================
# 5. فایل‌های miniapp (frontend)
# ========================================
echo ""
echo -e "${YELLOW}5. کپی فایل‌های miniapp...${NC}"
mkdir -p "$DEST/app/assets"

MINIAPP_FILES=(
    "app/index.php"
    "app/assets/service-detail-C7iGJ2PF.js"
    "app/assets/buy-Cma1zvMm.js"
)
for f in "${MINIAPP_FILES[@]}"; do
    copy_file "$SRC/$f" "$DEST/$f" "$f"
done

# ========================================
# 6. تنظیم دسترسی
# ========================================
echo ""
echo -e "${YELLOW}6. تنظیم دسترسی فایل‌ها...${NC}"
chown -R www-data:www-data "$DEST"
chmod -R 755 "$DEST"
echo -e "   ${GREEN}✓${NC} دسترسی‌ها تنظیم شد"

# ========================================
# 7. رفع خط جدید ویندوزی
# ========================================
echo ""
echo -e "${YELLOW}7. رفع خط جدید ویندوزی (CRLF → LF)...${NC}"
find "$DEST" -name "*.php" -exec sed -i 's/\r$//' {} +
find "$DEST" -name "*.json" -exec sed -i 's/\r$//' {} +
echo -e "   ${GREEN}✓${NC} خطوط جدید اصلاح شد"

# ========================================
# 8. نصب php-mysql برای CLI
# ========================================
echo ""
echo -e "${YELLOW}8. چک کردن php-mysql...${NC}"
if php -m 2>/dev/null | grep -qi mysqli; then
    echo -e "   ${GREEN}✓${NC} php-mysql قبلا نصب شده"
else
    PHP_VER=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;" 2>/dev/null)
    echo -e "   ${BLUE}در حال نصب php${PHP_VER}-mysql...${NC}"
    apt install -y php${PHP_VER}-mysql > /dev/null 2>&1
    if php -m 2>/dev/null | grep -qi mysqli; then
        echo -e "   ${GREEN}✓${NC} php${PHP_VER}-mysql نصب شد"
    else
        echo -e "   ${YELLOW}!${NC} نصب نشد. دستی نصب کن: apt install php${PHP_VER}-mysql"
    fi
fi

# ========================================
# 9. نصب phpseclib (برای Dragon)
# ========================================
echo ""
echo -e "${YELLOW}9. چک کردن phpseclib...${NC}"
if [ -d "$DEST/vendor/phpseclib" ]; then
    echo -e "   ${GREEN}✓${NC} phpseclib قبلا نصب شده"
else
    if command -v composer &> /dev/null; then
        echo -e "   ${BLUE}در حال نصب phpseclib...${NC}"
        cd "$DEST" && composer require phpseclib/phpseclib:~3.0 --no-interaction --quiet
        echo -e "   ${GREEN}✓${NC} phpseclib نصب شد"
    else
        echo -e "   ${YELLOW}!${NC} composer نصب نیست. دستی نصب کن:"
        echo -e "      cd $DEST && composer require phpseclib/phpseclib:~3.0"
    fi
fi

# ========================================
# 10. ریستارت Apache
# ========================================
echo ""
echo -e "${YELLOW}10. ریستارت Apache...${NC}"
if systemctl is-active --quiet apache2; then
    systemctl reload apache2
    echo -e "   ${GREEN}✓${NC} Apache ریلود شد"
elif systemctl is-active --quiet nginx; then
    systemctl reload nginx
    echo -e "   ${GREEN}✓${NC} Nginx ریلود شد"
fi

# ========================================
# خلاصه
# ========================================
echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}   خلاصه آپدیت${NC}"
echo -e "${BLUE}========================================${NC}"
echo -e "   ${GREEN}✓ موفق: $COPIED فایل${NC}"
if [ $FAILED -gt 0 ]; then
    echo -e "   ${RED}✗ ناموفق: $FAILED فایل${NC}"
fi
echo -e "   ${BLUE}↻ بات‌های فعال: $BOT_COUNT${NC}"
echo ""
echo -e "${GREEN}فایل‌های آپدیت شده:${NC}"
echo "  SSH Drivers: ssh_helpers, shahan, xpanel, rocket_ssh, dragon"
echo "  Core: panels, keyboard, function, admin, index, table, text.json"
echo "  API: panels, miniapp, category"
echo "  Vpnbot: index.php, admin.php, keyboard.php + $BOT_COUNT بات فعال"
echo "  Miniapp: index.php, service-detail.js, buy.js"
echo ""
echo -e "${GREEN}=== آپدیت با موفقیت انجام شد ===${NC}"
echo ""
echo -e "${YELLOW}نکته مهم:${NC}"
echo "برای آپدیت ساختار دیتابیس، یک پیام تست به ربات بفرست تا table.php اجرا بشه."
echo "یا دستی اجرا کن: cd $DEST && php table.php"
