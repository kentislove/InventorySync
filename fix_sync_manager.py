"""
修正 sync_manager.py 的腳本
"""
import re

# 讀取檔案
with open('src/sync_manager_backup.py', 'r', encoding='utf-8') as f:
    lines = f.readlines()

# 修正第 60 行的縮排錯誤（應該是 20 個空格，不是 24 個）
if len(lines) > 59:
    lines[59] = '                    # Platform internal ID (e.g. Yahoo ProductId, MOMO goodsCode) should be in extra_data.\r\n'
    # 插入缺失的程式碼
    lines.insert(60, '                    \r\n')
    lines.insert(61, '                    # Ensure name and spec_name are in extra_data for easy retrieval later\r\n')
    lines.insert(62, '                    extra_data[\'name\'] = item.get(\'name\')\r\n')
    lines.insert(63, '                    extra_data[\'spec_name\'] = item.get(\'spec_name\', \'\')\r\n')
    lines.insert(64, '                    \r\n')
    lines.insert(65, '                    # 統一 spec_name 格式：確保有 / 前綴\r\n')
    lines.insert(66, '                    if extra_data[\'spec_name\'] and not extra_data[\'spec_name\'].startswith(\'/\'):\r\n')
    lines.insert(67, '                        extra_data[\'spec_name\'] = f"/{extra_data[\'spec_name\']}"\r\n')
    lines.insert(68, '                    \r\n')
    lines.insert(69, '                    if platform_name == \'Yahoo\':\r\n')
    lines.insert(70, '                        extra_data[\'yahoo_id\'] = item.get(\'sku\')\r\n')
    lines.insert(71, '                    elif platform_name == \'MOMO\':\r\n')
    lines.insert(72, '                        extra_data[\'momo_code\'] = item.get(\'momo_sku\') # momo_client returns \'momo_sku\' as internal code\r\n')
    lines.insert(73, '                    elif platform_name == \'PChome\':\r\n')

# 修正第 87-88 行（移除 goodsdt_info fallback）
# 找到 "# Get spec_name from extra_data" 的位置
for i, line in enumerate(lines):
    if '# Get spec_name from extra_data' in line and i > 80:
        # 替換接下來的 4 行
        lines[i] = '                # Get spec_name from extra_data (already normalized during storage)\r\n'
        lines[i+1] = '                spec_name = extra_data.get(\'spec_name\', \'\')\r\n'
        lines[i+2] = '                \r\n'
        lines[i+3] = '                # Create composite key: Part No + Spec\r\n'
        # 刪除 "if not spec_name and row[1] == 'MOMO':" 那兩行
        if i+4 < len(lines) and 'if not spec_name and row[1]' in lines[i+4]:
            del lines[i+4:i+6]  # 刪除兩行
        break

# 修正第 113-117 行（簡化 spec 提取）
for i, line in enumerate(lines):
    if '# Extract SKU and Spec from first record' in line:
        # 替換接下來的幾行
        lines[i+1] = '                sku = records[0][0]\r\n'
        lines[i+2] = '                spec_name = records[0][4].get(\'spec_name\', \'Unknown\')\r\n'
        lines[i+3] = '                \r\n'
        # 刪除多餘的 goodsdt_info 檢查
        if i+4 < len(lines) and 'if not spec_name and records[0][1]' in lines[i+4]:
            # 找到並刪除這 4 行
            del lines[i+4:i+8]
        break

# 寫入修正後的檔案
with open('src/sync_manager.py', 'w', encoding='utf-8') as f:
    f.writelines(lines)

print("✅ sync_manager.py 已修正")
print("修正內容:")
print("1. 修復第 60 行縮排錯誤")
print("2. 加入 spec_name 格式統一邏輯（第 66-68 行）")
print("3. 移除讀取時的 goodsdt_info fallback（第 100-106 行）")
print("4. 簡化 spec 提取邏輯（第 127-131 行）")
