"""
Yahoo API 原始回應診斷
直接調用 Yahoo API 並顯示所有可用欄位
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
print("Yahoo API 原始回應診斷")
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
    'limit': 3,
    'offset': 0
}

headers = {
    'Cookie': cookie,
    'X-YahooWSSID-Authorization': wssid
}

print("\n正在抓取產品...")
response = requests.get(uri, params=params, headers=headers, timeout=30)

if response.status_code == 200:
    data = response.json()
    products = data.get('products', [])
    
    print(f"\n找到 {len(products)} 個產品")
    
    for i, product in enumerate(products, 1):
        print("\n" + "=" * 80)
        print(f"產品 {i}: {product.get('name', 'Unknown')}")
        print("=" * 80)
        
        # 顯示所有欄位（格式化 JSON）
        print("\n完整 JSON 資料:")
        print(json.dumps(product, indent=2, ensure_ascii=False))
        
        # 特別標註可能與下架時間相關的欄位
        print("\n" + "-" * 80)
        print("可能與下架/狀態相關的欄位:")
        print("-" * 80)
        
        time_status_keys = [
            'endTime', 'end_time', 'offlineTime', 'offline_time',
            'expireTime', 'expire_time', 'validUntil', 'valid_until',
            'status', 'state', 'isActive', 'is_active', 'active',
            'onlineTime', 'online_time', 'startTime', 'start_time',
            'saleStartTime', 'sale_start_time', 'saleEndTime', 'sale_end_time',
            'publishTime', 'publish_time', 'createTime', 'create_time',
            'updateTime', 'update_time', 'modifyTime', 'modify_time'
        ]
        
        found = False
        for key in time_status_keys:
            if key in product:
                print(f"  ✓ {key}: {product[key]}")
                found = True
        
        if not found:
            print("  未找到明顯的時間/狀態相關欄位")
        
        print()
else:
    print(f"API 請求失敗: {response.status_code}")
    print(response.text)

print("=" * 80)
print("診斷完成")
print("=" * 80)
