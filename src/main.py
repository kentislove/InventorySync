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
        # Robust path setup for frozen environment
        if getattr(sys, 'frozen', False):
            base_dir = os.path.dirname(os.path.abspath(__file__))
            sys.path.insert(0, base_dir) # Add src/ to path
            sys.path.insert(0, os.path.dirname(base_dir)) # Add parent to path
        else:
            sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

        # Try importing SyncManager
        try:
            from src.sync_manager import SyncManager
        except ImportError:
            try:
                from sync_manager import SyncManager
            except ImportError as e:
                logger.critical(f"Failed to import SyncManager: {e}")
                logger.info(f"sys.path: {sys.path}")
                raise

        manager = SyncManager(config)
        
        # 3. Perform Sync
        manager.run_sync()
        
    except Exception as e:
        logger.critical(f"Critical error in main execution: {e}")
        sys.exit(1)
    
    logger.info("Sync process completed.")

if __name__ == "__main__":
    main()
