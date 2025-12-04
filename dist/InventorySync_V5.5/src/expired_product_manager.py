"""
過期產品管理器
管理 Yahoo 已過期產品列表，提供過濾和更新功能
"""

import json
import logging
from datetime import datetime
from pathlib import Path

logger = logging.getLogger(__name__)


class ExpiredProductManager:
    """管理 Yahoo 過期產品列表"""
    
    def __init__(self, json_path='yahoo_expired_products.json'):
        """
        初始化過期產品管理器
        
        Args:
            json_path: 過期產品 JSON 檔案路徑
        """
        self.json_path = Path(json_path)
        self.expired_products = set()
        self.active_products = set()
        self.last_updated = None
        
        self._load_data()
    
    def _load_data(self):
        """載入過期產品資料"""
        if not self.json_path.exists():
            logger.warning(f"過期產品檔案不存在: {self.json_path}")
            logger.warning("將不會過濾任何產品。請執行過期產品篩選。")
            return
        
        try:
            with open(self.json_path, 'r', encoding='utf-8') as f:
                data = json.load(f)
            
            # 載入已過期產品的 Part No
            for product in data.get('expired_products', []):
                part_no = product.get('part_no')
                if part_no:
                    self.expired_products.add(part_no)
            
            # 載入上架中產品的 Part No
            for product in data.get('active_products', []):
                part_no = product.get('part_no')
                if part_no:
                    self.active_products.add(part_no)
            
            logger.info(f"已載入過期產品管理器:")
            logger.info(f"  - 已過期產品: {len(self.expired_products)} 個")
            logger.info(f"  - 上架中產品: {len(self.active_products)} 個")
            
        except Exception as e:
            logger.error(f"載入過期產品資料失敗: {e}")
    
    def is_expired(self, part_no):
        """
        檢查產品是否已過期
        
        Args:
            part_no: 供應商料號
            
        Returns:
            bool: True 表示已過期，False 表示上架中
        """
        if not part_no:
            return False
        
        return part_no in self.expired_products
    
    def is_active(self, part_no):
        """
        檢查產品是否上架中
        
        Args:
            part_no: 供應商料號
            
        Returns:
            bool: True 表示上架中，False 表示已過期或不存在
        """
        if not part_no:
            return False
        
        return part_no in self.active_products
    
    def get_active_products(self):
        """
        取得所有上架中的產品 Part No
        
        Returns:
            set: 上架中產品的 Part No 集合
        """
        return self.active_products.copy()
    
    def get_expired_products(self):
        """
        取得所有已過期的產品 Part No
        
        Returns:
            set: 已過期產品的 Part No 集合
        """
        return self.expired_products.copy()
    
    def get_statistics(self):
        """
        取得統計資訊
        
        Returns:
            dict: 統計資訊
        """
        return {
            'total': len(self.expired_products) + len(self.active_products),
            'expired': len(self.expired_products),
            'active': len(self.active_products),
            'expired_percentage': round(len(self.expired_products) / (len(self.expired_products) + len(self.active_products)) * 100, 2) if (len(self.expired_products) + len(self.active_products)) > 0 else 0
        }
    
    def update_expired_list(self, yahoo_client):
        """
        重新執行過期產品篩選（每周執行）
        
        Args:
            yahoo_client: YahooClient 實例
            
        Returns:
            dict: 更新結果統計
        """
        logger.info("開始重新篩選過期產品...")
        logger.warning("此操作需要約 5-6 小時，請耐心等待...")
        
        # 這裡應該調用 filter_expired_yahoo_products.py 的邏輯
        # 或者直接在這裡實作相同的邏輯
        
        # 暫時返回提示訊息
        return {
            'status': 'pending',
            'message': '請執行 filter_expired_yahoo_products.py 來更新過期產品列表'
        }
    
    def reload(self):
        """重新載入過期產品資料"""
        self.expired_products.clear()
        self.active_products.clear()
        self._load_data()
        logger.info("已重新載入過期產品資料")


# 測試程式碼
if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO)
    
    # 測試載入
    manager = ExpiredProductManager()
    
    # 顯示統計
    stats = manager.get_statistics()
    print("\n統計資訊:")
    print(f"  總產品數: {stats['total']}")
    print(f"  已過期: {stats['expired']} ({stats['expired_percentage']}%)")
    print(f"  上架中: {stats['active']}")
    
    # 測試檢查功能
    test_part_nos = ['2520290-C9', '2530011-05', '2440105-07']
    print("\n測試產品狀態:")
    for part_no in test_part_nos:
        is_exp = manager.is_expired(part_no)
        is_act = manager.is_active(part_no)
        print(f"  {part_no}: 已過期={is_exp}, 上架中={is_act}")
