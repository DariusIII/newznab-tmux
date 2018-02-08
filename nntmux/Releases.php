<?php

namespace nntmux;

use nntmux\db\DB;
use App\Models\Group;
use App\Models\Release;
use App\Models\Category;
use App\Models\Settings;
use nntmux\utility\Utility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Class Releases.
 */
class Releases
{
    // RAR/ZIP Passworded indicator.
    public const PASSWD_NONE = 0; // No password.
    public const PASSWD_POTENTIAL = 1; // Might have a password.
    public const BAD_FILE = 2; // Possibly broken RAR/ZIP.
    public const PASSWD_RAR = 10; // Definitely passworded.

    /**
     * @var \nntmux\db\DB
     */
    public $pdo;

    /**
     * @var \nntmux\ReleaseSearch
     */
    public $releaseSearch;

    /**
     * @var \nntmux\SphinxSearch
     */
    public $sphinxSearch;

    /**
     * @var string
     */
    public $showPasswords;

    /**
     * @var int
     */
    public $passwordStatus;

    /**
     * @var array Class instances.
     * @throws \Exception
     */
    public function __construct(array $options = [])
    {
        $defaults = [
            'Settings' => null,
            'Groups'   => null,
        ];
        $options += $defaults;

        $this->pdo = ($options['Settings'] instanceof DB ? $options['Settings'] : new DB());
        $this->sphinxSearch = new SphinxSearch();
        $this->releaseSearch = new ReleaseSearch($this->pdo);
        $this->showPasswords = self::showPasswords();
    }

    /**
     * Used for pager on browse page.
     *
     * @param array  $cat
     * @param int    $maxAge
     * @param array  $excludedCats
     * @param string|int $groupName
     *
     * @return int
     */
    public function getBrowseCount($cat, $maxAge = -1, array $excludedCats = [], $groupName = ''): int
    {
        $sql = sprintf(
                'SELECT COUNT(r.id) AS count
				FROM releases r
				%s
				WHERE r.nzbstatus = %d
				AND r.passwordstatus %s
				%s %s %s %s',
                ($groupName !== -1 ? 'LEFT JOIN groups g ON g.id = r.groups_id' : ''),
                NZB::NZB_ADDED,
                $this->showPasswords,
                ($groupName !== -1 ? sprintf(' AND g.name = %s', $this->pdo->escapeString($groupName)) : ''),
                Category::getCategorySearch($cat),
                ($maxAge > 0 ? (' AND r.postdate > NOW() - INTERVAL '.$maxAge.' DAY ') : ''),
                (\count($excludedCats) ? (' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')') : '')
        );
        $count = Cache::get(md5($sql));
        if ($count !== null) {
            return $count;
        }
        $count = $this->pdo->query($sql);
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_SHORT);
        Cache::put(md5($sql), $count[0]['count'], $expiresAt);

        return $count[0]['count'] ?? 0;
    }

    /**
     * Used for browse results.
     *
     * @param array  $cat
     * @param        $start
     * @param        $num
     * @param string|array $orderBy
     * @param int    $maxAge
     * @param array  $excludedCats
     * @param string|int $groupName
     * @param int    $minSize
     *
     * @return array
     */
    public function getBrowseRange($cat, $start, $num, $orderBy, $maxAge = -1, array $excludedCats = [], $groupName = -1, $minSize = 0): array
    {
        $orderBy = $this->getBrowseOrder($orderBy);

        $qry = sprintf(
            "SELECT r.*,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				CONCAT(cp.id, ',', c.id) AS category_ids,
				df.failed AS failed,
				rn.releases_id AS nfoid,
				re.releases_id AS reid,
				v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.imdb, v.tmdb,
				tve.title, tve.firstaired
			FROM
			(
				SELECT r.*, g.name AS group_name
				FROM releases r
				LEFT JOIN groups g ON g.id = r.groups_id
				WHERE r.nzbstatus = %d
				AND r.passwordstatus %s
				%s %s %s %s %s
				ORDER BY %s %s %s
			) r
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT OUTER JOIN videos v ON r.videos_id = v.id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT OUTER JOIN video_data re ON re.releases_id = r.id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
			GROUP BY r.id
			ORDER BY %8\$s %9\$s",
            NZB::NZB_ADDED,
            $this->showPasswords,
            Category::getCategorySearch($cat),
            ($maxAge > 0 ? (' AND postdate > NOW() - INTERVAL '.$maxAge.' DAY ') : ''),
            (\count($excludedCats) ? (' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')') : ''),
            ((int) $groupName !== -1 ? sprintf(' AND g.name = %s ', $this->pdo->escapeString($groupName)) : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : ''),
            $orderBy[0],
            $orderBy[1],
            ($start === false ? '' : ' LIMIT '.$num.' OFFSET '.$start)
        );

        $releases = Cache::get(md5($qry));
        if ($releases !== null) {
            return $releases;
        }
        $sql = $this->pdo->query($qry);
        if (\count($sql) > 0) {
            $possibleRows = $this->getBrowseCount($cat, $maxAge, $excludedCats, $groupName);
            $sql[0]['_totalcount'] = $sql[0]['_totalrows'] = $possibleRows;
        }
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($qry), $sql, $expiresAt);

        return $sql;
    }

