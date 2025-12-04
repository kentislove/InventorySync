import sys
import os
import logging
from datetime import datetime

# Add project root to path
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from src.main import load_config
from src.sheets_client import GoogleSheetsClient

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

def test_connection():
    logger.info("Testing Google Sheets Connection...")
    
    config = load_config()
    if not config:
        logger.error("Failed to load config.")
        return

    try:
        client = GoogleSheetsClient(config['google_sheets'])
        
        if client.sheet:
            logger.info(f"Successfully connected to Sheet: {client.sheet.title}")
            
            # Try writing a test log
            test_data = [{
                "timestamp": datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                "sku": "TEST-CONNECTION",
                "platform": "SYSTEM",
                "action": "TEST",
                "quantity_change": 0,
                "status": "Success",
                "message": "Connection test successful"
            }]
            
            success = client.log_sync_result(test_data)
            if success:
                logger.info("Successfully wrote test log to 'Sync_Log' worksheet.")
                print("TEST_SUCCESS")
            else:
                logger.error("Failed to write test log.")
                print("TEST_FAILED")
        else:
            logger.error("Client initialized but sheet object is None.")
            print("TEST_FAILED")
            
    except Exception as e:
        logger.error(f"Connection test failed: {e}")
        print("TEST_FAILED")

if __name__ == "__main__":
    test_connection()
