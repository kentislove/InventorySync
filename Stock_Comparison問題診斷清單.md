# Stock_Comparison 問題診斷清單

## 🔍 需要確認的資訊

為了準確診斷 Stock_Comparison 顯示錯誤的問題，請提供以下資訊：

### 1. MOMO_Inventory 工作表
查看 SKU `1810317-34` 的記錄：

```
SKU          | Name                    | Spec    | Quantity
-------------|-------------------------|---------|----------
1810317-34   | 【HERNO】ELISA...      | ???     | 1
1810317-34   | 【HERNO】ELISA...      | ???     | 1
```

**問題**：Spec 欄位的值是什麼？
- [ ] `/44` 和 `/46`（有斜線）
- [ ] `44` 和 `46`（沒有斜線）
- [ ] 其他：__________

### 2. Yahoo_Inventory 工作表
查看 SKU `1810317-34` 的記錄：

```
SKU          | Name                    | Spec    | Quantity
-------------|-------------------------|---------|----------
1810317-34   | HERNO...外套-44        | ???     | 1
1810317-34   | HERNO...外套-46        | ???     | 1
```

**問題**：Spec 欄位的值是什麼？
- [ ] `/44` 和 `/46`（有斜線）
- [ ] `44` 和 `46`（沒有斜線）
- [ ] 其他：__________

### 3. Stock_Comparison 工作表
查看 SKU `1810317-34` 的記錄：

**問題**：有幾筆記錄？
- [ ] 2 筆（目前的情況）
- [ ] 4 筆
- [ ] 其他：__________

每筆記錄的詳細資訊：

```
記錄 1:
- SKU: 1810317-34
- Name: ___________
- Spec: ___________
- Yahoo Qty: ___________
- MOMO Qty: ___________

記錄 2:
- SKU: 1810317-34
- Name: ___________
- Spec: ___________
- Yahoo Qty: ___________
- MOMO Qty: ___________
```

### 4. 資料庫檢查（可選）
如果可以，請執行以下命令並提供結果：

```bash
python -c "import sqlite3, json; conn = sqlite3.connect('inventory.db'); c = conn.cursor(); c.execute('SELECT sku, platform, quantity, extra_data FROM inventory WHERE sku=\"1810317-34\"'); rows = c.fetchall(); [print(f'{r[1]:8} | sku={r[0]} | qty={r[2]} | spec={json.loads(r[3]).get(\"spec_name\", \"\")}') for r in rows]"
```

## 📊 預期結果 vs 實際結果

### 預期（正確）
Stock_Comparison 應該有 **2 筆記錄**：

```
SKU          | Spec | Yahoo Qty | MOMO Qty
-------------|------|-----------|----------
1810317-34   | /44  | 1         | 1
1810317-34   | /46  | 1         | 1
```

### 實際（錯誤）
Stock_Comparison 有 **2 筆記錄**，但數量分開：

```
SKU          | Spec | Yahoo Qty | MOMO Qty
-------------|------|-----------|----------
1810317-34   | /44  | 1         | 0
1810317-34   | /46  | 0         | 1
```

## 🎯 可能的原因

1. **Spec 格式不一致**
   - Yahoo: `/44`
   - MOMO: `44`（沒有斜線）
   - 導致 composite_key 不匹配

2. **資料庫中的 spec_name 不一致**
   - 即使 Excel 顯示一致，資料庫中可能不一致

3. **比對邏輯錯誤**
   - 程式碼在填充數量時有 bug

---

**請填寫上述資訊後回覆，我會根據實際情況提供解決方案。**
