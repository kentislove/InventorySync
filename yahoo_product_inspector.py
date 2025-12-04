"""
Yahoo Product Inspector
獨立腳本：抓取特定供應商料號的產品資料並輸出成 Excel
"""

import requests
import json
import base64
import time
import hashlib
import hmac
import pandas as pd
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend
from cryptography.hazmat.primitives import padding

# ===== 設定區 =====
TARGET_PART_NO = "2410458-01"  # 目標供應商料號
OUTPUT_FILE = "yahoo_product_2410458-01.xlsx"  # 輸出檔案名稱

# Yahoo API 認證資訊（從 config/credentials.json 讀取）
def load_config():
    with open('config/credentials.json', 'r', encoding='utf-8') as f:
        config = json.load(f)
    return config['yahoo']

# ===== Yahoo API 加密/解密函式 =====
def encrypt_aes(plain_text, share_secret_key, share_secret_iv):
    """AES-256-CBC 加密"""
    key = base64.b64decode(share_secret_key)
    iv = base64.b64decode(share_secret_iv)
    
    padder = padding.PKCS7(128).padder()
    padded_data = padder.update(plain_text.encode('utf-8')) + padder.finalize()
    
    cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
    encryptor = cipher.encryptor()
    encrypted_data = encryptor.update(padded_data) + encryptor.finalize()
    
    return base64.b64encode(encrypted_data).decode('utf-8')

def decrypt_aes(encrypted_text, share_secret_key, share_secret_iv):
    """AES-256-CBC 解密"""
    key = base64.b64decode(share_secret_key)
    iv = base64.b64decode(share_secret_iv)
    
    encrypted_data = base64.b64decode(encrypted_text)
    
    cipher = Cipher(algorithms.AES(key), modes.CBC(iv), backend=default_backend())
    decryptor = cipher.decryptor()
    padded_data = decryptor.update(encrypted_data) + decryptor.finalize()
    
    unpadder = padding.PKCS7(128).unpadder()
    data = unpadder.update(padded_data) + unpadder.finalize()
    
    return data.decode('utf-8')

def get_signature(message_body, config):
    """生成 API 簽章"""
    timestamp = str(int(time.time()))
    
    # 從 seller_id 提取 token 和 supplier_id
    seller_id = config.get('seller_id', 'Supplier_10454')
    token = seller_id
    supplier_id = seller_id.replace('Supplier_', '')
    salt_key = 'kzIFcX0aXdJuphj9ruQSBd4nVCz1WMvs'  # 固定值
    
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

# ===== Yahoo API 登入 =====
def yahoo_login(config):
    """登入 Yahoo 並取得 Cookie 和 WSSID"""
    print("正在登入 Yahoo...")
    
    # 從 seller_id 提取 supplier_id
    seller_id = config.get('seller_id', 'Supplier_10454')
    supplier_id = seller_id.replace('Supplier_', '')
    
    # 1. 取得 Cookie
    uri = "https://tw.supplier.yahoo.com/api/spa/v1/signIn"
    payload = json.dumps({"supplierId": supplier_id}, separators=(',', ':'))
    
    sig_data = get_signature(payload, config)
    
    response = requests.post(uri, data=sig_data['cipher_text'], headers=sig_data['headers'])
    
    if response.status_code != 204:
        print(f"登入失敗: {response.status_code}")
        return None, None
    
    sp_cookie = response.cookies.get('_sp')
    if not sp_cookie:
        print("無法取得 Cookie")
        return None, None
    
    cookie = f"_sp={sp_cookie}"
    
    # 2. 取得 WSSID
    uri_token = 'https://tw.supplier.yahoo.com/api/spa/v1/token'
    headers = {'Cookie': cookie}
    
    res = requests.get(uri_token, headers=headers)
    if res.status_code != 200:
        print(f"取得 Token 失敗: {res.status_code}")
        return None, None
    
    data = res.json()
    wssid = data.get('wssid')
    
    print("登入成功！")
    return cookie, wssid

# ===== 抓取產品資料 =====
def fetch_product_by_part_no(cookie, wssid, target_part_no):
    """抓取特定供應商料號的產品資料"""
    print(f"\n正在搜尋供應商料號: {target_part_no}...")
    
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
    
    found_product = None
    total_checked = 0
    
    while True:
        print(f"檢查中... (已檢查 {total_checked} 個產品)")
        response = requests.get(uri, params=params, headers=headers, timeout=30)
        
        if response.status_code != 200:
            print(f"API 請求失敗: {response.status_code}")
            break
        
        data = response.json()
        products = data.get('products', [])
        
        if not products:
            print("已檢查完所有產品")
            break
        
        # 搜尋目標料號
        for p in products:
            total_checked += 1
            if p.get('partNo') == target_part_no:
                found_product = p
                print(f"\n✓ 找到了！產品 ID: {p.get('id')}")
                break
        
        if found_product:
            break
        
        if len(products) < 50:
            break
        
        params['offset'] += 50
        
        if params['offset'] > 30000:
            print("已達到 API 限制 (30000)")
            break
    
    if not found_product:
        print(f"\n✗ 找不到供應商料號: {target_part_no}")
    
    return found_product

# ===== 主程式 =====
if __name__ == "__main__":
    print("=" * 60)
    print("Yahoo 產品資料檢查工具")
    print("=" * 60)
    
    # 載入設定
    config = load_config()
    
    # 登入
    cookie, wssid = yahoo_login(config)
    if not cookie or not wssid:
        print("登入失敗，程式結束")
        exit(1)
    
    # 抓取產品
    product = fetch_product_by_part_no(cookie, wssid, TARGET_PART_NO)
    
    if product:
        print("\n" + "=" * 60)
        print("產品資料")
        print("=" * 60)
        
        # 將產品資料轉換成 DataFrame
        # 先將巢狀結構展平
        flat_data = {}
        
        for key, value in product.items():
            if isinstance(value, (list, dict)):
                # 將複雜結構轉成 JSON 字串
                flat_data[key] = json.dumps(value, ensure_ascii=False)
            else:
                flat_data[key] = value
        
        # 建立 DataFrame（單列）
        df = pd.DataFrame([flat_data])
        
        # 轉置以便查看（欄位名稱變成列）
        df_transposed = df.T
        df_transposed.columns = ['值']
        df_transposed.index.name = '欄位名稱'
        
        # 輸出到 Excel
        with pd.ExcelWriter(OUTPUT_FILE, engine='openpyxl') as writer:
            # Sheet 1: 原始格式（橫向）
            df.to_excel(writer, sheet_name='原始資料', index=False)
            
            # Sheet 2: 轉置格式（縱向，方便查看）
            df_transposed.to_excel(writer, sheet_name='欄位清單')
        
        print(f"\n✓ 資料已儲存至: {OUTPUT_FILE}")
        print(f"  - Sheet 1: 原始資料（橫向）")
        print(f"  - Sheet 2: 欄位清單（縱向，方便查看）")
        
        # 在終端機顯示部分重要欄位
        print("\n重要欄位預覽:")
        print("-" * 60)
        important_fields = ['id', 'name', 'partNo', 'stock', 'price', 'models', 'specifications']
        for field in important_fields:
            if field in product:
                value = product[field]
                if isinstance(value, (list, dict)):
                    value = json.dumps(value, ensure_ascii=False)[:100] + "..."
                print(f"{field:20s}: {value}")
        
        print("\n" + "=" * 60)
        print("完成！請開啟 Excel 檔案查看完整資料")
        print("=" * 60)
    else:
        print("\n程式結束")
