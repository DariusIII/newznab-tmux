<?php

require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'smarty.php';

use Blacklight\Books;
use Blacklight\utility\Utility;

$page = new AdminPage();

$book = new Books();

$page->title = 'Book List';

$bookCount = Utility::getCount('bookinfo');

$offset = \request()->input('offset') ?? 0;

$page->smarty->assign([
	'pagertotalitems' => $bookCount,
	'pagerquerysuffix'  => '#results',
	'pageroffset' => $offset,
	'pageritemsperpage' => env('ITEMS_PER_PAGE', 50),
	'pagerquerybase' => WWW_TOP.'/book-list.php?offset=',
]);

$pager = $page->smarty->fetch('pager.tpl');
$page->smarty->assign('pager', $pager);

$bookList = Utility::getRange('bookinfo', $offset, env('ITEMS_PER_PAGE', 50));

$page->smarty->assign('booklist', $bookList);

$page->content = $page->smarty->fetch('book-list.tpl');
$page->render();
