<?php

define('SUCCESS_STRING', 'setupscript_success');
define('FAIL_STRING', 'setupscript_fail');

$_SC_LOG_FILE = NULL;

function _Log($msg) {
  global $_SC_LOG_FILE;

  if (!isset($GLOBALS['LOG_OFF'])) {
    if ($_SC_LOG_FILE == NULL) {
      $SV = SetupVars::get();
      $_SC_LOG_FILE = $SV->INSTALL_DIR . '/piscript.log';
    }

    $args = func_get_args();
    array_shift($args);

    // convert objects to str
    foreach ($args as &$a) {
      if (is_object($a) || is_array($a)) {
        $a = print_r($a, true);
      }
    }

    $str = date('[H:i:s]') . ' ' . (!empty($args) ? @vsprintf($msg, $args) : $msg) . "\n";

    file_put_contents($_SC_LOG_FILE, $str, FILE_APPEND);
  }
}


function acqThrow() {
  $args = func_get_args();
  $msg = array_shift($args);
  $text = !empty($args) ? @vsprintf($msg, $args) : $msg;
  $db = debug_backtrace();
  $caller = $db[1];
  $func = isset($caller['class']) ? "{$caller['class']}::{$caller['function']}()" : "{$caller['function']}()";
  throw new Exception($func . ' ' . $text);
}

function acqFatal($format)
{
  $args = func_get_args();
  array_shift($args);
  fprintf(STDERR, vsprintf($format, $args));
  exit(-1);
}

if (Util::IsWin())
  define ('CONTROLPANEL_EXECUTABLE_SUBPATH', 'AcquiaDevDesktop');
else if (Util::IsOsx())
  define ('CONTROLPANEL_EXECUTABLE_SUBPATH', 'Acquia Dev Desktop.app/Contents/MacOS');

function acqCpPathFromInst($inst)
{
  $cpExec = $inst . (Util::IsWin()
      ? "\\" . CONTROLPANEL_EXECUTABLE_SUBPATH . "\\AcquiaDevDesktop2.exe"
      : "/" . CONTROLPANEL_EXECUTABLE_SUBPATH ."/Acquia Dev Desktop");
  return $cpExec;
}

class SetupVars {
  public $APACHE_DOCROOT;
  public $APACHE_PORT;
  public $APACHE_PORT_HTTPS;
  public $MYSQL_PORT;
  public $DB_USERNAME;
  public $INSTALL_DIR;
  public $INSTALL_DIR_UNIX_FMT;
  public $PLATFORM_NAME;
  public $AD_VER;
  public $SYS_USER;
  public $UPGRADE;
  public $TEMP_DIR;
  public $TIMEZONE;
  public $ENABLE_TRACKING;
  /**
   * @var UpgradeContext
   */
  public $_upgCtx;

  public function LoadSetupVars($file = null) {
    if (! $file)
      $file = dirname(__FILE__) . '/setupvars';
    $v = Util::_ParseIniFile($file);
    foreach ($v['vars'] as $name => $val) {
      $this->$name = $val;
    }

    // normalize
    $this->INSTALL_DIR = Util::NormalizePath($this->INSTALL_DIR);
    $this->APACHE_DOCROOT = Util::NormalizePath($this->APACHE_DOCROOT);

    $this->INSTALL_DIR_UNIX_FMT = $this->INSTALL_DIR;
    if (Util::IsWin()) {
      $this->INSTALL_DIR_UNIX_FMT = str_replace("\\", "/", $this->INSTALL_DIR_UNIX_FMT);
    }
  }

  public function Save($file = null) {
    if (! $file)
      $file = dirname(__FILE__) . '/setupvars';

    $str = "[vars]\n";
    foreach ($this as $n => $v) {
      if (substr($n, 0, 1) != '_')
        $str .= "$n=$v\n";
    }
    file_put_contents($file, $str);
  }

  /**
   * @return SetupVars
   */
  public static function get() {
    if (self::$_inst == null) {
      self::$_inst = new SetupVars();
      self::$_inst->LoadSetupVars();
    }
    return self::$_inst;
  }

  //private function __construct ()
  //{ }



  private static $_inst;
}

class UpgradeContext {
  public $oldCpIniPathS;
  public $oldCpIniPathD;
  public $oldCpIniDM;
  public $oldCpIniReg;

  public function __construct() {
    $sv = SetupVars::get();

    $newCpIniDir = $oldCpIniDir = '';
    $oldCpIniDir = $sv->INSTALL_DIR . '/' . CONTROLPANEL_EXECUTABLE_SUBPATH . '/';

    $this->oldCpIniPathD = $oldCpIniDir . 'dynamic.xml';
    $this->oldCpIniPathS = $oldCpIniDir . 'static.ini';
    $this->oldCpIniDM =    $oldCpIniDir . 'datamodel.xml';
    $this->oldCpIniReg =   $oldCpIniDir . 'reg.ini';
  }

  public function OldCpIniS() {
    return Util::ParseIniFile($this->oldCpIniPathS);
  }

  public function OldCpIniD() {
    return Util::ParseIniFile($this->oldCpIniPathD);
  }


  public function GetOldBuildDate() {
    static $cache = NULL;
    $sv = SetupVars::get();

    if ($cache !== NULL)
      return $cache;

    $date = FALSE;
    $cpExec = acqCpPathFromInst($sv->INSTALL_DIR) . '.bak';

    if (file_exists($cpExec)) {
      $date = self::GetCPBuildDate($cpExec);
    }

    $cache = $date;

    return $date;
  }

  public function GetNewBuildDate() {
    static $cache = NULL;
    $sv = SetupVars::get();

    if ($cache !== NULL)
      return $cache;

    $cpExec = acqCpPathFromInst($sv->INSTALL_DIR);
    $date = self::GetCPBuildDate($cpExec);

    $cache = $date;

    return $date;
  }

  /**
   * @param $path
   * @return DateTime|null
   */
  public static function GetCPBuildDate($path) {
    if (!file_exists($path))
      acqThrow("$path not found");

    $date = null;
    static $MARKER = "acqBUILD_DATE_MARKER";

    $contents = file_get_contents($path);
    $markerPos = strpos($contents, $MARKER);
    if ($markerPos !== FALSE) {
      $dateStr = substr($contents, $markerPos + strlen($MARKER), 11);
      $date = new DateTime($dateStr);
    }

    return $date;
  }
}

define('CBS_FLAG_OVERWRITE',   0x0);
define('CBS_FLAG_BACKUP',      0x1);
define('CBS_FLAG_KEEP_OLD',    0x2);


class Setup {
  //static private  $upgradeMode = false;

