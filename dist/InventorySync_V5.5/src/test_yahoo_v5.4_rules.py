import json
import logging
import os
import sys
import requests
from datetime import datetime

# Add src to path to allow imports if run directly
sys.path.append(os.path.join(os.path.dirname(__file__), '..'))

from src.yahoo_client import YahooClient

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("YahooV5.4Test")

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
    
    # Test SKU Formatting Logic
    print("\n--- Testing SKU Formatting ---")
    test_cases = [
        ("2040076-61", "尺寸", "L號", "2040076-61L"),
        ("2040076-61", "尺寸", "85CM", "2040076-6185CM"),
        ("2040076-61", "顏色", "黑色", "2040076-61"), # Not "尺寸", should not append
        ("12345", "尺寸", "XL", "12345XL"),
        ("12345", "尺寸", "XL特大", "12345XL"), # Remove Chinese
    ]
    
    for part_no, spec_name, spec_value, expected in test_cases:
        clean_val = client._clean_spec_value(spec_value)
        final_sku = part_no
        if "尺寸" in spec_name and spec_value:
            if clean_val:
                final_sku = f"{part_no}{clean_val}"
        
        result = "PASS" if final_sku == expected else f"FAIL (Got {final_sku})"
        print(f"[{result}] {part_no} + {spec_name}:{spec_value} -> Expected: {expected}")

    # Test Live Fetch for specific SKU
    print("\n--- Testing Live Logic on SKU: 2040076-61 ---")
    if not client.login():
        print("Login failed")
        return

    # Search for the target SKU
    uri = 'https://tw.supplier.yahoo.com/api/spa/v1/products'
    params = {
        'limit': 50,
        'offset': 25500, # Start where we know it is
        'fields': '+listingIdList'
    }
    headers = {'Cookie': client.cookie, 'X-YahooWSSID-Authorization': client.wssid}
    
    target_sku = "2040076-61"
    found = False
    
    while True:
        print(f"Scanning offset {params['offset']}...")
        try:
            res = requests.get(uri, params=params, headers=headers, timeout=30)
            if res.status_code != 200:
                print(f"Error: {res.status_code}")
                break
                
            data = res.json()
            products = data.get('products', [])
            if not products:
                break
                
            for p in products:
                if p.get('partNo') == target_sku:
                    found = True
                    print(f"\n[FOUND] Product ID: {p.get('id')}, Name: {p.get('name')}")
                    
                    # 1. Check Listing / EndTs
                    lids = p.get('listingIdList', [])
                    if lids:
                        lid = lids[0]
                        print(f"  Listing ID: {lid}")
                        end_ts_str = client._get_listing_end_ts(lid)
                        print(f"  EndTs: {end_ts_str}")
                        
                        # Check Expiration
                        is_expired = False
                        if end_ts_str:
                            ts_str = end_ts_str.replace('Z', '+00:00')
                            end_ts = datetime.fromisoformat(ts_str).replace(tzinfo=None)
                            current_time = datetime.utcnow()
                            print(f"  Current Time (UTC): {current_time}")
                            
                            if end_ts < current_time:
                                is_expired = True
                                print("  [RESULT] Status: EXPIRED (Will be filtered)")
                            else:
                                print("  [RESULT] Status: ACTIVE (Will be kept)")
                        else:
                            print("  [RESULT] Status: UNKNOWN (No EndTs)")
                    else:
                        print("  [WARNING] No Listing ID found")

                    # 2. Check SKU Formatting
                    spec_data = p.get('spec', {})
                    spec_name = spec_data.get('name', '')
                    spec_value = spec_data.get('selectedValue', '')
                    
                    if not spec_name:
                        # Try extract
                        extracted = client._extract_spec_from_name(p.get('name', ''))
                        if extracted:
                            spec_value = extracted.replace('/', '')
                            # Assume extracted is spec
                            # But we need spec_name to be "尺寸" for the rule to trigger?
                            # The rule says: "資料中有包含尺寸字串的 都要加以判斷 spec_value"
                            # If we extracted it, we don't know the name. 
                            # But usually extracted ones are what we want. 
                            # Let's see what the API returns for this item first.
                            pass
                            
                    print(f"  Spec Name: {spec_name}")
                    print(f"  Spec Value: {spec_value}")
                    
                    final_sku = target_sku
                    if "尺寸" in spec_name and spec_value:
                        clean_val = client._clean_spec_value(spec_value)
                        final_sku = f"{target_sku}{clean_val}"
                        print(f"  [RESULT] Formatted SKU: {final_sku} (Original: {target_sku} + {spec_value})")
                    else:
                        print(f"  [RESULT] SKU Unchanged: {final_sku}")
                        
            if found:
                # Keep scanning to find all variants
                pass
            
            if len(products) < 50:
                break
            
            params['offset'] += 50
            if params['offset'] > 26000: # Limit search
                break
                
        except Exception as e:
            print(f"Error: {e}")
            break
            
    if not found:
        print("Target SKU not found in search range.")

if __name__ == "__main__":
    main()
