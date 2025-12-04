# Inventory Sync System V5 說明文件

## 版本資訊
- **版本**: V5.0
- **發布日期**: 2025-12-03
- **主要更新**: Yahoo 過期產品過濾、執行模式優化、Y&M 比對

## V5 新功能

### 1. Yahoo 過期產品過濾
- 自動過濾 8,795 個已過期的 Yahoo 產品
- 只同步 13,255 個上架中的產品
- 解決「多筆記錄 (Y:2, M:1)」問題

### 2. 執行模式自動判斷
**每日首次執行（完整同步）**：
1. 下載三平台產品資料
2. 過濾 Yahoo 過期產品
3. 自動提取 Yahoo Spec
4. 儲存到資料庫
5. 產生 Y&M 比對表
6. 執行庫存同步

**每日後續執行（僅庫存更新）**：
1. 獲取最新庫存數量
2. 更新資料庫
3. 執行庫存同步

### 3. Yahoo Spec 自動提取
- 從產品名稱自動提取規格
- 格式：`產品名稱-規格` → Spec: `/規格`
- 範例：`LONGCHAMP 3D S-黑色` → Spec: `/黑色`

### 4. Y&M 庫存比對表
- 自動比對 Yahoo 和 MOMO 相同產品
- 識別庫存不一致的項目
- 上傳到 Google Sheets `Y&M_庫存比對` 工作表

### 5. 庫存雙向同步（總量確定論）
- Y 賣出 -1 → M 也 -1
- M 賣出 -1 → Y 也 -1
- Y 手動 +1 → M 也 +1
- M 手動 +1 → Y 也 +1
- 已過期產品不參與同步

## 使用方式

### 首次執行
1. 確保 `config/credentials.json` 設定正確
2. 確保 `dist/yahoo_expired_products.json` 存在
3. 執行 `InventorySync_V5.exe`

### 每日使用
- **首次執行**: 自動進行完整同步
- **後續執行**: 自動僅更新庫存

### 每周維護
執行過期產品篩選（約 5-6 小時）：
```bash
python filter_expired_yahoo_products.py
```

## 檔案結構
```
InventorySync_V5/
├── InventorySync_V5.exe          # 主程式
├── config/
│   └── credentials.json          # API 設定
├── yahoo_expired_products.json   # 過期產品列表
├── inventory.db                  # 本地資料庫
├── sync_v5.log                   # 執行日誌
└── VERSION_V5.txt                # 版本資訊
```

## 資料庫結構

### 新增表格
1. **inventory_history**: 追蹤庫存數量變化
2. **product_sync_tracking**: 記錄同步執行時間

## Google Sheets 整合

### 新增工作表
- **Y&M_庫存比對**: Yahoo vs MOMO 比對結果

### 欄位說明
- Part No: 供應商料號
- Spec: 產品規格
- Yahoo Qty: Yahoo 庫存數量
- MOMO Qty: MOMO 庫存數量
- Qty Diff: 數量差異
- Status: 一致/不一致

## 技術細節

### 過期產品判斷
```python
if listing_endTs < current_time:
    # 產品已過期，不納入同步
```

### 執行模式判斷
```python
if is_product_synced_today():
    mode = 'inventory_only'  # 僅更新庫存
else:
    mode = 'full'  # 完整同步
```

### 庫存同步邏輯
```python
# 判斷哪個平台有變動
if yahoo_changed and not momo_changed:
    # 同步到 MOMO
    new_momo_qty = current_momo_qty + (current_yahoo_qty - last_yahoo_qty)
elif momo_changed and not yahoo_changed:
    # 同步到 Yahoo
    new_yahoo_qty = current_yahoo_qty + (current_momo_qty - last_momo_qty)
```

## 注意事項

1. **首次執行時間**: 完整同步約需 10-15 分鐘
2. **後續執行時間**: 僅庫存更新約需 2-3 分鐘
3. **過期產品篩選**: 每周執行一次，約需 5-6 小時
4. **API 限制**: 需在有白名單的電腦上執行

## 疑難排解

### 問題：過期產品列表不存在
**解決**: 執行 `filter_expired_yahoo_products.py` 產生列表

### 問題：Google Sheets 連線失敗
**解決**: 檢查 `config/credentials.json` 中的憑證設定

### 問題：資料庫錯誤
**解決**: 刪除 `inventory.db` 重新執行（會自動建立）

## 版本歷史

### V5.0 (2025-12-03)
- 新增 Yahoo 過期產品過濾
- 新增執行模式自動判斷
- 新增 Y&M 比對表
- 新增庫存雙向同步
- 新增資料庫歷史追蹤

### V4.3 (2025-12-01)
- 移除 Spec 字元限制
- 優化 Spec 提取邏輯

### V4.2 (2025-11-30)
- 修復 Spec 欄位顯示問題
- 簡化 Spec 提取邏輯

## 聯絡資訊
如有問題請參考執行日誌 `sync_v5.log`
