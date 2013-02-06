<?php
/**
 * Authentication library
 *
 * Including this file will automatically try to login
 * a user by calling auth_login()
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */

if(!defined('DOKU_INC')) die('meh.');

// some ACL level defines
define('AUTH_NONE', 0);
define('AUTH_READ', 1);
define('AUTH_EDIT', 2);
define('AUTH_CREATE', 4);
define('AUTH_UPLOAD', 8);
define('AUTH_DELETE', 16);
define('AUTH_ADMIN', 255);

/**
 * Initialize the auth system.
 *
 * This function is automatically called at the end of init.php
 *
 * This used to be the main() of the auth.php
 *
 * @todo backend loading maybe should be handled by the class autoloader
 * @todo maybe split into multiple functions at the XXX marked positions
 * @triggers AUTH_LOGIN_CHECK
 * @return bool
 */
function auth_setup() {
    global $conf;
    /* @var auth_basic $auth */
    global $auth;
    /* @var Input $INPUT */
    global $INPUT;
    global $AUTH_ACL;
    global $lang;
    $AUTH_ACL = array();

    if(!$conf['useacl']) return false;

    // load the the backend auth functions and instantiate the auth object XXX
    if(@file_exists(DOKU_INC.'inc/auth/'.$conf['authtype'].'.class.php')) {
        require_once(DOKU_INC.'inc/auth/basic.class.php');
        require_once(DOKU_INC.'inc/auth/'.$conf['authtype'].'.class.php');

        $auth_class = "auth_".$conf['authtype'];
        if(class_exists($auth_class)) {
            $auth = new $auth_class();
            if($auth->success == false) {
                // degrade to unauthenticated user
                unset($auth);
                auth_logoff();
                msg($lang['authtempfail'], -1);
            }
        } else {
            nice_die($lang['authmodfailed']);
        }
    } else {
        nice_die($lang['authmodfailed']);
    }

    if(!isset($auth) || !$auth) return false;

    // do the login either by cookie or provided credentials XXX
    $INPUT->set('http_credentials', false);
    if(!$conf['rememberme']) $INPUT->set('r', false);

    // handle renamed HTTP_AUTHORIZATION variable (can happen when a fix like
    // the one presented at
    // http://www.besthostratings.com/articles/http-auth-php-cgi.html is used
    // for enabling HTTP authentication with CGI/SuExec)
    if(isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
        $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    // streamline HTTP auth credentials (IIS/rewrite -> mod_php)
    if(isset($_SERVER['HTTP_AUTHORIZATION'])) {
        list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) =
            explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)));
    }

    // if no credentials were given try to use HTTP auth (for SSO)
    if(!$INPUT->str('u') && empty($_COOKIE[DOKU_COOKIE]) && !empty($_SERVER['PHP_AUTH_USER'])) {
        $INPUT->set('u', $_SERVER['PHP_AUTH_USER']);
        $INPUT->set('p', $_SERVER['PHP_AUTH_PW']);
        $INPUT->set('http_credentials', true);
    }

    // apply cleaning
    $INPUT->set('u', $auth->cleanUser($INPUT->str('u')));

    if($INPUT->str('authtok')) {
        // when an authentication token is given, trust the session
        auth_validateToken($INPUT->str('authtok'));
    } elseif(!is_null($auth) && $auth->canDo('external')) {
        // external trust mechanism in place
        $auth->trustExternal($INPUT->str('u'), $INPUT->str('p'), $INPUT->bool('r'));
    } else {
        $evdata = array(
            'user'     => $INPUT->str('u'),
            'password' => $INPUT->str('p'),
            'sticky'   => $INPUT->bool('r'),
            'silent'   => $INPUT->bool('http_credentials')
        );
        trigger_event('AUTH_LOGIN_CHECK', $evdata, 'auth_login_wrapper');
    }

    //load ACL into a global array XXX
    $AUTH_ACL = auth_loadACL();

    return true;
}

/**
 * Loads the ACL setup and handle user wildcards
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 * @return array
 */
