"""
Yahoo 產品欄位診斷
檢查 Yahoo API 返回的所有欄位，特別是下架時間相關欄位
"""

import json
import sys
sys.path.insert(0, 'src')

from yahoo_client import YahooClient

# 載入設定
with open('config/credentials.json', 'r', encoding='utf-8') as f:
    config = json.load(f)['yahoo']

print("=" * 80)
print("Yahoo 產品欄位診斷")
print("=" * 80)

# 建立 Yahoo 客戶端
yahoo = YahooClient(config)

# 抓取前 5 個產品的完整資料
print("\n正在抓取產品資料...")
products = yahoo.fetch_products(limit=5)

if products:
    print(f"\n找到 {len(products)} 個產品")
    
    for i, product in enumerate(products, 1):
        print("\n" + "=" * 80)
        print(f"產品 {i}")
        print("=" * 80)
        
        # 顯示所有欄位
        print("\n所有欄位:")
        for key, value in product.items():
            # 如果值太長，只顯示前 100 個字元
            if isinstance(value, str) and len(value) > 100:
                print(f"  {key}: {value[:100]}...")
            else:
                print(f"  {key}: {value}")
        
        # 特別檢查可能與下架時間相關的欄位
        print("\n可能與下架時間相關的欄位:")
        time_related_keys = [
            'endTime', 'end_time', 'offlineTime', 'offline_time',
            'expireTime', 'expire_time', 'validUntil', 'valid_until',
            'status', 'state', 'isActive', 'is_active',
            'onlineTime', 'online_time', 'startTime', 'start_time',
            'saleStartTime', 'sale_start_time', 'saleEndTime', 'sale_end_time'
        ]
        
        found_fields = []
        for key in time_related_keys:
            if key in product:
                found_fields.append(f"  {key}: {product[key]}")
        
        if found_fields:
            for field in found_fields:
                print(field)
        else:
            print("  未找到明顯的時間相關欄位")
        
        # 檢查是否有 listing 資料
        if 'listingIdList' in product and product['listingIdList']:
            print(f"\n此產品有 Listing ID: {product['listingIdList']}")
            print("正在抓取 Listing 詳細資料...")
            
            listing_id = product['listingIdList'][0]
            listing_data = yahoo.fetch_listing_details(listing_id)
            
            if listing_data:
                print("\nListing 欄位:")
                for key, value in listing_data.items():
                    if isinstance(value, str) and len(value) > 100:
                        print(f"  {key}: {value[:100]}...")
                    elif isinstance(value, list) and len(value) > 0:
                        print(f"  {key}: [list with {len(value)} items]")
                    elif isinstance(value, dict):
                        print(f"  {key}: [dict with {len(value)} keys]")
                    else:
                        print(f"  {key}: {value}")
                
                # 檢查 Listing 中的時間相關欄位
                print("\nListing 中可能與下架時間相關的欄位:")
                found_listing_fields = []
                for key in time_related_keys:
                    if key in listing_data:
                        found_listing_fields.append(f"  {key}: {listing_data[key]}")
                
                if found_listing_fields:
                    for field in found_listing_fields:
                        print(field)
                else:
                    print("  未找到明顯的時間相關欄位")

print("\n" + "=" * 80)
print("診斷完成")
print("=" * 80)
