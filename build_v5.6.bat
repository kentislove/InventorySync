@echo off
echo Cleaning up previous builds...
if exist "build\InventorySync_V5.6" rd /s /q "build\InventorySync_V5.6"
if exist "dist\InventorySync_V5.6.exe" del /q "dist\InventorySync_V5.6.exe"

REM Build the executable
echo Building InventorySync_V5.6...
pyinstaller --noconfirm --onefile --console --name "InventorySync_V5.6" --paths "src" --add-data "config;config" --hidden-import "babel.numbers" --hidden-import "cryptography" --hidden-import "cryptography.hazmat.backends.openssl.backend" run_v5.py

echo Build complete!
echo Please check the 'dist' folder for InventorySync_V5.6.exe
pause
