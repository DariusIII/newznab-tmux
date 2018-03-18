<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    /**
     * @var string
     */
    protected $table = 'menu';

    /**
     * @var bool
     */
    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @param $role
     * @param $serverurl
     * @return array
     */
    public static function getMenu($role, $serverurl): array
    {
        $sql = self::query()->where('role', '=<', $role)->orderBy('ordinal');

        if ($role !== User::ROLE_ADMIN) {
            $sql->where('role', '!=', User::ROLE_ADMIN);
        }

        $data = $sql->get();

        $ret = [];
        foreach ($data as $d) {
            if (stripos($d['href'], 'http') === false) {
                $d['href'] = $serverurl.$d['href'];
                $ret[] = $d;
            } else {
                $ret[] = $d;
            }
        }

        return $ret;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public static function getAll()
    {
        return self::query()->orderBy('role')->orderBy('ordinal')->get();
    }

    /**
     * @param $id
     * @return \Illuminate\Database\Eloquent\Model|null|static
     */
    public static function getById($id)
    {
        return self::query()->where('id', $id)->first();
    }

    /**
     * @param $id
     * @return mixed
     */
    public static function deleteMenu($id)
    {
        return self::query()->where('id', $id)->delete();
    }

    /**
     * @param $menu
     * @return int
     */
    public static function addMenu($menu)
    {
        return self::create(
            [
                'href' => $menu['href'],
                'title' => $menu['title'],
                'tooltip' => $menu['tooltip'],
                'role' => $menu['role'],
                'ordinal' => $menu['ordinal'],
                'menueval' => $menu['menueval'],
                'newwindow' => $menu['newwindow'],
            ]
        )->id;
    }

    /**
     * @param $menu
     * @return int
     */
    public static function updateMenu($menu)
    {
        return self::query()
            ->where('id', $menu['id'])
            ->update(
                [
                    'href' => $menu['href'],
                    'title' => $menu['title'],
                    'tooltip' => $menu['tooltip'],
                    'role' => $menu['role'],
                    'ordinal' => $menu['ordinal'],
                    'menueval' => $menu['menueval'],
                    'newwindow' => $menu['newwindow'],
                ]
            );
    }
}
