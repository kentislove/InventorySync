"""
測試 Yahoo API 不同參數以找出上架狀態欄位
"""

import requests
import json
import base64
import time
import hashlib
import hmac
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

print("=" * 80)
print("測試 Yahoo API 不同參數")
print("=" * 80)

# 登入
print("\n正在登入...")
cookie, wssid = yahoo_login(config)

if not cookie or not wssid:
    print("登入失敗")
    exit(1)

print("登入成功！")

headers = {
    'Cookie': cookie,
    'X-YahooWSSID-Authorization': wssid
}

# 測試不同的 fields 參數
test_cases = [
    {
        'name': '基本欄位',
        'params': {
            'limit': 3,
            'offset': 0
        }
    },
    {
        'name': '加入 listingIdList',
        'params': {
            'limit': 3,
            'offset': 0,
            'fields': '+listingIdList'
        }
    },
    {
        'name': '加入所有可能的欄位',
        'params': {
            'limit': 3,
            'offset': 0,
            'fields': '+listingIdList,+status,+state,+isActive,+onlineStatus,+publishStatus'
        }
    }
]

uri = 'https://tw.supplier.yahoo.com/api/spa/v1/products'

for test in test_cases:
    print("\n" + "=" * 80)
    print(f"測試: {test['name']}")
    print("=" * 80)
    print(f"參數: {test['params']}")
    
    response = requests.get(uri, params=test['params'], headers=headers, timeout=30)
    
    if response.status_code == 200:
        data = response.json()
        products = data.get('products', [])
        
        if products:
            product = products[0]
            print(f"\n產品: {product.get('name', 'Unknown')}")
            print(f"所有欄位: {list(product.keys())}")
            
            # 檢查新增的欄位
            status_fields = ['status', 'state', 'isActive', 'onlineStatus', 'publishStatus', 'listingIdList']
            print("\n狀態相關欄位:")
            for field in status_fields:
                if field in product:
                    print(f"  ✓ {field}: {product[field]}")
    else:
        print(f"API 請求失敗: {response.status_code}")
        print(response.text)

# 如果有 listingIdList，嘗試獲取 listing 詳細資料
print("\n" + "=" * 80)
print("測試 Listing API")
print("=" * 80)

response = requests.get(uri, params={'limit': 1, 'offset': 0, 'fields': '+listingIdList'}, headers=headers, timeout=30)

if response.status_code == 200:
    data = response.json()
    products = data.get('products', [])
    
    if products and products[0].get('listingIdList'):
        listing_id = products[0]['listingIdList'][0]
        print(f"\n正在抓取 Listing {listing_id} 的詳細資料...")
        
        listing_uri = f'https://tw.supplier.yahoo.com/api/spa/v1/listings/{listing_id}'
        listing_response = requests.get(listing_uri, headers=headers, timeout=30)
        
        if listing_response.status_code == 200:
            listing_data = listing_response.json()
            print(f"\nListing 所有欄位: {list(listing_data.keys())}")
            
            # 檢查狀態相關欄位
            status_fields = ['status', 'state', 'isActive', 'onlineStatus', 'publishStatus', 'isOnline', 'isPublished']
            print("\nListing 狀態相關欄位:")
            for field in status_fields:
                if field in listing_data:
                    print(f"  ✓ {field}: {listing_data[field]}")
            
            # 顯示完整 JSON（縮排）
            print("\n完整 Listing JSON:")
            print(json.dumps(listing_data, indent=2, ensure_ascii=False)[:2000] + "...")

print("\n" + "=" * 80)
print("測試完成")
print("=" * 80)
