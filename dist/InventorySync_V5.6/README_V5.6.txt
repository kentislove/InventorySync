Inventory Sync System V5.6
==========================

版本說明 (Release Notes)
----------------------
V5.6 主要針對 MOMO 平台進行邏輯升級，包含狀態過濾、SKU 格式化與庫存擷取。

新增功能 (New Features)
---------------------
1. MOMO 狀態過濾 (Status Filtering)
   - 系統僅會同步狀態為「進行」或「暫時中斷」的產品。
   - 「永久中斷」或其他狀態的產品將被自動過濾。

2. MOMO SKU 格式化 (SKU Formatting)
   - 基礎 SKU 使用原廠料號 (entp_goods_no)。
   - 若規格欄位 (goodsdt_info) 包含 "/"，則取斜線後面的內容作為規格後綴。
   - 規格後綴會自動剔除中文字，僅保留英文與數字。
   - 格式範例: "2040076-61/L" (若規格為 "Color/L號")。

3. MOMO 庫存擷取 (Stock Fetching)
   - 針對篩選後的有效產品，系統會自動批次呼叫 MOMO 庫存 API。
   - 取得的庫存數量將用於後續的庫存同步與報表生成。

安裝與執行 (Installation & Execution)
-----------------------------------
1. 請確保 `config` 資料夾內包含正確的設定檔 (`credentials.json`, `google_credentials.json`)。
2. 直接執行 `InventorySync_V5.6.exe` 即可開始同步作業。
3. 程式會自動讀取 MOMO API 資料並執行上述邏輯。

注意事項 (Notes)
--------------
- 本版本包含 V5.5 的所有 Yahoo 邏輯 (EndTs 過濾、SKU 格式化)。
- 請確保執行環境網路正常，且 IP 已在 MOMO API 白名單中。
