<?php

use Blacklight\http\Page;

require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'resources/views/themes/smarty.php';

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);

$page = new Page;

if ($app->isDownForMaintenance()) {
    $page->showMaintenance();
}

switch ($page->page) {
    case 'ajax_mediainfo':
    case 'ajax_mymovies':
    case 'ajax_preinfo':
    case 'ajax_profile':
    case 'ajax_release-admin':
    case 'ajax_resetusergrabs-admin':
    case 'ajax_rarfilelist':
    case 'ajax_titleinfo':
    case 'ajax_tvinfo':
    case 'anime':
    case 'apihelp':
    case 'bookmodal':
    case 'books':
    case 'browse':
    case 'browsegroup':
    case 'btc_payment':
    case 'btc_payment_callback':
    case 'cart':
    case 'console':
    case 'consolemodal':
    case 'contact-us':
    case 'content':
    case 'details':
    case 'filelist':
    case 'forgottenpassword':
    case 'forum':
    case 'forumpost':
    case 'games':
    case 'getimage':
    case 'logout':
    case 'movies':
    case 'movie':
    case 'music':
    case 'musicmodal':
    case 'myshows':
    case 'mymovies':
    case 'mymoviesedit':
    case 'nfo':
    case 'nzbgetqueuedata':
    case 'password':
    case 'post_edit':
    case 'profile':
    case 'profileedit':
    case 'profile_delete':
    case 'queue':
    case 'register':
    case 'sabqueuedata':
    case 'search':
    case 'sendtocouch':
    case 'sendtoqueue':
    case 'series':
    case 'terms-and-conditions':
    case 'topic_delete':
    case 'upcoming':
    case 'xxx':
    case 'xxxmodal':
    case 'api':
    case 'failed':
    case 'getnzb':
    case 'login':
    case 'rss':
        include NN_WWW.'pages/'.$page->page.'.php';
        break;
    default:
        $page->show404();
        break;
}
