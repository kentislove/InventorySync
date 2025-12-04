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
logger = logging.getLogger("YahooFieldTest")

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

    headers = {
        'Cookie': client.cookie,
        'X-YahooWSSID-Authorization': client.wssid
    }
    
    # Try to fetch products with expanded fields
    uri = 'https://tw.supplier.yahoo.com/api/spa/v1/products'
    
    # Test 1: Try +listings
    print("Test 1: fields='+listings'")
    params = {
        'limit': 5,
        'offset': 0,
        'fields': '+listings' 
    }
    
    try:
        response = requests.get(uri, params=params, headers=headers, timeout=30)
        if response.status_code == 200:
            data = response.json()
            products = data.get('products', [])
            if products:
                print(f"Got {len(products)} products.")
                p = products[0]
                print(f"Keys: {list(p.keys())}")
                if 'listings' in p:
                    print("Found 'listings' field!")
                    print(json.dumps(p['listings'], ensure_ascii=False, indent=2))
                else:
                    print("'listings' field NOT found.")
            else:
                print("No products found.")
        else:
            print(f"Error: {response.status_code} {response.text}")
    except Exception as e:
        print(f"Test 1 failed: {e}")

    # Test 2: Try +listingIdList (we know this works, but does it give data?)
    print("\nTest 2: fields='+listingIdList'")
    params = {
        'limit': 5,
        'offset': 0,
        'fields': '+listingIdList' 
    }
    
    try:
        response = requests.get(uri, params=params, headers=headers, timeout=30)
        if response.status_code == 200:
            data = response.json()
            products = data.get('products', [])
            if products:
                p = products[0]
                print(f"Keys: {list(p.keys())}")
                if 'listingIdList' in p:
                    print(f"Found 'listingIdList': {p['listingIdList']}")
                else:
                    print("'listingIdList' field NOT found.")
        else:
            print(f"Error: {response.status_code} {response.text}")
    except Exception as e:
        print(f"Test 2 failed: {e}")

if __name__ == "__main__":
    main()
