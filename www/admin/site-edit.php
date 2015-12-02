<?php
require_once("config.php");

use newznab\db\Settings;
use newznab\SABnzbd;

$page  = new AdminPage();
$id    = 0;
$error = '';

// set the current action
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'view';

switch($action)
{
	case 'submit':

		if (!empty($_POST['book_reqids'])) {
			// book_reqids is an array it needs to be a comma separated string, make it so.
			$_POST['book_reqids'] = is_array($_POST['book_reqids']) ?
				implode(', ', $_POST['book_reqids']) : $_POST['book_reqids'];
		}
		$ret = $page->settings->update($_POST);
		if (is_int($ret)) {
			// TODO convert to switch
			if ($ret == Settings::ERR_BADUNRARPATH) {
				$error = "The unrar path does not point to a valid binary";
			} else {
				if ($ret == Settings::ERR_BADFFMPEGPATH) {
					$error = "The ffmpeg path does not point to a valid binary";
				} else {
					if ($ret == Settings::ERR_BADMEDIAINFOPATH) {
						$error = "The mediainfo path does not point to a valid binary";
					} else {
						if ($ret == Settings::ERR_BADNZBPATH) {
							$error = "The nzb path does not point to an existing directory";
						} else {
							if ($ret == Settings::ERR_DEEPNOUNRAR) {
								$error = "Deep password check requires a valid path to unrar binary";
							} else {
								if ($ret == Settings::ERR_BADTMPUNRARPATH) {
									$error = "The temp unrar path is not a valid directory";
								} else {
									if ($ret == Settings::ERR_BADNZBPATH_UNREADABLE) {
										$error = "The nzb path cannot be read from. Check the permissions.";
									} else {
										if ($ret == Settings::ERR_BADNZBPATH_UNSET) {
											$error = "The nzb path is required, please set it.";
										} else {
											if ($ret == Settings::ERR_BAD_COVERS_PATH) {
												$error = 'The covers&apos; path is required and must exist. Please set it.';
											} else {
												if ($ret == Settings::ERR_BAD_YYDECODER_PATH) {
													$error = 'The yydecoder&apos;s path must exist. Please set it or leave it empty.';
												}
											}
										}
									}
								}
							}
						}
					}
				}
			}
		}

		if ($error == "") {
			$site     = $ret;
			$returnid = $site['id'];
			header("Location:" . WWW_TOP . "/site-edit.php?id=" . $returnid);
		} else {
			$page->smarty->assign('error', $error);
			$page->smarty->assign('settings', $page->settings->rowsToArray($_POST));
		}
		break;

	case 'view':
	default:
		$page->title = "Site Edit";
		$site        = $page->settings;
		$page->smarty->assign('site', $site);
		$page->smarty->assign('settings', $site->getSettingsAsTree());

		break;
}

$page->smarty->assign('yesno_ids', array(1,0));
$page->smarty->assign('yesno_names', array( 'Yes', 'No'));

$page->smarty->assign('passwd_ids', array(1,0));
$page->smarty->assign('passwd_names', array( 'Deep (requires unrar)', 'None'));

/*0 = English, 2 = Danish, 3 = French, 1 = German*/
$page->smarty->assign('langlist_ids', [0, 2, 3, 1]);
$page->smarty->assign('langlist_names', ['English', 'Danish', 'French', 'German']);

$page->smarty->assign('imdblang_ids',
	[
		'en', 'da', 'nl', 'fi', 'fr', 'de', 'it', 'tlh', 'no', 'po', 'ru', 'es',
		'sv'
	]);
$page->smarty->assign('imdblang_names',
	[
		'English', 'Danish', 'Dutch', 'Finnish', 'French', 'German', 'Italian',
		'Klingon', 'Norwegian', 'Polish', 'Russian', 'Spanish', 'Swedish'
	]);

$page->smarty->assign('sabintegrationtype_ids', array(SABnzbd::INTEGRATION_TYPE_USER, SABnzbd::INTEGRATION_TYPE_SITEWIDE, SABnzbd::INTEGRATION_TYPE_NONE));
$page->smarty->assign('sabintegrationtype_names', array( 'User', 'Site-wide', 'None (Off)'));

$page->smarty->assign('sabapikeytype_ids', array(SABnzbd::API_TYPE_NZB,SABnzbd::API_TYPE_FULL));
$page->smarty->assign('sabapikeytype_names', array( 'Nzb Api Key', 'Full Api Key'));

