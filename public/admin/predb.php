<?php

use App\Models\Predb;

require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'smarty.php';

$page = new AdminPage();

$offset = (\request()->has('offset') && ctype_digit(\request()->input('offset'))) ? \request()->input('offset') : 0;

if (\request()->has('presearch')) {
    $lastSearch = \request()->input('presearch');
    $parr = Predb::getAll($offset, env('ITEMS_PER_PAGE', 50), \request()->input('presearch'));
} else {
    $lastSearch = '';
    $parr = Predb::getAll($offset, env('ITEMS_PER_PAGE', 50));
}

$page->smarty->assign('pagertotalitems', $parr['count']);
$page->smarty->assign('pageroffset', $offset);
$page->smarty->assign('pageritemsperpage', env('ITEMS_PER_PAGE', 50));
$page->smarty->assign('pagerquerybase', WWW_TOP.'/predb.php?offset=');
$page->smarty->assign('pagerquerysuffix', '#results');
$page->smarty->assign('lastSearch', $lastSearch);

$page->smarty->assign('pager', $page->smarty->fetch('pager.tpl'));
$page->smarty->assign('results', $parr['arr']);

$page->title = 'Browse PreDb';
$page->meta_title = 'View PreDb info';
$page->meta_keywords = 'view,predb,info,description,details';
$page->meta_description = 'View PreDb info';

$page->content = $page->smarty->fetch('predb.tpl');
$page->render();
