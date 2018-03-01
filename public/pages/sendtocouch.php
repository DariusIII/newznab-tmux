<?php

use App\Models\User;
use Blacklight\CouchPotato;

if (! User::isLoggedIn()) {
    $page->show403();
}

if (empty(\request()->input('id'))) {
    $page->show404();
} else {
    $cp = new CouchPotato($page);

    if (empty($cp->cpurl)) {
        $page->show404();
    }

    if (empty($cp->cpapi)) {
        $page->show404();
    }
    $id = \request()->input('id');
    $cp->sendToCouchPotato($id);
}
