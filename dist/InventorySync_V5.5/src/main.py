import sys
import os
import json
import logging
from datetime import datetime

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("sync.log"),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

def load_config(config_path="config/credentials.json"):
    """Load configuration from JSON file."""
    if not os.path.exists(config_path):
        logger.error(f"Configuration file not found: {config_path}")
        logger.info("Please copy config/credentials_template.json to config/credentials.json and fill in your details.")
        return None
    
    with open(config_path, 'r', encoding='utf-8') as f:
        return json.load(f)

def main():
    logger.info("Starting Inventory Sync System...")
    
    # 1. Load Configuration
    config = load_config()
    if not config:
        sys.exit(1)
        
    try:
        # Ensure current directory (src) is in path for module imports
        # When running as EXE, we are in the src folder context or need to add it
        if getattr(sys, 'frozen', False):
            # If frozen, we are likely in a temp dir. 
            # The script is running from that dir.
            # We need to ensure imports like 'import database' work.
            sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
        else:
            # If running as script, add current dir
            sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

        # 2. Initialize Sync Manager
        # Use direct import since we added path
        from sync_manager import SyncManager
        manager = SyncManager(config)
        
        # 3. Perform Sync
        manager.run_sync()
        
    except Exception as e:
        logger.critical(f"Critical error in main execution: {e}")
        sys.exit(1)
    
    logger.info("Sync process completed.")

if __name__ == "__main__":
    main()
