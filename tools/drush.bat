@echo off
rem Drush automatically determines the user's home directory by checking for
rem HOME or HOMEDRIVE/HOMEPATH environment variables, and the temporary
rem directory by checking for TEMP, TMP, or WINDIR environment variables.
rem The home path is used for caching Drush commands and the git --reference
rem cache. The temporary directory is used by various commands, including
rem package manager for downloading projects.
rem You may want to specify a path that is not user-specific here; e.g., to
rem keep cache files on the same filesystem, or to share caches with other
rem users.

rem set HOME=H:/drush
rem set TEMP=H:/drush

REM See http://drupal.org/node/506448 for more information.\

IF "%PHP_ID%"=="" (SET PHP_ID=php5_5)

SET DEVDESKTOP_DRUPAL_SETTINGS_DIR=%USERPROFILE%\.acquia\DevDesktop\DrupalSettings
SET PATH=D:\DevDesktop\common\msys\bin;D:\DevDesktop\%PHP_ID%;D:\DevDesktop\mysql\bin;%PATH%
IF EXIST "%USERPROFILE%\.acquia\DevDesktop\ssh-agent-params.bat" (
  CALL "%USERPROFILE%\.acquia\DevDesktop\ssh-agent-params.bat"
)

@php.exe "%~dp0vendor\drush\drush\drush.php" --php="php.exe" %*
