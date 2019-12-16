<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bootstrap/autoload.php';

$users = User::all();

$oldRoles = DB::table('user_roles')->get()->toArray();

$roles = Role::all()->pluck('name')->toArray();

$permissions = Permission::all()->pluck('name')->toArray();

$neededPerms = ['preview', 'hideads', 'edit release', 'view console', 'view movies', 'view audio', 'view pc', 'view tv', 'view adult', 'view books', 'view other'];

foreach ($neededPerms as $neededPerm) {
    if (! in_array($neededPerm, $permissions, false)) {
        Permission::create(['name' => $neededPerm]);
    }
}

foreach ($oldRoles as $oldRole) {
    if (! in_array($oldRole->name, $roles, false)) {
        $role = Role::create(
            [
                'name' => $oldRole->name,
                'apirequests' => $oldRole->apirequests,
                'downloadrequests' => $oldRole->downloadrequests,
                'defaultinvites' => $oldRole->defaultinvites,
                'isdefault' => $oldRole->isdefault,
                'donation' => $oldRole->donation,
                'addyears' => $oldRole->addyears,
                'rate_limit' => $oldRole->rate_limit,
            ]
        );

        if ((int) $oldRole->canpreview === 1) {
            $role->givePermissionTo('preview');
        }

        if ((int) $oldRole->hideads === 1) {
            $role->givePermissionTo('hideads');
        }

        if ($oldRole->name === 'Moderator') {
            $role->givePermissionTo(Permission::all());
        }

        if ($oldRole->name === 'Admin') {
            $role->givePermissionTo(Permission::all());
        }

        if ($oldRole->name === 'Friend') {
            $role->givePermissionTo(['preview', 'hideads', 'view console', 'view movies', 'view audio', 'view pc', 'view tv', 'view adult', 'view books', 'view other']);
        }
    }
}

foreach ($users as $user) {
    if ($user->role !== null && $user->hasRole($user->role->name) === false) {
        $user->syncRoles([$user->role->name]);
        echo 'Role: '.$user->role->name.' assigned to user: '.$user->username.PHP_EOL;
    } elseif ($user->role === null) {
        $user->syncRoles(['User']);
    } else {
        echo 'User '.$user->username.' already has the role: '.$user->role->name.PHP_EOL;
    }
}
