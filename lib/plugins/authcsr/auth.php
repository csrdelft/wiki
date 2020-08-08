<?php

/**
 * DokuWiki Plugin authcsr (Auth Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Gerrit Uitslag <klapinklapin@gmail.com>
 */

// must be run within Dokuwiki
use CsrDelft\common\ContainerFacade;
use CsrDelft\entity\security\Account;
use CsrDelft\entity\security\enum\AuthenticationMethod;
use CsrDelft\repository\groepen\RechtenGroepenRepository;
use CsrDelft\repository\security\AccountRepository;
use CsrDelft\service\security\LoginService;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

if (!defined('DOKU_INC')) {
	die();
}

class auth_plugin_authcsr extends DokuWiki_Auth_Plugin {
	/**
	 * @var RechtenGroepenRepository
	 */
	private $rechtenGroepenRepository;
	/**
	 * @var TokenStorageInterface
	 */
	private $tokenStorage;
	/**
	 * @var AccountRepository
	 */
	private $accountRepository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(); // for compatibility
		// set capabilities accordingly
		//$this->cando['modLogin']    => false; // can login names be changed?
		//$this->cando['modPass']     => false; // can passwords be changed?
		//$this->cando['modName']     => false; // can real names be changed?
		//$this->cando['modMail']     => false; // can emails be changed?
		//$this->cando['modGroups']   => false; // can groups be changed?
		//$this->cando['getUsers']    => false; // can a (filtered) list of users be retrieved?
		//$this->cando['getUserCount']=> false; // can the number of users be retrieved?
		//$this->cando['getGroups']   => false; // can a list of available groups be retrieved?
		$this->cando['external'] = true;
		$this->cando['logoff'] = true;

		//intialize your auth system and set success to true, if successful
		$this->success = true;

		$container = ContainerFacade::getContainer();