    /**
     * Return site setting for hiding/showing passworded releases.
     *
     * @return string
     * @throws \Exception
     */
    public static function showPasswords(): ?string
    {
        $setting = Settings::settingValue('..showpasswordedrelease', true);
        $setting = ($setting !== null && is_numeric($setting)) ? $setting : 10;

        switch ($setting) {
            case 0: // Hide releases with a password or a potential password (Hide unprocessed releases).
                return '='.self::PASSWD_NONE;
            case 1: // Show releases with no password or a potential password (Show unprocessed releases).
                return '<= '.self::PASSWD_POTENTIAL;
            case 2: // Hide releases with a password or a potential password (Show unprocessed releases).
                return '<= '.self::PASSWD_NONE;
            case 10: // Shows everything.
            default:
                return '<= '.self::PASSWD_RAR;
        }
    }

    /**
     * Use to order releases on site.
     *
     * @param string|array $orderBy
     *
     * @return array
     */
    public function getBrowseOrder($orderBy): array
    {
        $orderArr = explode('_', ($orderBy === '' ? 'posted_desc' : $orderBy));
        switch ($orderArr[0]) {
            case 'cat':
                $orderField = 'categories_id';
                break;
            case 'name':
                $orderField = 'searchname';
                break;
            case 'size':
                $orderField = 'size';
                break;
            case 'files':
                $orderField = 'totalpart';
                break;
            case 'stats':
                $orderField = 'grabs';
                break;
            case 'posted':
            default:
                $orderField = 'postdate';
                break;
        }

        return [$orderField, isset($orderArr[1]) && preg_match('/^(asc|desc)$/i', $orderArr[1]) ? $orderArr[1] : 'desc'];
    }

    /**
     * Return ordering types usable on site.
     *
     * @return string[]
     */
    public function getBrowseOrdering(): array
    {
        return [
            'name_asc',
            'name_desc',
            'cat_asc',
            'cat_desc',
            'posted_asc',
            'posted_desc',
            'size_asc',
            'size_desc',
            'files_asc',
            'files_desc',
            'stats_asc',
            'stats_desc',
        ];
    }

    /**
     * Get list of releases available for export.
     *
     * @param string $postFrom (optional) Date in this format : 01/01/2014
     * @param string $postTo   (optional) Date in this format : 01/01/2014
     * @param string|int $groupID  (optional) Group ID.
     *
     * @return array
     */
    public function getForExport($postFrom = '', $postTo = '', $groupID = ''): array
    {
        return $this->pdo->query(
            sprintf(
                "SELECT searchname, guid, groups.name AS gname, CONCAT(cp.title,'_',categories.title) AS catName
				FROM releases r
				LEFT JOIN categories c ON r.categories_id = c.id
				LEFT JOIN groups g ON r.groups_id = g.id
				LEFT JOIN categories cp ON cp.id = c.parentid
				WHERE r.nzbstatus = %d
				%s %s %s",
                NZB::NZB_ADDED,
                $this->exportDateString($postFrom),
                $this->exportDateString($postTo, false),
                $groupID !== '' && $groupID !== -1 ? sprintf(' AND r.groups_id = %d ', $groupID) : ''
            )
        );
    }

