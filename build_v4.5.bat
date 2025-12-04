@echo off
REM ========================================
REM 三平台API同步系統 V4.5 打包腳本
REM 獨立版本 - 不與其他版本混合
REM ========================================

echo ========================================
echo 開始打包 V4.5 獨立版本...
echo ========================================
echo.

REM 檢查 PyInstaller 是否已安裝
echo [1/5] 檢查 PyInstaller...
python -c "import PyInstaller" 2>nul
if errorlevel 1 (
    echo PyInstaller 未安裝,正在安裝...
    pip install pyinstaller
) else (
    echo PyInstaller 已安裝
)
echo.

REM 清理舊的 V4.5 打包檔案
echo [2/5] 清理舊的 V4.5 打包檔案...
if exist "dist\InventorySync_v4.5" (
    echo 刪除舊的 dist\InventorySync_v4.5 資料夾...
    rmdir /s /q "dist\InventorySync_v4.5"
)
if exist "build\InventorySync_v4.5" (
    echo 刪除舊的 build\InventorySync_v4.5 資料夾...
    rmdir /s /q "build\InventorySync_v4.5"
)
echo.

REM 執行打包
echo [3/5] 執行 PyInstaller 打包 (使用 V4.5 專用配置)...
pyinstaller InventorySync_v4.5.spec --clean
if errorlevel 1 (
    echo.
    echo ========================================
    echo 打包失敗!請檢查錯誤訊息
    echo ========================================
    pause
    exit /b 1
)
echo.

REM 創建版本資訊檔案
echo [4/5] 創建版本資訊...
echo V4.5 - 2025-12-02 > "dist\InventorySync_v4.5\VERSION.txt"
echo 三平台庫存同步系統 >> "dist\InventorySync_v4.5\VERSION.txt"
echo. >> "dist\InventorySync_v4.5\VERSION.txt"
echo 新增功能: >> "dist\InventorySync_v4.5\VERSION.txt"
echo - Yahoo 過期產品過濾 (基於 endTs 時間戳) >> "dist\InventorySync_v4.5\VERSION.txt"
echo - 自動過濾已過期商品,改善資料品質 >> "dist\InventorySync_v4.5\VERSION.txt"
echo - 新增產品檢查工具 >> "dist\InventorySync_v4.5\VERSION.txt"
echo. >> "dist\InventorySync_v4.5\VERSION.txt"
echo 包含檔案: >> "dist\InventorySync_v4.5\VERSION.txt"
echo - config 資料夾 (配置檔案) >> "dist\InventorySync_v4.5\VERSION.txt"
echo - size.xlsx (規格尺寸對照表) >> "dist\InventorySync_v4.5\VERSION.txt"
echo. >> "dist\InventorySync_v4.5\VERSION.txt"
echo 請確保 config/credentials.json 已正確配置 >> "dist\InventorySync_v4.5\VERSION.txt"

REM 複製說明文件
echo [5/5] 複製說明文件...
copy "V4.5_打包說明.md" "dist\InventorySync_v4.5\使用說明.md" >nul
copy "README.txt" "dist\InventorySync_v4.5\更新日誌.txt" >nul

echo.
echo ========================================
echo V4.5 版本打包完成!
echo ========================================
echo.
echo 輸出位置: dist\InventorySync_v4.5\
echo 執行檔: dist\InventorySync_v4.5\InventorySync_v4.5.exe
echo.
echo 資料夾內容:
dir /b "dist\InventorySync_v4.5"
echo.
echo 注意事項:
echo 1. 此為 V4.5 獨立版本,不會影響其他版本
echo 2. 請確保 config 資料夾中的 credentials.json 已正確配置
echo 3. size.xlsx 檔案已包含在打包中
echo 4. 首次執行時會在同目錄下創建 sync.log 日誌檔案
echo.
pause
