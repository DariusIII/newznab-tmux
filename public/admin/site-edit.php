<?php

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources/views/themes/smarty.php';

use Blacklight\Sites;
use Blacklight\SABnzbd;
use App\Models\Category;
use App\Models\Settings;
use Blacklight\http\AdminPage;
use Blacklight\utility\Utility;

$page = new AdminPage();

$sites = new Sites();
$id = 0;

// set the current action
$action = request()->input('action') ?? 'view';

switch ($action) {
    case 'submit':

        if (! request()->has('book_reqids')) {
            request()->merge(['book_reqids' => []]);
        }
        $error = '';
        $ret = Settings::settingsUpdate(request()->all());
        if (is_int($ret)) {
            if ($ret === Settings::ERR_BADUNRARPATH) {
                $error = 'The unrar path does not point to a valid binary';
            } elseif ($ret === Settings::ERR_BADFFMPEGPATH) {
                $error = 'The ffmpeg path does not point to a valid binary';
            } elseif ($ret === Settings::ERR_BADMEDIAINFOPATH) {
                $error = 'The mediainfo path does not point to a valid binary';
            } elseif ($ret === Settings::ERR_BADNZBPATH) {
                $error = 'The nzb path does not point to a valid directory';
            } elseif ($ret === Settings::ERR_DEEPNOUNRAR) {
                $error = 'Deep password check requires a valid path to unrar binary';
            } elseif ($ret === Settings::ERR_BADTMPUNRARPATH) {
                $error = 'The temp unrar path is not a valid directory';
            } elseif ($ret === Settings::ERR_BADLAMEPATH) {
                $error = 'The lame path is not a valid directory';
            } elseif ($ret === Settings::ERR_SABCOMPLETEPATH) {
                $error = 'The sab complete path is not a valid directory';
            }
        }

        if ($error === '') {
            $site = $ret;
            $returnid = $site['id'];
            header('Location:'.WWW_TOP.'/site-edit.php?id='.$returnid);
        } else {
            $page->smarty->assign('error', $error);
            $site = $sites->row2Object(request()->all());
            $page->smarty->assign('site', $site);
        }

        break;
    case 'view':
    default:

        $page->title = 'Site Edit';
        $site = $page->settings;
        $page->smarty->assign('site', $site);
        $page->smarty->assign('settings', Settings::toTree());
        break;
}

$page->smarty->assign('yesno_ids', [1, 0]);
$page->smarty->assign('yesno_names', ['Yes', 'No']);

$page->smarty->assign('passwd_ids', [1, 0]);
$page->smarty->assign('passwd_names', ['Deep (requires unrar)', 'None']);

/*0 = English, 2 = Danish, 3 = French, 1 = German*/
$page->smarty->assign('langlist_ids', [0, 2, 3, 1]);
$page->smarty->assign('langlist_names', ['English', 'Danish', 'French', 'German']);

$page->smarty->assign(
    'imdblang_ids',
    [
        'en', 'da', 'nl', 'fi', 'fr', 'de', 'it', 'tlh', 'no', 'po', 'ru', 'es',
        'sv',
    ]
);
$page->smarty->assign(
    'imdblang_names',
    [
        'English', 'Danish', 'Dutch', 'Finnish', 'French', 'German', 'Italian',
        'Klingon', 'Norwegian', 'Polish', 'Russian', 'Spanish', 'Swedish',
    ]
);

$page->smarty->assign('sabintegrationtype_ids', [SABnzbd::INTEGRATION_TYPE_USER, SABnzbd::INTEGRATION_TYPE_NONE]);
$page->smarty->assign('sabintegrationtype_names', ['User', 'None (Off)']);

$page->smarty->assign('newgroupscan_names', ['Days', 'Posts']);

$page->smarty->assign('registerstatus_ids', [Settings::REGISTER_STATUS_OPEN, Settings::REGISTER_STATUS_INVITE, Settings::REGISTER_STATUS_CLOSED]);
$page->smarty->assign('registerstatus_names', ['Open', 'Invite', 'Closed']);

$page->smarty->assign('passworded_ids', [0, 10]);
$page->smarty->assign('passworded_names', [
    'Hide passworded or potentially passworded',
    'Show everything',
]);

$page->smarty->assign('lookuplanguage_iso', ['en', 'de', 'es', 'fr', 'it', 'nl', 'pt', 'sv']);
$page->smarty->assign('lookuplanguage_names', ['English', 'Deutsch', 'Español', 'Français', 'Italiano', 'Nederlands', 'Português', 'Svenska']);

$page->smarty->assign('imdb_urls', [0, 1]);
$page->smarty->assign('imdburl_names', ['imdb.com', 'akas.imdb.com']);

$page->smarty->assign('lookupbooks_ids', [0, 1, 2]);
$page->smarty->assign('lookupbooks_names', ['Disabled', 'Lookup All Books', 'Lookup Renamed Books']);

$page->smarty->assign('lookupgames_ids', [0, 1, 2]);
$page->smarty->assign('lookupgames_names', ['Disabled', 'Lookup All Consoles', 'Lookup Renamed Consoles']);

$page->smarty->assign('lookupmusic_ids', [0, 1, 2]);
$page->smarty->assign('lookupmusic_names', ['Disabled', 'Lookup All Music', 'Lookup Renamed Music']);

$page->smarty->assign('lookupmovies_ids', [0, 1, 2]);
$page->smarty->assign('lookupmovies_names', ['Disabled', 'Lookup All Movies', 'Lookup Renamed Movies']);

$page->smarty->assign('lookuptv_ids', [0, 1, 2]);
$page->smarty->assign('lookuptv_names', ['Disabled', 'Lookup All TV', 'Lookup Renamed TV']);

$page->smarty->assign('lookup_reqids_ids', [0, 1, 2]);
$page->smarty->assign('lookup_reqids_names', ['Disabled', 'Lookup Request IDs', 'Lookup Request IDs Threaded']);

$page->smarty->assign('coversPath', NN_COVERS);

// return a list of audiobooks, mags, ebooks, technical and foreign books
$result = Category::query()->whereIn('id', [Category::MUSIC_AUDIOBOOK, Category::BOOKS_MAGAZINES, Category::BOOKS_TECHNICAL, Category::BOOKS_FOREIGN])->get(['id', 'title']);

// setup the display lists for these categories, this could have been static, but then if names changed they would be wrong
$book_reqids_ids = [];
$book_reqids_names = [];
foreach ($result as $bookcategory) {
    $book_reqids_ids[] = $bookcategory['id'];
    $book_reqids_names[] = $bookcategory['title'];
}

// convert from a string array to an int array as we want to use int
$book_reqids_ids = array_map(function ($value) {
    return (int) $value;
}, $book_reqids_ids);
$page->smarty->assign('book_reqids_ids', $book_reqids_ids);
$page->smarty->assign('book_reqids_names', $book_reqids_names);

// convert from a list to an array as we need to use an array, but teh Settings table only saves strings
$books_selected = explode(',', Settings::settingValue('..book_reqids'));

// convert from a string array to an int array
$books_selected = array_map(function ($value) {
    return (int) $value;
}, $books_selected);
$page->smarty->assign('book_reqids_selected', $books_selected);

$page->smarty->assign('themelist', Utility::getThemesList());

if (strpos(env('NNTP_SERVER'), 'astra') === false) {
    $page->smarty->assign('compress_headers_warning', 'compress_headers_warning');
}

$page->content = $page->smarty->fetch('site-edit.tpl');
$page->render();