function auth_loadACL() {
    global $config_cascade;
    global $USERINFO;

    if(!is_readable($config_cascade['acl']['default'])) return array();

    $acl = file($config_cascade['acl']['default']);

    //support user wildcard
    $out = array();
    foreach($acl as $line) {
        $line = trim($line);
        if($line{0} == '#') continue;
        list($id,$rest) = preg_split('/\s+/',$line,2);

        if(strstr($line, '%GROUP%')){
            foreach((array) $USERINFO['grps'] as $grp){
                $nid   = str_replace('%GROUP%',cleanID($grp),$id);
                $nrest = str_replace('%GROUP%','@'.auth_nameencode($grp),$rest);
                $out[] = "$nid\t$nrest";
            }
        } else {
            $id   = str_replace('%USER%',cleanID($_SERVER['REMOTE_USER']),$id);
            $rest = str_replace('%USER%',auth_nameencode($_SERVER['REMOTE_USER']),$rest);
            $out[] = "$id\t$rest";
        }
    }

    return $out;
}

/**
 * Event hook callback for AUTH_LOGIN_CHECK
 *
 * @param $evdata
 * @return bool
 */
function auth_login_wrapper($evdata) {
    return auth_login(
        $evdata['user'],
        $evdata['password'],
        $evdata['sticky'],
        $evdata['silent']
    );
}

/**
 * This tries to login the user based on the sent auth credentials
 *
 * The authentication works like this: if a username was given
 * a new login is assumed and user/password are checked. If they
 * are correct the password is encrypted with blowfish and stored
 * together with the username in a cookie - the same info is stored
 * in the session, too. Additonally a browserID is stored in the
 * session.
 *
 * If no username was given the cookie is checked: if the username,
 * crypted password and browserID match between session and cookie
 * no further testing is done and the user is accepted
 *
 * If a cookie was found but no session info was availabe the
 * blowfish encrypted password from the cookie is decrypted and
 * together with username rechecked by calling this function again.
 *
 * On a successful login $_SERVER[REMOTE_USER] and $USERINFO
 * are set.
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @param   string  $user    Username
 * @param   string  $pass    Cleartext Password
 * @param   bool    $sticky  Cookie should not expire
 * @param   bool    $silent  Don't show error on bad auth
 * @return  bool             true on successful auth
 */
function auth_login($user, $pass, $sticky = false, $silent = false) {
    global $USERINFO;
    global $conf;
    global $lang;
    /* @var auth_basic $auth */
    global $auth;

    $sticky ? $sticky = true : $sticky = false; //sanity check

    if(!$auth) return false;

    if(!empty($user)) {
        //usual login
        if($auth->checkPass($user, $pass)) {
            // make logininfo globally available
            $_SERVER['REMOTE_USER'] = $user;
            $secret                 = auth_cookiesalt(!$sticky); //bind non-sticky to session
            auth_setCookie($user, PMA_blowfish_encrypt($pass, $secret), $sticky);
            return true;
        } else {
            //invalid credentials - log off
            if(!$silent) msg($lang['badlogin'], -1);
            auth_logoff();
            return false;
        }
    } else {
        // read cookie information
        list($user, $sticky, $pass) = auth_getCookie();
        if($user && $pass) {
            // we got a cookie - see if we can trust it

            // get session info
            $session = $_SESSION[DOKU_COOKIE]['auth'];
            if(isset($session) &&
                $auth->useSessionCache($user) &&
                ($session['time'] >= time() - $conf['auth_security_timeout']) &&
                ($session['user'] == $user) &&
                ($session['pass'] == sha1($pass)) && //still crypted
                ($session['buid'] == auth_browseruid())
            ) {

                // he has session, cookie and browser right - let him in
                $_SERVER['REMOTE_USER'] = $user;
                $USERINFO               = $session['info']; //FIXME move all references to session
                return true;
            }
            // no we don't trust it yet - recheck pass but silent
            $secret = auth_cookiesalt(!$sticky); //bind non-sticky to session
            $pass   = PMA_blowfish_decrypt($pass, $secret);
            return auth_login($user, $pass, $sticky, true);
        }
    }
    //just to be sure
    auth_logoff(true);
    return false;
}

/**
 * Checks if a given authentication token was stored in the session
 *
 * Will setup authentication data using data from the session if the
 * token is correct. Will exit with a 401 Status if not.
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 * @param  string $token The authentication token
 * @return boolean true (or will exit on failure)
 */
