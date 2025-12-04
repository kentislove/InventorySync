# Stock_Comparison 問題分析與解決方案

## 📊 問題確認

根據您提供的資料：

### Yahoo_Inventory
```
SKU          | Spec
-------------|------
1810317-34   | /44
1810317-34   | /46
```

### MOMO_Inventory
```
SKU          | Spec
-------------|------
1810317-34   | /44
1810317-34   | /46
```

### Stock_Comparison（錯誤）
```
SKU          | Name (Yahoo)    | Spec | Y | M
-------------|-----------------|------|---|---
1810317-34   | HERNO...外套-44 | /44  | 1 | 0
1810317-34   | HERNO...ELISA   | /46  | 0 | 1
```

## 🔍 問題分析

**現象**：
- Yahoo 和 MOMO 的 Spec 格式一致（都是 `/44` 和 `/46`）
- 但 Stock_Comparison 顯示**兩筆分開的記錄**
- 第一筆用 Yahoo 的產品名稱，只有 Yahoo 數量
- 第二筆用 MOMO 的產品名稱，只有 MOMO 數量

**結論**：
這表示在**資料庫層級**，Yahoo 和 MOMO 的 `spec_name` **實際上不同**，即使在 Excel 中顯示相同！

## 🐛 根本原因

問題出在 `sync_manager.py` 第 96-99 行：

```python
# Get spec_name from extra_data
spec_name = extra_data.get('spec_name', '')
if not spec_name and row[1] == 'MOMO':
    spec_name = extra_data.get('goodsdt_info', '')
```

這段程式碼的邏輯是：
1. 先嘗試從 `extra_data['spec_name']` 取得 spec
2. 如果沒有，且平台是 MOMO，則從 `goodsdt_info` 取得

**問題**：
- Yahoo 的 `extra_data['spec_name']` = `/44`
- MOMO 的 `extra_data['spec_name']` 可能是空的或不同的值
- 所以程式會從 `goodsdt_info` 取得，但這個值可能是 `44`（沒有斜線）

## ✅ 解決方案

修改 `sync_manager.py` 第 96-99 行，統一處理 MOMO 的 spec：

```python
# Get spec_name from extra_data
spec_name = extra_data.get('spec_name', '')

# 如果 spec_name 是空的，嘗試從其他欄位取得
if not spec_name:
    if row[1] == 'MOMO':
        spec_name = extra_data.get('goodsdt_info', '')
        # 確保有斜線前綴
        if spec_name and not spec_name.startswith('/'):
            spec_name = f"/{spec_name}"
```

**或者更簡單的方法**：

在儲存到資料庫時就確保格式一致。修改 `sync_manager.py` 第 62-64 行：

```python
# Ensure name and spec_name are in extra_data for easy retrieval later
extra_data['name'] = item.get('name')
extra_data['spec_name'] = item.get('spec_name', '')

# 統一 spec_name 格式
if extra_data['spec_name'] and not extra_data['spec_name'].startswith('/'):
    extra_data['spec_name'] = f"/{extra_data['spec_name']}"
```

## 🔧 修正步驟

1. 修改 `src/sync_manager.py` 第 62-64 行（加入格式統一邏輯）
2. 重新打包 V5.3
3. **刪除舊的 inventory.db**
4. 重新執行同步

## 📝 預期結果

修正後，Stock_Comparison 應該顯示：

```
SKU          | Spec | Yahoo Qty | MOMO Qty | Status
-------------|------|-----------|----------|--------
1810317-34   | /44  | 1         | 1        | 一致
1810317-34   | /46  | 1         | 1        | 一致
```

只有 **2 筆記錄**，每筆都包含兩個平台的數量。
