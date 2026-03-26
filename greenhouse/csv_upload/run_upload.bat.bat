@echo off
echo ======================================== >> C:\wamp64\www\greenhouse\csv_upload\upload_cron.log
echo Run at: %date% %time% >> C:\wamp64\www\greenhouse\csv_upload\upload_cron.log
echo ======================================== >> C:\wamp64\www\greenhouse\csv_upload\upload_cron.log

cd /d C:\wamp64\www\greenhouse\csv_upload

REM Find your PHP version - change this to match your WAMP PHP version
REM Check C:\wamp64\bin\php\ to see the folder name
C:\wamp64\bin\php\php8.3.14\php.exe csv_uploader.php --last-24h >> upload_cron.log 2>&1

echo. >> C:\wamp64\www\greenhouse\csv_upload\upload_cron.log