		$this->tokenStorage = $container->get('security.token_storage');
		$this->rechtenGroepenRepository = $container->get(RechtenGroepenRepository::class);
		$this->accountRepository = $container->get(AccountRepository::class);
	}

	/**
	 * Do all authentication
	 *
	 * Set $this->cando['external'] = true when implemented
	 *
	 * If this function is implemented it will be used to
	 * authenticate a user - all other DokuWiki internals
	 * will not be used for authenticating, thus
	 * implementing the checkPass() function is not needed
	 * anymore.
	 *
	 * The function can be used to authenticate against third
	 * party cookies or Apache auth mechanisms and replaces
	 * the auth_login() function
	 *
	 * The function will be called with or without a set
	 * username. If the Username is given it was called
	 * from the login form and the given credentials might
	 * need to be checked. If no username was given it
	 * the function needs to check if the user is logged in
	 * by other means (cookie, environment).
	 *
	 * The function needs to set some globals needed by
	 * DokuWiki like auth_login() does.
	 *
	 * @param string $user Username (uid or nickname)
	 * @param string $pass Cleartext Password
	 * @param bool $sticky Cookie should not expire. Not used.
	 * @return  bool             true on successful auth
	 * @see auth_login()
	 * @throws AccessDeniedException
	 *
	 * Controleert of er een ingelogde gebruiker is, als dit niet het geval
	 * is, wordt een AccessDeniedException gethrowed wat er voor zorgt dat
	 * de gebruiker naar /login gestuurd wordt.
	 */
	function trustExternal($user, $pass, $sticky = false) {
		global $USERINFO;
		global $conf;

		$token = $this->tokenStorage->getToken();

		if ($user == "" && $token == null) {
			// Een AccessDeniedException zorgt ervoor dat naar /login geredirect wordt en dat de gebruiker
			// na login weer op de goede pagina terecht komt.
			throw new AccessDeniedException();
		}

		$wiki = array(
			AuthenticationMethod::cookie_token,
			AuthenticationMethod::password_login,
			AuthenticationMethod::recent_password_login,
		);

		// als ingelogd genoeg permissies heeft gegevens ophalen en bewaren
		if (LoginService::mag('P_LOGGED_IN,groep:wikitoegang', $wiki)
			or (LoginService::mag('P_LOGGED_IN,groep:wikitoegang', AuthenticationMethod::getEnumValues()) and $_SERVER['PHP_SELF'] == '/wiki/feed.php')
		) {

			// okay we're logged in - set the globals
			/** @var Account $account */
			$account = $token->getUser();
			$USERINFO['name'] = $account->profiel->getNaam('civitas');
			$USERINFO['mail'] = $account->email;
			$USERINFO['grps'] = $this->rechtenGroepenRepository->getWikiToegang($account->uid);
			// always add the default group to the list of groups
			if (!in_array($conf['defaultgroup'], $USERINFO['grps'])) {
				$USERINFO['grps'][] = $conf['defaultgroup'];
			}

			$_SERVER['REMOTE_USER'] = $account->uid;
			$_SESSION[DOKU_COOKIE]['auth']['user'] = $account->uid;
			$_SESSION[DOKU_COOKIE]['auth']['info'] = $USERINFO;

			return true;
		}

		if (LoginService::getUid() != 'x999') {
			msg('Niet genoeg permissies', -1);
		}
		// to be sure
		auth_logoff();
		return false;
	}

	/**
	 * Log off the current user. Remove cookie and login as nobody.
	 *
	 * Is run in addition to the ususal logoff method. Should
	 * only be needed when trustExternal is implemented.
	 *
	 * @see     auth_logoff()
	 */
	function logOff() {
		$token = $this->tokenStorage->getToken();
		// Alleen uitloggen als er een token is
		if ($token) {
			redirect("/logout");
		}
	}

	/**
	 * Return user info [required function]
	 *
	 * Returns info about the given user needs to contain
	 * at least these fields:
	 *
	 * name string  full name of the user
	 * mail string  email addres of the user
	 * grps array   list of groups the user is in
	 *
	 * @param string $useruid the user name
	 * @param bool $requireGroups
	 *
	 * @return array|false containing user data or false
	 */
	function getUserData($useruid, $requireGroups = true) {
		global $conf;

		$account = $this->accountRepository->find($useruid);

		if ($account) {
			$profiel = $account->profiel;
			$info['name'] = $profiel->getNaam();
			$info['mail'] = $profiel->getPrimaryEmail();
			$info['grps'] = $this->rechtenGroepenRepository->getWikiToegang($useruid);
			// always add the default group to the list of groups
			if (!in_array($conf['defaultgroup'], $info['grps']) and $useruid != 'x999') {
				$info['grps'][] = $conf['defaultgroup'];
			}

			return $info;
		}
		return false;
	}

	/**
	 * Return case sensitivity of the backend
	 *
	 * When your backend is caseinsensitive (eg. you can login with USER and
	 * user) then you need to overwrite this method and return false
	 *
	 * @return bool
	 */
	public function isCaseSensitive() {
		return true;
	}

	/**
	 * Sanitize a given username
	 *
	 * This function is applied to any user name that is given to
	 * the backend and should also be applied to any user name within
	 * the backend before returning it somewhere.
	 *
	 * This should be used to enforce username restrictions.
	 *
	 * @param string $user username
	 * @return string the cleaned username
	 */
	public function cleanUser($user) {
		return $user;
	}

	/**
	 * Sanitize a given groupname
	 *
	 * This function is applied to any groupname that is given to
	 * the backend and should also be applied to any groupname within
	 * the backend before returning it somewhere.
	 *
	 * This should be used to enforce groupname restrictions.
	 *
	 * Groupnames are to be passed without a leading '@' here.
	 *
	 * @param string $group groupname
	 * @return string the cleaned groupname
	 */
	public function cleanGroup($group) {
		return $group;
	}

}

// vim:ts=4:sw=4:et:
