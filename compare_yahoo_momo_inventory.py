"""
Yahoo vs MOMO 產品規格庫存比較表
比對 SKU+Spec 組合，計算庫存差異
"""

import pandas as pd

# 讀取 Excel 檔案
excel_file = "dist/Inventory_Sync_Log.xlsx"
print(f"正在讀取 {excel_file}...")

# 讀取兩個工作表
yahoo_df = pd.read_excel(excel_file, sheet_name='Yahoo_Inventory')
momo_df = pd.read_excel(excel_file, sheet_name='MOMO_Inventory')

print(f"Yahoo 產品數: {len(yahoo_df)}")
print(f"MOMO 產品數: {len(momo_df)}")

# 建立 SKU+Spec 組合欄位
yahoo_df['SKU_Spec'] = yahoo_df['SKU'].astype(str) + yahoo_df['Spec'].fillna('').astype(str)
momo_df['SKU_Spec'] = momo_df['SKU'].astype(str) + momo_df['Spec'].fillna('').astype(str)

print("\nYahoo SKU+Spec 範例（前 10 筆）:")
for i, row in yahoo_df.head(10).iterrows():
    print(f"  {row['SKU']} + {row['Spec']} = {row['SKU_Spec']}")

print("\nMOMO SKU+Spec 範例（前 10 筆）:")
for i, row in momo_df.head(10).iterrows():
    print(f"  {row['SKU']} + {row['Spec']} = {row['SKU_Spec']}")

# 找出共同的 SKU+Spec
yahoo_sku_specs = set(yahoo_df['SKU_Spec'])
momo_sku_specs = set(momo_df['SKU_Spec'])
common_sku_specs = yahoo_sku_specs & momo_sku_specs

print("\n" + "=" * 80)
print("SKU+Spec 比對統計")
print("=" * 80)
print(f"Yahoo 獨有的 SKU+Spec: {len(yahoo_sku_specs)}")
print(f"MOMO 獨有的 SKU+Spec: {len(momo_sku_specs)}")
print(f"共同的 SKU+Spec: {len(common_sku_specs)}")

# 建立比較結果列表
comparison_results = []

for sku_spec in common_sku_specs:
    # 取得 Yahoo 的資料
    yahoo_records = yahoo_df[yahoo_df['SKU_Spec'] == sku_spec]
    
    # 取得 MOMO 的資料
    momo_records = momo_df[momo_df['SKU_Spec'] == sku_spec]
    
    # 如果兩邊都只有一筆記錄
    if len(yahoo_records) == 1 and len(momo_records) == 1:
        yahoo_row = yahoo_records.iloc[0]
        momo_row = momo_records.iloc[0]
        
        yahoo_qty = yahoo_row['Quantity']
        momo_qty = momo_row['Quantity']
        qty_diff = yahoo_qty - momo_qty
        
        comparison_results.append({
            'SKU': yahoo_row['SKU'],
            'Spec': yahoo_row['Spec'],
            'SKU_Spec': sku_spec,
            'Product_Name': yahoo_row['Name'],
            'Yahoo_Qty': yahoo_qty,
            'MOMO_Qty': momo_qty,
            'Qty_Diff': qty_diff,
            'Status': '一致' if qty_diff == 0 else '不一致',
            'Yahoo_Last_Updated': yahoo_row.get('Last Updated', ''),
            'MOMO_Last_Updated': momo_row.get('Last Updated', '')
        })
    else:
        # 如果有多筆記錄，分別列出
        for yahoo_row in yahoo_records.itertuples():
            for momo_row in momo_records.itertuples():
                yahoo_qty = yahoo_row.Quantity
                momo_qty = momo_row.Quantity
                qty_diff = yahoo_qty - momo_qty
                
                comparison_results.append({
                    'SKU': yahoo_row.SKU,
                    'Spec': yahoo_row.Spec,
                    'SKU_Spec': sku_spec,
                    'Product_Name': yahoo_row.Name,
                    'Yahoo_Qty': yahoo_qty,
                    'MOMO_Qty': momo_qty,
                    'Qty_Diff': qty_diff,
                    'Status': f'多筆記錄 (Y:{len(yahoo_records)}, M:{len(momo_records)})',
                    'Yahoo_Last_Updated': getattr(yahoo_row, 'Last Updated', ''),
                    'MOMO_Last_Updated': getattr(momo_row, 'Last Updated', '')
                })