function auth_validateToken($token) {
    if(!$token || $token != $_SESSION[DOKU_COOKIE]['auth']['token']) {
        // bad token
        header("HTTP/1.0 401 Unauthorized");
        print 'Invalid auth token - maybe the session timed out';
        unset($_SESSION[DOKU_COOKIE]['auth']['token']); // no second chance
        exit;
    }
    // still here? trust the session data
    global $USERINFO;
    $_SERVER['REMOTE_USER'] = $_SESSION[DOKU_COOKIE]['auth']['user'];
    $USERINFO               = $_SESSION[DOKU_COOKIE]['auth']['info'];
    return true;
}

/**
 * Create an auth token and store it in the session
 *
 * NOTE: this is completely unrelated to the getSecurityToken() function
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 * @return string The auth token
 */
function auth_createToken() {
    $token = md5(mt_rand());
    @session_start(); // reopen the session if needed
    $_SESSION[DOKU_COOKIE]['auth']['token'] = $token;
    session_write_close();
    return $token;
}

/**
 * Builds a pseudo UID from browser and IP data
 *
 * This is neither unique nor unfakable - still it adds some
 * security. Using the first part of the IP makes sure
 * proxy farms like AOLs are stil okay.
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @return  string  a MD5 sum of various browser headers
 */
function auth_browseruid() {
    $ip  = clientIP(true);
    $uid = '';
    $uid .= $_SERVER['HTTP_USER_AGENT'];
    $uid .= $_SERVER['HTTP_ACCEPT_ENCODING'];
    $uid .= $_SERVER['HTTP_ACCEPT_LANGUAGE'];
    $uid .= $_SERVER['HTTP_ACCEPT_CHARSET'];
    $uid .= substr($ip, 0, strpos($ip, '.'));
    return md5($uid);
}

/**
 * Creates a random key to encrypt the password in cookies
 *
 * This function tries to read the password for encrypting
 * cookies from $conf['metadir'].'/_htcookiesalt'
 * if no such file is found a random key is created and
 * and stored in this file.
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 * @param   bool $addsession if true, the sessionid is added to the salt
 * @return  string
 */
function auth_cookiesalt($addsession = false) {
    global $conf;
    $file = $conf['metadir'].'/_htcookiesalt';
    $salt = io_readFile($file);
    if(empty($salt)) {
        $salt = uniqid(rand(), true);
        io_saveFile($file, $salt);
    }
    if($addsession) {
        $salt .= session_id();
    }
    return $salt;
}

/**
 * Log out the current user
 *
 * This clears all authentication data and thus log the user
 * off. It also clears session data.
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 * @param bool $keepbc - when true, the breadcrumb data is not cleared
 */
function auth_logoff($keepbc = false) {
    global $conf;
    global $USERINFO;
    /* @var auth_basic $auth */
    global $auth;

    // make sure the session is writable (it usually is)
    @session_start();

    if(isset($_SESSION[DOKU_COOKIE]['auth']['user']))
        unset($_SESSION[DOKU_COOKIE]['auth']['user']);
    if(isset($_SESSION[DOKU_COOKIE]['auth']['pass']))
        unset($_SESSION[DOKU_COOKIE]['auth']['pass']);
    if(isset($_SESSION[DOKU_COOKIE]['auth']['info']))
        unset($_SESSION[DOKU_COOKIE]['auth']['info']);
    if(!$keepbc && isset($_SESSION[DOKU_COOKIE]['bc']))
        unset($_SESSION[DOKU_COOKIE]['bc']);
    if(isset($_SERVER['REMOTE_USER']))
        unset($_SERVER['REMOTE_USER']);
    $USERINFO = null; //FIXME

    $cookieDir = empty($conf['cookiedir']) ? DOKU_REL : $conf['cookiedir'];
    if(version_compare(PHP_VERSION, '5.2.0', '>')) {
        setcookie(DOKU_COOKIE, '', time() - 600000, $cookieDir, '', ($conf['securecookie'] && is_ssl()), true);
    } else {
        setcookie(DOKU_COOKIE, '', time() - 600000, $cookieDir, '', ($conf['securecookie'] && is_ssl()));
    }

    if($auth) $auth->logOff();
}

/**
 * Check if a user is a manager
 *
 * Should usually be called without any parameters to check the current
 * user.
 *
 * The info is available through $INFO['ismanager'], too
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 * @see    auth_isadmin
 * @param  string $user       Username
 * @param  array  $groups     List of groups the user is in
 * @param  bool   $adminonly  when true checks if user is admin
 * @return bool
 */
