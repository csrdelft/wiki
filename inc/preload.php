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
use Illuminate\Support\Facades\Route;

/**
 * Initialize C.S.R. stek essentials
 */
require_once __DIR__ . '/../inc/common.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
global $app;
$app = require_once __DIR__ . '/../../../bootstrap/app.php';

/** @var \Illuminate\Contracts\Http\Kernel $kernel */
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request = Illuminate\Http\Request::capture());

// csrdelft.nl laad alle nl locale settings, een uitzondering voor de wiki:
setlocale(LC_NUMERIC, 'en_US.UTF-8');
