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
logger = logging.getLogger("YahooInspect")

def load_config(config_path="config/credentials.json"):
    if not os.path.exists(config_path):
        print(f"Config not found at {config_path}")
        return None
    with open(config_path, 'r', encoding='utf-8') as f:
        return json.load(f)

def recursive_search(data, target_strings, target_timestamps, path=""):
    """Recursively search for target strings or timestamps in JSON data."""
    if isinstance(data, dict):
        for k, v in data.items():
            new_path = f"{path}.{k}" if path else k
            
            # Check key for suspicious names
            if any(x in k.lower() for x in ['time', 'date', 'end', 'start', 'expire']):
                print(f"[Potential Field] {new_path}: {v}")
                
            recursive_search(v, target_strings, target_timestamps, new_path)
            
    elif isinstance(data, list):
        for i, item in enumerate(data):
            recursive_search(item, target_strings, target_timestamps, f"{path}[{i}]")
            
    else:
        # Check value
        str_val = str(data)
        
        # Check strings
        for t in target_strings:
            if t in str_val:
                print(f"[MATCH FOUND] {path}: {data} (Matches '{t}')")
                
        # Check timestamps (if data is int or float)
        if isinstance(data, (int, float)):
            for ts in target_timestamps:
                if abs(data - ts) < 60: # Allow 1 minute difference
                    print(f"[TIMESTAMP MATCH] {path}: {data} (Matches timestamp {ts})")
                if abs(data - ts*1000) < 60000: # Allow 1 minute difference for ms
                    print(f"[TIMESTAMP MATCH (ms)] {path}: {data} (Matches timestamp {ts}000)")

def main():
    # User provided: 2024/5/28 下午 03:53:44 -> 2024-05-28 15:53:44
    target_strings = [
        "2024-05-28",
        "2024/05/28",
        "15:53",
        "03:53",
        "2024-05-28T15:53:44"
    ]
    
    # Unix timestamp for 2024-05-28 15:53:44 UTC+8
    dt = datetime(2024, 5, 28, 15, 53, 44)
    ts = dt.timestamp()
    target_timestamps = [ts]
    
    print(f"Searching for targets: {target_strings}")
    print(f"Searching for timestamp: {ts}")
    
    config = load_config()
    if not config:
        return

    client = YahooClient(config['yahoo'])
    
    if not client.login():
        print("Login failed")
        return

    headers = {
        'Cookie': client.cookie,
        'X-YahooWSSID-Authorization': client.wssid
    }
    
    # Search for the product first to get listing IDs
    uri = 'https://tw.supplier.yahoo.com/api/spa/v1/products'
    params = {
        'limit': 50,
        'offset': 25500, 
        'fields': '+listingIdList' 
    }
    
    target_sku = "2040076-61"
    found_item = None
    
    print("Scanning for product...")
    while True:
        print(f"Scanning offset {params['offset']}...")
        try:
            response = requests.get(uri, params=params, headers=headers, timeout=30)
            if response.status_code != 200:
                print(f"Error: {response.status_code} {response.text}")
                break
                
            data = response.json()
            products = data.get('products', [])
            
            if not products:
                break
                
            for p in products:
                if p.get('partNo') == target_sku:
                    print(f"Found Product! ID: {p.get('id')}")
                    found_item = p
                    break
            
            if found_item:
                break
                
            if len(products) < 50:
                break
                
            params['offset'] += 50
            if params['offset'] > 30000:
                break
                
        except Exception as e:
            print(f"Exception: {e}")
            break
            
    if not found_item:
        print("Product not found.")
        return
        
    # Inspect Product Data
    print("\n--- Inspecting Product Data ---")
    recursive_search(found_item, target_strings, target_timestamps, "Product")
    
    # Check Listings
    listing_ids = found_item.get('listingIdList', [])
    print(f"\nFound Listing IDs: {listing_ids}")
    
    for lid in listing_ids:
        print(f"\n--- Inspecting Listing ID: {lid} ---")
        # Try different listing endpoints
        endpoints = [
            f'https://tw.supplier.yahoo.com/api/spa/v1/listings/{lid}',
            f'https://tw.supplier.yahoo.com/api/spa/v1/y/listings/{lid}', # Guess
            f'https://tw.supplier.yahoo.com/api/spa/v1/mall/listings/{lid}' # Guess
        ]
        
        for l_uri in endpoints:
            try:
                print(f"Trying endpoint: {l_uri}")
                l_res = requests.get(l_uri, headers=headers, timeout=30)
                if l_res.status_code == 200:
                    print("Fetch Successful!")
                    l_data = l_res.json()
                    # print(json.dumps(l_data, ensure_ascii=False, indent=2))
                    recursive_search(l_data, target_strings, target_timestamps, f"Listing({lid})")
                    break
                else:
                    print(f"Failed: {l_res.status_code}")
            except Exception as e:
                print(f"Error: {e}")

if __name__ == "__main__":
    main()
