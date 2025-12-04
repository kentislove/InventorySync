"""
Stock Comparison Module
獨立的庫存比對邏輯模組
避免修改 sync_manager.py 造成的問題
"""

import logging

logger = logging.getLogger(__name__)


class StockComparator:
    """庫存比對器 - 處理三平台庫存比對邏輯"""
    
    def __init__(self, db_manager):
        """
        初始化比對器
        
        Args:
            db_manager: 資料庫管理器實例
        """
        self.db = db_manager
    
    def compare_inventory(self):
        """
        執行庫存比對
        
        Returns:
            list: 比對結果矩陣
        """
        logger.info("開始執行庫存比對...")
        
        # 從資料庫獲取所有庫存
        all_products = self.db.get_all_inventory()
        
        # 按 Part No + Spec 分組
        product_map = self._group_by_sku_and_spec(all_products)
        
        # 生成比對矩陣
        comparison_matrix = self._generate_comparison_matrix(product_map)
        
        logger.info(f"庫存比對完成，共 {len(comparison_matrix)} 筆記錄")
        
        return comparison_matrix
    
    def _group_by_sku_and_spec(self, all_products):
        """
        按 SKU + Spec 分組
        
        Args:
            all_products: 所有產品列表
            
        Returns:
            dict: 分組後的產品字典
        """
        product_map = {}
        
        for row in all_products:
            # row: [sku, platform, quantity, last_synced, extra_data]
            sku = row[0]
            extra_data = row[4]
            
            # 從 extra_data 獲取 spec_name（已在儲存時統一格式）
            spec_name = extra_data.get('spec_name', '')
            
            # 創建複合鍵：Part No + Spec
            composite_key = f"{sku}_{spec_name}"
            
            if composite_key not in product_map:
                product_map[composite_key] = []
            product_map[composite_key].append(row)
        
        return product_map
    
    def _generate_comparison_matrix(self, product_map):
        """
        生成比對矩陣
        
        Args:
            product_map: 分組後的產品字典
            
        Returns:
            list: 比對矩陣
        """
        comparison_matrix = []
        
        for composite_key, records in product_map.items():
            # 計算最小庫存
            quantities = [r[2] for r in records if r[2] is not None]
            if not quantities:
                continue
            
            min_qty = min(quantities)
            
            # 從第一筆記錄提取 SKU 和 Spec
            # 注意：這裡只使用 spec_name，不使用 goodsdt_info
            sku = records[0][0]
            spec_name = records[0][4].get('spec_name', 'Unknown')
            
            # 準備比對記錄
            comp_record = {
                "sku": sku,
                "name": records[0][4].get('name', 'Unknown') if records else 'Unknown',
                "spec_name": spec_name,
                "yahoo_qty": 0,
                "pchome_qty": 0,
                "momo_qty": 0,
                "target_qty": min_qty,
                "yahoo_delta": 0,
                "pchome_delta": 0,
                "momo_delta": 0,
                "status": "Synced"
            }
            
            # 填充各平台數據
            for row in records:
                platform = row[1]
                current_qty = row[2]
                extra_data = row[4]
                
                if platform == 'Yahoo':
                    comp_record['yahoo_qty'] = current_qty
                    comp_record['yahoo_delta'] = min_qty - current_qty
                    comp_record['yahoo_id'] = extra_data.get('yahoo_id')
                elif platform == 'PChome':
                    comp_record['pchome_qty'] = current_qty
                    comp_record['pchome_delta'] = min_qty - current_qty
                    comp_record['pchome_id'] = extra_data.get('pchome_id')
                elif platform == 'MOMO':
                    comp_record['momo_qty'] = current_qty
                    comp_record['momo_delta'] = min_qty - current_qty
                    comp_record['momo_code'] = extra_data.get('momo_code')
                    comp_record['momo_extra'] = extra_data # MOMO needs full extra_data for update
            
            comparison_matrix.append(comp_record)
        
        return comparison_matrix
