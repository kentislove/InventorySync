# Yahoo 已過期產品過濾解決方案

## 問題說明

同一個供應商料號（Part No）+ 規格（Spec）的商品，在 Yahoo 可能有多個賣場記錄：
- 一個上架中（endTs 在未來）
- 一個已下架（endTs 已過期）

這導致在與 MOMO 比對時出現「多筆記錄 (Y:2, M:1)」的情況。

## 解決方案

### 方案 1：使用獨立過濾腳本（已實作）

**腳本**: `filter_expired_yahoo_products.py`

**功能**:
1. 登入 Yahoo API
2. 抓取所有產品
3. 對每個產品檢查 Listing API 的 `endTs` 欄位
4. 比對系統時間，過濾掉已過期的產品
5. 輸出結果到 `dist/yahoo_expired_products.json`

**使用方法**:
```bash
python filter_expired_yahoo_products.py
```

**輸出**:
- 總產品數
- 已過期產品數
- 上架中產品數
- 詳細的產品列表（JSON 格式）

### 方案 2：整合到主程式（未來實作）

**目標**: 修復 `yahoo_client.py` 並在 `get_inventory()` 方法中加入 endTs 檢查

**步驟**:
1. 恢復 `yahoo_client.py` 到正常狀態
2. 在抓取產品時，對每個產品調用 `fetch_listing_details()`
3. 檢查 `endTs` 是否晚於當前時間
4. 只保留未過期的產品

**優點**:
- 自動過濾，無需手動執行腳本
- 整合到主同步流程中

**缺點**:
- 需要修復損壞的 `yahoo_client.py`
- 會增加 API 呼叫次數（每個產品需額外呼叫 Listing API）

## 當前狀態

- ✅ 獨立過濾腳本已建立
- ⚠️ `yahoo_client.py` 檔案損壞，需要修復
- ✅ V4.2 可執行檔案仍可正常使用

## 建議

1. **短期**: 使用 `filter_expired_yahoo_products.py` 來識別已過期的產品
2. **中期**: 修復 `yahoo_client.py` 並整合 endTs 過濾邏輯
3. **長期**: 考慮在資料庫中記錄 endTs，避免重複 API 呼叫

## 技術細節

**Listing API 欄位**:
```json
{
  "endTs": "2035-11-30T03:55:59Z",  // 結束時間（UTC）
  "startTs": "...",                  // 開始時間
  "status": "normal"                 // 狀態
}
```

**過期判斷邏輯**:
```python
from datetime import datetime

end_time = datetime.strptime(endTs, "%Y-%m-%dT%H:%M:%SZ")
current_time = datetime.utcnow()

if end_time < current_time:
    # 已過期，不納入庫存
    pass
```

## 相關檔案

- `filter_expired_yahoo_products.py` - 獨立過濾腳本
- `src/yahoo_client.py` - Yahoo API 客戶端（目前損壞）
- `dist/InventorySync/InventorySync.exe` - V4.2 可執行檔案
- `dist/yahoo_expired_products.json` - 過濾結果輸出

## 下一步

1. 執行 `filter_expired_yahoo_products.py` 查看有多少產品已過期
2. 根據結果決定是否需要整合到主程式
3. 如果需要整合，先修復 `yahoo_client.py`
