"""
Yahoo vs MOMO SKU 重複檢查 - 增強版
比對 Yahoo_Inventory 和 MOMO_Inventory，找出 Yahoo 中有重複 SKU 的異常情況
"""

import pandas as pd
from collections import Counter

# 讀取 Excel 檔案
print("正在讀取 Inventory_Sync_Log.xlsx...")
excel_file = "dist/Inventory_Sync_Log.xlsx"

# 讀取兩個工作表
yahoo_df = pd.read_excel(excel_file, sheet_name='Yahoo_Inventory')
momo_df = pd.read_excel(excel_file, sheet_name='MOMO_Inventory')

print(f"Yahoo 產品數: {len(yahoo_df)}")
print(f"MOMO 產品數: {len(momo_df)}")

# 顯示欄位
print(f"\nYahoo 欄位: {list(yahoo_df.columns)}")
print(f"MOMO 欄位: {list(momo_df.columns)}")

# 顯示 Part No 範例
print("\n" + "=" * 80)
print("Part No 範例")
print("=" * 80)
print("\nYahoo Part No 前 10 筆:")
for i, part_no in enumerate(yahoo_df['Part No'].head(10), 1):
    print(f"  {i}. {part_no}")

print("\nMOMO Part No 前 10 筆:")
for i, part_no in enumerate(momo_df['Part No'].head(10), 1):
    print(f"  {i}. {part_no}")

# 提取 Part No（供應商料號）
yahoo_df['Part_No_Clean'] = yahoo_df['Part No'].astype(str).str.strip()
momo_df['Part_No_Clean'] = momo_df['Part No'].astype(str).str.strip()

# 找出共同的 Part No
yahoo_parts = set(yahoo_df['Part_No_Clean'])
momo_parts = set(momo_df['Part_No_Clean'])
common_parts = yahoo_parts & momo_parts

print("\n" + "=" * 80)
print("Part No 比對統計")
print("=" * 80)
print(f"Yahoo 獨有的 Part No: {len(yahoo_parts)}")
print(f"MOMO 獨有的 Part No: {len(momo_parts)}")
print(f"共同的 Part No: {len(common_parts)}")

if len(common_parts) > 0:
    print(f"\n共同 Part No 範例（前 10 個）:")
    for i, part_no in enumerate(list(common_parts)[:10], 1):
        print(f"  {i}. {part_no}")

# 統計 Yahoo 中每個 Part No 出現的次數
yahoo_part_no_counts = Counter(yahoo_df['Part_No_Clean'])

# 統計 MOMO 中每個 Part No 出現的次數
momo_part_no_counts = Counter(momo_df['Part_No_Clean'])

# 找出異常情況：MOMO 只有 1 個，但 Yahoo 有多個
abnormal_cases = []

for part_no in common_parts:
    momo_count = momo_part_no_counts[part_no]
    yahoo_count = yahoo_part_no_counts[part_no]
    
    # 如果 MOMO 只有 1 個，但 Yahoo 有多個
    if momo_count == 1 and yahoo_count > 1:
        # 取得 MOMO 的資料
        momo_records = momo_df[momo_df['Part_No_Clean'] == part_no]
        momo_sku = momo_records.iloc[0]['SKU']
        momo_spec = momo_records.iloc[0]['Spec']
        momo_qty = momo_records.iloc[0]['Quantity']
        momo_name = momo_records.iloc[0]['Name']
        
        # 取得 Yahoo 的所有記錄
        yahoo_records = yahoo_df[yahoo_df['Part_No_Clean'] == part_no]
        
        for idx, yahoo_row in yahoo_records.iterrows():
            abnormal_cases.append({
                'Part_No': part_no,
                'Status': '異常 - Yahoo 有重複',
                'MOMO_SKU': momo_sku,
                'MOMO_Spec': momo_spec,
                'MOMO_Qty': momo_qty,
                'MOMO_Name': momo_name,
                'Yahoo_SKU': yahoo_row['SKU'],
                'Yahoo_Spec': yahoo_row['Spec'],
                'Yahoo_Qty': yahoo_row['Quantity'],
                'Yahoo_Name': yahoo_row['Name'],
                'Yahoo_Count': yahoo_count,
                'MOMO_Count': momo_count
            })

# 找出正常情況：MOMO 只有 1 個，Yahoo 也只有 1 個
normal_cases = []