  public static function Run() {

    $sv = SetupVars::get();
    $userName = Util::GetRealUser();

    _Log('Post install script started ' . date('m/d/Y'));

    $sv->DB_USERNAME = 'drupaluser';

    $HOME =  $_SERVER[Util::IsWin() ? 'USERPROFILE' : 'HOME'];
    $acquiaDir = $HOME . '/.acquia';

    // Create personal data folder
    $pdDir = $acquiaDir . '/DevDesktop';
    if (!file_exists($pdDir) && !Util::MkdirWPermFix($pdDir, $userName))
      acqThrow("Could not create personal data folder - $pdDir");

    // save install locations
    file_put_contents($pdDir . '/locations.ini', sprintf("[common]\ninstallDir=%s\nsitesDir=%s\n", $sv->INSTALL_DIR, $sv->APACHE_DOCROOT));

    // detect upgrade mode
    $sv->UPGRADE = ($sv->UPGRADE == 'upgrade');
    _Log("Upgrade:" . (int) $sv->UPGRADE);


    if (Util::IsWin())
      $sv->TEMP_DIR = rtrim(sys_get_temp_dir(), "\\");
    else
      $sv->TEMP_DIR = '/tmp';
    $sv->TIMEZONE = date_default_timezone_get();

    // Gather old settings
    if ($sv->UPGRADE) {
      $sv->_upgCtx = new UpgradeContext();

      $oldCpIniD = Util::ParseIniFile($sv->INSTALL_DIR . '/' . CONTROLPANEL_EXECUTABLE_SUBPATH . '/' . 'dynamic.xml');

      $sv->APACHE_PORT = $oldCpIniD['services']['apachePort'];
      $sv->APACHE_PORT_HTTPS = isset($oldCpIniD['services']['apachePortHttps']) ? $oldCpIniD['services']['apachePortHttps'] : 8443;
      $sv->MYSQL_PORT = $oldCpIniD['services']['mysqlPort'];

      $sv->Save(); // just in case...

      self::UpgradeMysql();
    }

    self::InstallAllCfgFromBox();

    // substitute vars in config files
    self::SubstituteConfigVars(); // will be removed soon

    // 
    // Self update pipelines client
    //
    self::PipelinesSelfUpdate();

    //
    // osx permission fix
    //
    if ($sv->PLATFORM_NAME == 'osx') {
      $stdout = $stderr = null;

      $groupName = NULL;
      $fullName = NULL;
      if (OSXUtil::OSUserExists($userName) && ($groupName = OSXUtil::GetOSGroupNameByID(OSXUtil::GetOSUserGroupID($userName))) != null) {
        $fullName = escapeshellarg($userName) . ':' . escapeshellarg($groupName);
      }
      else {
        $fullName = escapeshellarg($userName);
      }

      _Log("Full username detected as $fullName");

      // create docroot
      if (!Util::MkdirWPermFix($sv->APACHE_DOCROOT, $userName, $HOME))
        acqThrow("Unable to create {$sv->APACHE_DOCROOT}");

      // current user and admin group are the owners
      Util::Exec(sprintf("chown -R %s:admin %s", escapeshellarg($userName), escapeshellarg($sv->INSTALL_DIR)), $stdout, $stderr);
      // readwrite for the user and group, nothing for others
      Util::Exec("chmod -R ug+rw,o-rwx " . escapeshellarg($sv->INSTALL_DIR), $stdout, $stderr);
      // user and group can list folders
      Util::Exec(sprintf("find %s -type d -exec chmod ug+x {} \;", escapeshellarg($sv->INSTALL_DIR)), $stdout, $stderr);

      //correct file permissions for personal data folder
      Util::Exec("chown -R $fullName " . escapeshellarg($acquiaDir), $stdout, $stderr);
      Util::Exec("chmod u+w " . escapeshellarg($acquiaDir), $stdout, $stderr);

      //self::CopyToolsToUsrBin();
    }
    else if ($sv->PLATFORM_NAME == 'windows') {

      // create docroot
      if (!file_exists($sv->APACHE_DOCROOT) && !mkdir($sv->APACHE_DOCROOT, 0777, true))
        acqThrow("Unable to create {$sv->APACHE_DOCROOT}");


      // disable some incompatible extensions on winXP
      if (version_compare(Util::GetOSVersion(), '5.1') <= 0) { // XP of earlier
        $inis = array($sv->INSTALL_DIR . '/php5_3/php.ini', $sv->INSTALL_DIR . '/php5_4/php.ini');
        self::DisablePhpExtension($inis[0], 'pdo_sqlsrv_53_ts');
        self::DisablePhpExtension($inis[0], 'sqlsrv_53_ts');
        self::DisablePhpExtension($inis[1], 'pdo_sqlsrv_54_ts');
        self::DisablePhpExtension($inis[1], 'sqlsrv_54_ts');
      }
    }

    if (!$sv->UPGRADE) {
      self::SetupMysql();
    }

    if ($sv->UPGRADE) {
      // Edit configs
      self::SetTrackingInDdCfg($sv->_upgCtx->oldCpIniPathD, $sv->ENABLE_TRACKING);

      // Execute upgrade handlers
      self::ExecuteUpgradeHandlers();
    }

    //
    // Setup XMail
    //
    if (Util::IsWin()) {
      self::SetupXMail();
    }

    //
    // Setup phpize
    //
    if ($sv->PLATFORM_NAME == 'osx') {
      _Log("Prepare PHP extension helper scripts");
      self::SetupUnixPhpize($sv->INSTALL_DIR . '/php5_3');
      self::SetupUnixPhpize($sv->INSTALL_DIR . '/php5_4');
      self::SetupUnixPhpize($sv->INSTALL_DIR . '/php5_5');
      self::SetupUnixPhpize($sv->INSTALL_DIR . '/php5_6');
      self::SetupUnixPhpize($sv->INSTALL_DIR . '/php7_0');
    }

    _Log('Post install script completed successfully');

  }

  public static function SetTrackingInDdCfg($cfgPath, $track) {
    $config = new XmlConfig($cfgPath);
    $dcfg = $config->Query1('/root/dcfg');

    $tracking = $config->Query1('tracking', $dcfg);
    if ($tracking === null)
      $tracking = $dcfg->appendChild($config->NewNode('tracking'));

    $enabled = $config->Query1('enabled', $tracking);
    if ($enabled !== null)
      $tracking->removeChild($enabled);

    $tracking->appendChild($config->NewNode('enabled', $track));
    $config->Save();
  }
/*
  public static function CopyToolsToUsrBin()
  {
    $sv = SetupVars::get();
    $dest = '/usr/bin';
    if (is_dir($dest))
    {
      Util::MoveFile($sv->INSTALL_DIR . '/tools/pipelines', $dest, true);
    }
    else
    {
      _Log("$dest not found");
    }
  }
*/
  public static function PipelinesSelfUpdate()
  {
    _Log("Updating pipelines client");
    $sv = SetupVars::get();
    $stdout = $stderr = '';

    $r = Util::Exec("\"{$sv->INSTALL_DIR}/tools/pipelines\" self-update", $stdout, $stderr);
    if ($r != 0)
      _Log("Failed to update pipelines client");
  }

  public static function UpgradeMysql()
  {
    $sv = SetupVars::get();

    $mysqlInitialDataDir = $sv->INSTALL_DIR . '/mysql/data.init';
    $iblog = $sv->INSTALL_DIR . '/mysql/data/ib_logfile';
    $mysqlDataDir = $sv->INSTALL_DIR . '/mysql/data';
    assert($sv->UPGRADE);
    // We are in upgrade mode so we don't need the initial blank mysql database
    if (is_dir($mysqlInitialDataDir)) {
      _Log("Deleting $mysqlInitialDataDir");
      Util::RmDir($mysqlInitialDataDir);
    }

    try {
      try {
        self::StartMySQL();
      }
      catch (Exception $e){
        _Log('UpgradeMysql(): Initial MySQL start failed. Will remove logs and try again.');
        if (file_exists($iblog . '0'))
          unlink($iblog . '0');
        if (file_exists($iblog . '1'))
          unlink($iblog . '1');
        self::StartMySQL();
      }
      
      self::UpgradeMySQLDatabase($sv);
    }
    catch (Exception $e) {
      _Log('An error caught. Stopping MySQL. ');
      // we don't care about exceptions here
      try {
        self::StopMySQL();
      }
      catch (Exception $__e) {
      }

      throw $e;
    }

    self::StopMySQL(true); // gracefully shutdown mysql

    sleep(3); // just in case...

    // erase log files
    if (file_exists($iblog . '0')) {
      _Log("Deleteing " . $iblog . '0');
      if (!unlink($iblog . '0'))
        _Log("Failed to delete file. err=", print_r(error_get_last(), false));
    }
    if (file_exists($iblog . '1'))
      _Log("Deleteing " . $iblog . '1');
      if (!unlink($iblog . '1'))
       _Log("Failed to delete file. err=", print_r(error_get_last(), false));
  }

  private static function SetupMysql()
  {
    $sv = SetupVars::get();
    $mysqlInitialDataDir = $sv->INSTALL_DIR . '/mysql/data.init';
    $mysqlDataDir = $sv->INSTALL_DIR . '/mysql/data';

    _Log("Renaming $mysqlInitialDataDir to $mysqlDataDir");
    if (!rename($mysqlInitialDataDir, $mysqlDataDir))
      acqThrow("Could not rename initial MySQL data dir");
    chmod($sv->INSTALL_DIR . '/mysql/data/mysql', 0775);
  }

  public static function UpgradeMySQLDatabase(SetupVars $sv)
  {
    // call mysqld_upgrade
    $stdout = null;
    $mysql_upgrade = null;

    if (Util::IsOSX())
      $mysql_upgrade = './mysql_upgrade';
    else if (Util::IsWin())
      $mysql_upgrade = 'mysql_upgrade.exe';
    Util::ExecC("$mysql_upgrade --protocol=tcp -h127.0.0.1 -P{$sv->MYSQL_PORT} -uroot --force", $stdout, $sv->INSTALL_DIR . '/mysql/bin');
  }

  private static function SetRegValue($section, $name, $value) {
    $sv = SetupVars::get();
    $reg = $sv->_upgCtx->oldCpIniReg;
    if (!file_exists($reg))
      touch($reg);
    $ini = Util::ParseIniFile($reg);
    $ini[$section][$name] = $value;
    Util::SaveIniFile($ini, $reg);
  }


  private static function ExecuteUpgradeHandlers() {
    $sv = SetupVars::get();
    _Log("Run upgrade handlers...");

    $oldBuild = $sv->_upgCtx->GetOldBuildDate();
    _Log("Old build date detected:" . $oldBuild->format('y.m.d'));

    $rc = new ReflectionClass(__CLASS__);
    $methods = $rc->getMethods();
    $handlers = array();
    foreach ($methods as $m) {
      /* @var $m ReflectionMethod */
      $name = explode('_', $m->getName());
      if ($name[0] == 'UpgradeHandler') {
        $handlers[] = $m->getName();
      }
    }
    sort($handlers);

    $oldestHandler = 'UpgradeHandler_' . $oldBuild->format('Ymd');
    _Log("Oldest handler:$oldestHandler");

    foreach ($handlers as $handler) {
      if (strcmp($handler, $oldestHandler) >= 0) {
        _Log("Running $handler");
        self::$handler($sv);
      }
    }

    _Log("Finished upgrade handlers.");
  }

  private static function UpgradeHandler_20140601(SetupVars $sv) {
    if (is_dir($sv->INSTALL_DIR . '/php5_2')) {
      rename($sv->INSTALL_DIR . '/php5_2', $sv->INSTALL_DIR . '/php5_2.old');
    }
  }

