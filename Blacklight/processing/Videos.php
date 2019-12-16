<?php
/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (see LICENSE.txt in the base directory.  If
 * not, see:
 *
 * @link      <http://www.gnu.org/licenses/>.
 * @author    niel
 * @copyright 2015 nZEDb
 */

namespace Blacklight\processing;

use App\Models\TvInfo;
use App\Models\Video;
use App\Models\VideoAlias;
use Illuminate\Support\Facades\Cache;

/**
 * Parent class for TV/Film and any similar classes to inherit from.
 */
abstract class Videos
{
    // Video Type Identifiers
    protected const TYPE_TV = 0; // Type of video is a TV Programme/Show
    protected const TYPE_FILM = 1; // Type of video is a Film/Movie
    protected const TYPE_ANIME = 2; // Type of video is a Anime

    /**
     * @var bool
     */
    public $echooutput;

    /**
     * @var array	sites	The sites that we have an ID columns for in our video table.
     */
    private static $sites = ['imdb', 'tmdb', 'trakt', 'tvdb', 'tvmaze', 'tvrage'];

    /**
     * @var array Temp Array of cached failed lookups
     */
    public $titleCache;

    public function __construct(array $options = [])
    {
        $defaults = [
            'Echo'     => false,
            'Settings' => null,
        ];
        $options += $defaults;

        $this->echooutput = ($options['Echo'] && config('nntmux.echocli'));
        $this->titleCache = [];
    }

    /**
     * Main processing director function for scrapers
     * Calls work query function and initiates processing.
     *
     * @param      $groupID
     * @param      $guidChar
     * @param      $process
     * @param bool $local
     */
    abstract protected function processSite($groupID, $guidChar, $process, $local = false): void;

    /**
     * Get video info from a Video ID and column.
     *
     * @param string  $siteColumn
     * @param int $videoID
     *
     * @return array|false    False if invalid site, or ID not found; Site id value otherwise.
     */
    protected function getSiteIDFromVideoID($siteColumn, $videoID)
    {
        if (\in_array($siteColumn, self::$sites, false)) {
            $result = Video::query()->where('id', $videoID)->first([$siteColumn]);

            return $result !== null ? $result[$siteColumn] : false;
        }

        return false;
    }

    /**
     * Get TV show local timezone from a Video ID.
     *
     * @param int $videoID
     *
     * @return string Empty string if no query return or tz style timezone
     */
    protected function getLocalZoneFromVideoID($videoID): string
    {
        $result = TvInfo::query()->where('videos_id', $videoID)->first(['localzone']);

        return $result !== null ? $result['localzone'] : '';
    }

    /**
     * Get video info from a Site ID and column.
     *
     *
     * @param $siteColumn
     * @param $siteID
     * @return bool|int
     */
    protected function getVideoIDFromSiteID($siteColumn, $siteID)
    {
        $result = null;
        if (\in_array($siteColumn, self::$sites, false)) {
            $result = Video::query()->where($siteColumn, $siteID)->first(['id']);
        }

        return $result !== null ? $result->id : false;
    }

    /**
     * Attempt a local lookup via the title first by exact match and then by like.
     * Returns a false for no match or the Video ID of the match.
     *
     * @param        $title
     * @param        $type
     * @param int    $source
     *
     * @return false|int
     */
    public function getByTitle($title, $type, $source = 0)
    {
        // Check if we already have an entry for this show.
        $res = $this->getTitleExact($title, $type, $source);
        if (isset($res['id'])) {
            return $res['id'];
        }

        $title2 = str_replace(' and ', ' & ', $title);
        if ((string) $title !== (string) $title2) {
            $res = $this->getTitleExact($title2, $type, $source);
            if (isset($res['id'])) {
                return $res['id'];
            }
            $pieces = explode(' ', $title2);
            $title2 = '%';
            foreach ($pieces as $piece) {
                $title2 .= str_replace(["'", '!'], '', $piece).'%';
            }
            $res = $this->getTitleLoose($title2, $type, $source);
            if (isset($res['id'])) {
                return $res['id'];
            }
        }

        // Some words are spelled correctly 2 ways
        // example theatre and theater
        $title2 = str_replace('er', 're', $title);
        if ((string) $title !== (string) $title2) {
            $res = $this->getTitleExact($title2, $type, $source);
            if (isset($res['id'])) {
                return $res['id'];
            }
            $pieces = explode(' ', $title2);
            $title2 = '%';
            foreach ($pieces as $piece) {
                $title2 .= str_replace(["'", '!'], '', $piece).'%';
            }
            $res = $this->getTitleLoose($title2, $type, $source);
            if (isset($res['id'])) {
                return $res['id'];
            }
        } else {

            // If there was not an exact title match, look for title with missing chars
            // example release name :Zorro 1990, tvrage name Zorro (1990)
            // Only search if the title contains more than one word to prevent incorrect matches
            $pieces = explode(' ', $title);
            if (\count($pieces) > 1) {
                $title2 = '%';
                foreach ($pieces as $piece) {
                    $title2 .= str_replace(["'", '!'], '', $piece).'%';
                }
                $res = $this->getTitleLoose($title2, $type, $source);
                if (isset($res['id'])) {
                    return $res['id'];
                }
            }
        }

        return false;
    }

    /**
     * @param $title
     * @param $type
     * @param int $source
     * @return bool|\Illuminate\Database\Eloquent\Model|null|static
     */
    public function getTitleExact($title, $type, $source = 0)
    {
        $return = false;
        if (! empty($title)) {
            $sql = Video::query()->where(['title' => $title, 'type' => $type]);
            if ($source > 0) {
                $sql->where('source', $source);
            }
            $return = $sql->first(['id']);
            // Try for an alias
            if (empty($return)) {
                $sql = Video::query()
                    ->join('videos_aliases', 'videos.id', '=', 'videos_aliases.videos_id')
                    ->where(['videos.title' => $title, 'videos.type' => $type]);
                if ($source > 0) {
                    $sql->where('videos.source', $source);
                }
                $return = $sql->first(['videos.id']);
            }
        }

        return $return;
    }

    /**
     * Supplementary function for getByTitle that queries for a like match.
     *
     * @param        $title
     * @param        $type
     * @param int    $source
     *
     * @return array|false
     */
    public function getTitleLoose($title, $type, $source = 0)
    {
        $return = false;

        if (! empty($title)) {
            $sql = Video::query()
                ->where('title', 'like', rtrim($title, '%'))
                ->where('type', $type);
            if ($source > 0) {
                $sql->where('source', $source);
            }
            $return = $sql->first(['id']);
            // Try for an alias
            if (empty($return)) {
                $sql = Video::query()
                    ->join('videos_aliases', 'videos.id', '=', 'videos_aliases.videos_id')
                    ->where('videos_aliases.title', '=', rtrim($title, '%'))
                    ->where('type', $type);
                if ($source > 0) {
                    $sql->where('videos.source', $source);
                }
                $return = $sql->first(['videos.id']);
            }
        }

        return $return;
    }

    /**
     * Inserts aliases for videos.
     *
     * @param       $videoId
     * @param array $aliases
     */
    public function addAliases($videoId, array $aliases = []): void
    {
        if (! empty($aliases) && $videoId > 0) {
            foreach ($aliases as $key => $title) {
                // Check for tvmaze style aka
                if (\is_array($title) && ! empty($title['name'])) {
                    $title = $title['name'];
                }
                // Check if we have the AKA already
                $check = $this->getAliases(0, $title);

                if ($check === false) {
                    VideoAlias::insertOrIgnore(['videos_id' => $videoId, 'title' => $title]);
                }
            }
        }
    }

    /**
     * Retrieves all aliases for given VideoID or VideoID for a given alias.
     *
     * @param int    $videoId
     * @param string $alias
     *
     * @return \PDOStatement|false
     */
    public function getAliases($videoId, $alias = '')
    {
        $return = false;
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));

        if ($videoId > 0 || $alias !== '') {
            $aliasCache = Cache::get(md5($videoId.$alias));
            if ($aliasCache !== null) {
                $return = $aliasCache;
            } else {
                $sql = VideoAlias::query();
                if ($videoId > 0) {
                    $sql->where('videos_id', $videoId);
                } elseif ($alias !== '') {
                    $sql->where('title', $alias);
                }
                $return = $sql->get();
                Cache::put(md5($videoId.$alias), $return, $expiresAt);
            }
        }

        return empty($return) ? false : $return;
    }
}
