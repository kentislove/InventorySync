@echo off
echo ========================================
echo Building Inventory Sync V5
echo ========================================

REM 清理舊的 build 和 dist 資料夾
echo Cleaning old build files...
if exist build rmdir /s /q build
if exist dist\InventorySync_V5.4 rmdir /s /q dist\InventorySync_V5.4

REM 使用 PyInstaller 打包
echo Building executable...
pyinstaller --name=InventorySync_V5.4 ^
    --onedir ^
    --console ^
    --add-data="src;src" ^
    --add-data="config;config" ^
    --add-data="dashboard;dashboard" ^
    --add-data="dist/yahoo_expired_products.json;." ^
    --hidden-import=gspread ^
    --hidden-import=oauth2client ^
    --hidden-import=pandas ^
    --hidden-import=openpyxl ^
    --hidden-import=cryptography ^
    --hidden-import=cryptography.hazmat.primitives.padding ^
    --hidden-import=cryptography.hazmat.primitives.ciphers ^
    --hidden-import=cryptography.hazmat.backends ^
    run_v5.py

REM 複製必要檔案到 dist 資料夾
echo Copying additional files...
xcopy /y config dist\InventorySync_V5.4\config\
xcopy /y /s dashboard dist\InventorySync_V5.4\dashboard\
xcopy /y dist\yahoo_expired_products.json dist\InventorySync_V5.4\
copy /y README_V5.txt dist\InventorySync_V5.4\

REM 建立版本資訊檔案
echo Creating version info...
(
echo Inventory Sync System V5.4
echo ========================================
echo.
echo Version: 5.4
echo Build Date: %date% %time%
echo.
echo Features:
echo - Yahoo expired product filtering
echo - Spec format normalization
echo - Stock_Comparison correct grouping
echo - PyInstaller module import fix
echo.
echo Usage:
echo 1. Delete old inventory.db
echo 2. Run InventorySync_V5.4.exe
echo 3. Check Stock_Comparison results
echo.
) > dist\InventorySync_V5.4\VERSION.txt

echo.
echo ========================================
echo Build Complete!
echo ========================================
echo Output: dist\InventorySync_V5.4\
echo.
pause
