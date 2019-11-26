<?php
/**
 * This is an example for a farm setup. Simply copy this file to preload.php and
 * uncomment what you need. See http://www.dokuwiki.org/farms for more information.
 * You can also use preload.php for other things than farming, e.g. for moving
 * local configuration files out of the main ./conf directory.
 */

// set this to your farm directory
//if(!defined('DOKU_FARMDIR')) define('DOKU_FARMDIR', '/var/www/farm');

// include this after DOKU_FARMDIR if you want to use farms
//include(fullpath(dirname(__FILE__)).'/farm.php');

// you can overwrite the $config_cascade to your liking
//$config_cascade = array(
//);
use CsrDelft\common\ContainerFacade;
use CsrDelft\Kernel;
use Symfony\Component\Debug\Debug;

/**
 * Initialize C.S.R. stek essentials
 */
require_once 'configuratie.include.php';

// Initialiseer Container.
$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
ContainerFacade::init($kernel->getContainer());

// csrdelft.nl laad alle nl locale settings, een uitzondering voor de wiki:
setlocale(LC_NUMERIC, 'en_US.UTF-8');

define('DOKU_SESSION_NAME', 'CSRSESSID');
define('DOKU_SESSION_LIFETIME', '');
define('DOKU_SESSION_PATH', '/');
define('DOKU_SESSION_DOMAIN', CSR_DOMAIN);

session_save_path(SESSION_PATH);
