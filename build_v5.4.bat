@echo off
echo Starting Build Process for Inventory Sync System V5.4...

REM Clean previous builds - SKIPPED as per user request
REM if exist "build" rmdir /s /q "build"
REM if exist "dist" rmdir /s /q "dist"

REM Build the executable
echo Building executable...
pyinstaller --noconfirm --onefile --windowed --name "InventorySync_V5.5" --paths "src" --add-data "config;config" --hidden-import "babel.numbers" --hidden-import "cryptography" --hidden-import "cryptography.hazmat.backends.openssl.backend" run_v5.py

echo Build complete!
echo Please check the 'dist' folder for InventorySync_V5.4.exe
pause
