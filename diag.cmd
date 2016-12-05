@echo off

echo This script collects all the information about your Acquia DevDesktop installation
echo that may be useful for troubleshooting. Output file is %HOMEDRIVE%%HOMEPATH%\acquia_dd_diag.zip
echo.

"%~dp0php5_4\php.exe" -n "%~dp0common\setup\setup.php" diag %1
