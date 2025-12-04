Inventory Sync System V5.5
發布日期: 2025-12-04

本版本包含完整的 Yahoo 產品資料下載比對邏輯及寫入條件。

## 核心邏輯與原則

### 1. Yahoo 產品過濾 (EndTs 比對)
- **原則**: 
  - 系統會針對每個 Yahoo 產品，透過 Listing API 查詢其 `endTs` (下架時間)。
  - 將 `endTs` 與系統當前時間進行比對。
- **邏輯**:
  - 若 `endTs` < 系統日期 (已過期):
    - 該產品**不會**被下載。
    - **不會**進入本地資料庫。
    - **不會**寫入 Google Sheets (GS)。
    - **不會**呈現於網頁上。
  - 若 `endTs` >= 系統日期 (未過期):
    - 該產品**會**被保留。
    - **會**寫入本地資料庫。
    - **會**寫入 Google Sheets (GS)。
    - **會**等候被查詢呈現於網頁上。

### 2. SKU 格式化 (Spec 處理)
- **原則**: 為了與其他平台 (MOMO, PCHome) 的 SKU 進行比對，需統一 SKU 格式。
- **邏輯**:
  - 系統會檢查產品的規格名稱 (`spec_name`)。
  - 若 `spec_name` 包含「**尺寸**」字串:
    - 取得規格值 (`spec_value`)。
    - **剔除**規格值中的所有中文字 (例如 "L號" -> "L")。
    - 將處理後的規格值附加到原始料號 (`partNo`) 後方。
    - **新 SKU = partNo + 處理後的 spec_value**
    - 此新 SKU 將被寫入本地資料庫及 Google Sheets 的 Yahoo_Inventory SKU 欄位。
  - 若 `spec_name` 不包含「尺寸」:
    - SKU 保持為原始料號 (`partNo`)。

### 3. 執行說明
- 請執行 `InventorySync_V5.5.exe`。
- 設定檔位於 `config/credentials.json`。
- 程式啟動後會自動執行上述邏輯，並將結果同步至資料庫與 Google Sheets。
