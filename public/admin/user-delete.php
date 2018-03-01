<?php

require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'smarty.php';

use App\Models\User;

$page = new AdminPage();

if (\request()->has('id')) {
    User::deleteUser(\request()->input('id'));
}

if (\request()->has('redir')) {
    header('Location: '.\request()->input('redir'));
} else {
    $referrer = \request()->server('HTTP_REFERER');
    header('Location: '.$referrer);
}
