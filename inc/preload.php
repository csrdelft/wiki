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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Initialize C.S.R. stek essentials
 */
/** @var Kernel $kernel */
$kernel = require __DIR__ . '/../../../lib/configuratie.include.php';
$kernel->boot();

// Doe alsof we een Symfony request gaan uitvoeren
$request = Request::createFromGlobals();
// Voor als je ooit wil checken of de huidige request een wiki request is
$request->attributes->set('is_wiki', true);
// Zet de _controller attribute om te voorkomen dat de router nog dingen gaat doen
$request->attributes->set('_controller', 'legacy_wiki');
// Zet SCRIPT_FILENAME om er voor te zorgen dat redirects vanuit Symfony naar / gaan ipv /wiki
$request->server->set('SCRIPT_FILENAME', 'index.php');

$container = $kernel->getContainer();

// Stop deze request in de request stack
$container->get('request_stack')->push($request);

// Publiceer een RequestEvent, dit zorgt ervoor dat de sessie gecontroleerd wordt
$event = new RequestEvent($container->get('http_kernel'), $request, HttpKernel::MASTER_REQUEST);
$container->get('event_dispatcher')
	->dispatch($event, KernelEvents::REQUEST);

register_shutdown_function(function () use ($container, $request) {
	$container->get('event_dispatcher')
		->dispatch(new FinishRequestEvent($container->get('http_kernel'), $request, HttpKernel::MASTER_REQUEST), KernelEvents::FINISH_REQUEST);
	$container->get('request_stack')->pop();
});

// csrdelft.nl laad alle nl locale settings, een uitzondering voor de wiki:
setlocale(LC_NUMERIC, 'en_US.UTF-8');

define('DOKU_SESSION_NAME', 'CSRSESSID');
define('DOKU_SESSION_LIFETIME', '');
define('DOKU_SESSION_PATH', '/');
define('DOKU_SESSION_DOMAIN', $request->getHost());
