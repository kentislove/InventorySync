"""
Inventory Sync System V5 - Main Entry Point (Direct Run)
直接從源碼執行，無需打包
"""

import logging
import sys
import os
from datetime import datetime

# 添加 src 到路徑
sys.path.insert(0, os.path.join(os.path.dirname(__file__), 'src'))

# 設定日誌
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('sync_v5.log', encoding='utf-8'),
        logging.StreamHandler(sys.stdout)
    ]
)

logger = logging.getLogger(__name__)

def main():
    """主程式入口"""
    logger.info("=" * 80)
    logger.info("Inventory Sync System V5 Starting...")
    logger.info("=" * 80)
    
    try:
        # 載入設定
        import json
        with open('config/credentials.json', 'r', encoding='utf-8') as f:
            config = json.load(f)
        
        # 建立同步管理器
        from src.sync_manager_v5 import SyncManager
        sync_manager = SyncManager(config)
        
        # 執行同步（自動判斷模式）
        sync_manager.run_sync(mode='auto')
        
        logger.info("=" * 80)
        logger.info("Sync completed successfully!")
        logger.info("=" * 80)
        
    except Exception as e:
        logger.error(f"Sync failed: {e}", exc_info=True)
        input("Press Enter to exit...")
        sys.exit(1)

if __name__ == '__main__':
    main()
    input("Press Enter to exit...")
