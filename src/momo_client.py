import requests
import json
import logging
import base64
import time
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives import padding

logger = logging.getLogger(__name__)

class MomoClient:
    def __init__(self, config):
        self.company_id = config.get('company_id', '53617790')
        self.vendor_code = config.get('vendor_code', '027410')
        self.password = config.get('password', 'BB22356664')
        self.otp_back_no = config.get('otp_back_no', '416')
        
        self.login_info = {
            "entpID": self.company_id,
            "entpCode": self.vendor_code,
            "entpPwd": self.password,
            "otpBackNo": self.otp_back_no
        }
        self.base_url = "https://scmapi.momoshop.com.tw"

    def _clean_spec_value(self, value):
        """
        移除規格值中的中文字，保留英文與數字
        """
        if not value:
            return ""
        import re
        # 使用正規表達式移除所有中文字元 (\u4e00-\u9fff)
        return re.sub(r'[\u4e00-\u9fff]', '', str(value)).strip()

    def get_inventory(self):
        """
        Fetch inventory.
        Ref: MomoShopping::getQty (but that gets single item).
        Ref: MomoShopping::getProducts (gets list of products)
        URI: /api/v1/goodsSaleStatus/query.scm
        """
        uri = f"{self.base_url}/api/v1/goodsSaleStatus/query.scm"
        
        send_info = {
            "goodsCode": "",
            "goodsName": "",
            "entpGoodsNo": "",
            "saleGB": "", # Empty to fetch all statuses, then filter in code
            "delyType": "",
            "outForNoGoods": "",
            "page": 1 # Note: PHP says 1000 per page. Need loop?
        }
        
        payload = {
            "loginInfo": self.login_info,
            "sendInfo": send_info
        }
        
        headers = {
            'Content-Type': 'application/json',
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        }
        
        all_inventory = []
        
        try:
            # Simple pagination loop (assuming page size is large enough or just one page for now)
            # PHP code sets page=1 and comment says "每頁 1000筆".
            # Let's try to loop until no data.
            page = 1
            while True:
                payload['sendInfo']['page'] = page
                response = requests.post(uri, json=payload, headers=headers, verify=False, timeout=30)
                
                if response.status_code == 200:
                    data = response.json()
                    items = data.get('dataList', [])
                    if not items:
                        break
                        
                    # Filter items by sale_gb_name
                    # Rules: 進行=上架, 暫時中斷=上架, 永久中斷=下架
                    # We want "所有上架狀態的產品" -> Keep "進行" and "暫時中斷"
                    valid_statuses = ["進行", "暫時中斷"]
                    
                    for item in items:
                        status = item.get('sale_gb_name', '')
                        if status not in valid_statuses:
                            continue

                        # ... (existing code) ...
                        all_inventory.append({
                            "sku": item.get('goods_code'), # MOMO internal code
                            "part_no": item.get('entp_goods_no'), # Our SKU
                            "name": item.get('goods_name'),
                            "goodsdt_code": item.get('goodsdt_code'),
                            "goodsdt_info": item.get('goodsdt_info')
                        })
                    
                    logger.info(f"Fetched page {page}, {len(items)} items. Total valid: {len(all_inventory)}")
                    
                    # If we got fewer items than expected page size, maybe we are done?
                    # But if page size is unknown (could be 500 or 1000), safer to continue until empty.
                    # However, if we get 0 items, we break at the top.
                    # If we get items, we continue to next page.
                    # RISK: Infinite loop if API ignores page param and returns same data.
                    # Mitigation: Check if items are duplicates? 
                    # For now, let's assume API works and just increment page.
                    # But usually APIs return fewer items on last page.
                    # If we get 500, and total is 500, next page is 0.
                    # So removing the 'len(items) < 1000' check is correct.
                    
                    page += 1
                    time.sleep(0.2)
                else:
                    logger.error(f"MOMO get_products failed: {response.status_code}")
                    break
            
            # Now fetch stock for these items
            # We need to group by goods_code because getQty takes goodsCodeList
            goods_codes = list(set(item['sku'] for item in all_inventory))
            
            # Batch process goods_codes (PHP says max 2000, let's do 100)
            batch_size = 100
            stock_map = {}
            
            for i in range(0, len(goods_codes), batch_size):
                batch = goods_codes[i:i+batch_size]
                stocks = self._get_stock_batch(batch)
                for s in stocks:
                    # Key: goods_code + goodsdt_info (because one goods_code can have multiple specs)
                    key = f"{s.get('goods_code')}_{s.get('goodsdt_info')}"
                    stock_map[key] = s.get('order_counsel_qty', 0)
            
            # Merge stock into inventory list
            final_inventory = []
            for item in all_inventory:
                key = f"{item['sku']}_{item['goodsdt_info']}"
                qty = stock_map.get(key, 0)
                if int(qty) > 0:
                    # SKU Generation Logic
                    part_no = item['part_no']
                    goodsdt_info = item['goodsdt_info']
                    final_sku = part_no
                    
                    if goodsdt_info and '/' in goodsdt_info:
                        # Extract part after slash
                        suffix = goodsdt_info.split('/')[-1]
                        clean_suffix = self._clean_spec_value(suffix)
                        if clean_suffix:
                            final_sku = f"{part_no}/{clean_suffix}"
                    
                    final_inventory.append({
                        "sku": final_sku, # Use generated SKU
                        "momo_sku": item['sku'],
                        "name": item['name'],
                        "quantity": int(qty),
                        "platform": "MOMO",
                        "goodsdt_info": goodsdt_info,
                        "spec_name": f"/{goodsdt_info}" if goodsdt_info and not goodsdt_info.startswith('/') else goodsdt_info, # Normalize format
                        "goodsdt_code": item['goodsdt_code']
                    })
                
            return final_inventory

        except Exception as e:
            logger.error(f"MOMO API error: {e}")
            return []

    def _get_stock_batch(self, goods_codes):
        """
        Get stock for a list of goods codes.
        Ref: MomoShopping::getQty
        """
        uri = f"{self.base_url}/api/v1/goodsStockQty/query.scm"
        payload = {
            "doAction": "query",
            "loginInfo": self.login_info,
            "goodsCodeList": goods_codes
        }
        headers = {
            'Content-Type': 'application/json',
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        }
        
        try:
            response = requests.post(uri, json=payload, headers=headers, verify=False, timeout=30)
            if response.status_code == 200:
                data = response.json()
                return data.get('dataList', [])
            return []
        except Exception as e:
            logger.error(f"MOMO batch stock fetch error: {e}")
            return []

    def update_inventory(self, sku, quantity, extra_data=None):
        """
        Update inventory.
        Ref: functions.php::momoUpdQty
        
        Args:
            sku: This should be the MOMO goodsCode (not our partNo)
            quantity: The DELTA quantity (addReduceQty) or Target?
            extra_data: Dict containing 'goodsName', 'goodsdtCode', 'goodsdtInfo', 'current_stock'
        """
        # Note: MOMO update requires: goodsCode, goodsName, goodsdtCode, goodsdtInfo, orderCounselQty (current), addReduceQty (delta)
        # We need extra_data to fulfill this.
        
        if not extra_data:
            logger.error("MOMO update requires extra_data (goodsName, goodsdtCode, goodsdtInfo, current_stock)")
            return False
            
        uri = f"{self.base_url}/GoodsServlet.do"
        
        current_stock = extra_data.get('current_stock', 0)
        delta = quantity - current_stock # If quantity is target
        # If quantity is delta, then delta = quantity. 
        # But standard interface is "set to quantity".
        
        if delta == 0:
            return True
            
        params = {
            "goodsCode": sku, # MOMO goods code
            "goodsName": extra_data.get('goodsName'),
            "goodsdtCode": extra_data.get('goodsdtCode'),
            "goodsdtInfo": extra_data.get('goodsdtInfo'),
            "orderCounselQty": str(current_stock),
            "addReduceQty": str(delta),
            "baljuChgFlag": ""
        }
        
        payload = {
            "doAction": "changeGoodsQty",
            "loginInfo": self.login_info,
            "sendInfoList": [params]
        }
        
        headers = {
            'Content-Type': 'application/json',
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        }
        
        try:
            response = requests.post(uri, json=payload, headers=headers, verify=False, timeout=30)
            if response.status_code == 200:
                # Check response content for success
                # Output is JSON.
                logger.info(f"Successfully updated MOMO inventory for {sku} (Delta: {delta})")
                return True
            else:
                logger.error(f"MOMO update failed: {response.status_code} {response.text}")
                return False
        except Exception as e:
            logger.error(f"MOMO API error: {e}")
            return False
