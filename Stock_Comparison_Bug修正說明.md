# Stock Comparison Bug 修正說明

## 🐛 問題描述

**SKU**: `1810317-65`

### 實際資料
- **Yahoo**: `1810317-65` + `/44` = 2 個
- **MOMO**: `1810317-65` + `/44` = 1 個

### 錯誤顯示（Stock_Comparison 工作表）
- 顯示為: `1810317-65` + `/40` ← **錯誤的規格！**

---

## 🔍 根本原因

### 錯誤的分組邏輯
```python
# 目前的錯誤邏輯（sync_manager.py 第 89-96 行）
product_map = {}
for row in all_products:
    sku = row[0]  # ← 只用 Part No 分組
    if sku not in product_map:
        product_map[sku] = []
    product_map[sku].append(row)
```

**問題**:
- 同一個 Part No (`1810317-65`) 有多個規格 (`/40`, `/44`, `/46` 等)
- 只用 Part No 分組會把所有規格混在一起
- 然後只取第一個找到的規格，導致錯誤

### 範例說明
```
Part No: 1810317-65
├── /40 (Yahoo: 1, MOMO: 0)
├── /44 (Yahoo: 2, MOMO: 1)  ← 應該比對這個
└── /46 (Yahoo: 1, MOMO: 1)

錯誤邏輯：
1. 把所有規格放在一起
2. 隨機取第一個規格 (/40)
3. 用錯誤的規格進行比對
```

---

## ✅ 正確的修正方案

### 修正後的分組邏輯
```python
# 正確的邏輯
product_map = {}
for row in all_products:
    sku = row[0]
    extra_data = row[4]
    
    # 取得規格
    spec_name = extra_data.get('spec_name', '')
    if not spec_name and row[1] == 'MOMO':
        spec_name = extra_data.get('goodsdt_info', '')
    
    # 使用複合鍵：Part No + Spec
    composite_key = f"{sku}_{spec_name}"  # ← 關鍵修正
    
    if composite_key not in product_map:
        product_map[composite_key] = []
    product_map[composite_key].append(row)
```

### 修正後的比對
```
Part No + Spec 作為唯一鍵：
├── 1810317-65_/40 (Yahoo: 1, MOMO: 0)
├── 1810317-65_/44 (Yahoo: 2, MOMO: 1)  ← 正確比對
└── 1810317-65_/46 (Yahoo: 1, MOMO: 1)

每個規格獨立比對，不會混淆！
```

---

## 📝 需要修改的檔案

### 1. src/sync_manager.py
**位置**: 第 88-137 行

**修改內容**:
```python
# 修改前（第 89-96 行）
product_map = {}
for row in all_products:
    sku = row[0]
    if sku not in product_map:
        product_map[sku] = []
    product_map[sku].append(row)

# 修改後
product_map = {}
for row in all_products:
    sku = row[0]
    extra_data = row[4]
    
    # Get spec_name from extra_data
    spec_name = extra_data.get('spec_name', '')
    if not spec_name and row[1] == 'MOMO':
        spec_name = extra_data.get('goodsdt_info', '')
    
    # Create composite key: Part No + Spec
    composite_key = f"{sku}_{spec_name}"
    
    if composite_key not in product_map:
        product_map[composite_key] = []
    product_map[composite_key].append(row)
```

**同時修改第 104 行**:
```python
# 修改前
for sku, records in product_map.items():

# 修改後
for composite_key, records in product_map.items():
```

**修改第 112-122 行（提取 spec_name 的邏輯）**:
```python
# 修改前（複雜且容易出錯）
spec_name = "Unknown"
for r in records:
    if r[4].get('spec_name'):
        spec_name = r[4].get('spec_name')
        break
    if r[1] == 'MOMO' and r[4].get('goodsdt_info'):
        spec_name = r[4].get('goodsdt_info')
        break

# 修改後（直接從第一筆記錄取得）
sku = records[0][0]
spec_name = records[0][4].get('spec_name', '')
if not spec_name and records[0][1] == 'MOMO':
    spec_name = records[0][4].get('goodsdt_info', '')
if not spec_name:
    spec_name = "Unknown"
```

### 2. src/sync_manager_v5.py
**同樣的修正**（如果 V5 使用的是複製的 sync_manager.py）

---

## 🔧 修正步驟

### 方法 1：手動修改（推薦）
1. 開啟 `src/sync_manager.py`
2. 找到第 88-137 行
3. 按照上述說明修改程式碼
4. 重新打包

### 方法 2：使用修正後的檔案
我可以為您建立完整的修正後檔案

---

## 📊 修正後的預期結果

### Stock_Comparison 工作表
```
SKU          | Name                    | Spec | Yahoo Qty | MOMO Qty | Status
-------------|-------------------------|------|-----------|----------|--------
1810317-65   | HERNO 羽絨外套-40       | /40  | 1         | 0        | 不一致
1810317-65   | HERNO 羽絨外套-44       | /44  | 2         | 1        | 不一致
1810317-65   | HERNO 羽絨外套-46       | /46  | 1         | 1        | 一致
```

每個規格都會有獨立的比對記錄！

---

## ⚠️ 影響範圍

### 受影響的功能
1. ✅ Stock_Comparison 工作表 - 會正確顯示每個規格
2. ✅ 庫存同步邏輯 - 會正確同步每個規格的數量
3. ✅ Dashboard 比對矩陣 - 會正確顯示

### 不受影響的功能
- ✅ Yahoo_Inventory 工作表
- ✅ MOMO_Inventory 工作表
- ✅ PChome_Inventory 工作表
- ✅ Y&M_庫存比對 工作表（V5 專用，已使用正確邏輯）

---

## 🎯 建議

1. **立即修正**: 這是一個嚴重的 bug，會導致錯誤的庫存同步
2. **測試驗證**: 修正後重新執行同步，檢查 `1810317-65` 的比對結果
3. **版本更新**: 建議發布 V5.1 修正版本

---

**文件日期**: 2025-12-03  
**問題嚴重度**: 🔴 高（影響資料準確性）  
**修正優先級**: ⚡ 緊急
