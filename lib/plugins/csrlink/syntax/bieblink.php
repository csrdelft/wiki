<?php
/**
 * DokuWiki Plugin csrlink (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 * @author  Gerrit Uitslag
 */

// must be run within Dokuwiki
use CsrDelft\model\bibliotheek\BiebBoek;

if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'syntax.php';

class syntax_plugin_csrlink_bieblink extends DokuWiki_Syntax_Plugin {
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
        $this->Lexer->addSpecialPattern('\[\[boek>.+?\]\]',$mode,'plugin_csrlink_bieblink');
    }

    function handle($match, $state, $pos, Doku_Handler $handler){
        $match = trim(substr($match,7,-2));


        @list($boekid, $title) = explode('|', $match, 2);
        @list($boekid, $opts) = explode('?', $boekid, 2);
        $opts = explode('&', $opts);
        foreach($opts as $option) {
            @list($key, $value) = explode(':', $option, 2);
            $options[$key] = $value;
        }
        //options are not yet filtered!
        return compact('boekid', 'title', 'options');
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        global $auth;
        global $conf;
        /** @var string $title */
        /** @var string $boekid */
        /** @var array $options */
        extract($data);

        if($mode != 'xhtml' || is_null($auth) || !$auth instanceof auth_plugin_authcsr){
            $renderer->cdata($title?$title:$boekid);
            return true;
        }

        if(isset($options['auteur']) && $options['auteur'] === 'ja') {
            $showauteur = true;
        } else {
            $showauteur = false;
        }

        require_once 'model/bibliotheek/BiebBoek.php';
        try{
            $boek =    new BiebBoek($boekid);
        }catch(Exception $e){
            // nothing found? render as text
            $renderer->doc .='<span class="csrlink invalid" title="[[boek>]] Geen geldig boek-id ('.hsc($boekid).')">'.hsc($title?$title:$boekid).'</span>';
            return true;
        }

        // get a nice title
        if(!$title){
            $title = $boek->getTitel();
        }

        //return html
        $renderer->doc .= '<a class="bieblink groeplink_plugin" href="' . $boek->getUrl() . '" title="Boek: ' . hsc($boek->getTitel()) . ', Auteur: ' . hsc($boek->getAuteur()) . '">';
        $renderer->doc .= '   <span title="' . $boek->getStatus() . ' boek" class="boekindicator ' . $boek->getStatus() . '">•</span>';
        $renderer->doc .= '   <span class="titel">' . $title . '</span>';
        if($showauteur) {
            $renderer->doc .= ' <span class="auteur">(' . hsc($boek->getAuteur()) . ')</span>';
        }
        $renderer->doc .= '</a>';
        return true;
    }
}

// vim:ts=4:sw=4:et:enc=utf-8:
