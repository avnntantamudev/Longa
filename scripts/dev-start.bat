@echo off
REM Arranca o ambiente local da LONGA no Windows/Laragon (cada processo numa janela).
REM Requisito: MySQL ligado no Laragon. O Redis é arrancado aqui se ainda não estiver a correr.
cd /d "%~dp0.."

set REDIS=C:\laragon\bin\redis\redis-x64-5.0.14.1
"%REDIS%\redis-cli.exe" -h 127.0.0.1 -p 6379 ping >nul 2>&1
if errorlevel 1 (
    echo A arrancar o Redis...
    start "LONGA Redis" "%REDIS%\redis-server.exe" --port 6379 --bind 127.0.0.1
) else (
    echo Redis ja esta a correr.
)

start "LONGA Web" cmd /k php artisan serve --host=0.0.0.0 --port=8000
start "LONGA Fila" cmd /k php artisan queue:work --queue=payments,notifications,default --sleep=1 --tries=3
start "LONGA Agendador" cmd /k php artisan schedule:work
start "LONGA Tempo real" cmd /k php artisan reverb:start --host=0.0.0.0 --port=8081

echo.
echo LONGA a correr: http://localhost:8000  (telemovel na mesma rede: http://IP-do-PC:8000)
echo Feche as janelas para parar.
