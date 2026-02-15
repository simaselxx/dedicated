#!/usr/bin/env python3
"""
Migration Script: Transfer active SSH services from XSSH to Mirzabot
Usage: python3 migrate_services.py /path/to/xssh/ssh.db

This script:
1. Reads user-account mappings from XSSH database (Users table)
2. Maps panels from XSSH to Mirzabot
3. Creates invoice records in Mirzabot for each active service
"""

import sqlite3
import mysql.connector
import sys
import os
import json
import time
import random
import string

# ========================================
# MySQL Configuration (Mirzabot)
# ========================================
MYSQL_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': 'Reza1234',  # Your MySQL password
    'database': 'mirzabot',
    'charset': 'utf8mb4'
}

# ========================================
# Panel Mapping: XSSH Host → Mirzabot Panel Name
# ========================================
# Update this mapping based on your panels
PANEL_MAPPING = {
    # 'XSSH Host Name': 'Mirzabot Panel Name'
    # Example:
    # 'Server1': 'تایید',
}

def generate_invoice_id():
    """Generate unique invoice ID like Mirzabot does"""
    timestamp = str(int(time.time()))
    random_str = ''.join(random.choices(string.ascii_lowercase + string.digits, k=6))
    return f"{timestamp}_{random_str}"

def main():
    if len(sys.argv) < 2:
        print("Usage: python3 migrate_services.py /path/to/xssh/ssh.db")
        print("Example: python3 migrate_services.py /root/xssh/ssh.db")
        sys.exit(1)

    sqlite_path = sys.argv[1]

    if not os.path.exists(sqlite_path):
        print(f"Error: File {sqlite_path} not found!")
        sys.exit(1)

    print("=" * 60)
    print("   Migrate SSH Services from XSSH to Mirzabot")
    print("=" * 60)
    print()

    # Connect to XSSH SQLite
    try:
        sqlite_conn = sqlite3.connect(sqlite_path)
        sqlite_cursor = sqlite_conn.cursor()
        print(f"✓ Connected to XSSH: {sqlite_path}")
    except Exception as e:
        print(f"✗ Error connecting to SQLite: {e}")
        sys.exit(1)

    # Connect to Mirzabot MySQL
    try:
        mysql_conn = mysql.connector.connect(**MYSQL_CONFIG)
        mysql_cursor = mysql_conn.cursor(dictionary=True)
        print(f"✓ Connected to Mirzabot MySQL")
    except Exception as e:
        print(f"✗ Error connecting to MySQL: {e}")
        sys.exit(1)

    print()

    # Step 1: Get unique hosts from XSSH
    print("Step 1: Checking panels...")
    sqlite_cursor.execute("SELECT DISTINCT Host FROM Users")
    hosts = [row[0] for row in sqlite_cursor.fetchall()]
    print(f"Found {len(hosts)} unique hosts in XSSH: {hosts}")

    # Step 2: Get panels from Mirzabot
    mysql_cursor.execute("SELECT name_panel, type FROM marzban_panel WHERE type IN ('shahan', 'xpanel', 'rocket_ssh', 'dragon')")
    mirzabot_panels = {row['name_panel']: row['type'] for row in mysql_cursor.fetchall()}
    print(f"Found {len(mirzabot_panels)} SSH panels in Mirzabot: {list(mirzabot_panels.keys())}")
    print()

    # Auto-map panels if not manually configured
    if not PANEL_MAPPING:
        print("No manual panel mapping configured. Trying auto-mapping...")
        for host in hosts:
            if host in mirzabot_panels:
                PANEL_MAPPING[host] = host
                print(f"  Auto-mapped: {host} → {host}")
            else:
                print(f"  ⚠ No match for: {host}")
        print()

    if not PANEL_MAPPING:
        print("Error: No panels could be mapped!")
        print("Edit PANEL_MAPPING in this script to map XSSH hosts to Mirzabot panels")
        sys.exit(1)

    # Step 3: Get all services from XSSH
    print("Step 2: Reading services from XSSH...")
    sqlite_cursor.execute("""
        SELECT u.ID, u.Name, u.Username, u.Account, u.Host, c.Balance
        FROM Users u
        LEFT JOIN Clients c ON u.ID = c.ID
    """)
    services = sqlite_cursor.fetchall()
    print(f"Found {len(services)} services in XSSH")
    print()

    if not services:
        print("No services to migrate.")
        sys.exit(0)

    # Display preview
    print("-" * 80)
    print(f"{'User ID':<15} {'SSH Username':<25} {'Host':<20} {'→ Mirzabot Panel':<20}")
    print("-" * 80)

    migrate_list = []
    for service in services:
        user_id, name, username, account, host, balance = service
        mirzabot_panel = PANEL_MAPPING.get(host)
        if mirzabot_panel:
            migrate_list.append({
                'user_id': user_id,
                'name': name or '',
                'username': username or '',
                'account': account,
                'host': host,
                'mirzabot_panel': mirzabot_panel,
                'balance': balance or 0
            })
            print(f"{user_id:<15} {account:<25} {host:<20} → {mirzabot_panel:<20}")
        else:
            print(f"{user_id:<15} {account:<25} {host:<20} → SKIPPED (no mapping)")

    print("-" * 80)
    print(f"Services to migrate: {len(migrate_list)}")
    print()

    if not migrate_list:
        print("No services to migrate (no panel mappings).")
        sys.exit(0)

    # Confirm
    confirm = input("Proceed with migration? (yes/no): ").strip().lower()
    if confirm != 'yes':
        print("Cancelled.")
        sys.exit(0)

    print()
    print("Migrating...")
    print()

    created = 0
    skipped = 0
    errors = 0

    for service in migrate_list:
        user_id = service['user_id']
        account = service['account']
        panel_name = service['mirzabot_panel']

        try:
            # Check if invoice already exists
            mysql_cursor.execute(
                "SELECT id_invoice FROM invoice WHERE username = %s AND Service_location = %s",
                (account, panel_name)
            )
            existing = mysql_cursor.fetchone()

            if existing:
                print(f"⊘ Skip {account}@{panel_name} (already exists)")
                skipped += 1
                continue

            # Check if user exists in Mirzabot
            mysql_cursor.execute("SELECT id FROM user WHERE id = %s", (user_id,))
            user_exists = mysql_cursor.fetchone()

            if not user_exists:
                # Create user
                mysql_cursor.execute(
                    "INSERT INTO user (id, name, username, Balance, step) VALUES (%s, %s, %s, %s, 'home')",
                    (user_id, service['name'], service['username'], service['balance'])
                )
                mysql_conn.commit()
                print(f"  + Created user {user_id}")

            # Create invoice record
            invoice_id = generate_invoice_id()
            now = time.strftime('%Y-%m-%d %H:%M:%S')

            # Default values - service info will be fetched from panel when viewed
            mysql_cursor.execute("""
                INSERT INTO invoice
                (id_invoice, id_user, username, name_product, price_product, Volume,
                 Service_location, time_sell, status, notifctions, uuid, user_info)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """, (
                invoice_id,
                user_id,
                account,
                'Migrated from XSSH',  # Product name
                '0',                    # Price (unknown)
                '0',                    # Volume (will be fetched from panel)
                panel_name,
                now,
                'active',
                json.dumps({'volume': False, 'time': False}),
                None,
                None
            ))
            mysql_conn.commit()
            print(f"✓ Migrated {account}@{panel_name} → invoice {invoice_id}")
            created += 1

        except Exception as e:
            print(f"✗ Error migrating {account}: {e}")
            errors += 1

    print()
    print("=" * 60)
    print("   Migration Summary")
    print("=" * 60)
    print(f"✓ Created: {created}")
    print(f"⊘ Skipped: {skipped}")
    if errors:
        print(f"✗ Errors: {errors}")
    print()
    print("=== Migration Complete ===")

    sqlite_conn.close()
    mysql_conn.close()

if __name__ == "__main__":
    main()
