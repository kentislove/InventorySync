# 對話與開發紀錄 (Conversation & Development Log)
**日期**: 2025-12-04 ~ 2025-12-05
**專案**: 三平台 API 同步 (Yahoo, Momo, PChome)

## 1. 摘要 (Summary)
本時段主要完成了 **Yahoo V5.5** (過期過濾、SKU 格式化) 與 **Momo V5.6** (狀態過濾、SKU 格式化、庫存擷取) 的核心邏輯開發與封包。同時解決了 Momo API IP 限制問題 (透過獨立工具)，並診斷了 Yahoo SKU 異常原因。

## 2. 詳細對話與開發歷程 (Detailed Log)

### [Session 1] Yahoo V5.5 規則實作
*   **目標**: 實作 Yahoo 每日同步的過濾與 SKU 規則。
*   **討論重點**:
    *   確認 `endTs` 欄位代表下架時間，需過濾掉 `endTs < 系統時間` 的產品。
    *   確認 SKU 格式化規則：若規格名稱 (`spec_name`) 包含「尺寸」，則 SKU = `partNo` + `/` + `spec_value` (需剔除中文字，保留英數)。
*   **實作內容**:
    *   修改 `src/yahoo_client.py`: 實作 `_clean_spec_value` 與 `get_inventory` 邏輯。
    *   建立 `InventorySync_V5.5.exe`。

### [Session 2] Momo API 欄位分析與工具開發
*   **目標**: 取得 Momo 所有欄位以供分析，制定 SKU 規則。
*   **遭遇問題**: 執行腳本時遇到 **400 Error** (`{"ERROR":"此IP...無權限"}`)，確認為 IP 白名單問題。
*   **解決方案**:
    *   開發獨立執行檔 **`MomoFieldFetcher.exe`**。
    *   用戶將此工具複製到白名單電腦執行，成功產出 `momo_api_fields.xlsx`。

### [Session 3] Momo SKU 規則定義
*   **目標**: 根據 Excel 資料定義 Momo SKU 格式。
*   **討論重點**:
    *   用戶提供規則：基礎為 `entp_goods_no`。
    *   若 `goodsdt_info` (規格) 包含 **"/"**，取斜線後面的內容作為後綴 (去中文)。
    *   若無斜線 (如 "無", "深灰(1220)")，則不加後綴。
*   **實作內容**:
    *   更新 `Phase1_Execution_Plan.md`。
    *   修改 `src/momo_client.py` 實作此邏輯。

### [Session 4] Momo 狀態過濾與庫存擷取
*   **目標**: 優化 Momo 同步邏輯，僅抓取有效產品並更新庫存。
*   **討論重點**:
    *   **狀態過濾**: 僅保留 `sale_gb_name` 為「**進行**」或「**暫時中斷**」的產品。過濾掉「永久中斷」。
    *   **庫存擷取**: 針對篩選後的產品，需額外呼叫 `goodsStockQty` API 取得庫存數量。
*   **實作內容**:
    *   更新 `MomoFieldFetcher` 工具支援上述功能。
    *   將邏輯整合至 `src/momo_client.py`。
    *   發布 **V5.6** 版本 (`InventorySync_V5.6.exe`)。

### [Session 5] Yahoo SKU 異常診斷
*   **目標**: 調查為何部分 Yahoo 產品 (如 `2030154-C5`) 未套用 SKU 格式化規則。
*   **診斷結果**:
    *   使用診斷腳本 (`diagnose_yahoo_sku_issues.py`) 發現這些產品的 API 回傳資料中，**`spec` 欄位為空**。
    *   因此程式無法偵測到「尺寸」關鍵字，故未執行格式化。
*   **決策**:
    *   曾提議從產品名稱提取規格，但用戶決定**不需要**。
    *   確認目前程式邏輯正確 (依賴 API spec 欄位)。

## 3. 產出檔案 (Artifacts)
*   **執行檔**:
    *   `dist/InventorySync_V5.5/InventorySync_V5.5.exe` (Yahoo V5.5)
    *   `dist/InventorySync_V5.6/InventorySync_V5.6.exe` (Yahoo V5.5 + Momo V5.6)
    *   `dist/MomoFieldFetcher.exe` (Momo 測試工具)
*   **文件**:
    *   `Phase1_Execution_Plan.md` (已更新 Momo 規則)
    *   `Phase2_Development_Plan.md` (已更新批次策略)
    *   `dist/InventorySync_V5.5/README_V5.5.txt`
    *   `dist/InventorySync_V5.6/README_V5.6.txt`
*   **原始碼**:
    *   `src/yahoo_client.py`
    *   `src/momo_client.py`
    *   `src/test_momo_fields.py`
    *   `src/diagnose_yahoo_sku_issues.py`

## 4. 待辦事項 (Next Steps)
1.  將專案上傳至 Git (User Request)。
2.  等待用戶提供最新的 Momo Excel 資料。
3.  開始 Phase 2 開發。
