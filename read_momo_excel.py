import pandas as pd

try:
    df = pd.read_excel('momo_api_fields.xlsx')
    print("Columns:", df.columns.tolist())
    print("\nFirst 10 rows of relevant columns:")
    # Check if columns exist before printing
    cols_to_print = []
    if 'entp_goods_no' in df.columns:
        cols_to_print.append('entp_goods_no')
    if 'goodsdt_info' in df.columns:
        cols_to_print.append('goodsdt_info')
    
    if cols_to_print:
        print(df[cols_to_print].head(10))
    else:
        print("Relevant columns not found.")
        print(df.head())
except Exception as e:
    print(f"Error reading excel: {e}")
