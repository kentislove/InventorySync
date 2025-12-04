"""
完整重建 sync_manager.py 的腳本
從 V4 的乾淨版本開始，加入所有 V5 修正
"""

# 從 V4 複製乾淨的版本，然後加入 V5 的修正
import shutil
import os

# 檢查是否有 V4 的備份
v4_path = 'archive/v4/src/sync_manager.py'
if not os.path.exists(v4_path):
    print("❌ 找不到 V4 備份檔案")
    print("請手動從 V4 版本複製 sync_manager.py")
    exit(1)

# 複製 V4 版本
shutil.copy(v4_path, 'src/sync_manager.py')
print("✅ 已從 V4 複製乾淨的 sync_manager.py")

# 讀取檔案
with open('src/sync_manager.py', 'r', encoding='utf-8') as f:
    content = f.read()

# 修正 1: 加入 filter_expired=True
content = content.replace(
    'yahoo_inv = self.yahoo.get_inventory()',
    'yahoo_inv = self.yahoo.get_inventory(filter_expired=True)'
)

# 修正 2: 移除讀取時的 goodsdt_info fallback (第 100-106 行附近)
content = content.replace(
    '''                # Get spec_name from extra_data
                spec_name = extra_data.get('spec_name', '')
                if not spec_name and row[1] == 'MOMO':
                    spec_name = extra_data.get('goodsdt_info', '')
                
                # Create composite key: Part No + Spec
                composite_key = f"{sku}_{spec_name}"''',
    '''                # Get spec_name from extra_data (already normalized during storage)
                spec_name = extra_data.get('spec_name', '')
                
                # Create composite key: Part No + Spec
                composite_key = f"{sku}_{spec_name}"'''
)

# 修正 3: 簡化 spec 提取邏輯 (第 127-131 行附近)
content = content.replace(
    '''                # Extract SKU and Spec from first record (already grouped by composite key)
                sku = records[0][0]
                spec_name = records[0][4].get('spec_name', '')
                if not spec_name and records[0][1] == 'MOMO':
                    spec_name = records[0][4].get('goodsdt_info', '')
                if not spec_name:
                    spec_name = "Unknown"''',
    '''                # Extract SKU and Spec from first record (already grouped by composite key)
                sku = records[0][0]
                spec_name = records[0][4].get('spec_name', 'Unknown')'''
)

# 寫回檔案
with open('src/sync_manager.py', 'w', encoding='utf-8') as f:
    f.write(content)

print("✅ V5 修正已套用")
print("修正內容:")
print("1. Yahoo 過期產品過濾 (filter_expired=True)")
print("2. 移除讀取時的 goodsdt_info fallback")
print("3. 簡化 spec 提取邏輯")
