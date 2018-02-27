<?php

use App\Models\User;
use Blacklight\Console;

if (! User::isLoggedIn()) {
    $page->show403();
}

if ($page->request->has('id') && ctype_digit($page->request->input('id'))) {
    $console = new Console(['Settings' => $page->settings]);
    $con = $console->getConsoleInfo($page->request->input('id'));
    if (! $con) {
        $page->show404();
    }

    $page->smarty->assign('console', $con);

    $page->title = 'Info for '.$con['title'];
    $page->meta_title = '';
    $page->meta_keywords = '';
    $page->meta_description = '';
    $page->smarty->registerPlugin('modifier', 'ss', 'stripslashes');

    $modal = false;
    if (isset($_GET['modal'])) {
        $modal = true;
        $page->smarty->assign('modal', true);
    }

    $page->content = $page->smarty->fetch('viewconsole.tpl');

    if ($modal) {
        echo $page->content;
    } else {
        $page->render();
    }
}