  private static function UpgradeHandler_20140809(SetupVars $sv) {
    self::SetRegValue('flags', 'reloadCloudDataOnStart', 'true');
  }

  private static function UpgradeHandler_20141002(SetupVars $sv) {
    if (Util::IsWin()) {
      self::EnablePhpExtension($sv->INSTALL_DIR . '/php5_5/php.ini', 'fileinfo', array());
    }
  }


  private static function UpgradeHandler_20141024(SetupVars $sv) {
    $curlCfg = array('curl.cainfo' =>
    '"' . $sv->INSTALL_DIR . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'cert' . DIRECTORY_SEPARATOR . 'cacert.pem"');
    self::MassEnablePhpExtension('', $curlCfg);
  }


  private static function UpgradeHandler_20150325(SetupVars $sv) { // for 2015.03.25 and older
    self::SetRegValue('selfUpdate', 'mute', 'false');
  }


  private static function MassEnablePhpExtension($extName, $params) {
    $sv = SetupVars::get();
    if (Util::IsWin()) {
      self::EnablePhpExtension($sv->INSTALL_DIR . '/php5_3/php.ini', $extName, $params);
      self::EnablePhpExtension($sv->INSTALL_DIR . '/php5_4/php.ini', $extName, $params);
      self::EnablePhpExtension($sv->INSTALL_DIR . '/php5_5/php.ini', $extName, $params);
      self::EnablePhpExtension($sv->INSTALL_DIR . '/php5_6/php.ini', $extName, $params);
      self::EnablePhpExtension($sv->INSTALL_DIR . '/php7_0/php.ini', $extName, $params);
    }
    else if(Util::IsOsx()){
      self::EnablePhpExtension($sv->INSTALL_DIR . '/php5_3/bin/php.ini', $extName, $params);
      self::EnablePhpExtension($sv->INSTALL_DIR . '/php5_4/bin/php.ini', $extName, $params);
      self::EnablePhpExtension($sv->INSTALL_DIR . '/php5_5/bin/php.ini', $extName, $params);
      self::EnablePhpExtension($sv->INSTALL_DIR . '/php5_6/bin/php.ini', $extName, $params);
      self::EnablePhpExtension($sv->INSTALL_DIR . '/php7_0/bin/php.ini', $extName, $params);
    }
  }



  public static function EnablePhpExtension($iniPath, $extName, $params) {
    $sv = SetupVars::get();
    if ($params === NULL) {
      $params = array();
    }
    _Log("Enable $extName extension in $iniPath");
    $text = file_get_contents($iniPath);
    if (!$text) {
      acqThrow("Unable to read $iniPath");
    }
    $text = self::_EnablePhpExtension($text, $extName, $params);
    if (!file_put_contents($iniPath, $text)) {
      acqThrow("Unable to write $iniPath");
    }
  }


  private static function DisablePhpExtension($iniPath, $extName) {
    $sv = SetupVars::get();
    $os = $sv->PLATFORM_NAME;
    _Log("Disable $extName extension in $iniPath");
    // define OS specific paramters
    if ($os == 'windows') {
      $extName = "php_$extName.dll";
    }
    else {
      $extName = "$extName.so";
    }
    Util::RegexReplaceInFile($iniPath, sprintf('/^\s*extension\s*=\s*%s\s*$/m', preg_quote($extName)), ';$0');
  }


  private static function _EnablePhpExtension($iniTxt, $extName, $params) {
    $sv = SetupVars::get();
    $os = $sv->PLATFORM_NAME;

    // define OS specific paramters
    if ($os == 'windows') {
      $prefix = 'php_';
      $fileExt = 'dll';
    }
    else {
      $prefix = '';
      $fileExt = 'so';
    }
    $extFileName = "$prefix$extName.$fileExt";

    $lines = explode("\n", $iniTxt);
    $extFound = false;
    $lastSettingLine = - 1;
    $lineNum = 0;
    $ptrn = sprintf('/^[\s;]*extension\s*=\s*%s.*$/', $extFileName);
    foreach ($lines as &$line) {

      if (!empty($extName) && preg_match($ptrn, $line)) {
        if (!$extFound) {
          $line = "extension=$extFileName";
          $extFound = true;
        }
        else if(preg_match(sprintf('/^\s*extension\s*=\s*%s.*$/', preg_quote($extFileName)), $line)) {
          $line = ';' . $line;
        }
      }

      // If extension setting is already defined - redefine it
      foreach ($params as $pname => $pval) {
        if (strpos($line, $pname) !== false) {
          $sPtrn = sprintf('/^[\s;]*%s\s*=.*$/', preg_quote($pname));
          if (preg_match($sPtrn, $line)) {
            $line = "$pname=$pval";
            unset($params[$pname]);
            $lastSettingLine = $lineNum;
          }
        }
      }

      $lineNum ++;
    }

    if ( !$extFound && !empty($extName)) {
      $lines[] = "extension=$prefix$extName.$fileExt";
    }

    // Add settings that have not been redefined
    $newSettings = array();
    foreach ($params as $pname => $pval) {
      $newSettings[] = "$pname=$pval";
    }

    if ($lastSettingLine != - 1) {
      array_splice($newLines, $lastSettingLine + 1, 0, $newSettings);
    }
    else {
      $lines = array_merge($lines, $newSettings);
    }

    return implode("\n", $lines);
  }


  private static function SubstituteConfigVars() {
    $sv = SetupVars::get();

    _Log("SubstituteConfigVars() - Setup vars:%s\n", print_r($sv, true));

    self::SubstituteFile($sv->INSTALL_DIR . '/MasterLicense.txt');
    self::SubstituteFile($sv->INSTALL_DIR . '/phpmyadmin/config.inc.php', array('@@BLOWFISH_SECRET@@' => md5(microtime())));


    if ($sv->PLATFORM_NAME == 'windows') {
      //windows mysql doesn't like \'s in ini paths.
      self::SubstituteFile($sv->INSTALL_DIR . '/mysql/my.cnf', array("\\" => "/"));
    }
    else if ($sv->PLATFORM_NAME == 'osx') {

    }
    else {
      acqThrow('Unsupported platform:' . $sv->PLATFORM_NAME);
    }

  }