function auth_ismanager($user = null, $groups = null, $adminonly = false) {
    global $conf;
    global $USERINFO;
    /* @var auth_basic $auth */
    global $auth;

    if(!$auth) return false;
    if(is_null($user)) {
        if(!isset($_SERVER['REMOTE_USER'])) {
            return false;
        } else {
            $user = $_SERVER['REMOTE_USER'];
        }
    }
    if(is_null($groups)) {
        $groups = (array) $USERINFO['grps'];
    }

    // check superuser match
    if(auth_isMember($conf['superuser'], $user, $groups)) return true;
    if($adminonly) return false;
    // check managers
    if(auth_isMember($conf['manager'], $user, $groups)) return true;

    return false;
}

/**
 * Check if a user is admin
 *
 * Alias to auth_ismanager with adminonly=true
 *
 * The info is available through $INFO['isadmin'], too
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 * @see auth_ismanager()
 * @param  string $user       Username
 * @param  array  $groups     List of groups the user is in
 * @return bool
 */
function auth_isadmin($user = null, $groups = null) {
    return auth_ismanager($user, $groups, true);
}

/**
 * Match a user and his groups against a comma separated list of
 * users and groups to determine membership status
 *
 * Note: all input should NOT be nameencoded.
 *
 * @param $memberlist string commaseparated list of allowed users and groups
 * @param $user       string user to match against
 * @param $groups     array  groups the user is member of
 * @return bool       true for membership acknowledged
 */
function auth_isMember($memberlist, $user, array $groups) {
    /* @var auth_basic $auth */
    global $auth;
    if(!$auth) return false;

    // clean user and groups
    if(!$auth->isCaseSensitive()) {
        $user   = utf8_strtolower($user);
        $groups = array_map('utf8_strtolower', $groups);
    }
    $user   = $auth->cleanUser($user);
    $groups = array_map(array($auth, 'cleanGroup'), $groups);

    // extract the memberlist
    $members = explode(',', $memberlist);
    $members = array_map('trim', $members);
    $members = array_unique($members);
    $members = array_filter($members);

    // compare cleaned values
    foreach($members as $member) {
        if(!$auth->isCaseSensitive()) $member = utf8_strtolower($member);
        if($member[0] == '@') {
            $member = $auth->cleanGroup(substr($member, 1));
            if(in_array($member, $groups)) return true;
        } else {
            $member = $auth->cleanUser($member);
            if($member == $user) return true;
        }
    }

    // still here? not a member!
    return false;
}

/**
 * Convinience function for auth_aclcheck()
 *
 * This checks the permissions for the current user
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @param  string  $id  page ID (needs to be resolved and cleaned)
 * @return int          permission level
 */
function auth_quickaclcheck($id) {
    global $conf;
    global $USERINFO;
    # if no ACL is used always return upload rights
    if(!$conf['useacl']) return AUTH_UPLOAD;
    return auth_aclcheck($id, $_SERVER['REMOTE_USER'], $USERINFO['grps']);
}

/**
 * Returns the maximum rights a user has for
 * the given ID or its namespace
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 *
 * @param  string       $id     page ID (needs to be resolved and cleaned)
 * @param  string       $user   Username
 * @param  array|null   $groups Array of groups the user is in
 * @return int             permission level
 */
function auth_aclcheck($id, $user, $groups) {
    global $conf;
    global $AUTH_ACL;
    /* @var auth_basic $auth */
    global $auth;

    // if no ACL is used always return upload rights
    if(!$conf['useacl']) return AUTH_UPLOAD;
    if(!$auth) return AUTH_NONE;

    //make sure groups is an array
    if(!is_array($groups)) $groups = array();

    //if user is superuser or in superusergroup return 255 (acl_admin)
    if(auth_isadmin($user, $groups)) {
        return AUTH_ADMIN;
    }

    $ci = '';
    if(!$auth->isCaseSensitive()) $ci = 'ui';

    $user   = $auth->cleanUser($user);
    $groups = array_map(array($auth, 'cleanGroup'), (array) $groups);
    $user   = auth_nameencode($user);

    //prepend groups with @ and nameencode
    $cnt = count($groups);
    for($i = 0; $i < $cnt; $i++) {
        $groups[$i] = '@'.auth_nameencode($groups[$i]);
    }

    $ns   = getNS($id);
    $perm = -1;

    if($user || count($groups)) {
        //add ALL group
        $groups[] = '@ALL';
        //add User
        if($user) $groups[] = $user;
    } else {
        $groups[] = '@ALL';
    }

    //check exact match first
    $matches = preg_grep('/^'.preg_quote($id, '/').'\s+(\S+)\s+/'.$ci, $AUTH_ACL);
    if(count($matches)) {
        foreach($matches as $match) {
            $match = preg_replace('/#.*$/', '', $match); //ignore comments
            $acl   = preg_split('/\s+/', $match);
            if(!in_array($acl[1], $groups)) {
                continue;
            }
            if($acl[2] > AUTH_DELETE) $acl[2] = AUTH_DELETE; //no admins in the ACL!
            if($acl[2] > $perm) {
                $perm = $acl[2];
            }
        }
        if($perm > -1) {
            //we had a match - return it
            return $perm;
        }
    }

    //still here? do the namespace checks
    if($ns) {
        $path = $ns.':*';
    } else {
        $path = '*'; //root document
    }

    do {
        $matches = preg_grep('/^'.preg_quote($path, '/').'\s+(\S+)\s+/'.$ci, $AUTH_ACL);
        if(count($matches)) {
            foreach($matches as $match) {
                $match = preg_replace('/#.*$/', '', $match); //ignore comments
                $acl   = preg_split('/\s+/', $match);
                if(!in_array($acl[1], $groups)) {
                    continue;
                }
                if($acl[2] > AUTH_DELETE) $acl[2] = AUTH_DELETE; //no admins in the ACL!
                if($acl[2] > $perm) {
                    $perm = $acl[2];
                }
            }
            //we had a match - return it
            if($perm != -1) {
                return $perm;
            }
        }
        //get next higher namespace
        $ns = getNS($ns);

        if($path != '*') {
            $path = $ns.':*';
            if($path == ':*') $path = '*';
        } else {
            //we did this already
            //looks like there is something wrong with the ACL
            //break here
            msg('No ACL setup yet! Denying access to everyone.');
            return AUTH_NONE;
        }
    } while(1); //this should never loop endless
    return AUTH_NONE;
}

/**
 * Encode ASCII special chars
 *
 * Some auth backends allow special chars in their user and groupnames
 * The special chars are encoded with this function. Only ASCII chars
 * are encoded UTF-8 multibyte are left as is (different from usual
 * urlencoding!).
 *
 * Decoding can be done with rawurldecode
 *
 * @author Andreas Gohr <gohr@cosmocode.de>
 * @see rawurldecode()
 */
function auth_nameencode($name, $skip_group = false) {
    global $cache_authname;
    $cache =& $cache_authname;
    $name  = (string) $name;

    // never encode wildcard FS#1955
    if($name == '%USER%') return $name;
    if($name == '%GROUP%') return $name;

    if(!isset($cache[$name][$skip_group])) {
        if($skip_group && $name{0} == '@') {
            $cache[$name][$skip_group] = '@'.preg_replace(
                '/([\x00-\x2f\x3a-\x40\x5b-\x60\x7b-\x7f])/e',
                "'%'.dechex(ord(substr('\\1',-1)))", substr($name, 1)
            );
        } else {
            $cache[$name][$skip_group] = preg_replace(
                '/([\x00-\x2f\x3a-\x40\x5b-\x60\x7b-\x7f])/e',
                "'%'.dechex(ord(substr('\\1',-1)))", $name
            );
        }
    }

    return $cache[$name][$skip_group];
}

/**
 * Create a pronouncable password
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 * @link    http://www.phpbuilder.com/annotate/message.php3?id=1014451
 *
 * @return string  pronouncable password
 */
function auth_pwgen() {
    $pw = '';
    $c  = 'bcdfghjklmnprstvwz'; //consonants except hard to speak ones
    $v  = 'aeiou'; //vowels
    $a  = $c.$v; //both

    //use two syllables...
    for($i = 0; $i < 2; $i++) {
        $pw .= $c[rand(0, strlen($c) - 1)];
        $pw .= $v[rand(0, strlen($v) - 1)];
        $pw .= $a[rand(0, strlen($a) - 1)];
    }
    //... and add a nice number
    $pw .= rand(10, 99);

    return $pw;
}

/**
 * Sends a password to the given user
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 * @param string $user Login name of the user
 * @param string $password The new password in clear text
 * @return bool  true on success
 */
function auth_sendPassword($user, $password) {
    global $lang;
    /* @var auth_basic $auth */
    global $auth;
    if(!$auth) return false;

    $user     = $auth->cleanUser($user);
    $userinfo = $auth->getUserData($user);

    if(!$userinfo['mail']) return false;

    $text = rawLocale('password');
    $trep = array(
        'FULLNAME' => $userinfo['name'],
        'LOGIN'    => $user,
        'PASSWORD' => $password
    );

    $mail = new Mailer();
    $mail->to($userinfo['name'].' <'.$userinfo['mail'].'>');
    $mail->subject($lang['regpwmail']);
    $mail->setBody($text, $trep);
    return $mail->send();
}

/**
 * Register a new user
 *
 * This registers a new user - Data is read directly from $_POST
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 * @return bool  true on success, false on any error
 */
function register() {
    global $lang;
    global $conf;
    /* @var auth_basic $auth */
    global $auth;
    global $INPUT;

    if(!$INPUT->post->bool('save')) return false;
    if(!actionOK('register')) return false;

    // gather input
    $login    = trim($auth->cleanUser($INPUT->post->str('login')));
    $fullname = trim(preg_replace('/[\x00-\x1f:<>&%,;]+/', '', $INPUT->post->str('fullname')));
    $email    = trim(preg_replace('/[\x00-\x1f:<>&%,;]+/', '', $INPUT->post->str('email')));
    $pass     = $INPUT->post->str('pass');
    $passchk  = $INPUT->post->str('passchk');

    if(empty($login) || empty($fullname) || empty($email)) {
        msg($lang['regmissing'], -1);
        return false;
    }

    if($conf['autopasswd']) {
        $pass = auth_pwgen(); // automatically generate password
    } elseif(empty($pass) || empty($passchk)) {
        msg($lang['regmissing'], -1); // complain about missing passwords
        return false;
    } elseif($pass != $passchk) {
        msg($lang['regbadpass'], -1); // complain about misspelled passwords
        return false;
    }

    //check mail
    if(!mail_isvalid($email)) {
        msg($lang['regbadmail'], -1);
        return false;
    }

    //okay try to create the user
    if(!$auth->triggerUserMod('create', array($login, $pass, $fullname, $email))) {
        msg($lang['reguexists'], -1);
        return false;
    }

    // create substitutions for use in notification email
    $substitutions = array(
        'NEWUSER'  => $login,
        'NEWNAME'  => $fullname,
        'NEWEMAIL' => $email,
    );

    if(!$conf['autopasswd']) {
        msg($lang['regsuccess2'], 1);
        notify('', 'register', '', $login, false, $substitutions);
        return true;
    }

    // autogenerated password? then send him the password
    if(auth_sendPassword($login, $pass)) {
        msg($lang['regsuccess'], 1);
        notify('', 'register', '', $login, false, $substitutions);
        return true;
    } else {
        msg($lang['regmailfail'], -1);
        return false;
    }
}

/**
 * Update user profile
 *
 * @author    Christopher Smith <chris@jalakai.co.uk>
 */
