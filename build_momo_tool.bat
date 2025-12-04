@echo off
echo Cleaning up previous builds...
if exist "build\MomoFieldFetcher" rd /s /q "build\MomoFieldFetcher"
if exist "dist\MomoFieldFetcher.exe" del /q "dist\MomoFieldFetcher.exe"

REM Build the executable
echo Building MomoFieldFetcher...
pyinstaller --noconfirm --onefile --console --name "MomoFieldFetcher" --paths "src" --add-data "config;config" --hidden-import "babel.numbers" --hidden-import "cryptography" --hidden-import "cryptography.hazmat.backends.openssl.backend" src/test_momo_fields.py

echo Build complete!
echo Please check the 'dist' folder for MomoFieldFetcher.exe
pause
