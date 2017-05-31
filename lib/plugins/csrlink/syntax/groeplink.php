<?php

/**
 * DokuWiki Plugin csrlink (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 * @author  Gerrit Uitslag
 */
// must be run within Dokuwiki
if (!defined('DOKU_INC'))
	die();

if (!defined('DOKU_LF'))
	define('DOKU_LF', "\n");
if (!defined('DOKU_TAB'))
	define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN'))
	define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');

require_once DOKU_PLUGIN . 'syntax.php';

class syntax_plugin_csrlink_groeplink extends DokuWiki_Syntax_Plugin {

	function getType() {
		return 'substition';
	}

	function getPType() {
		return 'normal';
	}

	function getSort() {
		return 150;
	}

	function connectTo($mode) {
		$this->Lexer->addSpecialPattern('\[\[groep>.+?\]\]', $mode, 'plugin_csrlink_groeplink');
	}

	function handle($match, $state, $pos, Doku_Handler $handler) {
		$match = trim(substr($match, 8, -2));


		list($groepid, $title) = explode('|', $match, 2);

		return compact('groepid', 'title');
	}

	function render($mode, Doku_Renderer $renderer, $data) {
		global $auth;
		/** @var string $title */
		/** @var string $groepid */
		extract($data);

		if ($mode != 'xhtml' || is_null($auth) || !$auth instanceof auth_plugin_authcsr) {
			$renderer->cdata($title ? $title : $groepid);
			return true;
		}

		try {
			throw new Exception('geen groep');
		} catch (Exception $e) {
			// nothing found? render as text
			$renderer->doc .='<span class="csrlink invalid" title="[[groep>]] Geen geldig groep-id (' . hsc($groepid) . ')">' . hsc($title ? $title : $groepid) . '</span>';
			return true;
		}

		// get a nice title
		if (!$title) {
			$title = $groep->getNaam();
		}

		//return html
		$renderer->doc .= '<a href="' . $groep->getUrl() . '" class="groeplink groeplink_plugin">' . hsc($title) . '</a>';

		return true;
	}

}

// vim:ts=4:sw=4:et:enc=utf-8:
