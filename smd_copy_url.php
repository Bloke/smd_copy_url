<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'smd_copy_url';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.1.0';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'Copy article URL to the clipboard';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '4';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
if (txpinterface === 'admin') {
    new smd_copy_url();
}

/**
 * Admin class for copying the URLs to clipboard.
 */
class smd_copy_url
{
    /**
     * The icon used ono the admin panels.
     *
     * @var string
     */
    protected $copyIcon = <<<EOSVG
<svg xmlns="http://www.w3.org/2000/svg" class="smd_url_copy_icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-link"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 15l6 -6" /><path d="M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464" /><path d="M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463" /></svg>
EOSVG;

    /**
     * Constructor to set up callbacks and environment.
     *
     * Access is also logged so we know who's logged in and active.
     */
    public function __construct()
    {
        add_privs('smd_copy_url_ajax','1,2,3,4,5,6');
        register_callback(array($this, 'smd_copy_url_ajax'), 'smd_copy_url_ajax');
        register_callback(array($this, 'smd_copy_write_url'), 'article_ui', 'url_title');
        register_callback(array($this, 'smd_copy_articles_url'), 'list');
    }

    /**
     * Jump off for Ajax functionality.
     *
     * @param  string $evt  Textpattern event
     * @param  string $stp  Textpattern step
     */
    public function smd_copy_url_ajax($evt, $stp)
    {
        $available_steps = array(
            'smd_copy_url_get_article' => true,
        );

        if (!$stp or !bouncer($stp, $available_steps)) {
            // Do nothing
        } else {
            $this->$stp();
        }
    }

    /**
     * Fetch an article given its ID.
     *
     * @return JSON  Record set
     */
    public function smd_copy_url_get_article()
    {
        $id = intval(gps('id'));
        $rs = safe_rows_start(
            "*, UNIX_TIMESTAMP(Posted) AS sPosted,
            UNIX_TIMESTAMP(Expires) AS sExpires,
            UNIX_TIMESTAMP(LastMod) AS sLastMod",
            'textpattern',
            "ID = $id"
        );

        send_json_response($rs);
        exit;
    }

