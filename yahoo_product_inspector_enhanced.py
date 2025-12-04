"""
Yahoo Product Inspector - Enhanced Version
獨立腳本：抓取特定供應商料號的產品資料並輸出成 Excel
增強版：包含 Listing 詳細資料和 Models 資訊
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
OUTPUT_FILE = "yahoo_product_2410458-01_detailed.xlsx"  # 輸出檔案名稱

# Yahoo API 認證資訊（從 config/credentials.json 讀取）
def load_config():
    with open('config/credentials.json', 'r', encoding='utf-8')  as f:
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

# ===== 抓取 Listing 詳細資料 =====
def fetch_listing_details(cookie, wssid, listing_id):
    """抓取 Listing 的詳細資料（包含 models）"""
    print(f"\n正在抓取 Listing 詳細資料: {listing_id}...")
    
    uri = f'https://tw.supplier.yahoo.com/api/spa/v1/listings/{listing_id}'
    
    headers = {
        'Cookie': cookie,
        'X-YahooWSSID-Authorization': wssid
    }
    
    try:
        response = requests.get(uri, headers=headers, timeout=30)
        
        if response.status_code == 200:
            data = response.json()
            print(f"✓ 成功抓取 Listing 資料")
            return data
        else:
            print(f"✗ 抓取 Listing 失敗: {response.status_code}")
            return None
    except Exception as e:
        print(f"✗ 錯誤: {e}")
        return None

# ===== 主程式 =====
if __name__ == "__main__":
    print("=" * 60)
    print("Yahoo 產品資料檢查工具 - 增強版")
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
        print("產品基本資料")
        print("=" * 60)
        
        # 顯示基本資訊
        print(f"產品 ID: {product.get('id')}")
        print(f"產品名稱: {product.get('name')}")
        print(f"供應商料號: {product.get('partNo')}")
        print(f"庫存: {product.get('stock')}")
        
        # 準備輸出資料
        all_data = {}
        
        # 1. 產品基本資料
        flat_product = {}
        for key, value in product.items():
            if isinstance(value, (list, dict)):
                flat_product[f"product_{key}"] = json.dumps(value, ensure_ascii=False)
            else:
                flat_product[f"product_{key}"] = value
        all_data.update(flat_product)
        
        # 2. 抓取 Listing 詳細資料
        listing_ids = product.get('listingIdList', [])
        if listing_ids:
            print(f"\n找到 {len(listing_ids)} 個 Listing ID: {listing_ids}")
            
            for idx, listing_id in enumerate(listing_ids):
                listing_data = fetch_listing_details(cookie, wssid, listing_id)
                
                if listing_data:
                    # 將 listing 資料加入
                    for key, value in listing_data.items():
                        if isinstance(value, (list, dict)):
                            all_data[f"listing_{idx+1}_{key}"] = json.dumps(value, ensure_ascii=False)
                        else:
                            all_data[f"listing_{idx+1}_{key}"] = value
                    
                    # 特別處理 models（尺寸資訊）
                    models = listing_data.get('models', [])
                    if models:
                        print(f"\n✓ 找到 {len(models)} 個尺寸變體:")
                        for model in models:
                            print(f"  - ID: {model.get('id')}, 名稱: {model.get('name')}, 庫存: {model.get('stock')}")
        else:
            print("\n⚠ 此產品沒有 Listing ID")
        
        # 建立 DataFrame
        df = pd.DataFrame([all_data])
        
        # 轉置以便查看
        df_transposed = df.T
        df_transposed.columns = ['值']
        df_transposed.index.name = '欄位名稱'
        
        # 輸出到 Excel
        with pd.ExcelWriter(OUTPUT_FILE, engine='openpyxl') as writer:
            # Sheet 1: 原始格式（橫向）
            df.to_excel(writer, sheet_name='原始資料', index=False)
            
            # Sheet 2: 轉置格式（縱向，方便查看）
            df_transposed.to_excel(writer, sheet_name='欄位清單')
            
            # Sheet 3: Models 詳細資料（如果有）
            if listing_ids and listing_data and listing_data.get('models'):
                models_df = pd.DataFrame(listing_data['models'])
                models_df.to_excel(writer, sheet_name='尺寸變體(Models)', index=False)
        
        print(f"\n✓ 資料已儲存至: {OUTPUT_FILE}")
        print(f"  - Sheet 1: 原始資料（橫向）")
        print(f"  - Sheet 2: 欄位清單（縱向，方便查看）")
        if listing_ids and listing_data and listing_data.get('models'):
            print(f"  - Sheet 3: 尺寸變體(Models)（獨立工作表）")
        
        print("\n" + "=" * 60)
        print("完成！請開啟 Excel 檔案查看完整資料")
        print("=" * 60)
    else:
        print("\n程式結束")
