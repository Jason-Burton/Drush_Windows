@echo off
SET PATH=D:\DevDesktop\apache\bin;%PATH%
"D:\DevDesktop\php5_4\php.exe" -n -d"extension_dir=\"D:\DevDesktop\php5_4\ext\"" -d"extension=php_mysql.dll" %*