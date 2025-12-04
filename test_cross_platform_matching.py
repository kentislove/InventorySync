"""
Test Cross-Platform SKU Matching
測試三個平台的 SKU 和 part_no 欄位，確認比對邏輯
"""

import json
import sys
sys.path.insert(0, 'src')

from yahoo_client import YahooClient
from pchome_client import PChomeClient
from momo_client import MomoClient

# 載入設定
with open('config/credentials.json', 'r', encoding='utf-8') as f:
    config = json.load(f)

print("=" * 80)
print("跨平台 SKU 比對測試")
print("=" * 80)

# 測試 Yahoo
print("\n" + "=" * 80)
print("Yahoo 產品結構")
print("=" * 80)
yahoo = YahooClient(config['yahoo'])
yahoo_inv = yahoo.get_inventory()

if yahoo_inv:
    print(f"總產品數: {len(yahoo_inv)}")
    print("\n前 5 筆產品:")
    for i, item in enumerate(yahoo_inv[:5], 1):
        print(f"\n產品 {i}:")
        print(f"  sku: {item.get('sku')}")
        print(f"  part_no: {item.get('part_no')}")
        print(f"  name: {item.get('name')}")
        print(f"  spec_name: {item.get('spec_name')}")
        print(f"  quantity: {item.get('quantity')}")

# 測試 PChome
print("\n" + "=" * 80)
print("PChome 產品結構")
print("=" * 80)
pchome = PChomeClient(config['pchome'])
pchome_inv = pchome.get_inventory()

if pchome_inv:
    print(f"總產品數: {len(pchome_inv)}")
    print("\n前 5 筆產品:")
    for i, item in enumerate(pchome_inv[:5], 1):
        print(f"\n產品 {i}:")
        print(f"  sku: {item.get('sku')}")
        print(f"  part_no: {item.get('part_no')}")
        print(f"  name: {item.get('name')}")
        print(f"  spec_name: {item.get('spec_name')}")
        print(f"  quantity: {item.get('quantity')}")

# 測試 MOMO
print("\n" + "=" * 80)
print("MOMO 產品結構")
print("=" * 80)
momo = MomoClient(config['momo'])
momo_inv = momo.get_inventory()

if momo_inv:
    print(f"總產品數: {len(momo_inv)}")
    print("\n前 5 筆產品:")
    for i, item in enumerate(momo_inv[:5], 1):
        print(f"\n產品 {i}:")
        print(f"  sku: {item.get('sku')}")
        print(f"  part_no: {item.get('part_no')}")
        print(f"  name: {item.get('name')}")
        print(f"  spec_name: {item.get('spec_name')}")
        print(f"  quantity: {item.get('quantity')}")

# 比對測試
print("\n" + "=" * 80)
print("跨平台比對測試")
print("=" * 80)

# 建立 part_no 索引
yahoo_parts = {item.get('part_no'): item for item in yahoo_inv if item.get('part_no')}
pchome_parts = {item.get('part_no'): item for item in pchome_inv if item.get('part_no')}
momo_parts = {item.get('part_no'): item for item in momo_inv if item.get('part_no')}

# 找出共同的 part_no
all_parts = set(yahoo_parts.keys()) | set(pchome_parts.keys()) | set(momo_parts.keys())

print(f"\nYahoo 獨有產品數: {len(yahoo_parts)}")
print(f"PChome 獨有產品數: {len(pchome_parts)}")
print(f"MOMO 獨有產品數: {len(momo_parts)}")
print(f"所有產品數 (合併): {len(all_parts)}")

# 找出在多個平台都有的產品
common_yahoo_pchome = set(yahoo_parts.keys()) & set(pchome_parts.keys())
common_yahoo_momo = set(yahoo_parts.keys()) & set(momo_parts.keys())
common_pchome_momo = set(pchome_parts.keys()) & set(momo_parts.keys())
common_all = set(yahoo_parts.keys()) & set(pchome_parts.keys()) & set(momo_parts.keys())

print(f"\nYahoo & PChome 共同產品: {len(common_yahoo_pchome)}")
print(f"Yahoo & MOMO 共同產品: {len(common_yahoo_momo)}")
print(f"PChome & MOMO 共同產品: {len(common_pchome_momo)}")
print(f"三平台共同產品: {len(common_all)}")

# 顯示幾個共同產品的庫存差異
if common_all:
    print(f"\n前 5 個三平台共同產品的庫存比對:")
    for i, part_no in enumerate(list(common_all)[:5], 1):
        print(f"\n{i}. Part No: {part_no}")
        print(f"   Yahoo:   {yahoo_parts[part_no].get('quantity')} (Spec: {yahoo_parts[part_no].get('spec_name')})")
        print(f"   PChome:  {pchome_parts[part_no].get('quantity')} (Spec: {pchome_parts[part_no].get('spec_name')})")
        print(f"   MOMO:    {momo_parts[part_no].get('quantity')} (Spec: {momo_parts[part_no].get('spec_name')})")
        
        qtys = [
            yahoo_parts[part_no].get('quantity'),
            pchome_parts[part_no].get('quantity'),
            momo_parts[part_no].get('quantity')
        ]
        min_qty = min(qtys)
        max_qty = max(qtys)
        
        if min_qty != max_qty:
            print(f"   ⚠️ 庫存不一致！最小: {min_qty}, 最大: {max_qty}, 差異: {max_qty - min_qty}")
        else:
            print(f"   ✓ 庫存一致: {min_qty}")

print("\n" + "=" * 80)
print("測試完成")
print("=" * 80)
