#!/usr/bin/env python3
"""
Migration Script: Transfer user balances from XSSH to Mirzabot
Usage: python3 migrate_balance.py /path/to/xssh/ssh.db

Example: python3 migrate_balance.py /root/xssh/ssh.db
"""

import sqlite3
import mysql.connector
import sys
import os

# ========================================
# تنظیمات MySQL (Mirzabot)
# ========================================
MYSQL_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': 'Reza1234',  # رمز MySQL
    'database': 'mirzabot',  # نام دیتابیس
    'charset': 'utf8mb4'
}

def main():
    # چک کردن آرگومان
    if len(sys.argv) < 2:
        print("Usage: python3 migrate_balance.py /path/to/xssh/ssh.db")
        print("Example: python3 migrate_balance.py /root/xssh/ssh.db")
        sys.exit(1)

    sqlite_path = sys.argv[1]

    # چک کردن فایل SQLite
    if not os.path.exists(sqlite_path):
        print(f"Error: فایل {sqlite_path} پیدا نشد!")
        sys.exit(1)

    print("=" * 50)
    print("   انتقال موجودی از XSSH به Mirzabot")
    print("=" * 50)
    print()

    # اتصال به SQLite (XSSH)
    try:
        sqlite_conn = sqlite3.connect(sqlite_path)
        sqlite_cursor = sqlite_conn.cursor()
        print(f"✓ اتصال به XSSH: {sqlite_path}")
    except Exception as e:
        print(f"✗ خطا در اتصال به SQLite: {e}")
        sys.exit(1)

    # اتصال به MySQL (Mirzabot)
    try:
        mysql_conn = mysql.connector.connect(**MYSQL_CONFIG)
        mysql_cursor = mysql_conn.cursor(dictionary=True)
        print(f"✓ اتصال به MySQL: {MYSQL_CONFIG['database']}")
    except Exception as e:
        print(f"✗ خطا در اتصال به MySQL: {e}")
        sys.exit(1)

    print()

    # خواندن کاربران با موجودی > 0
    sqlite_cursor.execute("SELECT ID, Name, Username, Balance FROM Clients WHERE Balance > 0")
    users = sqlite_cursor.fetchall()

    if not users:
        print("هیچ کاربری با موجودی > 0 پیدا نشد.")
        sys.exit(0)

    print(f"تعداد کاربران با موجودی: {len(users)}")
    print("-" * 50)
    print(f"{'User ID':<15} {'Username':<20} {'Balance':<15}")
    print("-" * 50)

    total_balance = 0
    for user in users:
        user_id, name, username, balance = user
        total_balance += balance
        print(f"{user_id:<15} @{username or 'N/A':<19} {balance:,} T")

    print("-" * 50)
    print(f"مجموع: {total_balance:,} تومان")
    print()

    # تایید کاربر
    confirm = input("آیا میخواهید ادامه دهید؟ (yes/no): ").strip().lower()
    if confirm != 'yes':
        print("لغو شد.")
        sys.exit(0)

    print()
    print("در حال انتقال...")
    print()

    migrated = 0
    created = 0
    errors = 0

    for user in users:
        user_id, name, username, balance = user
        balance = int(balance)

        try:
            # چک کردن وجود کاربر در Mirzabot
            mysql_cursor.execute("SELECT id, Balance FROM user WHERE id = %s", (user_id,))
            existing = mysql_cursor.fetchone()

            if existing:
                # کاربر وجود دارد - اضافه کردن موجودی
                new_balance = int(existing['Balance'] or 0) + balance
                mysql_cursor.execute(
                    "UPDATE user SET Balance = %s WHERE id = %s",
                    (new_balance, user_id)
                )
                mysql_conn.commit()
                print(f"✓ آپدیت {user_id}: {existing['Balance']} + {balance} = {new_balance} T")
                migrated += 1
            else:
                # کاربر جدید
                mysql_cursor.execute(
                    "INSERT INTO user (id, name, username, Balance, step) VALUES (%s, %s, %s, %s, 'home')",
                    (user_id, name or '', username or '', balance)
                )
                mysql_conn.commit()
                print(f"✓ ایجاد {user_id}: {balance} T")
                created += 1

        except Exception as e:
            print(f"✗ خطا برای {user_id}: {e}")
            errors += 1

    print()
    print("=" * 50)
    print("   خلاصه انتقال")
    print("=" * 50)
    print(f"✓ آپدیت شده: {migrated}")
    print(f"✓ ایجاد شده: {created}")
    if errors:
        print(f"✗ خطا: {errors}")
    print()
    print("=== انتقال با موفقیت انجام شد ===")

    # بستن اتصالات
    sqlite_conn.close()
    mysql_conn.close()

if __name__ == "__main__":
    main()
