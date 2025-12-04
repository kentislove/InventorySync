多平台庫存同步系統 - 更新日誌

[V5.6] - 2025-12-05
- 新增功能: MOMO 完整邏輯升級
  - 狀態過濾: 僅同步「進行」與「暫時中斷」的產品，自動過濾「永久中斷」。
  - SKU 格式化: 
    - 基礎 SKU 使用原廠料號 (entp_goods_no)。
    - 若規格含 "/"，則 SKU = entp_goods_no + "/" + 規格後綴 (剔除中文字)。
  - 庫存擷取: 針對篩選後的產品，自動批次呼叫庫存 API 取得數量。
  - 工具: 發布 MomoFieldFetcher.exe 獨立測試工具。

[V5.5] - 2025-12-04
- 新增功能: Yahoo SKU 規則與過濾
  - SKU 格式化: 若規格名稱含「尺寸」，SKU = partNo + "/" + 規格值 (剔除中文字)。
  - 過濾: 強制檢查 Listing API 的 endTs，過濾已下架 (過期) 產品。
  - 修復: 解決 PyInstaller 封包時的 ModuleNotFoundError。

[V4.5] - 2025-12-02
- 新增功能:Yahoo 過期產品過濾
  - 自動過濾已過期的 Yahoo 商品 (基於 endTs 時間戳)
  - 新增 filter_expired_yahoo_products.py 工具腳本
  - 新增 yahoo_product_inspector.py 產品檢查工具
  - 新增 yahoo_product_inspector_enhanced.py 增強版檢查工具
  - 改善資料品質:僅同步有效期內的商品
- 優化:改善 Yahoo API 資料處理邏輯
- 文件:新增 YAHOO_過期產品過濾說明.md

[V3.0] - 2025-12-01
- 新增功能：Yahoo 產品規格擷取
  - 讀取 `SIZE.xlsx` 檔案，自動從產品名稱中擷取尺寸資訊。
  - 將擷取到的尺寸填入規格 (Spec) 欄位。
  - 支援長度優先比對 (例如優先比對 "100CM" 而非 "100")。

[V2.0] - 2025-12-01
- 新增功能：零庫存過濾
  - 修改 Yahoo Client：排除庫存為 0 的商品。
  - 修改 PChome Client：排除庫存為 0 的商品。
  - 修改 MOMO Client：排除庫存為 0 的商品。
  - 結果：資料庫與 Google Sheets 現在僅包含有效庫存商品。

[V1.0] - 2025-12-01
- 初始穩定版本
- 功能：
  - 多平台支援：Yahoo、PChome、MOMO。
  - Google Sheets 整合：
    - 各平台獨立分頁。
    - "Stock_Comparison" (庫存對照表) 分頁，用於並排比對 SKU。
  - PChome 修正：
    - 實作兩階段抓取 (列表 -> 詳情) 以解決品名/規格缺失問題。
    - 對應 "原廠料號" (VendorPId) 至 SKU。
  - MOMO 修正：
    - 修正分頁邏輯。
    - 對應 goodsdt_info 至規格 (Spec)。
