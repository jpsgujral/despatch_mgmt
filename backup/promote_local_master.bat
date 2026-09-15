@echo off
setlocal

set "MYSQL_EXE="
if exist "D:\xampp\mysql\bin\mysql.exe" set "MYSQL_EXE=D:\xampp\mysql\bin\mysql.exe"
if not defined MYSQL_EXE if exist "C:\xampp\mysql\bin\mysql.exe" set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"

if not defined MYSQL_EXE (
    echo MySQL CLI was not found. Edit this file with the correct mysql.exe path.
    exit /b 1
)

echo Promoting local database to temporary master...
echo This stops replication, then turns read-only mode off.
echo.

"%MYSQL_EXE%" -u root -e "STOP SLAVE; SET GLOBAL read_only=OFF; SET GLOBAL super_read_only=OFF; SHOW VARIABLES LIKE 'read_only'; SHOW VARIABLES LIKE 'super_read_only';"

if %ERRORLEVEL% NEQ 0 (
    echo.
    echo Promotion command failed. If your MySQL root user has a password, edit this file and add -pYOUR_PASSWORD after -u root.
    exit /b %ERRORLEVEL%
)

echo.
echo Local database is now writable. Use local server only until web is rebuilt from local backup.