    /**
     * Return the JS common to the various panels.
     */
    protected function get_common_js()
    {
        return <<<EOJS
function fallbackCopyTextToClipboard(text)
{
    var textArea = document.createElement("textarea");
    textArea.value = text;

    // Avoid scrolling to bottom
    textArea.style.top = "0";
    textArea.style.left = "0";
    textArea.style.position = "fixed";

    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
        var successful = document.execCommand('copy');
        var msg = successful ? 'successful' : 'unsuccessful';
        console.log('smd_copy_url: URL clipboard copy: ' + msg);
    } catch (err) {
        console.error('smd_copy_url: Unable to fallback copy URL', err);
    }

    document.body.removeChild(textArea);
}

function copyTextToClipboard(text) {
    if (!navigator.clipboard) {
        fallbackCopyTextToClipboard(text);
        return;
    }

    navigator.clipboard.writeText(text).then(function() {
        console.log('smd_copy_url: URL copied to clipboard');
    }, function (err) {
        console.error('smd_copy_url: Unable to copy URL', err);
    });
}
EOJS;
    }

    /**
     * Fetch the section => permlink_mode mapping.
     *
     * @return JSON
     */
    protected function get_section_data()
    {
        global $txp_sections, $permlink_mode;

        $secData = array();

        foreach ($txp_sections as $sec => $blob) {
            $secData[$sec] = $blob['permlink_mode'] ? $blob['permlink_mode'] : $permlink_mode;
        }

        $secObj = json_encode($secData);

        return $secObj;
    }

    /**
     * Add the URL copy functionality to the Write panel.
     *
     * @param  string $evt  Textpattern event
     * @param  string $stp  Textpattern step
     * @param  string $data HTML block
     * @param  array  $rs   Record set of the current article
     * @return string       HTML
     */
    public function smd_copy_write_url($evt, $stp, $data, $rs)
    {
        $secJSON = $this->get_section_data();
        $commonJS = $this->get_common_js();

        return $data . n . script_js($commonJS . n . <<<EOJS
document.addEventListener("DOMContentLoaded", function() {
    let smd_copy_url_field = document.getElementsByClassName('txp-form-field url-title')[0].getElementsByTagName('label')[0];
    let smd_copy_url_secdata = {$secJSON};
    let svgContainer = document.createElement('span');
    svgContainer.innerHTML = '{$this->copyIcon}';
    smd_copy_url_field.append(svgContainer);

    let copy_url_button = document.querySelector('.smd_url_copy_icon');

    copy_url_button.addEventListener('click', function(event) {
        event.preventDefault();
        let sec = document.getElementById('section').value;
        let url = document.getElementById('url-title').value;
        let plink = smd_copy_url_secdata[sec];
        let msg = '';

        switch (plink) {
            case 'messy':
                let mid = document.getElementsByName('ID')[0].value;
                if (mid != 0) {
                    msg = '?id='+mid;
                }
                break;
            case 'year_month_day_title':
                let yr = document.getElementById('year').value;
                let mo = document.getElementById('month').value;
                let dy = document.getElementById('day').value;
                msg = '/'+yr+'/'+mo+'/'+dy+'/'+url;
                break;
            case 'section_category_title':
                let cat1 = document.getElementById('category-1').value;
                let cat2 = document.getElementById('category-2').value;
                msg = '/'+sec+(cat1 ? '/'+cat1 : '')+(cat2 ? '/'+cat2 : '')+'/'+url;
                break;
            case 'section_id_title':
                let sid = document.getElementsByName('ID')[0].value;
                if (sid != 0) {
                    msg = '/'+sec+'/'+sid+'/'+url;
                }
                break;
            case 'id_title':
                let tid = document.getElementsByName('ID')[0].value;
                if (tid != 0) {
                    msg = '/'+tid+'/'+url;
                }
                break;
            case 'title_only':
                msg = '/'+url;
                break;
            default:
                msg = '/'+sec+'/'+url;
                break;
        }

        copyTextToClipboard(msg);
        return false;
    });
});
EOJS
        );
    }

    /**
     * Add the URL copy functionality to the Articles list panel.
     *
     * @param  string $evt Textpattern event
     * @param  string $stp Textpattern step
     * @return string      HTML
     */
    public function smd_copy_articles_url($evt, $stp)
    {
        $secJSON = $this->get_section_data();
        $commonJS = $this->get_common_js();

        echo script_js($commonJS . n . <<<EOJS
// Callback function to execute when mutations are observed.
const smd_copy_url_reattach = (mutationList, observer) => {
    for (const mutation of mutationList) {
        if (mutation.type === "childList") {
            smd_copy_url_attach();
        }
    }
};

// Callback function to add the icons to each table row.
const smd_copy_url_attach = function() {
    let smd_copy_url_rows = document.querySelectorAll('td.txp-list-col-title a');
    smd_copy_url_rows.forEach(function (currentValue, currentIndex, listObj) {
        var svgContainer = document.createElement('span');
        svgContainer.innerHTML = '{$this->copyIcon}';
        currentValue.after(svgContainer);
    });
}

document.addEventListener("DOMContentLoaded", function() {
    let smd_copy_url_hook = document.querySelector('#list_container');
    let smd_copy_url_secdata = {$secJSON};

    // Options for the observer (which mutations to observe)
    const observerConfig = { attributes: false, childList: true, subtree: false };

    // Create an observer instance linked to the callback function
    const observer = new MutationObserver(smd_copy_url_reattach);

    // Start observing the target node for configured mutations
    observer.observe(smd_copy_url_hook, observerConfig);

    smd_copy_url_hook.addEventListener('click', (e) => {
        if (e.target.classList.contains('smd_url_copy_icon')) {
            let row = e.target.closest('tr');
            let artid = row.querySelector('th a').text;
            sendAsyncEvent({
                    event: 'smd_copy_url_ajax',
                    step: 'smd_copy_url_get_article',
                    id: artid
                }, function () {}, 'json').done(function (data, textStatus, jqXHR) {
                    let sec = data[0].Section;
                    let url = data[0].url_title;
                    let plink = smd_copy_url_secdata[sec];
                    let msg = '';

                    switch (plink) {
                        case 'messy':
                            let mid = data[0].ID;
                            msg = '?id='+mid;
                            break;
                        case 'year_month_day_title':
                            const date = new Date(parseInt(data[0].sPosted * 1000));
                            let yr = date.getFullYear();
                            let mo = date.getMonth();
                            let dy = date.getDate();
                            msg = '/'+yr+'/'+mo+'/'+dy+'/'+url;
                            break;
                        case 'section_category_title':
                            let cat1 = data[0].Category1;
                            let cat2 = data[0].Category2;
                            msg = '/'+sec+(cat1 ? '/'+cat1 : '')+(cat2 ? '/'+cat2 : '')+'/'+url;
                            break;
                        case 'section_id_title':
                            let sid = data[0].ID;
                            msg = '/'+sec+'/'+sid+'/'+url;
                            break;
                        case 'id_title':
                            let tid = data[0].ID;
                            msg = '/'+tid+'/'+url;
                            break;
                        case 'title_only':
                            msg = '/'+url;
                            break;
                        default:
                            msg = '/'+sec+'/'+url;
                            break;
                    }

                    copyTextToClipboard(msg);
                    return false;
                }).fail(function (jqXHR, textStatus, errorThrown) {
                    console.log('failed', jqXHR, textStatus, errorThrown);
                });
        }
    });

    smd_copy_url_attach();
});
EOJS
        );
    }
}


# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. smd_copy_url

Adds a small 'copy' link alongside the url_title on the Write panel, and alongside each title on the Articles list panel. Click that to copy the current article URL to the clipboard (without domain), which you can use in Textile links and anchors to refer to the current article.

Although it seems superfluous on the Articles panel because you can just copy the value in the Status column, this plugin has one advantage: the URL is valid regardless of the article status. Draft, Hidden, and Pending articles all return the final URL that will be used when/if the article is published. Handy for creating links to articles that aren't yet published.

Notes:

* The plugin takes into account all permlink schemes _except_ @/breadcrumb/title@ (at the moment: ideas welcome on how to sanely handle it).
* If you are creating a new article, you cannot use the copy URL link if you are using any permlink scheme that requires an ID (messy, /section/id/title, or /id/title). You must save the article first so it is assigned an ID.
* There's currently no feedback to know that you've copied the link (besides a notification in the JavaScript console). Anybody who has ideas on how best to indicate the action has succeeded, please speak up.

# --- END PLUGIN HELP ---
-->
<?php
}
?>