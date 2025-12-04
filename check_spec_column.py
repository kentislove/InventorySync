"""
Check Google Sheets Spec Column
檢查 Google Sheets 中的 Spec 欄位是否有資料
"""

import gspread
from oauth2client.service_account import ServiceAccountCredentials

# Google Sheets 設定
SHEET_NAME = "Inventory_Sync_Log"
CREDS_FILE = "config/google_credentials.json"

# 設定認證
scope = ['https://spreadsheets.google.com/feeds', 'https://www.googleapis.com/auth/drive']
creds = ServiceAccountCredentials.from_json_keyfile_name(CREDS_FILE, scope)
client = gspread.authorize(creds)

# 開啟工作表
sheet = client.open(SHEET_NAME)

print("=" * 80)
print("Google Sheets Spec 欄位檢查")
print("=" * 80)

# 檢查各個工作表
worksheets_to_check = ["Sync_Log", "Stock_Comparison", "Yahoo_Inventory", "PChome_Inventory", "MOMO_Inventory"]

for ws_name in worksheets_to_check:
    try:
        print(f"\n{'=' * 80}")
        print(f"工作表: {ws_name}")
        print("=" * 80)
        
        worksheet = sheet.worksheet(ws_name)
        
        # 取得標題列
        headers = worksheet.row_values(1)
        print(f"\n欄位標題: {headers}")
        
        # 檢查是否有 Spec 欄位
        if "Spec" in headers:
            spec_col_idx = headers.index("Spec") + 1  # gspread 使用 1-based index
            print(f"✓ 找到 Spec 欄位（第 {spec_col_idx} 欄）")
            
            # 取得前 20 筆資料的 Spec 欄位
            spec_values = worksheet.col_values(spec_col_idx)[1:21]  # 跳過標題，取前 20 筆
            
            # 統計
            total = len(spec_values)
            non_empty = sum(1 for v in spec_values if v and v.strip() and v.strip().lower() != 'unknown')
            empty_or_unknown = total - non_empty
            
            print(f"\n前 20 筆資料統計:")
            print(f"  - 總筆數: {total}")
            print(f"  - 有 Spec 資料: {non_empty} ({non_empty/total*100:.1f}%)")
            print(f"  - 空白或 Unknown: {empty_or_unknown} ({empty_or_unknown/total*100:.1f}%)")
            
            print(f"\n前 10 筆 Spec 值:")
            for i, val in enumerate(spec_values[:10], 1):
                display_val = val if val else "(空白)"
                print(f"  {i}. {display_val}")
        else:
            print("✗ 未找到 Spec 欄位")
            
    except gspread.exceptions.WorksheetNotFound:
        print(f"✗ 工作表 '{ws_name}' 不存在")
    except Exception as e:
        print(f"✗ 錯誤: {e}")

print("\n" + "=" * 80)
print("檢查完成")
print("=" * 80)
