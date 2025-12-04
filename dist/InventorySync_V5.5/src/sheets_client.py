import gspread
from oauth2client.service_account import ServiceAccountCredentials
import logging
import pandas as pd
from datetime import datetime

logger = logging.getLogger(__name__)

class GoogleSheetsClient:
    def __init__(self, config):
        self.credentials_file = config.get('credentials_file')
        self.sheet_name = config.get('sheet_name')
        self.client = None
        self.sheet = None
        self._authenticate()

    def _authenticate(self):
        """Authenticate with Google Sheets API."""
        try:
            scope = ['https://spreadsheets.google.com/feeds', 'https://www.googleapis.com/auth/drive']
            creds = ServiceAccountCredentials.from_json_keyfile_name(self.credentials_file, scope)
            self.client = gspread.authorize(creds)
            self.sheet = self.client.open(self.sheet_name)
            logger.info("Authenticated with Google Sheets successfully.")
        except Exception as e:
            logger.error(f"Google Sheets authentication failed: {e}")
            # Don't raise here to allow local operations to continue if internet is down, 
            # but syncing to sheets will fail.
            
    def log_sync_result(self, sync_data):
        """
        Log sync results to a specific worksheet.
        sync_data: list of dicts or lists to append
        """
        if not self.sheet:
            logger.warning("Google Sheets not connected. Skipping log.")
            return False

        try:
            worksheet = self._get_or_create_worksheet("Sync_Log")
            
            # Check if header exists, if not add it
            if worksheet.row_count == 0 or not worksheet.row_values(1):
                headers = ["Timestamp", "SKU", "Name", "Spec", "Platform", "Action", "Quantity Change", "Status", "Message"]
                worksheet.append_row(headers)

            rows_to_append = []
            for row in sync_data:
                row_values = []
                if isinstance(row, dict):
                    # Ensure order
                    row_values = [
                        row.get("timestamp", ""),
                        row.get("sku", ""),
                        row.get("name", ""), # New
                        row.get("spec", ""), # New
                        row.get("platform", ""),
                        row.get("action", ""),
                        row.get("quantity_change", ""),
                        row.get("status", ""),
                        row.get("message", "")
                    ]
                elif isinstance(row, list):
                    row_values = row
                else:
                    logger.warning(f"Skipping invalid row format: {type(row)}")
                    continue
                rows_to_append.append(row_values)
            
            if rows_to_append:
                worksheet.append_rows(rows_to_append)
                logger.info(f"Logged {len(rows_to_append)} rows to Google Sheets.")
            
            return True
        except Exception as e:
            logger.error(f"Failed to log to Google Sheets: {e}")
            return False

    def update_platform_inventory(self, platform_name, inventory_data):
        """
        Update a specific platform's inventory sheet.
        inventory_data: list of dicts
        """
        if not self.sheet:
            return

        sheet_title = f"{platform_name}_Inventory"
        try:
            worksheet = self._get_or_create_worksheet(sheet_title)
            worksheet.clear()
            
            # Headers
            headers = ["SKU", "Name", "Spec", "Quantity", "Part No", "Last Updated"]
            worksheet.append_row(headers)
            
            # Data
            rows = []
            timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            for item in inventory_data:
                rows.append([
                    item.get('sku', ''), # Platform ID
                    item.get('name', ''),
                    item.get('spec_name', ''),
                    item.get('quantity', 0),
                    item.get('part_no', ''), # Internal SKU
                    timestamp
                ])
            
            if rows:
                # Batch update
                worksheet.append_rows(rows)
            logger.info(f"Updated {sheet_title} in Google Sheets ({len(rows)} rows).")
        except Exception as e:
            logger.error(f"Failed to update {sheet_title}: {e}")

    def update_comparison_report(self, matrix_data):
        """
        Update the comparison report sheet (Side-by-side view).
        matrix_data: list of dicts (comparison_matrix)
        """
        if not self.sheet:
            return

        sheet_title = "Stock_Comparison"
        try:
            worksheet = self._get_or_create_worksheet(sheet_title)
            worksheet.clear()
            
            # Headers
            headers = ["SKU", "Name", "Spec", "Min Qty", "Yahoo Qty", "PChome Qty", "MOMO Qty", "Status", "Last Updated"]
            worksheet.append_row(headers)
            
            # Data
            rows = []
            timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            for item in matrix_data:
                # Highlight discrepancies? 
                # For now just dump the data
                rows.append([
                    item.get('sku', ''),
                    item.get('name', ''),
                    item.get('spec_name', ''),
                    item.get('min_qty', 0),
                    item.get('yahoo_qty', 0),
                    item.get('pchome_qty', 0),
                    item.get('momo_qty', 0),
                    item.get('status', ''),
                    timestamp
                ])
            
            if rows:
                worksheet.append_rows(rows)
            logger.info(f"Updated {sheet_title} in Google Sheets ({len(rows)} rows).")
        except Exception as e:
            logger.error(f"Failed to update {sheet_title}: {e}")

    def update_dashboard_data(self, inventory_data):
        """
        Update the dashboard data sheet.
        inventory_data: list of dicts representing current inventory state
        """
        if not self.sheet:
            return

        try:
            worksheet = self._get_or_create_worksheet("Dashboard_Data")
            worksheet.clear()
            
            # Convert to DataFrame for easier handling if needed, or just list of lists
            # Headers
            headers = ["SKU", "Name", "Platform", "Quantity", "Last Synced"]
            worksheet.append_row(headers)
            
            # Data
            rows = []
            for item in inventory_data:
                rows.append([
                    item.get('sku'),
                    item.get('name'),
                    item.get('platform'),
                    item.get('quantity'),
                    item.get('last_synced')
                ])
            
            if rows:
                worksheet.append_rows(rows)
            logger.info("Dashboard data updated in Google Sheets.")
        except Exception as e:
            logger.error(f"Failed to update dashboard data: {e}")

    def _get_or_create_worksheet(self, title):
        """Get a worksheet by title or create it if it doesn't exist."""
        try:
            return self.sheet.worksheet(title)
        except gspread.WorksheetNotFound:
            return self.sheet.add_worksheet(title=title, rows=1000, cols=20)
