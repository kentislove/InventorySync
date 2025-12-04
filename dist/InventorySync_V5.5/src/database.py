import sqlite3
import logging
import os
import json
from datetime import datetime

logger = logging.getLogger(__name__)

class DatabaseManager:
    def __init__(self, db_path="inventory.db"):
        self.db_path = db_path
        self.conn = None
        self.cursor = None
        self._initialize_db()

    def _initialize_db(self):
        """Initialize the database with required tables."""
        try:
            self.connect()
            # Products table: Stores the master list of products
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS products (
                    sku TEXT PRIMARY KEY,
                    name TEXT,
                    safety_stock INTEGER DEFAULT 0,
                    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ''')
            
            # Inventory table: Stores current stock levels for each platform
            # Added extra_data for platform specific fields (e.g. MOMO goodsdt_info)
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS inventory (
                    sku TEXT,
                    platform TEXT,
                    quantity INTEGER,
                    last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    extra_data TEXT, 
                    PRIMARY KEY (sku, platform),
                    FOREIGN KEY (sku) REFERENCES products (sku)
                )
            ''')
            
            # Check if extra_data column exists (migration for existing db)
            try:
                self.cursor.execute('SELECT extra_data FROM inventory LIMIT 1')
            except sqlite3.OperationalError:
                logger.info("Adding extra_data column to inventory table")
                self.cursor.execute('ALTER TABLE inventory ADD COLUMN extra_data TEXT')
            
            # Sync History table: Logs all sync actions
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS sync_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    sku TEXT,
                    platform TEXT,
                    action TEXT,
                    quantity_change INTEGER,
                    status TEXT,
                    message TEXT
                )
            ''')
            
            # Inventory History table: Tracks quantity changes over time
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS inventory_history (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    platform TEXT NOT NULL,
                    sku TEXT NOT NULL,
                    quantity INTEGER NOT NULL,
                    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ''')
            
            # Create index for faster queries
            self.cursor.execute('''
                CREATE INDEX IF NOT EXISTS idx_inventory_history_sku 
                ON inventory_history(platform, sku, timestamp DESC)
            ''')
            
            # Product Sync Tracking table: Records when products were last synced
            self.cursor.execute('''
                CREATE TABLE IF NOT EXISTS product_sync_tracking (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    sync_date DATE NOT NULL,
                    sync_type TEXT NOT NULL,
                    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ''')
            
            self.conn.commit()
            logger.info("Database initialized successfully.")
        except Exception as e:
            logger.error(f"Database initialization failed: {e}")
            raise
        finally:
            self.close()

    def connect(self):
        """Establish a connection to the database."""
        try:
            self.conn = sqlite3.connect(self.db_path)
            self.cursor = self.conn.cursor()
        except Exception as e:
            logger.error(f"Failed to connect to database: {e}")
            raise

    def close(self):
        """Close the database connection."""
        if self.conn:
            self.conn.close()
            self.conn = None
            self.cursor = None

    def upsert_product(self, sku, name, safety_stock=None):
        """Insert or update a product."""
        try:
            self.connect()
            if safety_stock is not None:
                self.cursor.execute('''
                    INSERT INTO products (sku, name, safety_stock, last_updated)
                    VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(sku) DO UPDATE SET
                        name=excluded.name,
                        safety_stock=excluded.safety_stock,
                        last_updated=CURRENT_TIMESTAMP
                ''', (sku, name, safety_stock))
            else:
                self.cursor.execute('''
                    INSERT INTO products (sku, name, last_updated)
                    VALUES (?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(sku) DO UPDATE SET
                        name=excluded.name,
                        last_updated=CURRENT_TIMESTAMP
                ''', (sku, name))
            self.conn.commit()
        except Exception as e:
            logger.error(f"Error upserting product {sku}: {e}")
        finally:
            self.close()

    def update_inventory(self, sku, platform, quantity, extra_data=None):
        """Update inventory count for a specific platform."""
        try:
            self.connect()
            extra_data_json = json.dumps(extra_data) if extra_data else None
            self.cursor.execute('''
                INSERT INTO inventory (sku, platform, quantity, last_synced, extra_data)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)
                ON CONFLICT(sku, platform) DO UPDATE SET
                    quantity=excluded.quantity,
                    last_synced=CURRENT_TIMESTAMP,
                    extra_data=excluded.extra_data
            ''', (sku, platform, quantity, extra_data_json))
            self.conn.commit()
        except Exception as e:
            logger.error(f"Error updating inventory for {sku} on {platform}: {e}")
        finally:
            self.close()

    def log_sync_action(self, sku, platform, action, quantity_change, status, message=""):
        """Log a sync action."""
        try:
            self.connect()
            self.cursor.execute('''
                INSERT INTO sync_history (sku, platform, action, quantity_change, status, message)
                VALUES (?, ?, ?, ?, ?, ?)
            ''', (sku, platform, action, quantity_change, status, message))
            self.conn.commit()
        except Exception as e:
            logger.error(f"Error logging sync action: {e}")
        finally:
            self.close()

    def get_all_inventory(self):
        """Get all inventory records."""
        try:
            self.connect()
            self.cursor.execute('SELECT * FROM inventory')
            rows = self.cursor.fetchall()
            # Convert extra_data back to dict
            result = []
            for row in rows:
                # row structure: sku, platform, quantity, last_synced, extra_data
                r = list(row)
                if r[4]: # extra_data
                    try:
                        r[4] = json.loads(r[4])
                    except:
                        r[4] = {}
                result.append(r)
            return result
        finally:
            self.close()

    def get_platform_inventory(self, sku, platform):
        """Get inventory record for specific sku and platform."""
        try:
            self.connect()
            self.cursor.execute('SELECT * FROM inventory WHERE sku = ? AND platform = ?', (sku, platform))
            row = self.cursor.fetchone()
            if row:
                r = list(row)
                if r[4]:
                    try:
                        r[4] = json.loads(r[4])
                    except:
                        r[4] = {}
                return r
            return None
        finally:
            self.close()
    
    def get_last_quantity(self, platform, sku):
        """取得上次記錄的庫存數量"""
        try:
            self.connect()
            self.cursor.execute('''
                SELECT quantity FROM inventory_history 
                WHERE platform = ? AND sku = ? 
                ORDER BY timestamp DESC LIMIT 1
            ''', (platform, sku))
            row = self.cursor.fetchone()
            return row[0] if row else None
        finally:
            self.close()
    
    def update_last_quantity(self, platform, sku, quantity):
        """更新庫存歷史記錄"""
        try:
            self.connect()
            self.cursor.execute('''
                INSERT INTO inventory_history (platform, sku, quantity, timestamp)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ''', (platform, sku, quantity))
            self.conn.commit()
        except Exception as e:
            logger.error(f"Error updating inventory history: {e}")
        finally:
            self.close()
    
    def is_product_synced_today(self):
        """檢查今天是否已執行過產品同步"""
        try:
            self.connect()
            self.cursor.execute('''
                SELECT COUNT(*) FROM product_sync_tracking 
                WHERE sync_date = DATE('now') AND sync_type = 'full'
            ''')
            count = self.cursor.fetchone()[0]
            return count > 0
        finally:
            self.close()
    
    def record_product_sync(self, sync_type='full'):
        """記錄產品同步執行"""
        try:
            self.connect()
            self.cursor.execute('''
                INSERT INTO product_sync_tracking (sync_date, sync_type, timestamp)
                VALUES (DATE('now'), ?, CURRENT_TIMESTAMP)
            ''', (sync_type,))
            self.conn.commit()
        except Exception as e:
            logger.error(f"Error recording product sync: {e}")
        finally:
            self.close()
    
    def get_platform_inventory_list(self, platform):
        """取得指定平台的所有庫存記錄"""
        try:
            self.connect()
            self.cursor.execute('''
                SELECT i.sku, p.name, i.quantity, i.extra_data
                FROM inventory i
                LEFT JOIN products p ON i.sku = p.sku
                WHERE i.platform = ?
            ''', (platform,))
            rows = self.cursor.fetchall()
            
            result = []
            for row in rows:
                extra_data = {}
                if row[3]:
                    try:
                        extra_data = json.loads(row[3])
                    except:
                        pass
                
                result.append({
                    'sku': row[0],
                    'name': row[1],
                    'quantity': row[2],
                    'part_no': extra_data.get('part_no', row[0]),
                    'spec_name': extra_data.get('spec_name', '')
                })
            
            return result
        finally:
            self.close()

