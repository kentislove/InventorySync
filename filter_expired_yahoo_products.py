"""
Yahoo 已過期產品過濾器
讀取 Yahoo 產品資料，檢查 Listing endTs，過濾掉已過期的產品
"""

import requests
import json
import base64
import time
import hashlib
import hmac
from datetime import datetime
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives import padding

# 載入設定
with open('config/credentials.json', 'r', encoding='utf-8') as f:
    config = json.load(f)['yahoo']

# AES 加密函式
def encrypt_aes(plain_text, share_secret_key, share_secret_iv):
    key = base64.b64decode(share_secret_key)
    iv = base64.b64decode(share_secret_iv)
    
    padder = padding.PKCS7(128).padder()
    padded_data = padder.update(plain_text.encode('utf-8')) + padder.finalize()
    
    cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
    encryptor = cipher.encryptor()
    encrypted_data = encryptor.update(padded_data) + encryptor.finalize()
    
    return base64.b64encode(encrypted_data).decode('utf-8')

# 生成簽章
def get_signature(message_body, config):
    timestamp = str(int(time.time()))
    seller_id = config.get('seller_id', 'Supplier_10454')
    token = seller_id
    supplier_id = seller_id.replace('Supplier_', '')
    salt_key = 'kzIFcX0aXdJuphj9ruQSBd4nVCz1WMvs'
    
    cipher_text = encrypt_aes(message_body, config['share_secret_key'], config['share_secret_iv'])
    
    data_to_sign = f"{timestamp}{token}{salt_key}{cipher_text}"
    signature = hmac.new(
        config['share_secret_key'].encode('utf-8'),
        data_to_sign.encode('utf-8'),
        hashlib.sha512
    ).hexdigest()
    
    return {
        'headers': {
            'Content-Type': 'application/json; charset=utf-8',
            'api-token': token,
            'api-signature': signature,
            'api-timestamp': timestamp,
            'api-keyversion': '1',
            'api-supplierid': supplier_id
        },
        'cipher_text': cipher_text
    }

# 登入
def yahoo_login(config):
    seller_id = config.get('seller_id', 'Supplier_10454')
    supplier_id = seller_id.replace('Supplier_', '')
    
    uri = "https://tw.supplier.yahoo.com/api/spa/v1/signIn"
    payload = json.dumps({"supplierId": supplier_id}, separators=(',', ':'))
    
    sig_data = get_signature(payload, config)
    response = requests.post(uri, data=sig_data['cipher_text'], headers=sig_data['headers'])
    
    if response.status_code != 204:
        return None, None
    
    sp_cookie = response.cookies.get('_sp')
    cookie = f"_sp={sp_cookie}"
    
    uri_token = 'https://tw.supplier.yahoo.com/api/spa/v1/token'
    headers = {'Cookie': cookie}
    res = requests.get(uri_token, headers=headers)
    
    if res.status_code != 200:
        return None, None
    
    data = res.json()
    wssid = data.get('wssid')
    
    return cookie, wssid

# 獲取 Listing 詳細資料
def fetch_listing_details(listing_id, cookie, wssid):
    uri = f'https://tw.supplier.yahoo.com/api/spa/v1/listings/{listing_id}'
    headers = {
        'Cookie': cookie,
        'X-YahooWSSID-Authorization': wssid
    }
    
    try:
        response = requests.get(uri, headers=headers, timeout=30)
        if response.status_code == 200:
            return response.json()
    except:
        pass
    return None

print("=" * 80)
print("Yahoo 已過期產品過濾器")
print("=" * 80)

# 登入
print("\n正在登入...")
cookie, wssid = yahoo_login(config)

if not cookie or not wssid:
    print("登入失敗")
    exit(1)

print("登入成功！")

# 抓取產品
uri = 'https://tw.supplier.yahoo.com/api/spa/v1/products'
params = {
    'limit': 50,
    'offset': 0,
    'fields': '+listingIdList'
}

headers = {
    'Cookie': cookie,
    'X-YahooWSSID-Authorization': wssid
}

all_products = []
expired_products = []
active_products = []

print("\n正在抓取產品並檢查過期狀態...")

current_time = datetime.utcnow()

try:
    while True:
        print(f"  正在抓取 offset {params['offset']}...")
        response = requests.get(uri, params=params, headers=headers, timeout=30)
        
        if response.status_code == 200:
            data = response.json()
            products = data.get('products', [])
            
            if not products:
                break
            
            for p in products:
                part_no = p.get('partNo')
                name = p.get('name', '')
                qty = p.get('stock', 0)
                listing_ids = p.get('listingIdList', [])
                
                is_expired = False
                end_ts = None
                
                # 檢查 Listing endTs
                if listing_ids:
                    listing_data = fetch_listing_details(listing_ids[0], cookie, wssid)
                    
                    if listing_data:
                        end_ts = listing_data.get('endTs')
                        
                        if end_ts:
                            try:
                                end_time = datetime.strptime(end_ts, "%Y-%m-%dT%H:%M:%SZ")
                                
                                if end_time < current_time:
                                    is_expired = True
                            except:
                                pass
                
                product_info = {
                    'part_no': part_no,
                    'name': name,
                    'quantity': qty,
                    'end_ts': end_ts,
                    'is_expired': is_expired
                }
                
                all_products.append(product_info)
                
                if is_expired:
                    expired_products.append(product_info)
                else:
                    active_products.append(product_info)
            
            if len(products) < 50:
                break
            
            params['offset'] += 50
            
            if params['offset'] > 30000:
                break
        else:
            print(f"API 請求失敗: {response.status_code}")
            break

except Exception as e:
    print(f"錯誤: {e}")

# 統計
print("\n" + "=" * 80)
print("統計結果")
print("=" * 80)
print(f"總產品數: {len(all_products)}")
print(f"已過期產品: {len(expired_products)}")
print(f"上架中產品: {len(active_products)}")

if expired_products:
    print(f"\n已過期產品列表（前 20 筆）:")
    for i, p in enumerate(expired_products[:20], 1):
        print(f"  {i}. {p['part_no']} - {p['name'][:50]}... (endTs: {p['end_ts']})")

# 輸出到檔案
output_file = "dist/yahoo_expired_products.json"
with open(output_file, 'w', encoding='utf-8') as f:
    json.dump({
        'total': len(all_products),
        'expired': len(expired_products),
        'active': len(active_products),
        'expired_products': expired_products,
        'active_products': active_products
    }, f, ensure_ascii=False, indent=2)

print(f"\n✓ 結果已儲存至: {output_file}")
print("\n" + "=" * 80)
