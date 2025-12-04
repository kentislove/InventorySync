import logging
from datetime import datetime
from .database import DatabaseManager
from .yahoo_client import YahooClient
from .pchome_client import PChomeClient
from .momo_client import MomoClient
from .sheets_client import GoogleSheetsClient
from .email_alerter import EmailAlerter

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

                        
                    # Update inventory record
                    self.db.update_inventory(sku, platform_name, item.get('quantity'), extra_data)

            process_inventory(yahoo_inv, 'Yahoo')
            process_inventory(pchome_inv, 'PChome')
            process_inventory(momo_inv, 'MOMO')
            
            # 3. Calculate & Sync
            # Strategy: Find minimum stock across all platforms for each SKU, then sync that minimum to all.
            # Or: Master stock is the lowest non-zero? Or just lowest?
            # User requirement: "Sync". Usually means A changed to 5, B is 6 -> B becomes 5.
            # If A=5, B=6, C=5. Min is 5.
            # If A=5, B=4, C=5. Min is 4.
            # We will take the MINIMUM of all available platform stocks as the "True Stock".
            
            all_products = self.db.get_all_inventory()
            # Group by Part No + Spec (composite key)
            product_map = {}
            for row in all_products:
                # row: [sku, platform, quantity, last_synced, extra_data]
                sku = row[0]
                extra_data = row[4]
                
                # Get spec_name from extra_data (already normalized during storage)

                spec_name = extra_data.get('spec_name', '')

                

                # Create composite key: Part No + Spec

                
                # Create composite key: Part No + Spec
                composite_key = f"{sku}_{spec_name}"
                
                if composite_key not in product_map:
                    product_map[composite_key] = []
                product_map[composite_key].append(row)
                
            sync_results = []
            
            # Track update counts
            update_counts = {'Yahoo': 0, 'PChome': 0, 'MOMO': 0}
            comparison_matrix = []
            
            for composite_key, records in product_map.items():
                # Calculate minimum quantity
                quantities = [r[2] for r in records if r[2] is not None]
                if not quantities:
                    continue
                    
                min_qty = min(quantities)
                
                # Extract SKU and Spec from first record (already grouped by composite key)
                sku = records[0][0]

                spec_name = records[0][4].get('spec_name', 'Unknown')

                

                    spec_name = records[0][4].get('goodsdt_info', '')
                if not spec_name:
                    spec_name = "Unknown"
                
                # Prepare comparison record for this SKU + Spec
                comp_record = {
                    "sku": sku,
                    "name": records[0][4].get('name', 'Unknown') if records else 'Unknown',
                    "spec_name": spec_name, # New field
                    "yahoo_qty": 0,
                    "pchome_qty": 0,
                    "momo_qty": 0,
                    "target_qty": min_qty,
                    "yahoo_delta": 0,
                    "pchome_delta": 0,
                    "momo_delta": 0,
                    "status": "Synced"
                }
                
                # Update platforms that don't match min_qty
                for row in records:
                    platform = row[1]
                    current_qty = row[2]
                    extra_data = row[4] # Dict
                    
                    # Fill comparison record
                    if platform == 'Yahoo':
                        comp_record['yahoo_qty'] = current_qty
                        comp_record['yahoo_delta'] = min_qty - current_qty
                    elif platform == 'PChome':
                        comp_record['pchome_qty'] = current_qty
                        comp_record['pchome_delta'] = min_qty - current_qty
                    elif platform == 'MOMO':
                        comp_record['momo_qty'] = current_qty
                        comp_record['momo_delta'] = min_qty - current_qty
                    
                    if current_qty != min_qty:
                        # Increment update count for this platform
                        update_counts[platform] = update_counts.get(platform, 0) + 1
                        comp_record['status'] = "Updating"
                        
                        success = False
                        msg = ""
                        
                        logger.info(f"Syncing {sku} ({spec_name}) on {platform}: {current_qty} -> {min_qty}")
                        
                        if platform == 'Yahoo':
                            pid = extra_data.get('yahoo_id')
                            if pid:
                                success = self.yahoo.update_inventory(pid, min_qty)
                            else:
                                msg = "Missing Yahoo Product ID"
                                
                        elif platform == 'PChome':
                            pid = extra_data.get('pchome_id')
                            if pid:
                                success = self.pchome.update_inventory(pid, min_qty)
                            else:
                                msg = "Missing PChome ID"
                                
                        elif platform == 'MOMO':
                            m_code = extra_data.get('momo_code')
                            if m_code:
                                extra_data['current_stock'] = current_qty
                                success = self.momo.update_inventory(m_code, min_qty, extra_data)
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
                            "name": comp_record['name'], # Add name
                            "spec": spec_name, # Add spec
                            "platform": platform,
                            "action": "Update",
                            "quantity_change": min_qty - current_qty,
                            "status": status,
                            "message": msg
                        })
                        
                        # Update local DB if success
                        if success:
                            self.db.update_inventory(sku, platform, min_qty, extra_data)
                
                comparison_matrix.append(comp_record)

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