function updateprofile() {
    global $conf;
    global $lang;
    /* @var auth_basic $auth */
    global $auth;
    /* @var Input $INPUT */
    global $INPUT;

    if(!$INPUT->post->bool('save')) return false;
    if(!checkSecurityToken()) return false;

    if(!actionOK('profile')) {
        msg($lang['profna'], -1);
        return false;
    }

    $changes         = array();
    $changes['pass'] = $INPUT->post->str('newpass');
    $changes['name'] = $INPUT->post->str('fullname');
    $changes['mail'] = $INPUT->post->str('email');

    // check misspelled passwords
    if($changes['pass'] != $INPUT->post->str('passchk')) {
        msg($lang['regbadpass'], -1);
        return false;
    }

    // clean fullname and email
    $changes['name'] = trim(preg_replace('/[\x00-\x1f:<>&%,;]+/', '', $changes['name']));
    $changes['mail'] = trim(preg_replace('/[\x00-\x1f:<>&%,;]+/', '', $changes['mail']));

    // no empty name and email (except the backend doesn't support them)
    if((empty($changes['name']) && $auth->canDo('modName')) ||
        (empty($changes['mail']) && $auth->canDo('modMail'))
    ) {
        msg($lang['profnoempty'], -1);
        return false;
    }
    if(!mail_isvalid($changes['mail']) && $auth->canDo('modMail')) {
        msg($lang['regbadmail'], -1);
        return false;
    }

    $changes = array_filter($changes);

    // check for unavailable capabilities
    if(!$auth->canDo('modName')) unset($changes['name']);
    if(!$auth->canDo('modMail')) unset($changes['mail']);
    if(!$auth->canDo('modPass')) unset($changes['pass']);

    // anything to do?
    if(!count($changes)) {
        msg($lang['profnochange'], -1);
        return false;
    }

    if($conf['profileconfirm']) {
        if(!$auth->checkPass($_SERVER['REMOTE_USER'], $INPUT->post->str('oldpass'))) {
            msg($lang['badlogin'], -1);
            return false;
        }
    }

    if($result = $auth->triggerUserMod('modify', array($_SERVER['REMOTE_USER'], $changes))) {
        // update cookie and session with the changed data
        if($changes['pass']) {
            list( /*user*/, $sticky, /*pass*/) = auth_getCookie();
            $pass = PMA_blowfish_encrypt($changes['pass'], auth_cookiesalt(!$sticky));
            auth_setCookie($_SERVER['REMOTE_USER'], $pass, (bool) $sticky);
        }
        return true;
    }

    return false;
}

/**
 * Send a  new password
 *
 * This function handles both phases of the password reset:
 *
 *   - handling the first request of password reset
 *   - validating the password reset auth token
 *
 * @author Benoit Chesneau <benoit@bchesneau.info>
 * @author Chris Smith <chris@jalakai.co.uk>
 * @author Andreas Gohr <andi@splitbrain.org>
 *
 * @return bool true on success, false on any error
 */
function act_resendpwd() {
    global $lang;
    global $conf;
    /* @var auth_basic $auth */
    global $auth;
    /* @var Input $INPUT */
    global $INPUT;

    if(!actionOK('resendpwd')) {
        msg($lang['resendna'], -1);
        return false;
    }

    $token = preg_replace('/[^a-f0-9]+/', '', $INPUT->str('pwauth'));

    if($token) {
        // we're in token phase - get user info from token

        $tfile = $conf['cachedir'].'/'.$token{0}.'/'.$token.'.pwauth';
        if(!@file_exists($tfile)) {
            msg($lang['resendpwdbadauth'], -1);
            $INPUT->remove('pwauth');
            return false;
        }
        // token is only valid for 3 days
        if((time() - filemtime($tfile)) > (3 * 60 * 60 * 24)) {
            msg($lang['resendpwdbadauth'], -1);
            $INPUT->remove('pwauth');
            @unlink($tfile);
            return false;
        }

        $user     = io_readfile($tfile);
        $userinfo = $auth->getUserData($user);
        if(!$userinfo['mail']) {
            msg($lang['resendpwdnouser'], -1);
            return false;
        }

        if(!$conf['autopasswd']) { // we let the user choose a password
            $pass = $INPUT->str('pass');

            // password given correctly?
            if(!$pass) return false;
            if($pass != $INPUT->str('passchk')) {
                msg($lang['regbadpass'], -1);
                return false;
            }

            // change it
            if(!$auth->triggerUserMod('modify', array($user, array('pass' => $pass)))) {
                msg('error modifying user data', -1);
                return false;
            }

        } else { // autogenerate the password and send by mail

            $pass = auth_pwgen();
            if(!$auth->triggerUserMod('modify', array($user, array('pass' => $pass)))) {
                msg('error modifying user data', -1);
                return false;
            }

            if(auth_sendPassword($user, $pass)) {
                msg($lang['resendpwdsuccess'], 1);
            } else {
                msg($lang['regmailfail'], -1);
            }
        }

        @unlink($tfile);
        return true;

    } else {
        // we're in request phase

        if(!$INPUT->post->bool('save')) return false;

        if(!$INPUT->post->str('login')) {
            msg($lang['resendpwdmissing'], -1);
            return false;
        } else {
            $user = trim($auth->cleanUser($INPUT->post->str('login')));
        }

        $userinfo = $auth->getUserData($user);
        if(!$userinfo['mail']) {
            msg($lang['resendpwdnouser'], -1);
            return false;
        }

        // generate auth token
        $token = md5(auth_cookiesalt().$user); //secret but user based
        $tfile = $conf['cachedir'].'/'.$token{0}.'/'.$token.'.pwauth';
        $url   = wl('', array('do'=> 'resendpwd', 'pwauth'=> $token), true, '&');

        io_saveFile($tfile, $user);

        $text = rawLocale('pwconfirm');
        $trep = array(
            'FULLNAME' => $userinfo['name'],
            'LOGIN'    => $user,
            'CONFIRM'  => $url
        );

        $mail = new Mailer();
        $mail->to($userinfo['name'].' <'.$userinfo['mail'].'>');
        $mail->subject($lang['regpwmail']);
        $mail->setBody($text, $trep);
        if($mail->send()) {
            msg($lang['resendpwdconfirm'], 1);
        } else {
            msg($lang['regmailfail'], -1);
        }
        return true;
    }
    // never reached
}

