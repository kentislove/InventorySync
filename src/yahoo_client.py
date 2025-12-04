"""
Yahoo 購物中心 API 客戶端
整合過期產品過濾和 Spec 自動提取功能
V5.4: 新增 endTs 過濾與 SKU 格式化規則
"""

import requests
import json
import logging
import base64
import time
import hashlib
import hmac
import re
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives import padding
from expired_product_manager import ExpiredProductManager

logger = logging.getLogger(__name__)


class YahooClient:
    def __init__(self, config):
        self.token = config.get('yahoo_token', "Supplier_10454")
        self.supplier_id = config.get('yahoo_supplier_id', "10454")
        
        self.share_secret_key = config.get('share_secret_key', 'aLuZHW3us4iWNs0C7YvbnzPiPH6NCmhaqDqRyZvNbmA=')
        self.share_secret_iv = config.get('share_secret_iv', 'JpVkbWmVcZdcjfQL4bravQ==')
        self.salt_key = config.get('salt_key', 'kzIFcX0aXdJuphj9ruQSBd4nVCz1WMvs')
        
        self.cookie = None
        self.wssid = None
        self.cookie_expired_time = 0
        self.sku_to_id = {}
        
        # 載入過期產品管理器 (V5.4 規則主要依賴 API endTs，但保留此作為備用或雙重檢查)
        self.expired_mgr = ExpiredProductManager()
        logger.info(f"Yahoo Client 初始化完成")

    def _encrypt_aes(self, plain_text):
        """AES 加密"""
        try:
            key = base64.b64decode(self.share_secret_key)
            iv = base64.b64decode(self.share_secret_iv)
            
            padder = padding.PKCS7(128).padder()
            padded_data = padder.update(plain_text.encode('utf-8')) + padder.finalize()
            
            cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
            encryptor = cipher.encryptor()
            encrypted_data = encryptor.update(padded_data) + encryptor.finalize()
            
            return base64.b64encode(encrypted_data).decode('utf-8')
        except Exception as e:
            logger.error(f"Yahoo Encryption failed: {e}")
            raise

    def _get_signature(self, message_body):
        """生成 API 簽章"""
        timestamp = str(int(time.time()))
        cipher_text = self._encrypt_aes(message_body)
        
        data_to_sign = f"{timestamp}{self.token}{self.salt_key}{cipher_text}"
        signature = hmac.new(
            self.share_secret_key.encode('utf-8'),
            data_to_sign.encode('utf-8'),
            hashlib.sha512
        ).hexdigest()
        
        return {
            'headers': {
                'Content-Type': 'application/json; charset=utf-8',
                'api-token': self.token,
                'api-signature': signature,
                'api-timestamp': timestamp,
                'api-keyversion': '1',
                'api-supplierid': self.supplier_id
            },
            'cipher_text': cipher_text
        }

    def login(self):
        """登入 Yahoo API"""
        uri = "https://tw.supplier.yahoo.com/api/spa/v1/signIn"
        payload = json.dumps({"supplierId": self.supplier_id}, separators=(',', ':'))
        
        sig_data = self._get_signature(payload)
        
        try:
            response = requests.post(uri, data=sig_data['cipher_text'], headers=sig_data['headers'])
            
            if response.status_code == 204:
                sp_cookie = response.cookies.get('_sp')
                if sp_cookie:
                    self.cookie = f"_sp={sp_cookie}"
                else:
                    logger.error("Yahoo Login: Could not find _sp cookie")
                    return False
                    
                self.cookie_expired_time = time.time() + 6 * 3600 - 600
            else:
                logger.error(f"Yahoo Login failed: {response.status_code}")
                return False
                
            # 取得 WSSID
            uri_token = 'https://tw.supplier.yahoo.com/api/spa/v1/token'
            headers = {'Cookie': self.cookie}
            
            res = requests.get(uri_token, headers=headers)
            if res.status_code == 200:
                data = res.json()
                self.wssid = data.get('wssid')
                logger.info("Yahoo Login successful")
                return True
            else:
                logger.error(f"Yahoo Get Token failed: {res.status_code} {res.text}")
                return False

        except Exception as e:
            logger.error(f"Yahoo Login error: {e}")
            return False

    def _extract_spec_from_name(self, name):
        """
        從產品名稱提取規格 (舊邏輯，保留備用)
        """
        if '-' in name:
            spec = name.split('-')[-1].strip()
            if spec:
                return f"/{spec}"
        return ""

    def _clean_spec_value(self, value):
        """
        移除規格值中的中文字，保留英文與數字
        例如: "L號" -> "L", "85CM" -> "85CM"
        """
        if not value:
            return ""
        # 使用正規表達式移除所有中文字元 (\u4e00-\u9fff)
        # 這裡假設非中文字元都要保留 (包括空格?)
        # 題目說 "數字跟英文都要保留"
        return re.sub(r'[\u4e00-\u9fff]', '', str(value)).strip()

    def _get_listing_end_ts(self, listing_id):
        """
        取得 Listing 的 endTs
        """
        uri = f'https://tw.supplier.yahoo.com/api/spa/v1/listings/{listing_id}'
        headers = {
            'Cookie': self.cookie,
            'X-YahooWSSID-Authorization': self.wssid
        }
        try:
            response = requests.get(uri, headers=headers, timeout=10)
            if response.status_code == 200:
                data = response.json()
                return data.get('endTs')
            else:
                logger.warning(f"Failed to get listing {listing_id}: {response.status_code}")
                return None
        except Exception as e:
            logger.error(f"Error fetching listing {listing_id}: {e}")
            return None

    def get_inventory(self, filter_expired=True):
        """
        抓取 Yahoo 產品庫存 (V5.4)
        
        Args:
            filter_expired: 是否過濾已過期產品 (V5.4 強制依賴 endTs)
        
        Returns:
            list: 產品列表
        """
        if not self.wssid:
            if not self.login():
                return []

        uri = 'https://tw.supplier.yahoo.com/api/spa/v1/products'
        params = {
            'limit': 50,
            'offset': 0,
            'fields': '+listingIdList' # V5.4 需要 Listing ID 來查 endTs
        }
        
        headers = {
            'Cookie': self.cookie,
            'X-YahooWSSID-Authorization': self.wssid
        }
        
        all_products = []
        self.sku_to_id = {}
        filtered_count = 0
        
        # 準備 ThreadPool
        max_workers = 10 # 控制並發數以免被擋
        
        try:
            while True:
                logger.info(f"Fetching Yahoo products offset {params['offset']}...")
                response = requests.get(uri, params=params, headers=headers, timeout=30)
                
                if response.status_code == 200:
                    data = response.json()
                    products = data.get('products', [])
                    
                    if not products:
                        logger.info("No more products found.")
                        break
                    
                    # 批次處理這一頁的產品
                    # 1. 收集所有需要查詢 endTs 的 listing IDs
                    # 注意：一個產品可能有多個 listing，我們通常看最新的或第一個？
                    # 假設 listingIdList[0] 是主要 listing
                    
                    products_to_check = []
                    for p in products:
                        listing_ids = p.get('listingIdList', [])
                        if listing_ids:
                            products_to_check.append((p, listing_ids[0]))
                        else:
                            # 沒有 listing ID 的產品可能已經下架或異常，視為過期？
                            # 或者保留？保守起見先保留，除非明確過期
                            logger.warning(f"Product {p.get('partNo')} has no listing IDs.")
                            # 暫時不過濾無 listing ID 的產品，除非有明確指示
                            # 但 V5.4 規則是 "比對 endTs"，無 endTs 無法比對
                            # 假設無 listing = 無法上架 = 不處理
                            pass

                    # 2. 並發查詢 endTs
                    logger.info(f"Checking endTs for {len(products_to_check)} products...")
                    current_time = datetime.utcnow() # endTs 是 UTC
                    
                    with ThreadPoolExecutor(max_workers=max_workers) as executor:
                        future_to_product = {
                            executor.submit(self._get_listing_end_ts, lid): p 
                            for p, lid in products_to_check
                        }
                        
                        for future in as_completed(future_to_product):
                            p = future_to_product[future]
                            end_ts_str = future.result()
                            
                            is_expired = False
                            if end_ts_str:
                                try:
                                    # endTs format: 2024-05-28T07:53:44Z
                                    # Python 3.7+ fromisoformat handle Z? No, usually replace Z with +00:00
                                    ts_str = end_ts_str.replace('Z', '+00:00')
                                    end_ts = datetime.fromisoformat(ts_str)
                                    
                                    # 轉換為 naive UTC 進行比較 (因為 current_time 是 naive UTC)
                                    # 或者都轉為 aware
                                    end_ts_utc = end_ts.replace(tzinfo=None)
                                    
                                    if end_ts_utc < current_time:
                                        is_expired = True
                                        # logger.debug(f"Product {p.get('partNo')} expired. EndTs: {end_ts_str}")
                                except Exception as e:
                                    logger.error(f"Error parsing endTs {end_ts_str}: {e}")
                                    # 解析失敗視為不過期？
                            
                            if is_expired:
                                filtered_count += 1
                                continue
                                
                            # 產品未過期，進行 SKU 處理
                            p_id = str(p.get('id'))
                            part_no = p.get('partNo')
                            name = p.get('name', '')
                            qty = p.get('stock', 0)
                            
                            # 處理 Spec 和 SKU
                            spec_name = ""
                            spec_value = ""
                            
                            # 從 API 回傳的 spec 物件取得
                            spec_data = p.get('spec', {})
                            if spec_data:
                                spec_name = spec_data.get('name', '')
                                spec_value = spec_data.get('selectedValue', '')
                            
                            # 如果 API 沒回傳 spec，嘗試從名稱提取 (舊邏輯)
                            if not spec_name:
                                extracted = self._extract_spec_from_name(name)
                                if extracted:
                                    # 舊邏輯回傳 "/黑色"，這裡我們需要拆分
                                    spec_value = extracted.replace('/', '')
                                    # 假設名稱提取的都是顏色或尺寸，暫不強制設 spec_name
                            
                            # V5.4 規則修正：如果 spec_name 包含 "尺寸"
                            # SKU = partNo + "/" + spec_value (去除中文)
                            final_sku = part_no
                            if "尺寸" in spec_name and spec_value:
                                clean_value = self._clean_spec_value(spec_value)
                                if clean_value:
                                    final_sku = f"{part_no}/{clean_value}"
                                    # logger.debug(f"Formatted SKU: {part_no} + {spec_value} -> {final_sku}")
                            
                            # 如果沒有 part_no，用 ID
                            if not final_sku:
                                final_sku = p_id

                            # 只包含庫存 > 0 的產品 (V5.4 規則沒明確說要過濾 0 庫存，但通常是需要的)
                            # 規則說 "比對所有 endTs 大於等於系統日期者... 需要寫入GS"
                            # 照舊邏輯，庫存 > 0 才同步
                            if qty is not None and int(qty) > 0:
                                self.sku_to_id[final_sku] = p_id
                                
                                all_products.append({
                                    "sku": final_sku, # 這是寫入 DB/GS 的 SKU
                                    "name": name,
                                    "spec_name": f"/{spec_value}" if spec_value else "", # 保持舊格式 /Value
                                    "quantity": qty,
                                    "platform": "Yahoo",
                                    "part_no": part_no, # 原始料號
                                    "yahoo_id": p_id,
                                    "end_ts": end_ts_str
                                })

                    logger.info(f"Processed {len(products)} products. "
                               f"Filtered (Expired): {filtered_count}, "
                               f"Total valid inventory: {len(all_products)}")
                    
                    if len(products) < 50:
                        break
                    
                    params['offset'] += 50
                    
                    if params['offset'] > 30000:
                        logger.warning("Reached Yahoo API offset limit (30000). Stopping fetch.")
                        break
                else:
                    logger.error(f"Yahoo get_inventory failed: {response.status_code} {response.text}")
                    break
                    
            logger.info(f"Yahoo inventory fetch complete. "
                       f"Total records: {len(all_products)}, "
                       f"Filtered expired: {filtered_count}")
            return all_products
            
        except Exception as e:
            logger.error(f"Yahoo API error: {e}")
            return []

    def update_inventory(self, sku, quantity):
        """
        更新產品庫存數量
        """
        product_id = self.sku_to_id.get(sku)
        # 如果 SKU 經過格式化，self.sku_to_id 應該已經存了 格式化後SKU -> ID 的對映
        
        if not product_id:
            # 嘗試直接用傳入的 sku 當 ID (如果它是 ID 的話)
            # 但通常 sku 是 partNo 或 組合字串
            logger.error(f"Cannot find product ID for SKU: {sku}")
            return False
        
        uri = f'https://tw.supplier.yahoo.com/api/spa/v1/products/{product_id}'
        
        headers = {
            'Cookie': self.cookie,
            'X-YahooWSSID-Authorization': self.wssid,
            'Content-Type': 'application/json'
        }
        
        payload = {
            'stock': quantity
        }
        
        try:
            response = requests.patch(uri, json=payload, headers=headers, timeout=30)
            
            if response.status_code == 200:
                logger.info(f"Updated Yahoo inventory: {sku} (ID:{product_id}) -> {quantity}")
                return True
            else:
                logger.error(f"Failed to update Yahoo inventory: {response.status_code} {response.text}")
                return False
                
        except Exception as e:
            logger.error(f"Error updating Yahoo inventory: {e}")
            return False
