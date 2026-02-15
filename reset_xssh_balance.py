#!/usr/bin/env python3
"""
Reset all XSSH user balances to zero
Usage: python3 reset_xssh_balance.py /path/to/xssh/ssh.db
"""

import sqlite3
import sys
import os

def main():
    if len(sys.argv) < 2:
        print("Usage: python3 reset_xssh_balance.py /path/to/xssh/ssh.db")
        print("Example: python3 reset_xssh_balance.py /root/xssh/ssh.db")
        sys.exit(1)

    db_path = sys.argv[1]

    if not os.path.exists(db_path):
        print(f"Error: Database not found: {db_path}")
        sys.exit(1)

    print("=== Reset XSSH Balances ===\n")

    conn = sqlite3.connect(db_path)
    cursor = conn.cursor()

    # Show current balances
    cursor.execute("SELECT COUNT(*), SUM(Balance) FROM Clients WHERE Balance > 0")
    count, total = cursor.fetchone()
    total = total or 0

    print(f"Users with balance > 0: {count}")
    print(f"Total balance: {total:,.0f} T\n")

    if count == 0:
        print("No balances to reset.")
        conn.close()
        sys.exit(0)

    # Show list
    cursor.execute("SELECT ID, Username, Balance FROM Clients WHERE Balance > 0")
    users = cursor.fetchall()

    print("-" * 50)
    print(f"{'User ID':<15} {'Username':<20} {'Balance':<15}")
    print("-" * 50)
    for user_id, username, balance in users:
        print(f"{user_id:<15} @{username or 'N/A':<19} {balance:,.0f} T")
    print("-" * 50)

    confirm = input("\nReset ALL balances to ZERO? (yes/no): ").strip().lower()

    if confirm != 'yes':
        print("Cancelled.")
        conn.close()
        sys.exit(0)

    # Reset all balances
    cursor.execute("UPDATE Clients SET Balance = 0 WHERE Balance > 0")
    conn.commit()

    print(f"\nReset {cursor.rowcount} user balances to zero.")
    print("Done!")

    conn.close()

if __name__ == "__main__":
    main()