    /**
     * Create a date query string for exporting.
     *
     * @param string $date
     * @param bool   $from
     *
     * @return string
     */
    private function exportDateString($date = '', $from = true): string
    {
        if ($date !== '') {
            $dateParts = explode('/', $date);
            if (\count($dateParts) === 3) {
                $date = sprintf(
                    ' AND postdate %s %s ',
                    ($from ? '>' : '<'),
                    $this->pdo->escapeString(
                        $dateParts[2].'-'.$dateParts[1].'-'.$dateParts[0].
                        ($from ? ' 00:00:00' : ' 23:59:59')
                    )
                );
            }
        }

        return $date;
    }

    /**
     * Get date in this format : 01/01/2014 of the oldest release.
     *
     * @note Used for exporting NZB's.
     * @return mixed
     */
    public function getEarliestUsenetPostDate()
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(min(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * Get date in this format : 01/01/2014 of the newest release.
     *
     * @note Used for exporting NZB's.
     * @return mixed
     */
    public function getLatestUsenetPostDate()
    {
        $row = Release::query()->selectRaw("DATE_FORMAT(max(postdate), '%d/%m/%Y') AS postdate")->first();

        return $row === null ? '01/01/2014' : $row['postdate'];
    }

    /**
     * Gets all groups for drop down selection on NZB-Export web page.
     *
     * @param bool $blnIncludeAll
     *
     * @note Used for exporting NZB's.
     * @return array
     */
    public function getReleasedGroupsForSelect($blnIncludeAll = true): array
    {
        $groups = Release::query()
            ->selectRaw('DISTINCT g.id, g.name')
            ->leftJoin('groups as g', 'g.id', '=', 'releases.groups_id')
            ->get();
        $temp_array = [];

        if ($blnIncludeAll) {
            $temp_array[-1] = '--All Groups--';
        }

        foreach ($groups as $group) {
            $temp_array[$group['id']] = $group['name'];
        }

        return $temp_array;
    }

    /**
     * Cache of concatenated category ID's used in queries.
     * @var null|array
     */
    private $concatenatedCategoryIDsCache = null;

    /**
     * Gets / sets a string of concatenated category ID's used in queries.
     *
     * @return array|null|string
     */
    public function getConcatenatedCategoryIDs()
    {
        if ($this->concatenatedCategoryIDsCache === null) {
            $this->concatenatedCategoryIDsCache = Cache::get('concatenatedcats');
            if ($this->concatenatedCategoryIDsCache !== null) {
                return $this->concatenatedCategoryIDsCache;
            }

            $result = Category::query()
                ->whereNotNull('categories.parentid')
                ->whereNotNull('cp.id')
                ->selectRaw('CONCAT(cp.id, ", ", categories.id) AS category_ids')
                ->leftJoin('categories as cp', 'cp.id', '=', 'categories.parentid')
                ->get();
            if (isset($result[0]['category_ids'])) {
                $this->concatenatedCategoryIDsCache = $result[0]['category_ids'];
            }
        }
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_LONG);
        Cache::put('concatenatedcats', $this->concatenatedCategoryIDsCache, $expiresAt);

        return $this->concatenatedCategoryIDsCache;
    }

    /**
     * Get TV for my shows page.
     *
     * @param          $userShows
     * @param int|bool $offset
     * @param int      $limit
     * @param string|array   $orderBy
     * @param int      $maxAge
     * @param array    $excludedCats
     *
     * @return array
     */
    public function getShowsRange($userShows, $offset, $limit, $orderBy, $maxAge = -1, array $excludedCats = []): array
    {
        $orderBy = $this->getBrowseOrder($orderBy);

        $sql = sprintf(
                "SELECT r.*,
					CONCAT(cp.title, '-', c.title) AS category_name,
					%s AS category_ids,
					g.name AS group_name,
					rn.releases_id AS nfoid, re.releases_id AS reid,
					tve.firstaired,
					(SELECT df.failed) AS failed
				FROM releases r
				LEFT OUTER JOIN video_data re ON re.releases_id = r.id
				LEFT JOIN groups g ON g.id = r.groups_id
				LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
				LEFT OUTER JOIN tv_episodes tve ON tve.videos_id = r.videos_id
				LEFT JOIN categories c ON c.id = r.categories_id
				LEFT JOIN categories cp ON cp.id = c.parentid
				LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
				WHERE r.categories_id BETWEEN 5000 AND 5999 %s %s
				AND r.nzbstatus = %d
				AND r.passwordstatus %s
				%s
				GROUP BY r.id
				ORDER BY %s %s %s",
                $this->getConcatenatedCategoryIDs(),
                $this->uSQL($userShows, 'videos_id'),
                (\count($excludedCats) ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
                NZB::NZB_ADDED,
                $this->showPasswords,
                ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : ''),
                $orderBy[0],
                $orderBy[1],
                ($offset === false ? '' : (' LIMIT '.$limit.' OFFSET '.$offset))
            );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);

        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Get count for my shows page pagination.
     *
     * @param       $userShows
     * @param int   $maxAge
     * @param array $excludedCats
     *
     * @return int
     */
    public function getShowsCount($userShows, $maxAge = -1, array $excludedCats = []): int
    {
        return $this->getPagerCount(
            sprintf(
                'SELECT r.id
				FROM releases r
				WHERE r.categories_id BETWEEN 5000 AND 5999 %s %s
				AND r.nzbstatus = %d
				AND r.passwordstatus %s
				%s',
                $this->uSQL($userShows, 'videos_id'),
                (\count($excludedCats) ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
                NZB::NZB_ADDED,
                $this->showPasswords,
                ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : '')
            )
        );
    }

    /**
     * Get count for my shows page pagination.
     *
     * @param       $userMovies
     * @param int   $maxAge
     * @param array $excludedCats
     *
     * @return int
     */
    public function getMovieCount($userMovies, $maxAge = -1, array $excludedCats = []): int
    {
        return $this->getPagerCount(
            sprintf(
                'SELECT r.id
				FROM releases r
				WHERE r.categories_id BETWEEN 3000 AND 3999 %s %s
				AND r.nzbstatus = %d
				AND r.passwordstatus %s
				%s',
                $this->uSQL($userMovies, 'imdbid'),
                (\count($excludedCats) ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
                NZB::NZB_ADDED,
                $this->showPasswords,
                ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : '')
            )
        );
    }

    /**
     * Delete multiple releases, or a single by ID.
     *
     * @param array|int|string $list   Array of GUID or ID of releases to delete.
     * @throws \Exception
     */
    public function deleteMultiple($list): void
    {
        $list = (array) $list;

        $nzb = new NZB();
        $releaseImage = new ReleaseImage();

        foreach ($list as $identifier) {
            $this->deleteSingle(['g' => $identifier, 'i' => false], $nzb, $releaseImage);
        }
    }

    /**
     * Deletes a single release by GUID, and all the corresponding files.
     *
     * @param array        $identifiers ['g' => Release GUID(mandatory), 'id => ReleaseID(optional, pass false)]
     * @param NZB          $nzb
     * @param ReleaseImage $releaseImage
     */
    public function deleteSingle($identifiers, $nzb, $releaseImage): void
    {
        // Delete NZB from disk.
        $nzbPath = $nzb->NZBPath($identifiers['g']);
        if ($nzbPath) {
            @unlink($nzbPath);
        }

        // Delete images.
        $releaseImage->delete($identifiers['g']);

        // Delete from sphinx.
        $this->sphinxSearch->deleteRelease($identifiers);

        // Delete from DB.
        Release::query()->where('guid', $identifiers['g'])->delete();
    }

    /**
     * @param $guids
     * @param $category
     * @param $grabs
     * @param $videoId
     * @param $episodeId
     * @param $anidbId
     * @param $imdbId
     * @return bool|int
     */
    public function updateMulti($guids, $category, $grabs, $videoId, $episodeId, $anidbId, $imdbId)
    {
        if (! \is_array($guids) || \count($guids) < 1) {
            return false;
        }

        $update = [
            'categories_id'     => $category === -1 ? 'categories_id' : $category,
            'grabs'          => $grabs,
            'videos_id'      => $videoId,
            'tv_episodes_id' => $episodeId,
            'anidbid'        => $anidbId,
            'imdbid'         => $imdbId,
        ];

        return Release::query()->whereIn('guid', $guids)->update($update);
    }

    /**
     * Creates part of a query for some functions.
     *
     * @param array  $userQuery
     * @param string $type
     *
     * @return string
     */
    public function uSQL($userQuery, $type): string
    {
        $sql = '(1=2 ';
        foreach ($userQuery as $query) {
            $sql .= sprintf('OR (r.%s = %d', $type, $query[$type]);
            if ($query['categories'] !== '') {
                $catsArr = explode('|', $query['categories']);
                if (\count($catsArr) > 1) {
                    $sql .= sprintf(' AND r.categories_id IN (%s)', implode(',', $catsArr));
                } else {
                    $sql .= sprintf(' AND r.categories_id = %d', $catsArr[0]);
                }
            }
            $sql .= ') ';
        }
        $sql .= ') ';

        return $sql;
    }

    /**
     * Function for searching on the site (by subject, searchname or advanced).
     *
     *
     * @param string $searchName
     * @param string $usenetName
     * @param string $posterName
     * @param string $fileName
     * @param string $groupName
     * @param string|int $sizeFrom
     * @param string|int $sizeTo
     * @param bool $hasNfo
     * @param bool $hasComments
     * @param string|int $daysNew
     * @param string|int $daysOld
     * @param int $offset
     * @param int $limit
     * @param string|array $orderBy
     * @param string|int $maxAge
     * @param array $excludedCats
     * @param string $type
     * @param array $cat
     * @param int $minSize
     * @return array
     */
    public function search($searchName = '', $usenetName = '', $posterName = '', $fileName = '', $groupName = '', $sizeFrom = '', $sizeTo = '', $hasNfo = false, $hasComments = false, $daysNew = '', $daysOld = '', $offset = 0, $limit = 1000, $orderBy = '', $maxAge = '', array $excludedCats = [], $type = 'basic', array $cat = [], $minSize = 0): array
    {
        $sizeRange = [
            1 => 1,
            2 => 2.5,
            3 => 5,
            4 => 10,
            5 => 20,
            6 => 30,
            7 => 40,
            8 => 80,
            9 => 160,
            10 => 320,
            11 => 640,
        ];

        if ($orderBy === '') {
            $orderBy = [
            'postdate ',
            'desc ',
        ];
        } else {
            $orderBy = $this->getBrowseOrder($orderBy);
        }

        $searchOptions = [];
        if ($searchName !== '') {
            $searchOptions['searchname'] = $searchName;
        }
        if ($usenetName !== '') {
            $searchOptions['name'] = $usenetName;
        }
        if ($posterName !== '') {
            $searchOptions['fromname'] = $posterName;
        }
        if ($fileName !== '') {
            $searchOptions['filename'] = $fileName;
        }

        $catQuery = '';
        if ($type === 'basic') {
            $catQuery = Category::getCategorySearch($cat);
        } elseif ($type === 'advanced' && ! empty($cat)) {
            $catQuery = sprintf('AND r.categories_id = %d', $cat[0]);
        }

        $whereSql = sprintf(
            '%s WHERE r.passwordstatus %s AND r.nzbstatus = %d %s %s %s %s %s %s %s %s %s %s %s %s',
            $this->releaseSearch->getFullTextJoinString(),
            $this->showPasswords,
            NZB::NZB_ADDED,
            ($maxAge > 0 ? sprintf(' AND r.postdate > (NOW() - INTERVAL %d DAY) ', $maxAge) : ''),
            ((int) $groupName !== '' ? sprintf(' AND r.groups_id = %d ', Group::getIDByName($groupName)) : ''),
            (array_key_exists($sizeFrom, $sizeRange) ? ' AND r.size > '.(string) (104857600 * (int) $sizeRange[$sizeFrom]).' ' : ''),
            (array_key_exists($sizeTo, $sizeRange) ? ' AND r.size < '.(string) (104857600 * (int) $sizeRange[$sizeTo]).' ' : ''),
            ($hasNfo !== false ? ' AND r.nfostatus = 1 ' : ''),
            ($hasComments !== false ? ' AND r.comments > 0 ' : ''),
            $catQuery,
            ($daysNew !== '' ? sprintf(' AND r.postdate < (NOW() - INTERVAL %d DAY) ', $daysNew) : ''),
            ($daysOld !== '' ? sprintf(' AND r.postdate > (NOW() - INTERVAL %d DAY) ', $daysOld) : ''),
            (\count($excludedCats) > 0 ? ' AND r.categories_id NOT IN ('.implode(',', $excludedCats).')' : ''),
            (\count($searchOptions) > 0 ? $this->releaseSearch->getSearchSQL($searchOptions) : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : '')
        );

        $baseSql = sprintf(
            "SELECT r.*,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				df.failed AS failed,
				g.name AS group_name,
				rn.releases_id AS nfoid,
				re.releases_id AS reid,
				cp.id AS categoryparentid,
				v.tvdb, v.trakt, v.tvrage, v.tvmaze, v.imdb, v.tmdb,
				tve.firstaired
			FROM releases r
			LEFT OUTER JOIN video_data re ON re.releases_id = r.id
			LEFT OUTER JOIN videos v ON r.videos_id = v.id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT JOIN groups g ON g.id = r.groups_id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
			%s",
            $this->getConcatenatedCategoryIDs(),
            $whereSql
        );

        $sql = sprintf(
            'SELECT * FROM (
				%s
			) r
			ORDER BY r.%s %s
			LIMIT %d OFFSET %d',
            $baseSql,
            $orderBy[0],
            $orderBy[1],
            $limit,
            $offset
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        if (! empty($releases) && \count($releases)) {
            $releases[0]['_totalrows'] = $this->getPagerCount($baseSql);
        }

        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Search TV Shows via the API.
     *
     * @param array  $siteIdArr Array containing all possible TV Processing site IDs desired
     * @param string $series The series or season number requested
     * @param string $episode The episode number requested
     * @param string $airdate The airdate of the episode requested
     * @param int    $offset Skip this many releases
     * @param int    $limit Return this many releases
     * @param string $name The show name to search
     * @param array  $cat The category to search
     * @param int    $maxAge The maximum age of releases to be returned
     * @param int    $minSize The minimum size of releases to be returned
     *
     * @return array
     */
    public function searchShows(
        array $siteIdArr = [],
        $series = '',
        $episode = '',
        $airdate = '',
        $offset = 0,
        $limit = 100,
        $name = '',
        array $cat = [-1],
        $maxAge = -1,
        $minSize = 0
    ): array {
        $siteSQL = [];
        $showSql = '';

        if (\is_array($siteIdArr)) {
            foreach ($siteIdArr as $column => $Id) {
                if ($Id > 0) {
                    $siteSQL[] = sprintf('v.%s = %d', $column, $Id);
                }
            }
        }

        if (\count($siteSQL) > 0) {
            // If we have show info, find the Episode ID/Video ID first to avoid table scans
            $showQry = sprintf(
                "
				SELECT
					v.id AS video,
					GROUP_CONCAT(tve.id SEPARATOR ',') AS episodes
				FROM videos v
				LEFT JOIN tv_episodes tve ON v.id = tve.videos_id
				WHERE (%s) %s %s %s
				GROUP BY v.id",
                implode(' OR ', $siteSQL),
                ($series !== '' ? sprintf('AND tve.series = %d', (int) preg_replace('/^s0*/i', '', $series)) : ''),
                ($episode !== '' ? sprintf('AND tve.episode = %d', (int) preg_replace('/^e0*/i', '', $episode)) : ''),
                ($airdate !== '' ? sprintf('AND DATE(tve.firstaired) = %s', $this->pdo->escapeString($airdate)) : '')
            );
            $show = $this->pdo->queryOneRow($showQry);
            if ($show !== false) {
                if ((! empty($series) || ! empty($episode) || ! empty($airdate)) && strlen((string) $show['episodes']) > 0) {
                    $showSql = sprintf('AND r.tv_episodes_id IN (%s)', $show['episodes']);
                } elseif ((int) $show['video'] > 0) {
                    $showSql = 'AND r.videos_id = '.$show['video'];
                    // If $series is set but episode is not, return Season Packs only
                    if (! empty($series) && empty($episode)) {
                        $showSql .= ' AND r.tv_episodes_id = 0';
                    }
                } else {
                    // If we were passed Episode Info and no match was found, do not run the query
                    return [];
                }
            } else {
                // If we were passed Site ID Info and no match was found, do not run the query
                return [];
            }
        }

        // If $name is set it is a fallback search, add available SxxExx/airdate info to the query
        if (! empty($name) && $showSql === '') {
            if (! empty($series) && (int) $series < 1900) {
                $name .= sprintf(' S%s', str_pad($series, 2, '0', STR_PAD_LEFT));
                if (! empty($episode) && strpos($episode, '/') === false) {
                    $name .= sprintf('E%s', str_pad($episode, 2, '0', STR_PAD_LEFT));
                }
            } elseif (! empty($airdate)) {
                $name .= sprintf(' %s', str_replace(['/', '-', '.', '_'], ' ', $airdate));
            }
        }

        $whereSql = sprintf(
            '%s
			WHERE r.nzbstatus = %d
			AND r.passwordstatus %s
			%s %s %s %s %s',
            ($name !== '' ? $this->releaseSearch->getFullTextJoinString() : ''),
            NZB::NZB_ADDED,
            $this->showPasswords,
            $showSql,
            ($name !== '' ? $this->releaseSearch->getSearchSQL(['searchname' => $name]) : ''),
            Category::getCategorySearch($cat),
            ($maxAge > 0 ? sprintf('AND r.postdate > NOW() - INTERVAL %d DAY', $maxAge) : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : '')
        );

        $baseSql = sprintf(
            "SELECT r.*,
				v.title, v.countries_id, v.started, v.tvdb, v.trakt,
					v.imdb, v.tmdb, v.tvmaze, v.tvrage, v.source,
				tvi.summary, tvi.publisher, tvi.image,
				tve.series, tve.episode, tve.se_complete, tve.title, tve.firstaired, tve.summary,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				g.name AS group_name,
				rn.releases_id AS nfoid,
				re.releases_id AS reid
			FROM releases r
			LEFT OUTER JOIN videos v ON r.videos_id = v.id AND v.type = 0
			LEFT OUTER JOIN tv_info tvi ON v.id = tvi.videos_id
			LEFT OUTER JOIN tv_episodes tve ON r.tv_episodes_id = tve.id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT JOIN groups g ON g.id = r.groups_id
			LEFT OUTER JOIN video_data re ON re.releases_id = r.id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			%s",
            $this->getConcatenatedCategoryIDs(),
            $whereSql
        );

        $sql = sprintf(
            '%s
			ORDER BY postdate DESC
			LIMIT %d OFFSET %d',
            $baseSql,
            $limit,
            $offset
        );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);
        if (! empty($releases) && \count($releases)) {
            $releases[0]['_totalrows'] = $this->getPagerCount(
                preg_replace('#LEFT(\s+OUTER)?\s+JOIN\s+(?!tv_episodes)\s+.*ON.*=.*\n#i', ' ', $baseSql)
            );
        }
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * @param        $aniDbID
     * @param int    $offset
     * @param int    $limit
     * @param string $name
     * @param array  $cat
     * @param int    $maxAge
     *
     * @return array
     */
    public function searchbyAnidbId($aniDbID, $offset = 0, $limit = 100, $name = '', array $cat = [-1], $maxAge = -1): array
    {
        $whereSql = sprintf(
            '%s
			WHERE r.passwordstatus %s
			AND r.nzbstatus = %d
			%s %s %s %s',
            ($name !== '' ? $this->releaseSearch->getFullTextJoinString() : ''),
            $this->showPasswords,
            NZB::NZB_ADDED,
            ($aniDbID > -1 ? sprintf(' AND r.anidbid = %d ', $aniDbID) : ''),
            ($name !== '' ? $this->releaseSearch->getSearchSQL(['searchname' => $name]) : ''),
            Category::getCategorySearch($cat),
            ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : '')
        );

        $baseSql = sprintf(
            "SELECT r.*,
				CONCAT(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				g.name AS group_name,
				rn.releases_id AS nfoid,
				re.releases_id AS reid
			FROM releases r
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT JOIN groups g ON g.id = r.groups_id
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			LEFT OUTER JOIN releaseextrafull re ON re.releases_id = r.id
			%s",
            $this->getConcatenatedCategoryIDs(),
            $whereSql
        );

        $sql = sprintf(
            '%s
			ORDER BY postdate DESC
			LIMIT %d OFFSET %d',
            $baseSql,
            $limit,
            $offset
        );
        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);

        if (! empty($releases) && \count($releases)) {
            $releases[0]['_totalrows'] = $this->getPagerCount($baseSql);
        }
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * @param int $imDbId
     * @param int $offset
     * @param int $limit
     * @param string $name
     * @param array $cat
     * @param int $maxAge
     * @param int $minSize
     *
     * @return array
     */
    public function searchbyImdbId($imDbId, $offset = 0, $limit = 100, $name = '', array $cat = [-1], $maxAge = -1, $minSize = 0): array
    {
        $whereSql = sprintf(
            '%s
			WHERE r.nzbstatus = %d
			AND r.passwordstatus %s
			%s %s %s %s %s',
            ($name !== '' ? $this->releaseSearch->getFullTextJoinString() : ''),
            NZB::NZB_ADDED,
            $this->showPasswords,
            ($name !== '' ? $this->releaseSearch->getSearchSQL(['searchname' => $name]) : ''),
            (($imDbId !== -1 && is_numeric($imDbId)) ? sprintf(' AND imdbid = %d ', str_pad($imDbId, 7, '0', STR_PAD_LEFT)) : ''),
            Category::getCategorySearch($cat),
            ($maxAge > 0 ? sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxAge) : ''),
            ($minSize > 0 ? sprintf('AND r.size >= %d', $minSize) : '')
        );

        $baseSql = sprintf(
            "SELECT r.*,
				concat(cp.title, ' > ', c.title) AS category_name,
				%s AS category_ids,
				g.name AS group_name,
				rn.releases_id AS nfoid
			FROM releases r
			LEFT JOIN groups g ON g.id = r.groups_id
			LEFT JOIN categories c ON c.id = r.categories_id
			LEFT JOIN categories cp ON cp.id = c.parentid
			LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
			WHERE r.categories_id BETWEEN 5000 AND 5999
			%s",
            $this->getConcatenatedCategoryIDs(),
            $whereSql
        );

        $sql = sprintf(
            '%s
			ORDER BY postdate DESC
			LIMIT %d OFFSET %d',
            $baseSql,
            $limit,
            $offset
        );

        $releases = Cache::get(md5($sql));
        if ($releases !== null) {
            return $releases;
        }

        $releases = $this->pdo->query($sql);

        if (! empty($releases) && \count($releases)) {
            $releases[0]['_totalrows'] = $this->getPagerCount($baseSql);
        }
        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_MEDIUM);
        Cache::put(md5($sql), $releases, $expiresAt);

        return $releases;
    }