for part_no in common_parts:
    momo_count = momo_part_no_counts[part_no]
    yahoo_count = yahoo_part_no_counts[part_no]
    
    # 如果 MOMO 只有 1 個，Yahoo 也只有 1 個
    if momo_count == 1 and yahoo_count == 1:
        # 取得 MOMO 的資料
        momo_records = momo_df[momo_df['Part_No_Clean'] == part_no]
        momo_sku = momo_records.iloc[0]['SKU']
        momo_spec = momo_records.iloc[0]['Spec']
        momo_qty = momo_records.iloc[0]['Quantity']
        momo_name = momo_records.iloc[0]['Name']
        
        # 取得 Yahoo 的資料
        yahoo_records = yahoo_df[yahoo_df['Part_No_Clean'] == part_no]
        yahoo_sku = yahoo_records.iloc[0]['SKU']
        yahoo_spec = yahoo_records.iloc[0]['Spec']
        yahoo_qty = yahoo_records.iloc[0]['Quantity']
        yahoo_name = yahoo_records.iloc[0]['Name']
        
        qty_diff = yahoo_qty - momo_qty
        
        normal_cases.append({
            'Part_No': part_no,
            'Status': '正常' if qty_diff == 0 else '正常但庫存不一致',
            'MOMO_SKU': momo_sku,
            'MOMO_Spec': momo_spec,
            'MOMO_Qty': momo_qty,
            'MOMO_Name': momo_name,
            'Yahoo_SKU': yahoo_sku,
            'Yahoo_Spec': yahoo_spec,
            'Yahoo_Qty': yahoo_qty,
            'Yahoo_Name': yahoo_name,
            'Qty_Diff': qty_diff,
            'Yahoo_Count': yahoo_count,
            'MOMO_Count': momo_count
        })

# 建立 DataFrame
abnormal_df = pd.DataFrame(abnormal_cases)
normal_df = pd.DataFrame(normal_cases)

# 統計
print("\n" + "=" * 80)
print("統計結果")
print("=" * 80)
print(f"異常情況（MOMO 1個，Yahoo 多個）: {len(abnormal_df)} 筆")
print(f"正常情況（MOMO 1個，Yahoo 1個）: {len(normal_df)} 筆")

if len(abnormal_df) > 0:
    # 統計有多少個不同的 Part No 有異常
    unique_abnormal_part_nos = abnormal_df['Part_No'].nunique()
    print(f"異常的供應商料號數量: {unique_abnormal_part_nos}")
    
    print("\n前 10 個異常案例:")
    print(abnormal_df[['Part_No', 'Yahoo_SKU', 'Yahoo_Spec', 'Yahoo_Qty', 'MOMO_SKU', 'MOMO_Spec', 'MOMO_Qty']].head(10).to_string(index=False))

# 輸出到 Excel
output_file = "dist/Yahoo_MOMO_SKU_Comparison.xlsx"

with pd.ExcelWriter(output_file, engine='openpyxl') as writer:
    # 異常情況
    if len(abnormal_df) > 0:
        abnormal_df.to_excel(writer, sheet_name='異常_Yahoo重複SKU', index=False)
    
    # 正常情況
    if len(normal_df) > 0:
        normal_df.to_excel(writer, sheet_name='正常_1對1比對', index=False)
    
    # 統計摘要
    summary_data = {
        '項目': [
            'Yahoo 總產品數',
            'MOMO 總產品數',
            'Yahoo 獨有 Part No 數',
            'MOMO 獨有 Part No 數',
            '共同 Part No 數',
            '異常情況（MOMO 1個，Yahoo 多個）',
            '異常的供應商料號數量',
            '正常情況（MOMO 1個，Yahoo 1個）',
            '庫存一致的產品數',
            '庫存不一致的產品數'
        ],
        '數量': [
            len(yahoo_df),
            len(momo_df),
            len(yahoo_parts),
            len(momo_parts),
            len(common_parts),
            len(abnormal_df),
            abnormal_df['Part_No'].nunique() if len(abnormal_df) > 0 else 0,
            len(normal_df),
            len(normal_df[normal_df['Qty_Diff'] == 0]) if len(normal_df) > 0 else 0,
            len(normal_df[normal_df['Qty_Diff'] != 0]) if len(normal_df) > 0 else 0
        ]
    }
    summary_df = pd.DataFrame(summary_data)
    summary_df.to_excel(writer, sheet_name='統計摘要', index=False)

print(f"\n✓ 分析完成！結果已儲存至: {output_file}")
print("\n工作表說明:")
print("  - 異常_Yahoo重複SKU: MOMO 只有 1 個 SKU，但 Yahoo 有多個 SKU 的異常情況")
print("  - 正常_1對1比對: MOMO 和 Yahoo 都只有 1 個 SKU 的正常情況")
print("  - 統計摘要: 整體統計資訊")
print("\n" + "=" * 80)
