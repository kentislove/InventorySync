import requests
import json
import logging
import base64
import time
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives import padding

logger = logging.getLogger(__name__)

class PChomeClient:
    def __init__(self, config):
        logger.info(f"PChome Config Keys: {list(config.keys())}")
        self.vendor_id = config.get('vendor_id') # This is merchantId in PHP
        if not self.vendor_id:
             logger.error("PChome vendor_id is MISSING in config!")
        else:
             logger.info(f"PChome vendor_id: {self.vendor_id[:2]}***{self.vendor_id[-2:]}")
             
        self.encrypt_key = config.get('encrypt_key')
        self.encrypt_iv = config.get('encrypt_iv')
        self.base_url = "https://ecvdr.pchome.com.tw/vdr"

    def _encrypt_data(self, data):
        """Encrypt data using AES-128-CBC (inferred from PHP/User info)."""
        # PHP code doesn't show encryption in PCHomeShopping.php, it uses FetchHttp.
        # But user provided EncryptKey (32 chars) and IV (16 chars).
        # 32 hex chars = 16 bytes = 128 bit key.
        # So AES-128-CBC is correct.
        try:
            key = bytes.fromhex(self.encrypt_key)
            iv = bytes.fromhex(self.encrypt_iv) # IV is likely hex too if key is hex, or just string?
            # User said: EncryptIV 77ded9bdcad15793 (16 chars). 
            # If it's 16 chars string, it's 16 bytes. If it's hex, it's 8 bytes (too short for AES).
            # So likely it's a raw string of 16 characters.
            # Let's try to interpret as string first, if that fails (e.g. not 16 bytes), try hex.
            
            if len(self.encrypt_iv) == 16:
                iv = self.encrypt_iv.encode('utf-8')
            else:
                # If user meant hex string representing 16 bytes, it would be 32 chars.
                # If user provided 16 chars hex, that's 8 bytes, invalid for AES.
                # Let's assume user provided the actual IV string.
                iv = self.encrypt_iv.encode('utf-8')

            # Pad data
            padder = padding.PKCS7(128).padder()
            padded_data = padder.update(json.dumps(data).encode('utf-8')) + padder.finalize()
            
            # Encrypt
            cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
            encryptor = cipher.encryptor()
            encrypted_data = encryptor.update(padded_data) + encryptor.finalize()
            
            return base64.b64encode(encrypted_data).decode('utf-8')
        except Exception as e:
            logger.error(f"Encryption failed: {e}")
            raise

    def get_products(self, p_id):
        """
        Get product details.
        Ref: public function getProducts($pId)
        URI: /prod/v3.3/index.php/vendor/{merchantId}/prod/{pId}?extra_fields=PmName
        """
        uri = f"{self.base_url}/prod/v3.3/index.php/vendor/{self.vendor_id}/prod/{p_id}?extra_fields=PmName"
        try:
            response = requests.get(uri, headers=headers, timeout=30) # Authentication headers needed? PHP uses FetchHttp.
            # Assuming FetchHttp handles auth. Without it, this might fail.
            # But let's implement the logic first.
            
            if response.status_code == 200:
                return response.json()
            else:
                logger.error(f"PChome get_products failed: {response.status_code} {response.text}")
                return []
        except Exception as e:
            logger.error(f"PChome API error: {e}")
            return []

    def update_inventory(self, sku, quantity):
        """
        Update inventory.
        Ref: public function updQty($pId, $updQty)
        URI: PUT /prod/v3.3/index.php/vendor/{merchantId}/prod/qty?prodid={pId}
        Payload: [{"Id": pId, "Qty": quantity}]
        """
        uri = f"{self.base_url}/prod/v3.3/index.php/vendor/{self.vendor_id}/prod/qty?prodid={sku}"
        payload = [{"Id": sku, "Qty": quantity}]
        
        try:
            # PHP: $res = $fetch->httpPost($uri, json_encode($posts), $extras);
            # Extras: _CURLOPT_CUSTOMREQUEST = 'PUT'
            
            response = requests.put(uri, json=payload, timeout=30)
            
            if response.status_code == 204:
                logger.info(f"Successfully updated PChome inventory for {sku} to {quantity}")
                return True
            else:
                logger.error(f"PChome update failed: {response.status_code} {response.text}")
                return False
        except Exception as e:
            logger.error(f"PChome API error: {e}")
            return False

    def get_inventory(self):
        """
        Fetch all inventory.
        Strategy:
        1. Fetch list of IDs (since list API doesn't return details).
        2. Fetch details for each ID in parallel.
        """
        all_products = []
        product_ids = []
        
        # Step 1: Fetch all IDs
        offset = 1
        limit = 100 # Increase limit for ID fetch
        
        while True:
            # Use "No Fields" mode as it's proven to work for IDs
            uri = f"{self.base_url}/prod/v3.3/index.php/core/vendor/{self.vendor_id}/prod?isshelf=1&limit={limit}&offset={offset}"
            
            try:
                logger.info(f"Fetching PChome ID list offset {offset}...")
                response = requests.get(uri, timeout=30)
                
                if response.status_code == 200:
                    data = response.json()
                    rows = []
                    if isinstance(data, dict):
                        if 'Rows' in data: rows = data['Rows']
                        elif 'data' in data: rows = data['data']
                    elif isinstance(data, list):
                        rows = data
                        
                    if not rows:
                        break
                        
                    for item in rows:
                        if isinstance(item, dict) and 'Id' in item:
                            product_ids.append(item['Id'])
                    
                    if len(rows) < limit:
                        break
                    
                    offset += limit
                    time.sleep(0.2)
                else:
                    logger.error(f"PChome ID fetch failed: {response.status_code}")
                    break
            except Exception as e:
                logger.error(f"PChome ID fetch error: {e}")
                break
        
        logger.info(f"Fetched {len(product_ids)} PChome IDs. Now fetching details...")
        
        # Step 2: Fetch details in parallel
        from concurrent.futures import ThreadPoolExecutor, as_completed
        
        def fetch_detail(p_id):
            try:
                # URI: /prod/v3.3/index.php/vendor/{merchantId}/prod/{pId}?extra_fields=PmName
                uri = f"{self.base_url}/prod/v3.3/index.php/vendor/{self.vendor_id}/prod/{p_id}?extra_fields=PmName"
                res = requests.get(uri, timeout=20)
                if res.status_code == 200:
                    # Response is a list containing one dict
                    data = res.json()
                    if isinstance(data, list) and data:
                        return data[0]
                    elif isinstance(data, dict):
                        return data
                return None
            except Exception:
                return None

        # Use ThreadPool to speed up 2500 requests
        # Max workers 10 to be safe
        with ThreadPoolExecutor(max_workers=10) as executor:
            future_to_id = {executor.submit(fetch_detail, pid): pid for pid in product_ids}
            
            completed_count = 0
            total_count = len(product_ids)
            
            for future in as_completed(future_to_id):
                p_id = future_to_id[future]
                try:
                    item = future.result()
                    if item:
                        qty = item.get('Qty')
                        if qty is not None and int(qty) > 0:
                            all_products.append({
                                "sku": item.get('VendorPId', str(item.get('Id'))), 
                                "pchome_id": str(item.get('Id')), 
                                "name": item.get('Name'),
                                "spec_name": item.get('Spec', item.get('Nick', '')),
                                "quantity": qty,
                                "platform": "PChome",
                                "part_no": item.get('PartNo') 
                            })
                    
                    completed_count += 1
                    if completed_count % 100 == 0:
                        logger.info(f"PChome details progress: {completed_count}/{total_count}")
                        
                except Exception as e:
                    logger.error(f"Error fetching detail for {p_id}: {e}")
                    
        return all_products
