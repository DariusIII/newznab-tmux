<?php

use App\Models\ReleaseComment;

require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'smarty.php';

$page = new AdminPage();

if (\request()->has('id')) {
    ReleaseComment::deleteComment(\request()->input('id'));
}

$referrer = \request()->server('HTTP_REFERER');
header('Location: '.$referrer);