    /**
     * Get count of releases for pager.
     *
     * @param string $query The query to get the count from.
     *
     * @return int
     */
    private function getPagerCount($query): int
    {
        $sql = sprintf(
                        'SELECT COUNT(z.id) AS count FROM (%s LIMIT %s) z',
                        preg_replace('/SELECT.+?FROM\s+releases/is', 'SELECT r.id FROM releases', $query),
                        NN_MAX_PAGER_RESULTS
        );

        $count = Cache::get(md5($sql));
        if ($count !== null) {
            return $count;
        }

        $count = $this->pdo->query($sql);

        $expiresAt = Carbon::now()->addSeconds(NN_CACHE_EXPIRY_SHORT);
        Cache::put(md5($sql), $count[0]['count'], $expiresAt);

        return $count[0]['count'] ?? 0;
    }

    /**
     * @param       $currentID
     * @param       $name
     * @param int $limit
     * @param array $excludedCats
     *
     * @return array
     * @throws \Exception
     */
    public function searchSimilar($currentID, $name, $limit = 6, array $excludedCats = []): array
    {
        // Get the category for the parent of this release.
        $currRow = Release::getCatByRelId($currentID);
        $catRow = Category::find($currRow['categories_id']);
        $parentCat = $catRow['parentid'];

        $results = $this->search(
            getSimilarName($name),
            -1,
            -1,
            -1,
            -1,
            -1,
            -1,
            0,
            0,
            -1,
            -1,
            0,
            $limit,
            '',
            -1,
            $excludedCats,
            null,
            [$parentCat]
        );
        if (! $results) {
            return $results;
        }

        $ret = [];
        foreach ($results as $res) {
            if ($res['id'] !== $currentID && $res['categoryparentid'] === $parentCat) {
                $ret[] = $res;
            }
        }

        return $ret;
    }

    /**
     * @param array $guids
     * @return string
     * @throws \Exception
     */
    public function getZipped($guids): string
    {
        $nzb = new NZB();
        $zipFile = new \ZipFile();

        foreach ($guids as $guid) {
            $nzbPath = $nzb->NZBPath($guid);

            if ($nzbPath) {
                $nzbContents = Utility::unzipGzipFile($nzbPath);

                if ($nzbContents) {
                    $filename = $guid;
                    $r = Release::getByGuid($guid);
                    if ($r) {
                        $filename = $r['searchname'];
                    }
                    $zipFile->addFile($nzbContents, $filename.'.nzb');
                }
            }
        }

        return $zipFile->file();
    }
}