# 建立 DataFrame
comparison_df = pd.DataFrame(comparison_results)

# 統計
print("\n" + "=" * 80)
print("比對結果統計")
print("=" * 80)
print(f"總比對筆數: {len(comparison_df)}")

if len(comparison_df) > 0:
    consistent = len(comparison_df[comparison_df['Status'] == '一致'])
    inconsistent = len(comparison_df[comparison_df['Status'] == '不一致'])
    
    print(f"庫存一致: {consistent} 筆")
    print(f"庫存不一致: {inconsistent} 筆")
    
    if inconsistent > 0:
        print(f"\n庫存差異統計:")
        print(f"  - 平均差異: {comparison_df['Qty_Diff'].mean():.2f}")
        print(f"  - 最大差異: {comparison_df['Qty_Diff'].max()}")
        print(f"  - 最小差異: {comparison_df['Qty_Diff'].min()}")
    
    print("\n前 10 筆比對結果:")
    print(comparison_df[['SKU', 'Spec', 'Product_Name', 'Yahoo_Qty', 'MOMO_Qty', 'Qty_Diff', 'Status']].head(10).to_string(index=False))

# 輸出到 Excel
output_file = "dist/Y&M_產品規格庫存比較表.xlsx"

with pd.ExcelWriter(output_file, engine='openpyxl') as writer:
    # 所有比對結果
    if len(comparison_df) > 0:
        comparison_df.to_excel(writer, sheet_name='完整比對結果', index=False)
        
        # 只顯示不一致的
        inconsistent_df = comparison_df[comparison_df['Status'] == '不一致']
        if len(inconsistent_df) > 0:
            inconsistent_df.to_excel(writer, sheet_name='庫存不一致', index=False)
        
        # 只顯示一致的
        consistent_df = comparison_df[comparison_df['Status'] == '一致']
        if len(consistent_df) > 0:
            consistent_df.to_excel(writer, sheet_name='庫存一致', index=False)
    
    # 統計摘要
    summary_data = {
        '項目': [
            'Yahoo 總產品數',
            'MOMO 總產品數',
            'Yahoo 獨有 SKU+Spec',
            'MOMO 獨有 SKU+Spec',
            '共同 SKU+Spec',
            '總比對筆數',
            '庫存一致',
            '庫存不一致',
            '平均庫存差異',
            '最大庫存差異',
            '最小庫存差異'
        ],
        '數量': [
            len(yahoo_df),
            len(momo_df),
            len(yahoo_sku_specs),
            len(momo_sku_specs),
            len(common_sku_specs),
            len(comparison_df),
            len(comparison_df[comparison_df['Status'] == '一致']) if len(comparison_df) > 0 else 0,
            len(comparison_df[comparison_df['Status'] == '不一致']) if len(comparison_df) > 0 else 0,
            f"{comparison_df['Qty_Diff'].mean():.2f}" if len(comparison_df) > 0 else 0,
            comparison_df['Qty_Diff'].max() if len(comparison_df) > 0 else 0,
            comparison_df['Qty_Diff'].min() if len(comparison_df) > 0 else 0
        ]
    }
    summary_df = pd.DataFrame(summary_data)
    summary_df.to_excel(writer, sheet_name='統計摘要', index=False)

print(f"\n✓ 分析完成！結果已儲存至: {output_file}")
print("\n工作表說明:")
print("  - 完整比對結果: 所有 SKU+Spec 的比對結果")
print("  - 庫存不一致: 只顯示 Yahoo 和 MOMO 庫存數量不同的產品")
print("  - 庫存一致: 只顯示 Yahoo 和 MOMO 庫存數量相同的產品")
print("  - 統計摘要: 整體統計資訊")
print("\n" + "=" * 80)
