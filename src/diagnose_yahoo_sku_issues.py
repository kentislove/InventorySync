import json
import logging
import os
import sys
import requests
from datetime import datetime

# Add src to path
sys.path.append(os.path.join(os.path.dirname(__file__), '..'))

from src.yahoo_client import YahooClient

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("YahooDiagnose")

def load_config(config_path="config/credentials.json"):
    if not os.path.exists(config_path):
        print(f"Config not found at {config_path}")
        return None
    with open(config_path, 'r', encoding='utf-8') as f:
        return json.load(f)

def main():
    config = load_config()
    if not config:
        return

    client = YahooClient(config['yahoo'])
    if not client.login():
        print("Login failed")
        return

    target_skus = [
        "2030154-C5",
        "1930056-E9",
        "2010029-01",
        "1920144-87",
        "1820160-20",
        "1810228-20",
        "1540840-29",
        "1440157-20"
    ]

    print(f"Diagnosing {len(target_skus)} SKUs...")
    
    # We need to search for these SKUs. 
    # Since we don't know the offset, we might need to search or use a specific API if available.
    # But get_inventory uses offset. 
    # Let's try to search by keyword if possible? 
    # The API `GET /api/spa/v1/products` supports `keyword` param? 
    # Let's try fetching with keyword=partNo.
    
    # The API might not support 'keyword' in this endpoint or requires different params.
    # Let's switch to scanning using the same logic as get_inventory to be safe.
    
    uri = 'https://tw.supplier.yahoo.com/api/spa/v1/products'
    params = {
        'limit': 50,
        'offset': 0,
        # 'fields': '+spec' # Removed to avoid 400 error, spec should be in default fields
    }
    headers = {'Cookie': client.cookie, 'X-YahooWSSID-Authorization': client.wssid}
    
    found_count = 0
    max_offset = 5000 # Limit search depth for diagnosis
    
    while found_count < len(target_skus) and params['offset'] < max_offset:
        print(f"Scanning offset {params['offset']}...")
        try:
            res = requests.get(uri, params=params, headers=headers, timeout=30)
            if res.status_code == 200:
                data = res.json()
                products = data.get('products', [])
                if not products:
                    break
                    
                for p in products:
                    part_no = p.get('partNo')
                    if part_no in target_skus:
                        print(f"\n[FOUND] SKU: {part_no}")
                        print(f"Name: {p.get('name')}")
                        
                        # Inspect Spec
                        spec_data = p.get('spec', {})
                        print(f"Raw Spec Data: {json.dumps(spec_data, ensure_ascii=False)}")
                        
                        spec_name = spec_data.get('name', '')
                        spec_value = spec_data.get('selectedValue', '')
                        
                        print(f"Spec Name: '{spec_name}'")
                        print(f"Spec Value: '{spec_value}'")
                        
                        if "尺寸" in spec_name:
                            print("Condition '尺寸' in spec_name: TRUE")
                        else:
                            print("Condition '尺寸' in spec_name: FALSE")
                            
                        clean_val = client._clean_spec_value(spec_value)
                        print(f"Cleaned Value: '{clean_val}'")
                        
                        final_sku = part_no
                        if "尺寸" in spec_name and spec_value:
                            if clean_val:
                                final_sku = f"{part_no}/{clean_val}"
                        
                        print(f"Calculated SKU: {final_sku}")
                        found_count += 1
                        
                params['offset'] += 50
            else:
                print(f"Error: {res.status_code}")
                break
        except Exception as e:
            print(f"Error: {e}")
            break

if __name__ == "__main__":
    main()
