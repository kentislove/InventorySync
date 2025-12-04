import json
import logging
import os
import sys
import pandas as pd
from datetime import datetime

# Add src to path
sys.path.append(os.path.join(os.path.dirname(__file__), '..'))

try:
    from src.momo_client import MomoClient
except ImportError:
    try:
        from momo_client import MomoClient
    except ImportError:
        # Fallback for frozen exe where sys.path might need help
        sys.path.append(os.path.dirname(os.path.abspath(__file__)))
        from momo_client import MomoClient

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("MomoFieldsTest")

def load_config(config_path="config/credentials.json"):
    if not os.path.exists(config_path):
        print(f"Config not found at {config_path}")
        return None
    with open(config_path, 'r', encoding='utf-8') as f:
        return json.load(f)

def flatten_dict(d, parent_key='', sep='_'):
    items = []
    for k, v in d.items():
        new_key = f"{parent_key}{sep}{k}" if parent_key else k
        if isinstance(v, dict):
            items.extend(flatten_dict(v, new_key, sep=sep).items())
        elif isinstance(v, list):
            # For lists, convert to string representation to keep it in one cell
            items.append((new_key, str(v)))
        else:
            items.append((new_key, v))
    return dict(items)

def main():
    config = load_config()
    if not config:
        return

    client = MomoClient(config['momo'])
    
    print("Fetching Momo inventory...")
    # We will use the internal logic of get_inventory but capture raw data if possible.
    # Since get_inventory processes data, we might want to replicate the raw fetch here
    # to get ALL fields, not just the processed ones.
    
    # Replicating fetch logic from MomoClient.get_inventory
    uri = f"{client.base_url}/api/v1/goodsSaleStatus/query.scm"
    send_info = {
        "goodsCode": "",
        "goodsName": "",
        "entpGoodsNo": "",
        "saleGB": "", # Empty to fetch all statuses, then filter in code
        "delyType": "",
        "outForNoGoods": "",
        "page": 1
    }
    payload = {
        "loginInfo": client.login_info,
        "sendInfo": send_info
    }
    headers = {
        'Content-Type': 'application/json',
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    }
    
    all_raw_items = []
    page = 1
    
    try:
        while True:
            print(f"Fetching page {page}...")
            payload['sendInfo']['page'] = page
            
            # Debug: Print payload (masking password)
            debug_payload = payload.copy()
            # debug_payload['loginInfo']['entpPwd'] = '******'
            # print(f"Payload: {json.dumps(debug_payload, ensure_ascii=False)}")
            
            response = requests.post(uri, json=payload, headers=headers, verify=False, timeout=30)
            
            if response.status_code == 200:
                data = response.json()
                items = data.get('dataList', [])
                if not items:
                    print("No more items.")
                    break
                
                # Filter items by sale_gb_name
                # Rules: 進行=上架, 暫時中斷=上架, 永久中斷=下架
                # We want "所有上架狀態的產品" -> Keep "進行" and "暫時中斷"
                valid_statuses = ["進行", "暫時中斷"]
                
                filtered_items = []
                for item in items:
                    status = item.get('sale_gb_name', '')
                    # Note: Status might be "進行中" or just "進行"? 
                    # Based on user input: "進行", "暫時中斷", "永久中斷"
                    # Let's check if the status string *contains* these keywords to be safe, or exact match?
                    # The Excel showed "sale_gb_name" column.
                    # Let's assume exact match or partial. 
                    # User said: "進行=上架", "暫時中斷=上架".
                    # I will check if status is in the list.
                    if status in valid_statuses:
                        filtered_items.append(item)
                    else:
                        # Debug print for discarded items
                        # print(f"Discarding item {item.get('goods_code')} with status: {status}")
                        pass
                        
                all_raw_items.extend(filtered_items)
                print(f"Got {len(items)} items. Kept {len(filtered_items)}. Total: {len(all_raw_items)}")
                
                page += 1
                if page > 50: # Safety break
                    print("Reached page limit (50). Stopping.")
                    break
            else:
                print(f"Error: {response.status_code}")
                print(f"Response: {response.text}")
                break
                
    except Exception as e:
        print(f"Error fetching data: {e}")

    if not all_raw_items:
        print("No data found.")
        return

    print(f"Total raw items: {len(all_raw_items)}")
    
    # Fetch Stock
    print("Fetching stock quantities...")
    goods_codes = list(set(item['goods_code'] for item in all_raw_items if 'goods_code' in item))
    
    batch_size = 100
    stock_map = {}
    
    stock_uri = f"{client.base_url}/api/v1/goodsStockQty/query.scm"
    
    for i in range(0, len(goods_codes), batch_size):
        batch = goods_codes[i:i+batch_size]
        print(f"Fetching stock batch {i//batch_size + 1}/{len(goods_codes)//batch_size + 1}...")
        
        stock_payload = {
            "doAction": "query",
            "loginInfo": client.login_info,
            "goodsCodeList": batch
        }
        
        try:
            res = requests.post(stock_uri, json=stock_payload, headers=headers, verify=False, timeout=30)
            if res.status_code == 200:
                s_data = res.json()
                s_items = s_data.get('dataList', [])
                for s in s_items:
                    # Key: goods_code + goodsdt_info
                    # Note: goodsdt_info in stock API might match goodsdt_info in list API
                    key = f"{s.get('goods_code')}_{s.get('goodsdt_info')}"
                    stock_map[key] = s.get('order_counsel_qty', 0)
            else:
                print(f"Stock fetch failed: {res.status_code}")
        except Exception as e:
            print(f"Stock fetch error: {e}")
            
    # Merge stock into items
    print("Merging stock data...")
    for item in all_raw_items:
        key = f"{item.get('goods_code')}_{item.get('goodsdt_info')}"
        # Also try matching by goodsdt_code if info doesn't match? 
        # For now use the same key logic as MomoClient
        qty = stock_map.get(key, 0)
        item['stock_quantity'] = qty

    # Flatten data
    print("Flattening data...")
    flattened_data = [flatten_dict(item) for item in all_raw_items]
    
    # Create DataFrame
    df = pd.DataFrame(flattened_data)
    
    # Save to Excel
    output_file = "momo_api_fields.xlsx"
    print(f"Saving to {output_file}...")
    df.to_excel(output_file, index=False)
    print("Done!")

import requests # Import here to ensure it's available

if __name__ == "__main__":
    main()
