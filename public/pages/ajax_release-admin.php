<?php

use App\Models\Release;
use App\Models\Category;
use Blacklight\Releases;

$page = new AdminPage();
$releases = new Releases(['Settings' => $page->settings]);

// Set the current action.
$action = $page->request->input('action') ?? '';

// Request is for id, but guid is actually being provided
if ($page->request->has('id') && is_array($page->request->input('id'))) {
    $id = $page->request->input('id');
    //Get info for first guid to populate form
    $rel = Release::getByGuid($page->request->input('id')[0]);
} else {
    $id = $rel = '';
}

$page->smarty->assign('action', $action);
$page->smarty->assign('idArr', $id);

switch ($action) {
    case 'doedit':
    case 'edit':
        $success = false;
        if ($action === 'doedit') {
            $success = $releases->updateMulti(
                $_POST['id'],
                $_POST['category'],
                $_POST['grabs'],
                $_POST['videosid'],
                $_POST['episodesid'],
                $_POST['anidbid'],
                $_POST['imdbid']
            );
        }
        $page->smarty->assign('release', $rel);
        $page->smarty->assign('success', $success);
        $page->smarty->assign('from', $_POST['from'] ?? '');
        $page->smarty->assign('catlist', Category::getForSelect(false));
        $page->content = $page->smarty->fetch('ajax_release-edit.tpl');
        echo $page->content;

        break;
    case 'dodelete':
        $releases->deleteMultiple($page->request->input('id'));
        break;
    default:
        $page->show404();
        break;
}
