"""
Test Yahoo Models Extraction
測試 Yahoo 產品的 models 資料是否被正確提取
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

# 抓取產品
def fetch_products(cookie, wssid, limit=10):
    uri = 'https://tw.supplier.yahoo.com/api/spa/v1/products'
    params = {
        'limit': limit,
        'offset': 0,
        'fields': '+listingIdList'
    }
    
    headers = {
        'Cookie': cookie,
        'X-YahooWSSID-Authorization': wssid
    }
    
    response = requests.get(uri, params=params, headers=headers, timeout=30)
    
    if response.status_code == 200:
        data = response.json()
        return data.get('products', [])
    return []

# 抓取 Listing 詳細資料
def fetch_listing_details(cookie, wssid, listing_id):
    uri = f'https://tw.supplier.yahoo.com/api/spa/v1/listings/{listing_id}'
    
    headers = {
        'Cookie': cookie,
        'X-YahooWSSID-Authorization': wssid
    }
    
    try:
        response = requests.get(uri, headers=headers, timeout=30)
        
        if response.status_code == 200:
            return response.json()
        else:
            return None
    except Exception as e:
        return None

print("=" * 80)
print("Yahoo Models 資料測試")
print("=" * 80)

# 登入
print("\n正在登入...")
cookie, wssid = yahoo_login(config)

if not cookie or not wssid:
    print("登入失敗")
    exit(1)

print("登入成功！")

# 抓取前 10 個產品
print("\n正在抓取前 10 個產品...")
products = fetch_products(cookie, wssid, limit=10)

print(f"找到 {len(products)} 個產品\n")

# 檢查每個產品
products_with_models = 0
products_without_models = 0

for i, p in enumerate(products, 1):
    print("=" * 80)
    print(f"產品 {i}/{len(products)}")
    print("=" * 80)
    print(f"ID: {p.get('id')}")
    print(f"Name: {p.get('name')}")
    print(f"Part No: {p.get('partNo')}")
    print(f"Stock: {p.get('stock')}")
    
    listing_ids = p.get('listingIdList', [])
    print(f"Listing IDs: {listing_ids}")
    
    if listing_ids:
        for listing_id in listing_ids:
            print(f"\n  正在抓取 Listing {listing_id} 的詳細資料...")
            listing_data = fetch_listing_details(cookie, wssid, listing_id)
            
            if listing_data:
                models = listing_data.get('models', [])
                print(f"  ✓ Models 數量: {len(models)}")
                
                if models:
                    products_with_models += 1
                    print(f"  ✓ 有 Models 資料！")
                    for j, model in enumerate(models[:5], 1):  # 只顯示前 5 個
                        print(f"    Model {j}: {model.get('name')} (Stock: {model.get('stock')})")
                else:
                    products_without_models += 1
                    print(f"  ✗ 沒有 Models 資料")
            else:
                products_without_models += 1
                print(f"  ✗ 無法抓取 Listing 資料")
    else:
        products_without_models += 1
        print("  ✗ 沒有 Listing ID")
    
    print()

print("=" * 80)
print("統計結果")
print("=" * 80)
print(f"有 Models 的產品: {products_with_models}/{len(products)} ({products_with_models/len(products)*100:.1f}%)")
print(f"沒有 Models 的產品: {products_without_models}/{len(products)} ({products_without_models/len(products)*100:.1f}%)")
print("=" * 80)
