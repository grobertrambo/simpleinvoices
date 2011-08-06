<?php
/*
 * Zend framework init - start
 */
set_include_path(get_include_path() . PATH_SEPARATOR . "./sys/include/class");
set_include_path(get_include_path() . PATH_SEPARATOR . "../lib/");
set_include_path(get_include_path() . PATH_SEPARATOR . "../lib/pdf");
set_include_path(get_include_path() . PATH_SEPARATOR . "./sys/include/");

require_once 'Zend/Loader/Autoloader.php';

$autoloader = Zend_Loader_Autoloader::getInstance();
$autoloader->setFallbackAutoloader(true);
#Zend_Loader::registerAutoload();


//session_start();
Zend_Session::start();
$auth_session = new Zend_Session_Namespace('Zend_Auth');


//start use of zend_cache
$frontendOptions = array(
    'lifetime' => 7200, // cache lifetime of 2 hours
    'automatic_serialization' => true
);


/*
 * Zend framework init - end
 */



/*
 * Smarty inint - start
 */

#ini_set('display_errors',true);

require_once("smarty/Smarty.class.php");
require_once("lib/paypal/paypal.class.php");

require_once('lib/HTMLPurifier/HTMLPurifier.standalone.php');
require_once('sys/include/functions.php');

//ob_start('addCSRFProtection');

/*
 * log file - start
 */
$logFile = $app_folder . "/tmp/log/si.log";
if (!is_file($logFile))
{
	$createLogFile = fopen($logFile, 'w') or die(simpleInvoicesError('notWriteable','folder', $app_folder . '/tmp/log'));
	fclose($createLogFile);
}
if (!is_writable($logFile)) {

   simpleInvoicesError('notWriteable','file',$logFile);
}
$writer = new Zend_Log_Writer_Stream($logFile);
$logger = new Zend_Log($writer);
/*
 * log file - end
 */

if (!is_writable( $app_folder . '/tmp/cache')) {

   simpleInvoicesError('notWriteable','file', $app_folder . '/tmp/cache');
}
/*
 * Zend Framework cache section - start
 * -- must come after the tmp dir writeable check
 */
$backendOptions = array(
    'cache_dir' => $app_folder . '/tmp/' // Directory where to put the cache files
);

// getting a Zend_Cache_Core object
$cache = Zend_Cache::factory('Core',
                             'File',
                             $frontendOptions,
                             $backendOptions);

//required for some servers
Zend_Date::setOptions(array('cache' => $cache)); // Active aussi pour Zend_Locale
/*
 * Zend Framework cache section - end
 */

$smarty = new Smarty();

$smarty->debugging = false;

//cache directory. Have to be writeable (chmod 777)
$smarty -> compile_dir = $app_folder . "/tmp/cache";
if(!is_writable($smarty -> compile_dir)) {
	simpleInvoicesError("notWriteable", 'folder', $smarty -> compile_dir);
	//exit("Simple Invoices Error : The folder <i>".$smarty -> compile_dir."</i> has to be writeable");
}

//adds own smarty plugins
$smarty->plugins_dir = array("plugins","include/smarty_plugins");

//add stripslash smarty function
$smarty->register_modifier("unescape","stripslashes");
$smarty->register_modifier("capitalise","ucwords");
/*
 * Smarty inint - end
 */


$path = pathinfo($_SERVER['REQUEST_URI']);
//SC: Install path handling will need changes if used in non-HTML contexts
$install_path = htmlsafe($path['dirname']);


include_once($app_folder . '/config/define.php');

/*
 * Include another config file if required
 */
if( is_file($app_folder . '/config/custom.config.ini') ){
     $config = new Zend_Config_Ini( $app_folder . '/config/custom.config.ini', $environment,true);
} else {
    $config = new Zend_Config_Ini( $app_folder . '/config/config.ini', $environment,true);	//added 'true' to allow modifications from db
}

//set up app with relevant php setting
date_default_timezone_set($config->phpSettings->date->timezone);
error_reporting($config->debug->error_reporting);
ini_set('display_startup_errors', $config->phpSettings->display_startup_errors);
ini_set('display_errors', $config->phpSettings->display_errors);
ini_set('log_errors', $config->phpSettings->log_errors);
ini_set('error_log', $config->phpSettings->error_log);



$zendDb = Zend_Db::factory($config->database->adapter, array(
    'host'     => $config->database->params->host,
    'username' => $config->database->params->username,
    'password' => $config->database->params->password,
    'dbname'   => $config->database->params->dbname,
    'port'     => $config->database->params->port)
);

