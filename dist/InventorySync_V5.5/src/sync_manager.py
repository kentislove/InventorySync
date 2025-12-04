import logging
from datetime import datetime
from database import DatabaseManager
from yahoo_client import YahooClient
from pchome_client import PChomeClient
from momo_client import MomoClient
from sheets_client import GoogleSheetsClient
from email_alerter import EmailAlerter
from stock_comparison import StockComparator

logger = logging.getLogger(__name__)

class SyncManager:
    def __init__(self, config):
        self.config = config
        self.db = DatabaseManager(config['database']['path'])
        self.yahoo = YahooClient(config['yahoo'])
        self.pchome = PChomeClient(config['pchome'])
        self.momo = MomoClient(config['momo'])
        self.sheets = GoogleSheetsClient(config['google_sheets'])
        self.alerter = EmailAlerter(config['email_alert'])
        self.comparator = StockComparator(self.db)
        self.safety_stock = config['system'].get('safety_stock', 0)

    def run_sync(self):
        """Execute the full sync process."""
        logger.info("Starting sync process...")
        
        try:
            # 1. Download Inventory from all platforms
            logger.info("Fetching inventory from platforms...")
            yahoo_inv = self.yahoo.get_inventory(filter_expired=True)
            pchome_inv = self.pchome.get_inventory()
            momo_inv = self.momo.get_inventory()
            
            logger.info(f"Downloaded: Yahoo={len(yahoo_inv)}, PChome={len(pchome_inv)}, MOMO={len(momo_inv)}")
            
            # 2. Update Local Database & Consolidate
            # We use 'sku' (which corresponds to 'part_no' in API clients) as the common identifier.
            # Note: API clients return 'sku' as the platform's internal ID, and 'part_no' as our SKU.
            # We should normalize this.
            
            # Helper to process inventory list
            def process_inventory(inv_list, platform_name):
                for item in inv_list:
                    # Use 'part_no' as our SKU if available, else fallback to 'sku'
                    sku = item.get('part_no') or item.get('sku')
                    if not sku:
                        continue
                        
                    # Upsert product master
                    self.db.upsert_product(sku, item.get('name'))
                    
                    # Prepare extra data
                    extra_data = {
                        k: v for k, v in item.items() 
                        if k not in ['sku', 'part_no', 'name', 'quantity', 'platform']
                    }
                    # Also store platform internal ID in extra_data or separate field?
                    # Database schema has 'sku' and 'platform'. 
                    # We treat 'sku' column in DB as our common SKU (part_no).
                    # Platform internal ID (e.g. Yahoo ProductId, MOMO goodsCode) should be in extra_data.

                    

                    # Ensure name and spec_name are in extra_data for easy retrieval later

                    extra_data['name'] = item.get('name')

                    extra_data['spec_name'] = item.get('spec_name', '')

                    

                    # 統一 spec_name 格式：確保有 / 前綴

                    if extra_data['spec_name'] and not extra_data['spec_name'].startswith('/'):

                        extra_data['spec_name'] = f"/{extra_data['spec_name']}"

                    

                    if platform_name == 'Yahoo':

                        extra_data['yahoo_id'] = item.get('sku')

                    elif platform_name == 'MOMO':

                        extra_data['momo_code'] = item.get('momo_sku') # momo_client returns 'momo_sku' as internal code

                    elif platform_name == 'PChome':
                        extra_data['pchome_id'] = item.get('sku')

                    self.db.update_inventory(sku, platform_name, item.get('quantity'), extra_data)

            process_inventory(yahoo_inv, 'Yahoo')
            process_inventory(pchome_inv, 'PChome')
            process_inventory(momo_inv, 'MOMO')
            
            # 3. Calculate & Sync
            # Use the new StockComparator module
            logger.info("Comparing inventory across platforms...")
            comparison_matrix = self.comparator.compare_inventory()
            
            sync_results = []
            update_counts = {'Yahoo': 0, 'PChome': 0, 'MOMO': 0}
            
            # 4. Execute Updates based on Comparison Matrix
            for record in comparison_matrix:
                sku = record['sku']
                spec_name = record['spec_name']
                min_qty = record['target_qty']
                
                # Check each platform for delta
                platforms_to_update = []
                if record['yahoo_delta'] != 0:
                    platforms_to_update.append(('Yahoo', record['yahoo_qty'], record['yahoo_id']))
                if record['pchome_delta'] != 0:
                    platforms_to_update.append(('PChome', record['pchome_qty'], record['pchome_id']))
                if record['momo_delta'] != 0:
                    platforms_to_update.append(('MOMO', record['momo_qty'], (record.get('momo_code'), record.get('momo_extra'))))
                
                for platform, current_qty, platform_id_data in platforms_to_update:
                    # Increment update count
                    update_counts[platform] = update_counts.get(platform, 0) + 1
                    record['status'] = "Updating" # Update status in matrix for report
                    
                    success = False
                    msg = ""
                    
                    logger.info(f"Syncing {sku} ({spec_name}) on {platform}: {current_qty} -> {min_qty}")
                    
                    if platform == 'Yahoo':
                        if platform_id_data:
                            success = self.yahoo.update_inventory(platform_id_data, min_qty)
                        else:
                            msg = "Missing Yahoo Product ID"
                            
                    elif platform == 'PChome':
                        if platform_id_data:
                            success = self.pchome.update_inventory(platform_id_data, min_qty)
                        else:
                            msg = "Missing PChome ID"
                            
                    elif platform == 'MOMO':
                        m_code, m_extra = platform_id_data
                        if m_code:
                            # Ensure current_stock is in extra_data for MOMO check
                            if m_extra:
                                m_extra['current_stock'] = current_qty
                            success = self.momo.update_inventory(m_code, min_qty, m_extra)
                        else:
                            msg = "Missing MOMO Code"
                    
                    # Log result
                    status = "Success" if success else "Failed"
                    if not success and not msg:
                        msg = "API Error"
                        
                    self.db.log_sync_action(sku, platform, "Update", min_qty - current_qty, status, msg)
                    
                    sync_results.append({
                        "timestamp": datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                        "sku": sku,
                        "name": record['name'],
                        "spec": spec_name,
                        "platform": platform,
                        "action": "Update",
                        "quantity_change": min_qty - current_qty,
                        "status": status,
                        "message": msg
                    })
                    
                    # Update local DB if success
                    if success:
                        try:
                            # Fetch current data to preserve extra_data
                            current_record = self.db.get_platform_inventory(sku, platform)
                            current_extra = current_record[4] if current_record else {}
                            # If we have new extra data from MOMO update, merge it? 
                            # For now, just preserving is enough, or if MOMO returned new info we might want it.
                            # But let's stick to preserving what we have + updating quantity.
                            self.db.update_inventory(sku, platform, min_qty, current_extra)
                        except Exception as db_e:
                            logger.error(f"Failed to update local DB for {sku} on {platform}: {db_e}")

            # 5. Log to Google Sheets
            if sync_results:
                self.sheets.log_sync_result(sync_results)
            
            # Export Individual Platform Inventories to Google Sheets
            self.sheets.update_platform_inventory('Yahoo', yahoo_inv)
            self.sheets.update_platform_inventory('PChome', pchome_inv)
            self.sheets.update_platform_inventory('MOMO', momo_inv)
            
            # Export Comparison Report
            self.sheets.update_comparison_report(comparison_matrix)
            
            # 6. Export Local Dashboard Data
            download_counts = {
                'Yahoo': len(yahoo_inv),
                'PChome': len(pchome_inv),
                'MOMO': len(momo_inv)
            }
            self.export_dashboard_data(download_counts, update_counts, comparison_matrix)
            
            logger.info("Sync process completed successfully.")
            
        except Exception as e:
            logger.error(f"Sync process failed: {e}")
            self.alerter.send_alert("Sync Failed", str(e))
            raise

    def export_dashboard_data(self, download_counts, update_counts, comparison_matrix):
        """Export data to JS file for the local dashboard (JSONP style to avoid CORS)."""
        import json
        import os
        
        try:
            # Fetch stats from DB
            all_inv = self.db.get_all_inventory()
            yahoo_total = sum(1 for r in all_inv if r[1] == 'Yahoo')
            pchome_total = sum(1 for r in all_inv if r[1] == 'PChome')
            momo_total = sum(1 for r in all_inv if r[1] == 'MOMO')
            
            data = {
                "stats": {
                    "last_sync": datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                    "yahoo_downloaded": download_counts.get('Yahoo', 0),
                    "pchome_downloaded": download_counts.get('PChome', 0),
                    "momo_downloaded": download_counts.get('MOMO', 0),
                    "yahoo_updated": update_counts.get('Yahoo', 0),
                    "pchome_updated": update_counts.get('PChome', 0),
                    "momo_updated": update_counts.get('MOMO', 0),
                    "yahoo_total_db": yahoo_total,
                    "pchome_total_db": pchome_total,
                    "momo_total_db": momo_total
                },
                "comparison_matrix": comparison_matrix,
                "recent_logs": []
            }
            
            # Get recent logs from DB
            self.db.connect()
            self.db.cursor.execute('SELECT timestamp, sku, platform, action, status FROM sync_history ORDER BY id DESC LIMIT 50')
            logs = self.db.cursor.fetchall()
            self.db.close()
            
            for log in logs:
                data["recent_logs"].append({
                    "timestamp": log[0],
                    "sku": log[1],
                    "platform": log[2],
                    "action": log[3],
                    "status": log[4]
                })
            
            # Ensure dashboard directory exists
            os.makedirs('dashboard', exist_ok=True)
            
            # Write to data.js
            with open('dashboard/data.js', 'w', encoding='utf-8') as f:
                json_str = json.dumps(data, ensure_ascii=False, indent=2)
                f.write(f"window.dashboardData = {json_str};")
                
            logger.info("Dashboard data exported to dashboard/data.js")
        except Exception as e:
            logger.error(f"Failed to export dashboard data: {e}")
