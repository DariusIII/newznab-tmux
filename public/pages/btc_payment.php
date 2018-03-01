<?php

use App\Models\User;
use App\Models\UserRole;
use Blacklight\libraries\Geary;

$page = new Page();

if (! User::isLoggedIn()) {
    $page->show403();
}

$gateway_id = env('MYCELIUM_GATEWAY_ID');
$gateway_secret = env('MYCELIUM_GATEWAY_SECRET');

$userId = User::currentUserId();
$user = User::find($userId);
$action = \request()->input('action') ?? 'view';
$donation = UserRole::query()->where('donation', '>', 0)->get(['id', 'name', 'donation', 'addyears']);
$page->smarty->assign('donation', $donation);

switch ($action) {
    case 'submit':
        $price = \request()->input('price');
        $role = \request()->input('role');
        $roleName = \request()->input('rolename');
        $addYears = \request()->input('addyears');
        $data = ['user_id' => $userId, 'username' => $user->username, 'price' => $price, 'role' => $role, 'rolename' => $roleName, 'addyears' => $addYears];
        $keychain_id = random_int(0, 19);
        $callback_data = json_encode($data);

        $geary = new Geary($gateway_id, $gateway_secret);
        $order = $geary->create_order($price, $keychain_id, $callback_data);

        if ($order->payment_id) {
            // Redirect to a payment gateway
            $url = 'https://gateway.gear.mycelium.com/pay/'.$order->payment_id;
            header('Location: '.$url);
            die();
        }
        break;
    case 'view':
    default:
        $userId = User::currentUserId();
        break;
}

$page->title = 'Become a supporter';
$page->meta_title = 'Become a supporter';
$page->meta_description = 'Become a supporter';

$page->content = $page->smarty->fetch('btc_payment.tpl');
$page->render();
