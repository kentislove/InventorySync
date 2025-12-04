import sys
import os
import json
import logging
import requests
from src.pchome_client import PChomeClient

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("test_pchome.log", encoding='utf-8'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

def log_print(msg):
    print(msg)
    logger.info(msg)

def load_config():
    config_path = "config/credentials.json"
    if not os.path.exists(config_path):
        log_print(f"Config file not found: {config_path}")
        return None
    with open(config_path, 'r', encoding='utf-8') as f:
        return json.load(f)

def test_pchome_fetch():
    try:
        config = load_config()
        if not config:
            input("Press Enter to exit...")
            return

        client = PChomeClient(config['pchome'])
        
        log_print(f"\nTesting PChome API for Vendor ID: {client.vendor_id}")
        log_print("="*50)

        # Test Cases
        test_cases = [
            {"name": "Try PmName (isshelf=1, fields=Id,PmName)", "params": {"isshelf": 1, "fields": "Id,PmName", "limit": 10, "offset": 1}},
            {"name": "Try extra_fields (isshelf=1, extra_fields=PmName)", "params": {"isshelf": 1, "extra_fields": "PmName", "limit": 10, "offset": 1}},
            {"name": "Try lower case (fields=id,name)", "params": {"isshelf": 1, "fields": "id,name", "limit": 10, "offset": 1}},
        ]

        first_id = None

        for case in test_cases:
            log_print(f"\nRunning Test Case: {case['name']}")
            uri = f"{client.base_url}/prod/v3.3/index.php/core/vendor/{client.vendor_id}/prod"
            
            # Construct query string manually to ensure control
            query_parts = []
            for k, v in case['params'].items():
                query_parts.append(f"{k}={v}")
            full_uri = f"{uri}?{'&'.join(query_parts)}"
            
            log_print(f"Request URI: {full_uri}")
            
            try:
                response = requests.get(full_uri, timeout=30)
                log_print(f"Status Code: {response.status_code}")
                
                if response.status_code == 200:
                    data = response.json()
                    if isinstance(data, dict):
                        log_print(f"Response Keys: {list(data.keys())}")
                        if 'TotalRows' in data:
                            log_print(f"TotalRows: {data['TotalRows']}")
                        
                        products = []
                        if 'data' in data: products = data['data']
                        elif 'products' in data: products = data['products']
                        elif 'Rows' in data: products = data['Rows']
                        
                        if products:
                            log_print(f"Fetched {len(products)} items.")
                            log_print(f"First Item Keys: {list(products[0].keys())}")
                            log_print(f"First Item Raw: {json.dumps(products[0], ensure_ascii=False)}")
                            if not first_id and 'Id' in products[0]:
                                first_id = products[0]['Id']
                        else:
                            log_print("No products found in response.")
                    else:
                        log_print(f"Unexpected response type: {type(data)}")
                else:
                    log_print(f"Error Response: {response.text}")
                    
            except Exception as e:
                log_print(f"Exception: {e}")

        # Test Single Product Fetch if ID found
        if first_id:
            log_print(f"\nTesting Single Product Fetch for ID: {first_id}")
            # URI: /prod/v3.3/index.php/vendor/{merchantId}/prod/{pId}?extra_fields=PmName
            single_uri = f"{client.base_url}/prod/v3.3/index.php/vendor/{client.vendor_id}/prod/{first_id}?extra_fields=PmName"
            log_print(f"Request URI: {single_uri}")
            try:
                response = requests.get(single_uri, timeout=30)
                log_print(f"Status Code: {response.status_code}")
                if response.status_code == 200:
                     log_print(f"Response Raw: {response.text[:1000]}")
                else:
                     log_print(f"Error Response: {response.text}")
            except Exception as e:
                log_print(f"Exception: {e}")
        else:
            # Fallback: Try to fetch ID from No Fields mode if previous failed
            log_print("\nNo ID found in previous tests. Trying 'No Fields' mode to get an ID...")
            uri = f"{client.base_url}/prod/v3.3/index.php/core/vendor/{client.vendor_id}/prod?isshelf=1&limit=1&offset=1"
            try:
                response = requests.get(uri, timeout=30)
                if response.status_code == 200:
                    data = response.json()
                    products = data.get('Rows', [])
                    if products:
                        first_id = products[0].get('Id')
                        log_print(f"Found ID: {first_id}")
                        # Retry single fetch
                        single_uri = f"{client.base_url}/prod/v3.3/index.php/vendor/{client.vendor_id}/prod/{first_id}?extra_fields=PmName"
                        log_print(f"Request URI: {single_uri}")
                        response = requests.get(single_uri, timeout=30)
                        log_print(f"Status Code: {response.status_code}")
                        log_print(f"Response Raw: {response.text[:1000]}")
            except Exception as e:
                log_print(f"Exception: {e}")
            
    except Exception as e:
        log_print(f"Critical Error: {e}")
    
    input("\nTest completed. Press Enter to exit...")

if __name__ == "__main__":
    test_pchome_fetch()
