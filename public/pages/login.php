<?php

use App\Models\User;
use Blacklight\Captcha;
use App\Models\Settings;
use Blacklight\utility\Utility;

$page->smarty->assign(['error' => '', 'username' => '', 'rememberme' => '']);

$captcha = new Captcha($page);

if (! User::isLoggedIn()) {
    if (! request()->has('username') && ! request()->has('password')) {
        $page->smarty->assign('error', 'Please enter your username and password.');
    } elseif ($captcha->getError() === false) {
        $username = htmlspecialchars(request()->input('username'), ENT_QUOTES | ENT_HTML5);
        $page->smarty->assign('username', $username);
        if (Utility::checkCSRFToken() === true) {
            $res = User::getByUsername($username);
            if ($res === null) {
                $res = User::getByEmail($username);
            }

            if ($res !== null) {
                $dis = User::isDisabled($username);
                if ($dis) {
                    $page->smarty->assign('error', 'Your account has been disabled.');
                } elseif (User::checkPassword(request()->input('password'), $res['password'], $res['id'])) {
                    $rememberMe = (request()->has('rememberme') && request()->input('rememberme') === 'on');
                    User::login($res['id'], request()->ip(), $rememberMe);

                    if (request()->has('redirect') && request()->input('redirect') !== '') {
                        header('Location: '.request()->input('redirect'));
                    } else {
                        header('Location: '.WWW_TOP.Settings::settingValue('site.main.home_link'));
                    }
                    die();
                } else {
                    $page->smarty->assign('error', 'Incorrect username/email or password.');
                }
            } else {
                $page->smarty->assign('error', 'Incorrect username/email or password.');
            }
        } else {
            $page->showTokenError();
        }
    }
} else {
    header('Location: '.WWW_TOP.Settings::settingValue('site.main.home_link'));
}

$page->smarty->assign('redirect', request()->input('redirect') ?? '');
$page->meta_title = 'Login';
$page->meta_keywords = 'Login';
$page->meta_description = 'Login';
$page->content = $page->smarty->fetch('login.tpl');
$page->render();