$page->smarty->assign('sabpriority_ids', array(SABnzbd::PRIORITY_FORCE, SABnzbd::PRIORITY_HIGH, SABnzbd::PRIORITY_NORMAL, SABnzbd::PRIORITY_LOW));
$page->smarty->assign('sabpriority_names', array( 'Force', 'High', 'Normal', 'Low'));

$page->smarty->assign('curlproxytype_names', array( '', 'HTTP', 'SOCKS5'));

$page->smarty->assign('newgroupscan_names', array('Days','Posts'));

$page->smarty->assign('registerstatus_ids', array(Settings::REGISTER_STATUS_API_ONLY, Settings::REGISTER_STATUS_OPEN, Settings::REGISTER_STATUS_INVITE, Settings::REGISTER_STATUS_CLOSED));
$page->smarty->assign('registerstatus_names', array('API Only', 'Open', 'Invite', 'Closed'));

$page->smarty->assign('passworded_ids', array(0,1,2));
$page->smarty->assign('passworded_names', [
	'Hide passworded or potentially passworded (*yes)',
	'Hide passworded or potentially passworded (*no)',
	'Show non-passworded and potentially passworded (*no)',
	'Show everything (*no)'
]);

$page->smarty->assign('sphinxrebuildfreqday_days', array('', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'));

$page->smarty->assign('lookuplanguage_iso', array('en', 'de', 'es', 'fr', 'it', 'nl', 'pt', 'sv'));
$page->smarty->assign('lookuplanguage_names', array('English', 'Deutsch', 'Español', 'Français', 'Italiano', 'Nederlands', 'Português', 'Svenska'));

$page->smarty->assign('imdb_urls', array(0,1));
$page->smarty->assign('imdburl_names', array('imdb.com', 'akas.imdb.com'));

$page->smarty->assign('lookupbooks_ids', [0,1,2]);
$page->smarty->assign('lookupbooks_names', ['Disabled', 'Lookup All Books', 'Lookup Renamed Books']);

$page->smarty->assign('lookupgames_ids', [0,1,2]);
$page->smarty->assign('lookupgames_names', ['Disabled', 'Lookup All Consoles', 'Lookup Renamed Consoles']);

$page->smarty->assign('lookupmusic_ids', [0,1,2]);
$page->smarty->assign('lookupmusic_names', ['Disabled', 'Lookup All Music', 'Lookup Renamed Music']);

$page->smarty->assign('lookupmovies_ids', [0,1,2]);
$page->smarty->assign('lookupmovies_names', ['Disabled', 'Lookup All Movies', 'Lookup Renamed Movies']);

$page->smarty->assign('lookuptv_ids', [0,1,2]);
$page->smarty->assign('lookuptv_names', ['Disabled', 'Lookup All TV', 'Lookup Renamed TV']);

$page->smarty->assign('lookup_reqids_ids', array(0,1,2));
$page->smarty->assign('lookup_reqids_names', array('Disabled', 'Lookup Request IDs', 'Lookup Request IDs Threaded'));

$page->smarty->assign('coversPath', NN_COVERS);

// return a list of audiobooks, mags, ebooks, technical and foreign books
$result = $page->settings->query("SELECT id, title FROM category WHERE id in (3030, 7010, 7020, 7040, 7060)");

// setup the display lists for these categories, this could have been static, but then if names changed they would be wrong
$book_reqids_ids = [];
$book_reqids_names = [];
foreach ($result as $bookcategory)
{
	$book_reqids_ids[]   = $bookcategory["id"];
	$book_reqids_names[] = $bookcategory["title"];
}

// convert from a string array to an int array as we want to use int
$book_reqids_ids = array_map(create_function('$value', 'return (int)$value;'), $book_reqids_ids);
$page->smarty->assign('book_reqids_ids', $book_reqids_ids);
$page->smarty->assign('book_reqids_names', $book_reqids_names);

// convert from a list to an array as we need to use an array, but teh Settings table only saves strings
$books_selected = explode(",", $page->settings->book_reqids);

// convert from a string array to an int array
$books_selected = array_map(create_function('$value', 'return (int)$value;'), $books_selected);
$page->smarty->assign('book_reqids_selected', $books_selected);

$themelist = [];
$themes    = scandir(NN_WWW . "/themes");
foreach ($themes as $theme) {
	if (strpos($theme, ".") === false && is_dir(NN_THEMES . $theme) && ucfirst($theme) === $theme) {
		$themelist[] = $theme;
	}
}
sort($themelist);

$page->smarty->assign('themelist', $themelist);

if (strpos(NNTP_SERVER, "astra")===false)
	$page->smarty->assign('compress_headers_warning', "compress_headers_warning");

$page->content = $page->smarty->fetch('site-edit.tpl');
$page->render();
