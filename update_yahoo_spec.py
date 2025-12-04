"""
更新 Yahoo_Inventory 的 Spec 欄位
從 Name (B欄) 中提取 "-" 後面的字串，加上 "/" 後填入 Spec (C欄)
"""

import pandas as pd
from openpyxl import load_workbook

# 讀取 Excel 檔案
excel_file = "dist/Inventory_Sync_Log.xlsx"
print(f"正在讀取 {excel_file}...")

# 讀取所有工作表
all_sheets = pd.read_excel(excel_file, sheet_name=None)

# 讀取 Yahoo_Inventory 工作表
yahoo_df = all_sheets['Yahoo_Inventory']

print(f"Yahoo_Inventory 總筆數: {len(yahoo_df)}")
print(f"欄位: {list(yahoo_df.columns)}")

# 顯示更新前的範例
print("\n更新前範例（前 10 筆）:")
print(yahoo_df[['SKU', 'Name', 'Spec']].head(10).to_string(index=False))

# 更新 Spec 欄位
updated_count = 0
for idx, row in yahoo_df.iterrows():
    name = str(row['Name'])
    
    # 如果 Name 包含 "-"
    if '-' in name:
        # 取得最後一個 "-" 後面的字串
        spec_part = name.split('-')[-1].strip()
        
        # 如果提取到的字串不是空的
        if spec_part:
            # 在前面加上 "/"
            new_spec = f"/{spec_part}"
            
            # 更新 Spec 欄位
            yahoo_df.at[idx, 'Spec'] = new_spec
            updated_count += 1

print(f"\n✓ 已更新 {updated_count} 筆記錄")

# 顯示更新後的範例
print("\n更新後範例（前 10 筆）:")
print(yahoo_df[['SKU', 'Name', 'Spec']].head(10).to_string(index=False))

# 更新 all_sheets 中的 Yahoo_Inventory
all_sheets['Yahoo_Inventory'] = yahoo_df

# 將所有工作表寫回 Excel
with pd.ExcelWriter(excel_file, engine='openpyxl') as writer:
    for sheet_name, df in all_sheets.items():
        df.to_excel(writer, sheet_name=sheet_name, index=False)

print(f"\n✓ 已儲存更新至 {excel_file}")
print("\n統計:")
print(f"  - 總筆數: {len(yahoo_df)}")
print(f"  - 已更新 Spec: {updated_count}")
print(f"  - 未更新（無 '-' 或已有值）: {len(yahoo_df) - updated_count}")