//after config is loaded - do auth
require_once($include_dir . 'sys/include/include_auth.php');

include_once("sys/include/class/db.php");
include_once("sys/include/class/index.php");
$db = db::getInstance();

include_once("sys/include/sql_queries.php");

$smarty->register_modifier("siLocal_number", array("siLocal", "number"));
$smarty->register_modifier("siLocal_number_clean", array("siLocal", "number_clean"));
$smarty->register_modifier("siLocal_number_trim", array("siLocal", "number_trim"));
$smarty->register_modifier("siLocal_number_formatted", array("siLocal", "number_formatted"));
$smarty->register_modifier("siLocal_date", array("siLocal", "date"));
$smarty->register_modifier('htmlsafe', 'htmlsafe');
$smarty->register_modifier('urlsafe', 'urlsafe');
$smarty->register_modifier('urlencode', 'urlencode');
$smarty->register_modifier('outhtml', 'outhtml');
$smarty->register_modifier('htmlout', 'outhtml'); //common typo
$smarty->register_modifier('urlescape', 'urlencode'); //common typo
$install_tables_exists = checkTableExists(TB_PREFIX."biller");
if ($install_tables_exists == true)
{
	$install_data_exists = checkDataExists();
}

//TODO - add this as a function in sql_queries.php or a class file
//if ( ($install_tables_exists != false) AND ($install_data_exists != false) )
if ( $install_tables_exists != false )
{
	if (getNumberOfDoneSQLPatches() > "196")
	{
	    $sql="SELECT * from ".TB_PREFIX."extensions WHERE (domain_id = :id OR domain_id =  0 ) ORDER BY domain_id ASC";
	    $sth = dbQuery($sql,':id', $auth_session->domain_id ) or die(htmlsafe(end($dbh->errorInfo())));

	    while ( $this_extension = $sth->fetch() )
	    {
	    	$DB_extensions[$this_extension['name']] = $this_extension;
	    }
	    $config->extension = $DB_extensions;
	}
}

/*
 * Moved the extensions init loop out of index so can load extensions stuff before acl code
 */
foreach($config->extension as $extension)
{
	/*
	* If extension is enabled then continue and include the requested file for that extension if it exists
	*/
	if($extension->enabled == "1")
	{
		//echo "Enabled:".$value['name']."<br><br>";
		if(file_exists($include_dir . "sys/extensions/$extension->name/include/init.php"))
		{
			require_once("sys/extensions/$extension->name/include/init.php");
		}
	}
}

// If no extension loaded, load Core
if (! $config->extension)
{
	$extension_core = new Zend_Config(array('core'=>array(
		'id'=>1,
		'domain_id'=>1,
		'name'=>'core',
		'description'=>'Core part of Simple Invoices - always enabled',
		'enabled'=>1
		)));
	$config->extension = $extension_core;
}

require_once($include_dir . 'sys/include/language.php');


//add class files for extensions


checkConnection();

require_once($include_dir . 'sys/include/manageCustomFields.php');
require_once($include_dir . "sys/include/validation.php");

//if authentication enabled then do acl check etc..
if ($config->authentication->enabled == 1 )
{
	require_once($include_dir . "sys/include/acl.php");
	require_once($include_dir . "sys/include/check_permission.php");
}

/*
Array: Early_exit
- Pages that don't need header or exit prior to adding the template add in here
*/
$early_exit = array();
$early_exit[] = "auth_login";
$early_exit[] = "api_cron";
$early_exit[] = "auth_logout";
$early_exit[] = "export_pdf";
$early_exit[] = "export_invoice";
$early_exit[] = "statement_export";
$early_exit[] = "invoice_template";
$early_exit[] = "payments_print";
#$early_exit[] = "reports_report_statement";
$early_exit[] = "documentation_view";
$early_exit[] = "biller_upload-logo";
//$early_exit[] = "install_index";


$module = isset($_GET['module'])?$_GET['module']:null;
switch ($module)
{
	case "export" :
		$smarty_output = "fetch";
		break;
	default :
		$smarty_output = "display";
		break;
}

//get the url - used for templates / pdf
$siUrl = getURL($app);
//zend db

// Get extensions from DB, and update config array


//If using the folowing line, the DB settings should be appended to the config array, instead of replacing it (NOT TESTED!)
//$config->extension() = $DB_extensions;


include_once($include_dir . "sys/include/backup.lib.php");

$defaults = getSystemDefaults();
$smarty -> assign("defaults",$defaults);