<?php

namespace App\Transformers;

use App\Models\Release;
use App\Models\Category;
use Illuminate\Support\Carbon;
use League\Fractal\TransformerAbstract;

class DetailsTransformer extends TransformerAbstract
{
    protected $user;

    /**
     * ApiTransformer constructor.
     *
     * @param $user
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * A Fractal transformer.
     *
     * @param \App\Models\Release $releases
     *
     * @return array
     */
    public function transform(Release $releases): array
    {
        if (\in_array($releases->categories_id, Category::MOVIES_GROUP, false)) {
            return [
                'title' => $releases->searchname,
                'details' => url('/').'/details/'.$releases->guid,
                'link' => url('/').'/getnzb?id='.$releases->guid.'.nzb'.'&i='.$this->user->id.'&r='.$this->user->api_token,
                'category' => $releases->categories_id,
                'category name' => $releases->category_name,
                'size' => $releases->size,
                'files' => $releases->totalpart,
                'poster' => $releases->fromname,
                'imdbid' => $releases->imdbid,
                'grabs' => $releases->grabs,
                'comments' => $releases->comments,
                'password' => $releases->passwordstatus,
                'usenetdate' => Carbon::parse($releases->postdate)->format('D, d M Y H:i:s O'),
                'group' => $releases->group_name,
            ];
        }

        if (\in_array($releases->categories_id, Category::TV_GROUP, false)) {
            return [
                'title' => $releases->searchname,
                'details' => url('/').'/details/'.$releases->guid,
                'link' => url('/').'/getnzb?id='.$releases->guid.'.nzb'.'&i='.$this->user->id.'&r='.$this->user->api_token,
                'category' => $releases->categories_id,
                'category name' => $releases->category_name,
                'size' => $releases->size,
                'files' => $releases->totalpart,
                'poster' => $releases->fromname,
                'tvairdate' => $releases->firstaired,
                'tvdbid' => $releases->tvdb,
                'traktid' => $releases->trakt,
                'tvrageid' => $releases->tvrage,
                'tvmazeid' => $releases->tvmaze,
                'imdbid' => $releases->imdb,
                'tmdbid' => $releases->tmdb,
                'grabs' => $releases->grabs,
                'comments' => $releases->comments,
                'password' => $releases->passwordstatus,
                'usenetdate' => Carbon::parse($releases->postdate)->format('D, d M Y H:i:s O'),
                'group' => $releases->group_name,
            ];
        }

        return [
            'title' => $releases->searchname,
            'details' => url('/').'/details/'.$releases->guid,
            'link' => url('/').'/getnzb?id='.$releases->guid.'.nzb'.'&i='.$this->user->id.'&r='.$this->user->api_token,
            'category' => $releases->categories_id,
            'category name' => $releases->category_name,
            'size' => $releases->size,
            'files' => $releases->totalpart,
            'poster' => $releases->fromname,
            'grabs' => $releases->grabs,
            'comments' => $releases->comments,
            'password' => $releases->passwordstatus,
            'usenetdate' => Carbon::parse($releases->postdate)->format('D, d M Y H:i:s O'),
            'group' => $releases->group_name,
        ];
    }
}