/**
 * Encrypts a password using the given method and salt
 *
 * If the selected method needs a salt and none was given, a random one
 * is chosen.
 *
 * @author  Andreas Gohr <andi@splitbrain.org>
 * @param string $clear The clear text password
 * @param string $method The hashing method
 * @param string $salt A salt, null for random
 * @return  string  The crypted password
 */
function auth_cryptPassword($clear, $method = '', $salt = null) {
    global $conf;
    if(empty($method)) $method = $conf['passcrypt'];

    $pass = new PassHash();
    $call = 'hash_'.$method;

    if(!method_exists($pass, $call)) {
        msg("Unsupported crypt method $method", -1);
        return false;
    }

    return $pass->$call($clear, $salt);
}

/**
 * Verifies a cleartext password against a crypted hash
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 * @param  string $clear The clear text password
 * @param  string $crypt The hash to compare with
 * @return bool true if both match
 */
function auth_verifyPassword($clear, $crypt) {
    $pass = new PassHash();
    return $pass->verify_hash($clear, $crypt);
}

/**
 * Set the authentication cookie and add user identification data to the session
 *
 * @param string  $user       username
 * @param string  $pass       encrypted password
 * @param bool    $sticky     whether or not the cookie will last beyond the session
 * @return bool
 */
function auth_setCookie($user, $pass, $sticky) {
    global $conf;
    /* @var auth_basic $auth */
    global $auth;
    global $USERINFO;

    if(!$auth) return false;
    $USERINFO = $auth->getUserData($user);

    // set cookie
    $cookie    = base64_encode($user).'|'.((int) $sticky).'|'.base64_encode($pass);
    $cookieDir = empty($conf['cookiedir']) ? DOKU_REL : $conf['cookiedir'];
    $time      = $sticky ? (time() + 60 * 60 * 24 * 365) : 0; //one year
    if(version_compare(PHP_VERSION, '5.2.0', '>')) {
        setcookie(DOKU_COOKIE, $cookie, $time, $cookieDir, '', ($conf['securecookie'] && is_ssl()), true);
    } else {
        setcookie(DOKU_COOKIE, $cookie, $time, $cookieDir, '', ($conf['securecookie'] && is_ssl()));
    }
    // set session
    $_SESSION[DOKU_COOKIE]['auth']['user'] = $user;
    $_SESSION[DOKU_COOKIE]['auth']['pass'] = sha1($pass);
    $_SESSION[DOKU_COOKIE]['auth']['buid'] = auth_browseruid();
    $_SESSION[DOKU_COOKIE]['auth']['info'] = $USERINFO;
    $_SESSION[DOKU_COOKIE]['auth']['time'] = time();

    return true;
}

/**
 * Returns the user, (encrypted) password and sticky bit from cookie
 *
 * @returns array
 */
function auth_getCookie() {
    if(!isset($_COOKIE[DOKU_COOKIE])) {
        return array(null, null, null);
    }
    list($user, $sticky, $pass) = explode('|', $_COOKIE[DOKU_COOKIE], 3);
    $sticky = (bool) $sticky;
    $pass   = base64_decode($pass);
    $user   = base64_decode($user);
    return array($user, $sticky, $pass);
}

//Setup VIM: ex: et ts=2 :
