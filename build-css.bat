@echo off
echo [Tailwind CSS Derleyici] dist/style.css olusturuluyor...
tools\tailwindcss.exe -i src\input.css -o dist\style.css -m
if %ERRORLEVEL% EQU 0 (
    echo [Basarili] dist/style.css basariyla derlendi ve optimize edildi!
) else (
    echo [Hata] Derleme sirasinda bir hata olustu.
)
pause
