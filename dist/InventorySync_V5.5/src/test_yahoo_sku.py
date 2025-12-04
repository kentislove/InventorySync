import json
import logging
import os
import sys
import pandas as pd
import requests
from datetime import datetime

# Add src to path to allow imports if run directly
sys.path.append(os.path.join(os.path.dirname(__file__), '..'))

from src.yahoo_client import YahooClient

# Setup logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("YahooTest")

def load_config(config_path="config/credentials.json"):
    if not os.path.exists(config_path):
        print(f"Config not found at {config_path}")
        return None
    with open(config_path, 'r', encoding='utf-8') as f:
        return json.load(f)

def flatten_product(p):
    """Flatten product dictionary for Excel export."""
    flat = p.copy()
    
    # Handle spec
    if 'spec' in p and isinstance(p['spec'], dict):
        flat['spec_name'] = p['spec'].get('name')
        flat['spec_value'] = p['spec'].get('selectedValue')
        del flat['spec']
        
    # Handle shipType
    if 'shipType' in p and isinstance(p['shipType'], dict):
        flat['shipType'] = p['shipType'].get('name')
        
    # Handle structuredData
    if 'structuredData' in p and isinstance(p['structuredData'], dict):
        attrs = p['structuredData'].get('attributes', [])
        for attr in attrs:
            attr_name = attr.get('name')
            attr_vals = attr.get('values', [])
            if attr_name:
                flat[f"Attr_{attr_name}"] = ", ".join(attr_vals)
        del flat['structuredData']
        
    # Handle images
    if 'images' in p and isinstance(p['images'], list):
        urls = [img.get('url') for img in p['images'] if img.get('url')]
        flat['images'] = "\n".join(urls)
        
    return flat

def main():
    target_sku = "2040076-61"
    
    config = load_config()
    if not config:
        return

    client = YahooClient(config['yahoo'])
    
    if not client.login():
        print("Login failed")
        return

    print(f"Searching for SKU: {target_sku}...")
    
    # Search logic
    uri = 'https://tw.supplier.yahoo.com/api/spa/v1/products'
    params = {
        'limit': 50,
        'offset': 0, # Scan from beginning to be safe
        'fields': '+listingIdList' 
    }
    
    headers = {
        'Cookie': client.cookie,
        'X-YahooWSSID-Authorization': client.wssid
    }
    
    found_products = []
    
    while True:
        if params['offset'] % 1000 == 0:
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
                part_no = p.get('partNo')
                
                if part_no == target_sku:
                    print(f"Found match! ID: {p.get('id')}, PartNo: {part_no}")
                    found_products.append(p)
            
            if len(products) < 50:
                break
                
            params['offset'] += 50
            
            # Safety break
            if params['offset'] > 35000:
                break
                
        except Exception as e:
            print(f"Exception: {e}")
            break

    if not found_products:
        print("No products found with that SKU.")
        return

    print(f"Total found: {len(found_products)}")
    
    # Flatten and Save to Excel
    flat_data = [flatten_product(p) for p in found_products]
    df = pd.json_normalize(flat_data)
    
    output_file = f"yahoo_sku_{target_sku}.xlsx"
    # Use absolute path to ensure we know where it is
    output_path = os.path.abspath(output_file)
    
    df.to_excel(output_path, index=False)
    print(f"Saved results to {output_path}")

if __name__ == "__main__":
    main()