  public static function InstallAllCfgFromBox() {
    if (Util::IsWin()) {
      $cfgs = array(
        array('AcquiaDevDesktop/dynamic.xml', CBS_FLAG_KEEP_OLD),
        array('AcquiaDevDesktop/static.ini',  CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),

        array('apache/conf/httpd.conf',       CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('apache/conf/vhosts.conf',      CBS_FLAG_KEEP_OLD),

        array('tools/composer.bat',           CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('tools/drush.bat',              CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('tools/pipelines.bat',          CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),

        array('mysql/my.cnf',                 CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('mysql/bin/mysql.cmd',          CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),

        array('php5_3/php.ini',               CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('php5_4/php.ini',               CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('php5_5/php.ini',               CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('php5_6/php.ini',               CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('php7_0/php.ini',               CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),

        array('phpmyadmin/config.inc.php',    CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP)
      );
    }

    if (Util::IsOSX()) {
      $cfgs = array(
        array('Acquia Dev Desktop.app/Contents/MacOS/dynamic.xml', CBS_FLAG_KEEP_OLD),
        array('Acquia Dev Desktop.app/Contents/MacOS/static.ini',  CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),

#        array('apache/bin/httpd',             CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('apache/bin/apachectl',         CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('apache/conf/httpd.conf',       CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('apache/conf/vhosts.conf',      CBS_FLAG_KEEP_OLD),

        array('tools/drush',                  CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),

        array('mysql/my.cnf',                 CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('mysql/bin/mysqlcommon',        CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),

        array('php5_3/bin/php.ini',           CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('php5_4/bin/php.ini',           CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('php5_5/bin/php.ini',           CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('php5_6/bin/php.ini',           CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
        array('php7_0/bin/php.ini',           CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),

        array('phpmyadmin/config.inc.php',    CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),

        array('common/envvars',               CBS_FLAG_OVERWRITE | CBS_FLAG_BACKUP),
      );
    }

    self::InstallCfgFromBox($cfgs);
  }

  /**
   * @param $files - array(array(string filename, int strategy), ...)
   */
  private static function InstallCfgFromBox($files) {
    $sv = SetupVars::get();

    $cbox = $sv->INSTALL_DIR . '/common/cfgbox';
    $inst = $sv->INSTALL_DIR;

    // Do the checks
    foreach($files as $file) {
      $fpath = $file[0];
      $src = $cbox . '/' . $fpath;
      $dst = $inst . '/' . $fpath;

      // Source must exist
      if (!file_exists($src))
        acqThrow("$src not found");

      // Destination must be writable
      if (file_exists($dst)) {
        if (!is_writable($dst))
          acqThrow("$dst is not writable");
      }
      else {
        $dstdir = dirname($dst);
        if (!is_writable($dstdir))
          acqThrow("$dstdir is not writable");
      }
    }

    // Prepare backup
    $bak = $cbox . '/bak';

    if (!file_exists($bak) && !mkdir($bak, 0777, true))
        acqThrow("Could not create $bak");

    // Install configs
    foreach($files as $file) {
      $fpath = $file[0];
      $flags = $file[1];
      $src = $cbox . '/' . $fpath;
      $dst = $inst . '/' . $fpath;

      _Log("Install $src to $dst. Flags: $flags");

      if ($flags & CBS_FLAG_BACKUP && file_exists($dst)) {
        $bakFolder = $bak . '/' . dirname($fpath);
        if (!is_dir($bakFolder) && !mkdir($bakFolder, 0777, true)) {
          acqThrow("Could not create $bakFolder");
        }

        if (!Util::CopyFile($dst, $bakFolder, true)) {
          acqThrow("Could not copy $dst to bak folder");
        }
      }

      if ($flags & CBS_FLAG_KEEP_OLD) {
        if (file_exists($dst))
          continue;
      }

      // Apply
      self::SubstituteFile($src, array(), $dst);
    }
  }



  private static function SetupXMail() {
    $sv = SetupVars::get();
    if (file_exists($sv->INSTALL_DIR . '\xmail')) {
      $stdout = null;
      Util::ExecC('"' . $sv->INSTALL_DIR . '\xmail\XMail.exe" --install-auto', $stdout);
      Util::ExecC('reg add HKLM\Software\GNU\XMail /f /v MAIL_ROOT /t REG_MULTI_SZ /d "' . $sv->INSTALL_DIR . '\xmail\MailRoot"', $stdout);
      Util::ExecC('reg add HKLM\Software\GNU\XMail /f /v MAIL_CMD_LINE /t REG_MULTI_SZ /d "-P- -B- -X- -Y- -F- -Mx 3"', $stdout);
      Util::ExecC('net start XMail', $stdout);
    }
  }

  private static function SetupUnixPhpize($phpFolder) {
    $sv = SetupVars::get();
    // find php version
    $phpBin = escapeshellarg($phpFolder . '/bin/php');

    $stderr = $stdout = null;
    Util::Exec("$phpBin -v", $stdout, $stderr);

    $matches = null;
    preg_match('/^PHP\s+([\d\.]+)/', $stdout, $matches);
    $phpVer = $matches[1];

    // find php version id
    $phpVerId = vsprintf('%d%02d%02d', explode('.', $phpVer));

    $instDir = $sv->INSTALL_DIR . '/common';
    // replace vars in php-config
    $repl = array('@prefix@' => $phpFolder, '@SED@' => '/usr/bin/sed', '@exec_prefix@' => '${prefix}', '@PHP_VERSION@' => $phpVer, '@PHP_VERSION_ID@' => $phpVerId, '@includedir@' => '${prefix}/include',
      '@PHP_LDFLAGS@' => " -L {$sv->INSTALL_DIR}/common/lib",
      '@EXTRA_LIBS@' => ' -lz -lmysqlclient -liconv -liconv -lpng -lz -ljpeg -lssl -lcrypto -lcurl -lbz2 -lz -lssl -lcrypto -lm  -lxml2 -lz -licucore -lm -lcurl -lz -lxml2 -lz -licucore -lm -lmysqlclient -lz -lm -lmysqlclient -lz -lm -lxml2 -lz -licucore -lm -lxml2 -lz -licucore -lm -lxml2 -lz -licucore -lm -lxml2 -lz -licucore -lm ',
      '@EXTENSION_DIR@' => "$phpFolder/ext", '@program_prefix@' => '', '@program_suffix@' => '', '@EXEEXT@' => '',
      '@CONFIGURE_OPTIONS@' => " '--prefix=$phpFolder/php' '--with-gd' '--with-png-dir=$instDir' '--with-curl=$instDir' '--enable-mbstring' '--enable-pcntl' '--enable-ftp' '--with-zlib' '--with-bz2' '--enable-zip' '--with-openssl=$instDir' '--with-mysql=$instDir'  '--with-pdo-mysql=$instDir'",
      '@PHP_INSTALLED_SAPIS@' => "", '@bindir@' => '${exec_prefix}/bin');
    _Log("SetupUnixPhpize(): replace in php-config\n" . print_r($repl, true));
    $target = $phpFolder . '/bin/php-config';
    self::SubstituteFile($target . '.in', $repl);
    rename($target . '.in', $target);
    chmod($target, 0777);

    $repl = array('@prefix@' => $phpFolder, '@exec_prefix@' => '${prefix}', '@libdir@' => '${exec_prefix}/lib/php', '@includedir@' => '${prefix}/include', '@SED@' => '/usr/bin/sed');
    _Log("SetupUnixPhpize(): replace in phpize\n" . print_r($repl, true));
    $target = $phpFolder . '/bin/phpize';
    self::SubstituteFile($target . '.in', $repl);
    rename($target . '.in', $target);
    chmod($target, 0777);
  }

  private static function SubstituteFile($filename, $pairs = array(), $dstFilename = null) {
    _Log('Making substitutions in "%s"', $filename);
    if (empty($dstFilename))
      $dstFilename = $filename;

    if (empty($pairs)) {
      $sv = SetupVars::get();
      $rc = new ReflectionClass('SetupVars');
      $props = $rc->getProperties();
      foreach ($props as $prop) {
        /*@var $prop ReflectionProperty*/
        if ($prop->isPublic() && ! $prop->isStatic() && substr($prop->getName(), 0, 1) != '_')
          $pairs['@@' . $prop->getName() . '@@'] = $prop->getValue($sv);
      }
    }
    $data = file_get_contents($filename);
    if ($data === false)
      acqThrow('Cannot read ' . $filename);
    $data = str_replace(array_keys($pairs), array_values($pairs), $data);

    if (file_put_contents($dstFilename, $data) === false)
      acqThrow('Cannot write ' . $filename);

    if (Util::IsOSX() && $filename != $dstFilename)
    {
      $s = stat($filename);
      chmod($dstFilename, $s['mode']);
    }
  }

  private static function UnixPath($path) {
    if (SetupVars::get()->PLATFORM_NAME == 'windows')
      $path = str_replace("\\", "/", $path);
    else if (SetupVars::get()->PLATFORM_NAME == 'osx')
      $path = str_replace(':', '/', $path);
    return rtrim($path, '/');
  }

  private static function StartMySQL() {
    _Log("Starting MySQL");
    $sv = SetupVars::get();
    $cmdline = '';

    $tmpdir = sys_get_temp_dir();
    if (substr($tmpdir, - 1) == DIRECTORY_SEPARATOR) {
      $tmpdir = substr($tmpdir, 0, - 1);
    }

    $outFile = $tmpdir . DIRECTORY_SEPARATOR . 'damp_setup.out';
    $errFile = $tmpdir . DIRECTORY_SEPARATOR . 'damp_setup.err';

    if ($sv->PLATFORM_NAME == 'windows') {
      $cmdline = sprintf('cmd.exe /c start "mysqld" /B mysqld.exe --defaults-file="%s" >"%s" 2>"%s"', realpath($sv->INSTALL_DIR . '/mysql/my.cnf'), $outFile, $errFile);
    }
    else if ($sv->PLATFORM_NAME == 'osx') {
      $cmdline = sprintf('sudo -u %s ./mysqld --defaults-file="%s" >"%s" 2>"%s" &', escapeshellarg(Util::GetRealUser()), realpath($sv->INSTALL_DIR . '/mysql/my.cnf'), $outFile, $errFile);
      //$cmdline = sprintf('./mysqld --defaults-file="%s" >"%s" 2>"%s" &', realpath($sv->INSTALL_DIR . '/mysql/my.cnf'), $outFile, $errFile);
    }
    else {
      acqThrow('Unsupported platform:' . SetupVars::get()->PLATFORM_NAME);
    }

    $stdout = null;
    Util::ExecC($cmdline, $stdout, realpath($sv->INSTALL_DIR . '/mysql/bin'), null, true);
    self::WaitMySQLStart();

  }

  private static function WaitMySQLStart() {
    _Log("Waiting for mysql to start");
    $sv = SetupVars::get();
    $timeout = 240;
    $startTime = time();
    $oldTimeout = ini_get('mysql.connect_timeout');
    $hDB = null;
    ini_set('mysql.connect_timeout', 2);

    for ($i = 0;; $i ++) {
      $hDB = @mysql_connect('127.0.0.1:' . $sv->MYSQL_PORT, 'root', '');
      if ($hDB !== false) {
        _Log("Wait succeeded");
        mysql_close($hDB);
        break;
      }

      if (time() - $startTime > $timeout) {
        ini_set('mysql.connect_timeout', $oldTimeout);
        $outFile = @file_get_contents(realpath(sys_get_temp_dir() . '/damp_setup.out'));
        $errFile = @file_get_contents(realpath(sys_get_temp_dir() . '/damp_setup.err'));

        _Log("Wait failed. (i=$i) mysqlerr:%s\nserver stderr:%s\nserver stdout:%s", mysql_error(), $errFile, $outFile);

        acqThrow("MySQL start timeout.");
      }
      sleep(1);
    }
    ini_set('mysql.connect_timeout', $oldTimeout);
  }

  public static function StopMySQL($purge = true) {
    _Log("Stop MySQL");
    $sv = SetupVars::get();

    $pid = intval(@file_get_contents($sv->INSTALL_DIR . '/mysql/data/mysql.pid'));
    $stdout = $stderr = null;

    $mysqladmin = Util::IsWin() ? "mysqladmin.exe" : "./mysqladmin";
    $mysql      = Util::IsWin() ? "mysql.cmd"      : "./mysql";

    if ($pid > 0) {
      _Log("MySQL pid:$pid");

      if ($purge) {
        $cmdline = sprintf("$mysql -uroot -e \"SET GLOBAL innodb_fast_shutdown = 0;\"", realpath($sv->INSTALL_DIR . '/mysql/my.cnf'));
        Util::Exec($cmdline, $stdout, $stderr, realpath($sv->INSTALL_DIR . '/mysql/bin'));
      }

      $cmdline = sprintf("$mysqladmin --defaults-file=\"%s\" shutdown", realpath($sv->INSTALL_DIR . '/mysql/my.cnf'));
      Util::Exec($cmdline, $stdout, $stderr, realpath($sv->INSTALL_DIR . '/mysql/bin'));
    }
  }

  private static function StartApache() {
    _Log("Starting Apache");
    $sv = SetupVars::get();
    $cmdline = '';

    $env = null;
    if ($sv->PLATFORM_NAME == 'windows') {
      $cmdline = sprintf('cmd.exe /c start "apache" /B httpd.exe -D php5_3 -f "%s"', realpath($sv->INSTALL_DIR . '/apache/conf/httpd.conf'));
      // Alter PATH so apache would pick up correct dlls from the PHP home folder
      $env = Util::GetAllEnv();
      foreach ($env as $n => &$v) {
        if (strcasecmp($n, 'path') == 0) {
          $v = $sv->INSTALL_DIR . '\php5_3;' . $v;
          break;
        }
      }
    }
    else if ($sv->PLATFORM_NAME == 'osx') {
      $cmdline = sprintf('sudo -u %s ./httpd -D php5_3 -f "%s"', escapeshellarg(Util::GetRealUser()), realpath($sv->INSTALL_DIR . '/apache/conf/httpd.conf'));
    }
    else {
      acqThrow('Unsupported platform:' . SetupVars::get()->PLATFORM_NAME);
    }

    $stdout = null;
    Util::ExecC($cmdline, $stdout, realpath($sv->INSTALL_DIR . '/apache/bin'), $env, true);
    self::WaitApacheStart();
  }

  public static function StopApache() {
    _Log("Stop Apache");
    $sv = SetupVars::get();

    $pid = intval(@file_get_contents($sv->INSTALL_DIR . '/apache/logs/httpd.pid'));
    if ($pid > 0) {
      _Log("Apache pid:$pid");

      $cwd = null;
      if ($sv->PLATFORM_NAME == 'windows') {
        $cmdline = "taskkill.exe /F /T /PID $pid";
        $cwd = "{$sv->INSTALL_DIR}\\common";
      }
      else if ($sv->PLATFORM_NAME == 'osx') {
        $cmdline = "kill $pid";
      }
      else {
        acqThrow('Unsupported platform:' . SetupVars::get()->PLATFORM_NAME);
      }

      $stdout = $stderr = null;
      Util::Exec($cmdline, $stdout, $stderr, $cwd);

      if ($sv->PLATFORM_NAME == 'windows')
        @unlink($sv->INSTALL_DIR . '/apache/logs/httpd.pid');
    }
  }

  private static function WaitApacheStart() {
    _Log('Wait for apache to start');
    $sv = SetupVars::get();
    $timeout = 30;
    $startTime = time();
    for ($i = 0;; $i ++) {
      $errno = 0;
      $errstr = '';
      $fs = @fsockopen('127.0.0.1', $sv->APACHE_PORT, $errno, $errstr, 1);
      if ($fs !== FALSE) {
        _Log("Wait succeeded");
        fclose($fs);
        break;
      }
      if (time() - $startTime > $timeout) {
        _Log("Wait failed. (i=$i) err:$errstr ($errno)");
        acqThrow('Apache start timeout');
      }
      sleep(1);
    }
  }


  private static function MySQLQuery($query, $hDB) {
    _Log("Runing SQL query:" . $query);
    $res = mysql_query($query, $hDB);
    if ($res === false) {
      acqThrow("Query failed.\nQuery:" . substr($query, 0, 200) . "\nError:" . mysql_error($hDB));
    }
    return $res;
  }

  private static function _TraverseDir($dir, &$res) {
    foreach (glob($dir) as $file) {
      if (is_dir($file)) {
        self::_TraverseDir("$file/*", $res);
      }
      else {
        $res[] = $file;
      }
    }
  }
}

class XmlConfig {
  public function  __construct($file) {
    $this->doc = new DOMDocument();
    if (!$this->doc->load($file))
      acqThrow("Could not load $file");
    $this->file = $file;
  }

  /**
   * @param $q
   * @return DOMNodeList
   */
  public function Query($q, DOMNode $ctx = null) {
    $xpath = new DOMXpath($this->doc);
    return $xpath->query($q, $ctx);
  }

  /**
   * @param $q
   * @return DOMNode|null
   */
  public function Query1($q, DOMNode $ctx = null) {
    $r = $this->Query($q, $ctx);
    return $r->length > 0 ? $r->item(0) : null;
  }

  public function NewNode($name, $val = null) {
    return $this->doc->createElement($name, $val);
  }

  public function NewText($text) {
    return $this->doc->createTextNode($text);
  }

  public function Save() {
    if (!$this->doc->save($this->file))
      acqThrow("Could not save {$this->file}");
  }

  /**
   * @var DOMDocument
   */
  private $doc;
  private $file;
}

class Util {

  public static function IsWin() {
    return (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
  }

  public static function IsWin64() {
    $pf = getenv('ProgramFiles(x86)');
    return !empty($pf);
  }

  public static function GetOSVersion()
  {
    $version = FALSE;
    $parts = preg_split('/\s+/', trim(php_uname()));
    if ($parts[0] == 'Windows')
      $version = $parts[3];
    else if ($parts[1] == 'Darwin')
      $version = $parts[2];
    return $version;
  }


  public static function IsOSX() {
    return (strtoupper(substr(PHP_OS, 0, 6)) === 'DARWIN');
  }

  public static function GetMetaRefreshUrl($html, $rel = true) {
    $result = null;
    $matches = null;
    $n = preg_match('/<meta\s+http-equiv="Refresh".*URL=(.*)"/i', $html, $matches);
    if ($n > 0) {
      if ($rel) {
        $parts = parse_url($matches[1]);
        $result = $parts['path'];
        if ($parts['query'])
          $result .= '?' . str_replace('&amp;', '&', $parts['query']);
      }
      else {
        $result = $matches[1];
      }
    }
    return $result;
  }

  public static function Exec($cmd, &$stdout, &$stderr, $cwd = null, $env = null, $bypass_shell = false) {
    $stdout = $stderr = null;
    _Log("Exec:" . $cmd . ($cwd ? " [cwd:$cwd]" : ''));
    $tmpNameOut = tempnam(sys_get_temp_dir(), 'out');
    $tmpNameErr = tempnam(sys_get_temp_dir(), 'err');

    $descriptorspec = array(0 => array("pipe", "r"), //stdin
      1 => array("file", $tmpNameOut, "w"), //stdout
      2 => array("file", $tmpNameErr, "w")); //stderr

    $sv = SetupVars::get();

    $pipes = array();
    $options = array();
    if ($bypass_shell && $sv->PLATFORM_NAME == 'windows') {
      $options['bypass_shell'] = TRUE;
    }
    $process = proc_open($cmd, $descriptorspec, $pipes, $cwd, $env, $options);
    if (is_resource($process)) {
      fclose($pipes[0]);
      $res = proc_close($process);
    }
    else {
      acqThrow(sprintf(' Unable to execute command line - %s', $cmd));
    }

    $stdout = @file_get_contents($tmpNameOut);
    $stderr = @file_get_contents($tmpNameErr);

    @unlink($tmpNameOut);
    @unlink($tmpNameErr);

    $logStdout = strlen($stdout) > 2048 ? substr($stdout, 0, 2048) . "..." : $stdout;
    $logStderr = strlen($stderr) > 2048 ? substr($stderr, 0, 2048) . "..." : $stderr;

    _Log("Exec result: $res \nSTDOUT:$logStdout \nSTDERR:$logStderr");

    return $res;
  }

  public static function ExecC($cmd, &$stdout, $cwd = null, $env = null, $bypass_shell = false) {
    $stderr = '';
    $r = self::Exec($cmd, $stdout, $stderr, $cwd, $env, $bypass_shell);
    if ($r != 0)
      acqThrow("Command returned " . $r . ". stderr:" . $stderr);
  }


  public static function GetAllEnv()
  {
    $env = array();
    foreach ($_SERVER as $n => $v) {
      if (getenv($n) == $v) {
        $env[$n] = $v;
      }
    }
    return $env;
  }

  /**
   * Parses ini file
   * Return value format: $res[section_name][var_name]=var_value
   *
   * @param string $file
   * @return array
   */
  public static function ParseIniFile($file) {
    $ext = strtolower(substr($file, -4));
    $res = NULL;
    if ($ext == '.ini')
      $res = self::_ParseIniFile($file);
    else if ($ext == '.xml')
      $res = self::_ParseXmlFile($file);
    else
      acqThrow("Unknown ini type:" . $file);
    return $res;
  }

  public static function _ParseIniFile($file) {
    $lines = @file($file);
    if ($lines === false)
      acqThrow("Cannot read:" . $file);
    $res = array();
    $curSection = null;
    foreach ($lines as $line) {
      $line = trim($line);
      if (! empty($line)) {
        $matches = null;
        if (preg_match('/^\[(.*)\]$/', $line, $matches) > 0) {
          $res[$matches[1]] = array();
          $curSection = &$res[$matches[1]];
        }
        elseif (preg_match('/^([^=]+)=(.*)$/', $line, $matches) > 0) {
          $curSection[$matches[1]] = $matches[2];
        }
      }
    }
    return $res;
  }

  public static function _ParseXMLFile($file) {
    $doc = new DOMDocument();
    if (!$doc->load($file))
      acqThrow("Cannot read:" . $file);
    $cfg = self::_LoadXmlCfgNode($doc->documentElement);
    reset($cfg);
    return current($cfg);
  }

  private static function _LoadXmlCfgNode($elt) {
    $res = NULL;
    if ($elt->childNodes->length == 1 && $elt->firstChild->nodeType == XML_TEXT_NODE) {
      $res = $elt->firstChild->nodeValue;
    }
    else if ($elt->childNodes->length > 0) {
      $res = array();
      foreach ($elt->childNodes as $n) {
        if ($n->nodeType == XML_ELEMENT_NODE) {
          $res[$n->nodeName] = self::_LoadXmlCfgNode($n);
        }
      }
    }
    return $res;
  }


  public static function SaveIniFile($ini, $filePath) {
    $str = "";
    foreach ($ini as $section => $pairs) {
      $str .= "[$section]\n";
      if (is_array($pairs)) {
        foreach ($pairs as $n => $v) {
          $str .= "$n=$v\n";
        }
      }
      $str .= "\n";
    }

    file_put_contents($filePath, $str);
  }


  public static function RmDir($dir) {
    foreach (glob($dir, GLOB_NOSORT) as $file) {
      if (is_dir($file)) {
        $bn = basename($file);
        if ($bn != '.' && $bn != '..') {
          self::RmDir("$file/.*");
          self::RmDir("$file/*");
          rmdir($file);
        }
      }
      else {
        if (self::IsWin())
          chmod($file, 0777); // this clears read-only attribute
        unlink($file);
      }
    }
  }

  public static function CopyDir($from, $to) {
    if (! file_exists($to) && @mkdir($to) === false)
      return false;

    foreach (glob($from . '/*', GLOB_NOSORT) as $file) {
      if (is_dir($file)) {
        if (! self::CopyDir($file, $to . '/' . basename($file)))
          return false;
      }
      else {
        self::CopyFile($file, $to);
      }
    }
    return true;
  }

  public static function CopyFile($from, $to, $log = false)
  {
    $ok = true;

    if (is_dir($to))
      $to = $to . '/' . basename($from);

    if ($log)
      _Log("Copying file $from to $to");

    if (file_exists($from))
    {
      if (!@copy($from, $to))
      {
        _Log("Couldn't copy file $from to $to");
        $ok = false;
      }
    }
    else
    {
      _Log("Couldn't copy file. Source $from not found.");
      $ok = false;
    }
    return $ok;
  }

  public static function MoveFile($from, $to, $log = false)
  {
    $ok = true;

    if (is_dir($to))
      $to = $to . '/' . basename($from);

    if ($log)
      _Log("Moving file $from to $to");

    if (file_exists($from))
    {
      if (!@rename($from, $to))
      {
        _Log("Couldn't move file $from to $to");
        $ok = false;
      }
    }
    else
    {
      _Log("Couldn't move file. Source $from not found.");
      $ok = false;
    }
    return $ok;
  }

  public static function MkdirWPermFix($path, $owner, $home = null) {
    if ($path == '')
      return false;
    if (self::IsWin()) {
      return mkdir($path, 0777, true);
    }
    else {
      $home =  $_SERVER['HOME'];
      $parts = explode('/', $path);
      $cur = $path[0] == '/' ? '/' : '';
      foreach($parts as $p) {
        if (strlen($cur) > 0 && substr($cur, -1) != '/')
          $cur .= '/';
        $cur .= $p;

        if (!file_exists($cur))
        {
          if(!mkdir($cur))
            return false;
          chown($cur, $owner);
        }
        else if (!empty($home) && strpos($cur, $home) === 0)
        {
          chown($cur, $owner);
        }
      }
    }

    return true;
  }

  public static function NormalizePath($path) {
    $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
    $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
    $absolutes = array();
    foreach ($parts as $part) {
      if ('.' == $part) continue;
      if ('..' == $part) {
        array_pop($absolutes);
      } else {
        $absolutes[] = $part;
      }
    }
    $res = implode(DIRECTORY_SEPARATOR, $absolutes);

    if (!empty($path) && $path[0] == DIRECTORY_SEPARATOR)
      $res = DIRECTORY_SEPARATOR . $res;

    return  $res;
  }

  public static function IsDirEmpty($dir) {
    if (!is_readable($dir)) return NULL;
    return (count(scandir($dir)) == 2);
  }

  public static function ParseArgv($argv) {
    $res = array();
    foreach ($argv as $a) {
      list($nm, $val) = explode('=', $a);
      if (substr($nm, 0, 2) == '--' && ! empty($val)) {
        $nm = trim($nm, '-');
        $res[$nm] = $val;
      }
    }
    return $res;
  }

  public static function RegexReplaceInFile($file, $regex, $repl) {
    _Log("RegexReplaceInFile. file:$file, regex:$regex, repl:$repl");
    $text = file_get_contents($file);
    if ($text === false)
      acqThrow(sprintf('Unable read file - %s', $file));
    $count = 0;
    $text = preg_replace($regex, $repl, $text, - 1, $count);
    _Log("Nummatch:$count");
    if ($count > 0) {
      $readonly = false;
      if (! is_writable($file)) {
        chmod($file, 0666);
        $readonly = true;
      }
      if (! file_put_contents($file, $text))
        acqThrow(sprintf(' Unable write file - %s', $file));

      if ($readonly)
        chmod($file, 0444);
    }
    return $count;
  }

  public static function ReplaceInFile($file, $find, $repl) {
    _Log("ReplaceInFile. file:$file, find:$find, repl:$repl");
    $text = file_get_contents($file);
    if ($text === false)
      acqThrow(sprintf('Unable read file - %s', $file));
    $count = 0;
    $text = str_replace($find, $repl, $text, $count);
    _Log("Nummatch:$count");
    if ($count > 0) {
      $readonly = false;
      if (! is_writable($file)) {
        chmod($file, 0666);
        $readonly = true;
      }
      if (! file_put_contents($file, $text))
        acqThrow(sprintf(' Unable write file - %s', $file));

      if ($readonly)
        chmod($file, 0444);
    }
    return $count;
  }

  public static function GetRealUser() {
    $username = '';
    if (self::IsWin()) {
      $userName = getenv('USERNAME');
    }
    else {
      $userName = getenv('SUDO_USER') ? getenv('SUDO_USER') : getenv('USER');
    }
    return $userName;
  }

  public static function CompareVersions($v1, $v2) {
    $res = 0;

    $v1 = explode('.', $v1);
    $v2 = explode('.', $v2);
    $msz = max(array(count($v1), count($v2)));
    $v1 = array_pad($v1, $msz, "0");
    $v2 = array_pad($v2, $msz, "0");
    for ($i = 0; $i < count($v1) && $res == 0; $i ++) {
      $res = ($v1[$i] < $v2[$i] ? - 1 : ($v1[$i] > $v2[$i] ? 1 : 0));
    }

    return $res;
  }
}

class OSXUtil {

  public static function GetOSUserList() {
    _Log(__CLASS__ . '::' . __FUNCTION__);
    $stdout = '';
    Util::ExecC("dscl localhost -list /Local/Default/Users", $stdout);
    return explode("\n", $stdout);
  }

  public static function GetOSGroupList() {
    _Log(__CLASS__ . '::' . __FUNCTION__);
    $stdout = '';
    Util::ExecC("dscl localhost -list /Local/Default/Groups", $stdout);
    return explode("\n", $stdout);
  }

  public static function GetOSNewGroupPrimaryID() {
    $stdout = '';
    Util::ExecC("dscl localhost -list /Local/Default/Groups PrimaryGroupID | awk '{print $2}'", $stdout);
    $ids = explode("\n", $stdout);
    sort($ids, SORT_NUMERIC);
    $unqueId = $ids[count($ids) - 1] + 1;
    return $unqueId;
  }

  public static function GetOSNewUserUniqueID() {
    $stdout = '';
    Util::ExecC("dscl localhost -list /Local/Default/Users UniqueID | awk '{print $2}'", $stdout);
    $ids = explode("\n", $stdout);
    sort($ids, SORT_NUMERIC);
    $unqueId = $ids[count($ids) - 1] + 1;
    return $unqueId;
  }

  /**
   * @return created group PrimaryGroupID
   */
  public static function CreateOSUserGroup($groupName) {
    _Log(__CLASS__ . '::' . __FUNCTION__ . "($groupName)");
    if (self::OSGroupExists($groupName))
      acqThrow(" group already exists - " . $groupName);

    $stdout = '';
    $gid = self::GetOSNewGroupPrimaryID();
    Util::ExecC("dscl localhost -create " . escapeshellarg("/Local/Default/Groups/$groupName"), $stdout);
    Util::ExecC(sprintf("dscl localhost -create %s PrimaryGroupID $gid", escapeshellarg("/Local/Default/Groups/$groupName")), $stdout);
    return intval($gid);
  }

  /**
   * @param string $userName
   * @return int - group PrimaryGroupID on success and null on failure
   */
  public static function GetOSUserGroupID($userName) {
    $gid = self::GetDSPropertyVal("/Local/Default/Users/$userName", 'PrimaryGroupID');
    return $gid === null ? null : intval($gid);
  }

  /**
   * @param int $groupID
   * @return string - group name on success and null otherwise
   */
  public static function GetOSGroupNameByID($groupID) {
    $gnames = self::GetDSNodeListByPropertyKeyVal('/Local/Default/Groups', 'PrimaryGroupID', $groupID);
    return empty($gnames) ? null : $gnames[0];
  }

  public static function DeleteOSUserGroup($groupName) {
    if (! self::OSGroupExists($groupName))
      acqThrow(" Group doesn't exist - " . $groupName);

    $stdout = null;
    Util::ExecC(sprintf("dscl localhost -delete %s", escapeshellarg("/Local/Default/Groups/$groupName")), $stdout);
  }

  /**
   * @param string $userName
   * @param string $password
   * @param string $group
   * @return created user ID
   */
  public static function CreateOSUser($userName, $password, $group) {
    _Log("Ceate user(name:$userName password:$password, group:$group )");
    $uid = - 1;
    Util::ExecC(sprintf("dscl localhost -create %s", escapeshellarg("/Local/Default/Users/$userName")), $stdout);
    $uid = self::GetOSNewUserUniqueID();
    Util::ExecC(sprintf("dscl localhost -create %s UniqueID $uid", escapeshellarg("/Local/Default/Users/$userName")), $stdout);
    $gid = self::GetDSPropertyVal("/Local/Default/Groups/$group", 'PrimaryGroupID');
    Util::ExecC(sprintf("dscl localhost -create %s PrimaryGroupID $gid", escapeshellarg("/Local/Default/Users/$userName")), $stdout);
    Util::ExecC(sprintf("dscl localhost -create %s UserShell /usr/bin/false", escapeshellarg("/Local/Default/Users/$userName")), $stdout);
    Util::ExecC(sprintf("dscl localhost -passwd %s %s", escapeshellarg("/Local/Default/Users/$userName"), escapeshellarg($password)), $stdout);

    self::GroupMembershipUserAdd($group, $userName);

    return $uid;
  }

  public static function DeleteOSUser($userName) {
    $stdout = '';

    if (! self::OSUserExists($userName))
      acqThrow(" User doesn't exist - " . $userName);

    // delete the user from primary group
    $gid = self::GetDSPropertyVal("/Local/Default/Users/$userName", 'PrimaryGroupID');
    if ($gid !== null) {

      $gname = self::GetOSGroupNameByID($gid);
      if ($gname) {
        self::GroupMembershipUserRemove($gname, $userName);
      }
    }

    Util::ExecC(sprintf("dscl localhost -delete %s", escapeshellarg("/Local/Default/Users/$userName")), $stdout);
  }

  private static function GetGroupMembership($groupName) {
    $res = null;
    $users = self::GetDSPropertyVal("/Local/Default/Groups/$groupName", 'GroupMembership');
    return empty($users) ? array() : explode(' ', $users);
  }

  private static function GroupMembershipUserAdd($group, $user) {
    $groupUsers = self::GetGroupMembership($group);
    if (! in_array($user, $groupUsers))
      Util::ExecC(sprintf("dscl localhost -append %s GroupMembership %s", escapeshellarg("/Local/Default/Groups/$group"), escapeshellarg($user)), $stdout);
  }

  public static function GroupMembershipUserRemove($group, $user) {
    $groupUsers = self::GetGroupMembership($group);
    if (in_array($user, $groupUsers))
      Util::ExecC(sprintf("dscl localhost -delete %s GroupMembership %s", escapeshellarg("/Local/Default/Groups/$group"), escapeshellarg($user)), $stdout);
  }

  private static function PathExists($path) {
    $stdout = $stderr = '';
    $r = Util::Exec(sprintf("dscl localhost -read %s", escapeshellarg($path)), $stdout, $stderr);
    return $r == 0;
  }

  public static function OSUserExists($user) {
    return self::PathExists("/Local/Default/Users/$user");
  }

  public static function OSGroupExists($user) {
    return self::PathExists("/Local/Default/Groups/$user");
  }

  private static function GetDSPropertyVal($path, $key) {
    $stdout = '';
    Util::ExecC(sprintf("dscl localhost -read %s %s", escapeshellarg($path), escapeshellarg($key)), $stdout);
    $matches = null;
    $result = null;
    if (preg_match("/^$key: ?(.*)$/s", $stdout, $matches) > 0) {
      $result = trim($matches[1]);
    }
    return $result;
  }

  private static function GetDSNodeListByPropertyKeyVal($path, $key, $val) {
    $stdout = '';
    Util::ExecC(sprintf("dscl localhost -list %s %s", escapeshellarg($path), escapeshellarg($key)), $stdout);

    $res = array();
    $matches = null;
    $r = preg_match_all('/^(\S+)\s+(\S*)$/m', $stdout, $m);

    for ($i = 0; $i < $r; $i ++)
      if ($m[2][$i] == $val)
        $res[] = $m[1][$i];
    return $res;
  }
}

class Uninst {

  public static function PreUninst() {
    try {
      Setup::StopMySQL();
    }
    catch (Exception $__e) {
    }
    try {
      Setup::StopApache();
    }
    catch (Exception $__e) {
    }

    if (Util::IsWin()) {
      try {
        self::RemoveXMail();
      }
      catch (Exception $__e) {
      }
    }

    self::RemoveHostEntries();
  }

  function RemoveHostEntries() {
    $installDir = self::InstallDir();

    $hostsfile = null;
    if (Util::IsWin()) {
      $hostsfile = getenv('SystemRoot') . "\\system32\\drivers\\etc\\hosts";
      $iniFile = $installDir . '/AcquiaDevDesktop/dynamic.xml';
    }
    else if (Util::IsOSX()) {
      $hostsfile = "/etc/hosts";
      $iniFile = $installDir . '/Acquia Dev Desktop.app/Contents/MacOS/dynamic.xml';
    }
    else {
      die('?');
    }

    $ini = Util::ParseIniFile($iniFile);
    $hosts = array();
    foreach ($ini as $section => $vars) {
      $secPath = explode('/', $section);
      if (count($secPath) == 3 && $secPath[0] == 'sites' && $secPath[1] == 'm_sites' && $vars['host'] != 'localhost') {
        $hosts[] = $vars['host'];
      }
    }

    if (count($hosts) > 0) {
      $lines = file($hostsfile);
      $lines2 = array();
      foreach ($lines as $line) {
        $remove = false;

        foreach ($hosts as $host) {
          if (preg_match('/^127.0.0.1\s+' . preg_quote($host) . '/', $line)) {
            $remove = true;
            break;
          }
        }
        if (! $remove)
          $lines2[] = $line;
      }
      file_put_contents($hostsfile, implode("", $lines2));
      //echo implode( "", $lines2 );
    }

  }

  private static function RemoveXMail() {
    $installDir = self::InstallDir();
    if (file_exists($installDir . '\xmail')) {
      $stdout = $stderr = null;
      Util::Exec('net stop XMail', $stdout, $stderr);
      Util::Exec('"' . $installDir . '\xmail\XMail.exe" --remove', $stdout, $stderr);
    }
  }

  private static function InstallDir() {
    return realpath(dirname(__FILE__) . '/../..');
  }
}

function main($argv) {
  date_default_timezone_set('UTC');
  try {
    if (isset($argv[1])) {
      if ($argv[1] == 'preuninst') {
        Uninst::PreUninst();
      }
      else if ($argv[1] == 'diag') {
        $GLOBALS['LOG_OFF'] = true; // log file may not be accessible
        $diag = new Diag();
        $diag->CollectInfo(isset($argv[2]) ? $argv[2] : NULL);
      }
    }
    else {
      Setup::Run();
    }
  }
  catch (Exception $ex) {
    echo $ex->getMessage();
    _Log("Exception caught:" . $ex->getMessage());
    _Log("Trace:" . $ex->getTraceAsString());
    echo "\n" . FAIL_STRING;
    return - 1;
  }
  echo "\n" . SUCCESS_STRING;
  return 0;
}




$DATA_PHPINFO_PHP_CODE = <<<MYDATA
<?php
if( \$_SERVER['REMOTE_ADDR'] == '127.0.0.1' || \$_SERVER['REMOTE_ADDR'] == '::1')
{
    ob_start();
    phpinfo();
    \$pinfo = ob_get_contents();
    ob_end_clean();
    echo str_replace( '<head>', '<head><link rel="shortcut icon" href="/misc/favicon.ico" type="image/x-icon" />', \$pinfo);
}
?>
MYDATA;



class Diag
{
  private $tmpDir;


  function __construct()
  {
    $this->tmpDir = sys_get_temp_dir();
    if (substr( $this->tmpDir, -1 ) == DIRECTORY_SEPARATOR) {
      $this->tmpDir = substr( $this->tmpDir, 0, -1 );
    }
    $this->tmpDir = $this->tmpDir . DIRECTORY_SEPARATOR . 'acquia_dd_diag';
  }

  public function CollectInfo($arc = NULL)
  {
    $sv = SetupVars::get();
    $instDir = $sv->INSTALL_DIR;

    if (file_exists($this->tmpDir)) {
      Util::RmDir($this->tmpDir);
    }
    mkdir($this->tmpDir);

    if (Util::IsOSX()) {
      $cpIniPath = 'Acquia Dev Desktop.app/Contents/MacOS';
      $apacheErr = 'error_log';
      $apacheAcs = 'access_log';
      $phpIni = 'bin/php.ini';
      if (!$arc)
        $arc = getenv('HOME') . '/acquia_dd_diag.zip';
    }
    else if (Util::IsWin ()) {
      $cpIniPath = 'AcquiaDevDesktop';
      $apacheErr = 'error.log';
      $apacheAcs = 'access.log';
      $phpIni = 'php.ini';
      if (!$arc)
        $arc = getenv('HOMEDRIVE') . getenv('HOMEPATH') . '\acquia_dd_diag.zip';
    }
    echo "Collecting information...\n";
    // installer logs
    self::CopyIfExists($instDir . '/installer.log', $this->tmpDir . '/installer.log');
    self::CopyIfExists($instDir . '/piscript.log', $this->tmpDir . '/piscript.log');

    // control panel ini
    self::CopyIfExists($instDir . '/' . $cpIniPath . '/static.ini', $this->tmpDir . '/controlpanel_static.ini');
    self::CopyIfExists($instDir . '/' . $cpIniPath . '/dynamic.xml', $this->tmpDir . '/controlpanel_dynamic.xml');
    self::CopyIfExists($instDir . '/' . $cpIniPath . '/datamodel.xml', $this->tmpDir . '/controlpanel_datamodel.xml');
    self::CopyIfExists($instDir . '/' . $cpIniPath . '/reg.ini', $this->tmpDir . '/controlpanel_reg.ini');

    // apache config and logs
    self::CopyIfExists($instDir . '/apache/logs/' . $apacheErr, $this->tmpDir . '/apache_error.log');
    self::CopyIfExists($instDir . '/apache/logs/' . $apacheAcs, $this->tmpDir . '/apache_access.log');
    self::CopyIfExists($instDir . '/apache/conf/httpd.conf', $this->tmpDir . '/apache_httpd.conf');
    self::CopyIfExists($instDir . '/apache/conf/vhosts.conf', $this->tmpDir . '/apache_vhosts.conf');

    // mysql config and log
    self::CopyIfExists($instDir . '/mysql/my.cnf', $this->tmpDir . '/mysql_my.cnf');
    self::CopyIfExists($instDir . '/mysql/data/mysql.err', $this->tmpDir . '/mysql_mysql.err');

    // php ini
    self::CopyIfExists($instDir . '/php5_3/' . $phpIni, $this->tmpDir . '/php5_3_php.ini');
    self::CopyIfExists($instDir . '/php5_4/' . $phpIni, $this->tmpDir . '/php5_4_php.ini');
    self::CopyIfExists($instDir . '/php5_5/' . $phpIni, $this->tmpDir . '/php5_5_php.ini');
    self::CopyIfExists($instDir . '/php5_6/' . $phpIni, $this->tmpDir . '/php5_6_php.ini');
    self::CopyIfExists($instDir . '/php7_0/' . $phpIni, $this->tmpDir . '/php7_0_php.ini');

    // cfg backup
    if (is_dir($instDir . '/common/cfgbox/bak'))
      Util::CopyDir($instDir . '/common/cfgbox/bak', $this->tmpDir . '/cfgbak');

    $acquiaDir = $_SERVER[Util::IsWin() ? 'USERPROFILE' : 'HOME'] . DIRECTORY_SEPARATOR . '.acquia';
    // Crash dumps    
    $cdDir = $acquiaDir . '/DevDesktop/CrashDumps';
    $dumpDir = $this->tmpDir . '/CrashDumps';
    mkdir($dumpDir);
    if (file_exists($cdDir))
      Util::CopyDir($cdDir, $dumpDir);

    if (Util::IsOSX()) {
      // copy system crash dumps as well
      $scdDir = $_SERVER['HOME'] . '/Library/Logs/DiagnosticReports';
      if (is_dir($scdDir)) {
        if ($dh = opendir($scdDir)) {
          while (($file = readdir($dh)) !== false) {
            if (strpos($file, 'Acquia Dev Desktop') !== false) {
              copy($scdDir . '/' . $file, $dumpDir . '/' . $file);
            }
          }
          closedir($dh);
        }
      }
    }

    // xmail spool
    if (Util::IsWin() && file_exists($instDir . '\xmail')) {
      Util::CopyDir($instDir . '\xmail\MailRoot\spool', $this->tmpDir . '\xmail_spool');
    }

    $diagRep = $this->tmpDir . DIRECTORY_SEPARATOR . 'flist.txt';
    $stdout = '';
    if (Util::IsOSX()) {
      $sudoUser = getenv('SUDO_USER');
      Util::ExecC('whoami > ' . escapeshellarg($diagRep), $stdout);
      if ($sudoUser) {
        Util::ExecC("id $sudoUser >> " . escapeshellarg($diagRep), $stdout);
      }
      Util::ExecC('ls -l -R ' . escapeshellarg($instDir) . ' >> ' . escapeshellarg($diagRep), $stdout);
      Util::ExecC('ls -l -R ' . escapeshellarg($acquiaDir) . ' >> ' . escapeshellarg($diagRep), $stdout);
      file_put_contents($diagRep, "================ Processes ================\n", FILE_APPEND);
      Util::ExecC('ps -axl >> ' . escapeshellarg($diagRep), $stdout);
    }
    else if (Util::IsWin ()) {
      Util::ExecC('cmd.exe /c dir /s ' . escapeshellarg($instDir) . ' > ' . escapeshellarg($diagRep), $stdout);
      Util::ExecC('cmd.exe /c dir /s ' . escapeshellarg($acquiaDir) . ' >> ' . escapeshellarg($diagRep), $stdout);
      file_put_contents($diagRep, "================ Processes ================\n", FILE_APPEND);
      $qpCmdLine = 'qprocess.exe';
      if (Util::IsWin64() && file_exists(getenv('WinDir') . '\Sysnative\qprocess.exe'))
        $qpCmdLine = getenv('WinDir') . '\Sysnative\qprocess.exe';
      @exec("$qpCmdLine *  >> " . escapeshellarg($diagRep), $stdout); // qprocess may be not available for some systems.
    }

    $env = Util::GetAllEnv();
    file_put_contents($diagRep, "=============== Environment ===============\n" . print_r($env, true), FILE_APPEND);


    echo "Packaging {$this->tmpDir} to $arc...\n";
    $zip = new ZipArchive();
    $r = $zip->open($arc, ZIPARCHIVE::OVERWRITE);
    if ($r !== TRUE) {
      acqThrow("Could not open ZIP file. Code:$r");
    }
    $this->zip($this->tmpDir, $zip);
    $r = $zip->close();
    if ($r !== TRUE) {
      acqThrow("Could not close ZIP file.");
    }

    Util::RmDir($this->tmpDir);
  }

  private function zip($folder, ZipArchive $arc, $zipPath = null) {
    if ($zipPath) {
      $arc->addEmptyDir($zipPath);
    }
    $dir = new DirectoryIterator($folder);
    foreach($dir as $file) {
      if(!$file->isDot()) {
        $filename = $file->getFilename();
        $fullName = $folder . DIRECTORY_SEPARATOR . $filename;
        $fullZipName = $zipPath ? $zipPath . DIRECTORY_SEPARATOR . $filename : $filename;
        if($file->isDir()) {
          $this->zip($fullName, $arc, $fullZipName);
        }
        else {
          $r = $arc->addFile($fullName, $fullZipName);
          if (!$r) {
            echo "Could not add $fullName to archive\n";
          }
        }
      }
    }
  }

  private static function CopyIfExists($src, $dst)
  {
    if (file_exists($src))
      copy($src, $dst);
  }
}

function test() {
}

//
// Script entry point
//
date_default_timezone_set('UTC');
if (isset($_SERVER['ACQ_TEST'])) {
  test();
}
else {
  $r = main($_SERVER['argv']);
  exit($r);
}

?>
