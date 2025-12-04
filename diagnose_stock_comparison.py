"""
診斷 Stock_Comparison 問題的腳本
"""
import sqlite3
import json

# 連接資料庫
conn = sqlite3.connect('dist/InventorySync_V5/inventory.db')
c = conn.cursor()

# 查詢 1810317-34 的所有記錄
c.execute('''
    SELECT sku, platform, quantity, extra_data 
    FROM inventory 
    WHERE sku = "1810317-34"
    ORDER BY platform, sku
''')

rows = c.fetchall()

print("=== 資料庫記錄 ===")
print(f"{'Platform':<10} | {'SKU':<15} | {'Qty':<5} | {'Spec':<10} | {'Composite Key'}")
print("-" * 80)

composite_keys = set()
for row in rows:
    sku = row[0]
    platform = row[1]
    qty = row[2]
    extra_data = json.loads(row[3] or '{}')
    spec_name = extra_data.get('spec_name', '')
    
    # 模擬程式碼中的邏輯
    if not spec_name and platform == 'MOMO':
        spec_name = extra_data.get('goodsdt_info', '')
    
    composite_key = f"{sku}_{spec_name}"
    composite_keys.add(composite_key)
    
    print(f"{platform:<10} | {sku:<15} | {qty:<5} | {spec_name:<10} | {composite_key}")

print(f"\n=== Composite Keys ===")
print(f"總共 {len(composite_keys)} 個不同的 composite_key:")
for key in sorted(composite_keys):
    print(f"  - {key}")

print(f"\n=== 分析 ===")
if len(composite_keys) == 2:
    print("✅ 正確：應該有 2 個 composite_key (/44 和 /46)")
    print("   每個 composite_key 應該包含 Yahoo 和 MOMO 的記錄")
elif len(composite_keys) == 4:
    print("❌ 錯誤：有 4 個 composite_key")
    print("   這表示 Yahoo 和 MOMO 的 spec_name 格式不同")
else:
    print(f"⚠️  異常：有 {len(composite_keys)} 個 composite_key")

# 檢查每個 composite_key 包含哪些平台
print(f"\n=== 每個 Composite Key 的平台分布 ===")
c.execute('''
    SELECT sku, platform, quantity, extra_data 
    FROM inventory 
    WHERE sku = "1810317-34"
''')
rows = c.fetchall()

key_platforms = {}
for row in rows:
    sku = row[0]
    platform = row[1]
    extra_data = json.loads(row[3] or '{}')
    spec_name = extra_data.get('spec_name', '')
    if not spec_name and platform == 'MOMO':
        spec_name = extra_data.get('goodsdt_info', '')
    
    composite_key = f"{sku}_{spec_name}"
    
    if composite_key not in key_platforms:
        key_platforms[composite_key] = []
    key_platforms[composite_key].append(platform)

for key, platforms in sorted(key_platforms.items()):
    print(f"{key}: {', '.join(platforms)}")
    if len(platforms) == 1:
        print(f"  ❌ 問題：只有一個平台！")
    elif len(platforms) == 2:
        print(f"  ✅ 正確：有兩個平台")

conn.close()
