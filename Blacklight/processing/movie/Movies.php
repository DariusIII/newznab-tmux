<?php

namespace Blacklight\processing\movie;

use App\Models\Video;
use App\Models\TvInfo;
use App\Models\Release;
use App\Models\Category;
use App\Models\Settings;
use App\Models\TvEpisode;
use Blacklight\utility\Country;
use Blacklight\utility\Utility;
use Blacklight\processing\Videos;

/**
 * Class TV -- abstract extension of Videos
 * Contains functions suitable for re-use in all TV scrapers.
 */
abstract class Movies extends Videos
{
    // Television Sources
    protected const SOURCE_NONE = 0;   // No Scrape source
    protected const SOURCE_TMDB = 1;   // Scrape source was TMDB
    protected const SOURCE_TRAKT = 2;  // Scrape source was Trakt
    protected const SOURCE_IMDB = 3;   // Scrape source was IMDB
    protected const SOURCE_OMDB = 4;   // Scrape source was OMDB

    // Processing signifiers
    protected const PROCESS_TMDB = 0;   // Process TMDB First
    protected const PROCESS_TRAKT = -1;   // Process Trakt Second
    protected const PROCESS_IMDB = -2;   // Process IMDB Third
    protected const PROCESS_OMDB = -3;  // Process OMDB Fourth
    protected const NO_MATCH_FOUND = -6;   // Failed All Methods
    protected const FAILED_PARSE = -100; // Failed Parsing

    /**
     * @var int
     */
    public $movieqty;

    /**
     * @string Path to Save Images
     */
    public $imgSavePath;

    /**
     * @var array Site ID columns for Movies
     */
    public $siteColumns;

    /**
     * @var string The Movie categories_id lookup SQL language
     */
    public $catWhere;

    /**
     * TV constructor.
     *
     * @param array $options
     * @throws \Exception
     */
    public function __construct(array $options = [])
    {
        parent::__construct($options);
        $this->catWhere = 'categories_id BETWEEN '.Category::MOVIE_ROOT.' AND '.Category::MOVIE_OTHER;
        $this->movieqty = Settings::settingValue('..maximdbprocessed') !== '' ? (int) Settings::settingValue('..maximdbprocessed') : 100;
        $this->imgSavePath = NN_COVERS.'movies'.DS;
        $this->siteColumns = ['trakt', 'imdb', 'tmdb'];
    }

    /**
     * Retrieve banner image from site using its API.
     *
     * @param $videoID
     * @param $siteId
     *
     * @return mixed
     */
    abstract protected function getBanner($videoID, $siteId);

    /**
     * Retrieve info of TV episode from site using its API.
     *
     * @param int $siteId
     * @param int $movie
     *
     * @return array|false    False on failure, an array of information fields otherwise.
     */
    abstract protected function getMovieInfo($siteId, $movie);

    /**
     * Retrieve poster image for TV episode from site using its API.
     *
     * @param int $videoId ID from videos table.
     * @param int $siteId  ID that this site uses for the programme.
     *
     * @return int
     */
    abstract protected function getPoster($videoId, $siteId): int;

    /**
     * Assigns API show response values to a formatted array for insertion
     * Returns the formatted array.
     *
     * @param $movie
     *
     * @return array
     */
    abstract protected function formatMovieInfo($movie): array;

    /**
     * Retrieve releases for TV processing.
     *
     *
     * @param string $groupID
     * @param string $guidChar
     * @param int    $lookupSetting
     * @param null   $status
     *
     * @return \Illuminate\Database\Eloquent\Collection|int|static[]
     */
    public function getMovieReleases($groupID = '', $guidChar = '', $lookupSetting = 1, $status = null)
    {
        $ret = 0;
        if ($lookupSetting === 0) {
            return $ret;
        }

        $qry = Release::query()
            ->where(['nzbstatus' => 1, 'videos_id' => 0, 'imdbid' => $status])
            ->where('size', '>', 1048576)
            ->whereBetween('categories_id', [Category::MOVIE_ROOT, Category::MOVIE_OTHER])
            ->orderBy('postdate', 'desc')
            ->limit($this->movieqty);
        if ($groupID !== '') {
            $qry->where('groups_id', $groupID);
        }
        if ($guidChar !== '') {
            $qry->where('leftguid', $guidChar);
        }
        if ($lookupSetting === 2) {
            $qry->where('isrenamed', '=', 1);
        }

        return $qry->get();
    }

    /**
     * Updates the release when match for the current scraper is found.
     *
     * @param     $videoId
     * @param     $releaseId
     * @param int $imdbId
     */
    public function setVideoIdFound($videoId, $releaseId, $imdbId): void
    {
        Release::query()
            ->where('id', $releaseId)
            ->update(['videos_id' => $videoId, 'imdbid' => $imdbId]);
    }

    /**
     * Updates the release tv_episodes_id status when scraper match is not found.
     *
     * @param $status
     * @param $Id
     */
    public function setVideoNotFound($status, $Id): void
    {
        Release::query()
            ->where('id', $Id)
            ->update(['imdbid' => $status]);
    }

    /**
     * Inserts a new video ID into the database for TV shows
     * If a duplicate is found it is handle by calling update instead.
     *
     * @param array $movie
     *
     * @return int
     */
    public function add(array $movie = []): int
    {
        $videoId = false;

        // Check if the country is not a proper code and retrieve if not
        if ($movie['country'] !== '' && \strlen($movie['country']) > 2) {
            $movie['country'] = Country::countryCode($movie['country']);
        }

        // Check if video already exists based on site ID info
        // if that fails be sure we're not inserting duplicates by checking the title
        foreach ($this->siteColumns as $column) {
            if ($movie[$column] > 0) {
                $videoId = $this->getVideoIDFromSiteID($column, $movie[$column]);
            }
            if ($videoId !== null) {
                break;
            }
        }

        if ($videoId === null) {
            // Insert the Show
            $videoId = Video::create(
                [
                    'type' => $movie['type'],
                    'title' => $movie['title'],
                    'countries_id' => $movie['country'] ?? '',
                    'source' => $movie['source'],
                    'trakt' => $movie['trakt'],
                    'imdb' => $movie['imdb'],
                    'tmdb' => $movie['tmdb'],
                ]
            )->id;
            // Insert the supplementary show info
            TvInfo::query()
                ->insert(
                    [
                        'videos_id' => $videoId,
                        'summary' => $movie['summary'],
                        'publisher' => $movie['publisher'],
                        'localzone' => $movie['localzone'],
                    ]
                );
            // If we have AKAs\aliases, insert those as well
            if (! empty($movie['aliases'])) {
                $this->addAliases($videoId, $movie['aliases']);
            }
        } else {
            // If a local match was found, just update missing video info
            $this->update($videoId, $movie);
        }

        return $videoId;
    }

    /**
     * Inserts a new TV episode into the tv_episodes table following a match to a Video ID.
     *
     * @param $videoId
     * @param array $episode
     * @return false|int
     */
    public function addEpisode($videoId, array $episode = [])
    {
        $episodeId = $this->getBySeasonEp($videoId, $episode['series'], $episode['episode'], $episode['firstaired']);

        if ($episodeId === false) {
            $episodeId = TvEpisode::query()->insert(
                [
                    'videos_id' => $videoId,
                    'series' => $episode['series'],
                    'episode' => $episode['episode'],
                    'se_complete' => $episode['se_complete'],
                    'title' => $episode['title'],
                    'firstaired' => $episode['firstaired'] !== '' ? $episode['firstaired'] : null,
                    'summary' => $episode['summary'],
                ]
            );
        }

        return $episodeId;
    }

    /**
     * Updates the show info with data from the supplied array
     * Only called when a duplicate show is found during insert.
     *
     * @param int   $videoId
     * @param array $movie
     */
    public function update($videoId, array $movie = []): void
    {
        if ($movie['country'] !== '') {
            $movie['country'] = Country::countryCode($movie['country']);
        }

        $ifStringID = 'IF(%s = 0, %s, %s)';
        $ifStringInfo = "IF(%s = '', %s, %s)";

        $this->pdo->queryExec(
                sprintf(
                    '
				UPDATE videos v
				LEFT JOIN tv_info tvi ON v.id = tvi.videos_id
				SET v.countries_id = %s, v.tvdb = %s, v.trakt = %s, v.tvrage = %s,
					v.tvmaze = %s, v.imdb = %s, v.tmdb = %s,
					tvi.summary = %s, tvi.publisher = %s, tvi.localzone = %s
				WHERE v.id = %d',
                        sprintf($ifStringInfo, 'v.countries_id', $this->pdo->escapeString($movie['country']), 'v.countries_id'),
                        sprintf($ifStringID, 'v.tvdb', $movie['tvdb'], 'v.tvdb'),
                        sprintf($ifStringID, 'v.trakt', $movie['trakt'], 'v.trakt'),
                        sprintf($ifStringID, 'v.tvrage', $movie['tvrage'], 'v.tvrage'),
                        sprintf($ifStringID, 'v.tvmaze', $movie['tvmaze'], 'v.tvmaze'),
                        sprintf($ifStringID, 'v.imdb', $movie['imdb'], 'v.imdb'),
                        sprintf($ifStringID, 'v.tmdb', $movie['tmdb'], 'v.tmdb'),
                        sprintf($ifStringInfo, 'tvi.summary', $this->pdo->escapeString($movie['summary']), 'tvi.summary'),
                        sprintf($ifStringInfo, 'tvi.publisher', $this->pdo->escapeString($movie['publisher']), 'tvi.publisher'),
                        sprintf($ifStringInfo, 'tvi.localzone', $this->pdo->escapeString($movie['localzone']), 'tvi.localzone'),
                        $videoId
                )
        );
        if (! empty($movie['aliases'])) {
            $this->addAliases($videoId, $movie['aliases']);
        }
    }

    /**
     * Deletes a TV show entirely from all child tables via the Video ID.
     *
     * @param $id
     *
     * @return \PDOStatement|false
     */
    public function delete($id)
    {
        return $this->pdo->queryExec(
            sprintf(
                '
				DELETE v, tvi, tve, va
				FROM videos v
				LEFT JOIN tv_info tvi ON v.id = tvi.videos_id
				LEFT JOIN tv_episodes tve ON v.id = tve.videos_id
				LEFT JOIN videos_aliases va ON v.id = va.videos_id
				WHERE v.id = %d',
                $id
            )
        );
    }

    /**
     * Sets the TV show's image column to found (1).
     *
     * @param $videoId
     */
    public function setCoverFound($videoId): void
    {
        TvInfo::query()->where('videos_id', $videoId)->update(['image' => 1]);
    }

    /**
     * Get site ID from a Video ID and the site's respective column.
     * Returns the ID value or false if none found.
     *
     *
     * @param $column
     * @param $id
     * @return bool|\Illuminate\Database\Eloquent\Model|mixed|null|static
     */
    public function getSiteByID($column, $id)
    {
        $return = false;
        $video = Video::query()->where('id', $id)->first([$column]);
        if ($column === '*') {
            $return = $video;
        } elseif ($column !== '*' && $video !== null) {
            $return = $video[$column];
        }

        return $return;
    }

    /**
     * Retrieves the Episode ID using the Video ID and either:
     * season/episode numbers OR the airdate.
     *
     * Returns the Episode ID or false if not found
     *
     * @param        $id
     * @param        $series
     * @param        $episode
     * @param string $airdate
     *
     * @return int|false
     */
    public function getBySeasonEp($id, $series, $episode, $airdate = '')
    {
        if ($series > 0 && $episode > 0) {
            $queryString = sprintf('tve.series = %d AND tve.episode = %d', $series, $episode);
        } elseif (! empty($airdate)) {
            $queryString = sprintf('DATE(tve.firstaired) = %s', $this->pdo->escapeString(date('Y-m-d', strtotime($airdate))));
        } else {
            return false;
        }

        $episodeArr = $this->pdo->queryOneRow(
            sprintf(
                '
				SELECT tve.id
				FROM tv_episodes tve
				WHERE tve.videos_id = %d
				AND %s',
                $id,
                $queryString
            )
        );

        return $episodeArr['id'] ?? false;
    }

    /**
     * Returns (true) if episodes for a given Video ID exist or don't (false).
     *
     *
     * @param $videoId
     * @return bool
     */
    public function countEpsByVideoID($videoId): bool
    {
        $count = TvEpisode::query()
            ->where('videos_id', $videoId)->count(['id']);

        return $count !== null && $count > 0;
    }

    /**
     * Parses a release searchname for specific TV show data
     * Returns an array of show data.
     *
     * @param $relname
     *
     * @return array|false
     */
    public function parseInfo($relname)
    {
        $showInfo['name'] = $this->parseName($relname);

        if (! empty($showInfo['name'])) {

            // Retrieve the country from the cleaned name
            $showInfo['country'] = $this->parseCountry($showInfo['name']);

            // Clean show name.
            $showInfo['cleanname'] = preg_replace('/ - \d{1,}$/i', '', $this->cleanName($showInfo['name']));

            // Get the Season/Episode/Airdate
            $showInfo += $this->parseSeasonEp($relname);

            if ((isset($showInfo['season']) && isset($showInfo['episode'])) || isset($showInfo['airdate'])) {
                if (! isset($showInfo['airdate'])) {
                    // If year is present in the release name, add it to the cleaned name for title search
                    if (preg_match('/[^a-z0-9](?P<year>(19|20)(\d{2}))[^a-z0-9]/i', $relname, $yearMatch)) {
                        $showInfo['cleanname'] .= ' ('.$yearMatch['year'].')';
                    }
                    // Check for multi episode release.
                    if (\is_array($showInfo['episode'])) {
                        $showInfo['episode'] = $showInfo['episode'][0];
                    }
                    $showInfo['airdate'] = '';
                }

                return $showInfo;
            }
        }

        return false;
    }

    /**
     * Parses the release searchname and returns a show title.
     *
     * @param string $relname
     *
     * @return string
     */
    private function parseName($relname)
    {
        $showName = '';

        $following = '[^a-z0-9](\d\d-\d\d|\d{1,3}x\d{2,3}|\(?(19|20)\d{2}\)?|(480|720|1080)[ip]|AAC2?|BD-?Rip|Blu-?Ray|D0?\d'.
                '|DD5|DiVX|DLMux|DTS|DVD(-?Rip)?|E\d{2,3}|[HX][-_. ]?26[45]|ITA(-ENG)?|HEVC|[HPS]DTV|PROPER|REPACK|Season|Episode|'.
                'S\d+[^a-z0-9]?((E\d+)[abr]?)*|WEB[-_. ]?(DL|Rip)|XViD)[^a-z0-9]?';

        // For names that don't start with the title.
        if (preg_match('/^([^a-z0-9]{2,}|(sample|proof|repost)-)(?P<name>[\w .-]*?)'.$following.'/i', $relname, $matches)) {
            $showName = $matches['name'];
        } elseif (preg_match('/^(?P<name>[a-z0-9][\w\' .-]*?)'.$following.'/i', $relname, $matches)) {
            // For names that start with the title.
            $showName = $matches['name'];
        }
        // If we still have any of the words in $following, remove them.
        $showName = preg_replace('/'.$following.'/i', ' ', $showName);
        // Remove leading date if present
        $showName = preg_replace('/^\d{6}/', '', $showName);
        // Remove periods, underscored, anything between parenthesis.
        $showName = preg_replace('/\(.*?\)|[._]/i', ' ', $showName);
        // Finally remove multiple spaces and trim leading spaces.
        $showName = trim(preg_replace('/\s{2,}/', ' ', $showName));

        return $showName;
    }

    /**
     * Parses the release searchname for the season/episode/airdate information.
     *
     * @param $relname
     *
     * @return array
     */
    private function parseSeasonEp($relname)
    {
        $episodeArr = [];

        // S01E01-E02 and S01E01-02
        if (preg_match('/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?e(\d{1,3})(?:[e-])(\d{1,3})[^a-z0-9]/i', $relname, $matches)) {
            $episodeArr['season'] = (int) $matches[2];
            $episodeArr['episode'] = [(int) $matches[3], (int) $matches[4]];
        }
        //S01E0102 and S01E01E02 - lame no delimit numbering, regex would collide if there was ever 1000 ep season.
        elseif (preg_match('/^(.*?)[^a-z0-9]s(\d{2})[^a-z0-9]?e(\d{2})e?(\d{2})[^a-z0-9]/i', $relname, $matches)) {
            $episodeArr['season'] = (int) $matches[2];
            $episodeArr['episode'] = (int) $matches[3];
        }
        // S01E01 and S01.E01
        elseif (preg_match('/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?e(\d{1,3})[abr]?[^a-z0-9]/i', $relname, $matches)) {
            $episodeArr['season'] = (int) $matches[2];
            $episodeArr['episode'] = (int) $matches[3];
        }
        // S01
        elseif (preg_match('/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]/i', $relname, $matches)) {
            $episodeArr['season'] = (int) $matches[2];
            $episodeArr['episode'] = 'all';
        }
        // S01D1 and S1D1
        elseif (preg_match('/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?d\d{1}[^a-z0-9]/i', $relname, $matches)) {
            $episodeArr['season'] = (int) $matches[2];
            $episodeArr['episode'] = 'all';
        }
        // 1x01 and 101
        elseif (preg_match('/^(.*?)[^a-z0-9](\d{1,2})x(\d{1,3})[^a-z0-9]/i', $relname, $matches)) {
            $episodeArr['season'] = (int) $matches[2];
            $episodeArr['episode'] = (int) $matches[3];
        }
        // 2009.01.01 and 2009-01-01
        elseif (preg_match('/^(.*?)[^a-z0-9](?P<airdate>(19|20)(\d{2})[.\/-](\d{2})[.\/-](\d{2}))[^a-z0-9]/i', $relname, $matches)) {
            $episodeArr['season'] = $matches[4].$matches[5];
            $episodeArr['episode'] = $matches[5].'/'.$matches[6];
            $episodeArr['airdate'] = date('Y-m-d', strtotime(preg_replace('/[^0-9]/i', '/', $matches['airdate']))); //yyyy-mm-dd
        }
        // 01.01.2009
        elseif (preg_match('/^(.*?)[^a-z0-9](?P<airdate>(\d{2})[^a-z0-9](\d{2})[^a-z0-9](19|20)(\d{2}))[^a-z0-9]/i', $relname, $matches)) {
            $episodeArr['season'] = $matches[5].$matches[6];
            $episodeArr['episode'] = $matches[3].'/'.$matches[4];
            $episodeArr['airdate'] = date('Y-m-d', strtotime(preg_replace('/[^0-9]/i', '/', $matches['airdate']))); //yyyy-mm-dd
        }
        // 01.01.09
        elseif (preg_match('/^(.*?)[^a-z0-9](\d{2})[^a-z0-9](\d{2})[^a-z0-9](\d{2})[^a-z0-9]/i', $relname, $matches)) {
            // Add extra logic to capture the proper YYYY year
            $episodeArr['season'] = $matches[4] = ($matches[4] <= 99 && $matches[4] > 15) ? '19'.$matches[4] : '20'.$matches[4];
            $episodeArr['episode'] = $matches[2].'/'.$matches[3];
            $tmpAirdate = $episodeArr['season'].'/'.$episodeArr['episode'];
            $episodeArr['airdate'] = date('Y-m-d', strtotime(preg_replace('/[^0-9]/i', '/', $tmpAirdate))); //yyyy-mm-dd
        }
        // 2009.E01
        elseif (preg_match('/^(.*?)[^a-z0-9]20(\d{2})[^a-z0-9](\d{1,3})[^a-z0-9]/i', $relname, $matches)) {
            $episodeArr['season'] = '20'.$matches[2];
            $episodeArr['episode'] = (int) $matches[3];
        }
        // 2009.Part1
        elseif (preg_match('/^(.*?)[^a-z0-9](19|20)(\d{2})[^a-z0-9]Part(\d{1,2})[^a-z0-9]/i', $relname, $matches)) {
            $episodeArr['season'] = $matches[2].$matches[3];
            $episodeArr['episode'] = (int) $matches[4];
        }
        // Part1/Pt1
        elseif (preg_match('/^(.*?)[^a-z0-9](?:Part|Pt)[^a-z0-9](\d{1,2})[^a-z0-9]/i', $relname, $matches)) {
            $episodeArr['season'] = 1;
            $episodeArr['episode'] = (int) $matches[2];
        }
        //The.Pacific.Pt.VI.HDTV.XviD-XII / Part.IV
        elseif (preg_match('/^(.*?)[^a-z0-9](?:Part|Pt)[^a-z0-9]([ivx]+)/i', $relname, $matches)) {
            $episodeArr['season'] = 1;
            $epLow = strtolower($matches[2]);
            $episodeArr['episode'] = Utility::convertRomanToInt($epLow);
        }
        // Band.Of.Brothers.EP06.Bastogne.DVDRiP.XviD-DEiTY
        elseif (preg_match('/^(.*?)[^a-z0-9]EP?[^a-z0-9]?(\d{1,3})/i', $relname, $matches)) {
            $episodeArr['season'] = 1;
            $episodeArr['episode'] = (int) $matches[2];
        }
        // Season.1
        elseif (preg_match('/^(.*?)[^a-z0-9]Seasons?[^a-z0-9]?(\d{1,2})/i', $relname, $matches)) {
            $episodeArr['season'] = (int) $matches[2];
            $episodeArr['episode'] = 'all';
        }

        return $episodeArr;
    }

    /**
     * Parses the cleaned release name to determine if it has a country appended.
     *
     * @param string $showName
     *
     * @return string
     */
    private function parseCountry($showName): string
    {
        // Country or origin matching.
        if (preg_match('/[^a-z0-9](US|UK|AU|NZ|CA|NL|Canada|Australia|America|United[^a-z0-9]States|United[^a-z0-9]Kingdom)/i', $showName, $countryMatch)) {
            $currentCountry = strtolower($countryMatch[1]);
            if ($currentCountry === 'canada') {
                $country = 'CA';
            } elseif ($currentCountry === 'australia') {
                $country = 'AU';
            } elseif ($currentCountry === 'america' || $currentCountry === 'united states') {
                $country = 'US';
            } elseif ($currentCountry === 'united kingdom') {
                $country = 'UK';
            } else {
                $country = strtoupper($countryMatch[1]);
            }
        } else {
            $country = '';
        }

        return $country;
    }

    /**
     * Supplementary to parseInfo
     * Cleans a derived local 'showname' for better matching probability
     * Returns the cleaned string.
     *
     * @param $str
     *
     * @return string
     */
    public function cleanName($str): string
    {
        $str = str_replace(['.', '_'], ' ', $str);

        $str = str_replace(['à', 'á', 'â', 'ã', 'ä', 'æ', 'À', 'Á', 'Â', 'Ã', 'Ä'], 'a', $str);
        $str = str_replace(['ç', 'Ç'], 'c', $str);
        $str = str_replace(['Σ', 'è', 'é', 'ê', 'ë', 'È', 'É', 'Ê', 'Ë'], 'e', $str);
        $str = str_replace(['ì', 'í', 'î', 'ï', 'Ì', 'Í', 'Î', 'Ï'], 'i', $str);
        $str = str_replace(['ò', 'ó', 'ô', 'õ', 'ö', 'Ò', 'Ó', 'Ô', 'Õ', 'Ö'], 'o', $str);
        $str = str_replace(['ù', 'ú', 'û', 'ü', 'ū', 'Ú', 'Û', 'Ü', 'Ū'], 'u', $str);
        $str = str_replace('ß', 'ss', $str);

        $str = str_replace('&', 'and', $str);
        $str = preg_replace('/^(history|discovery) channel/i', '', $str);
        $str = str_replace(['\'', ':', '!', '"', '#', '*', '’', ',', '(', ')', '?'], '', $str);
        $str = str_replace('$', 's', $str);
        $str = preg_replace('/\s{2,}/', ' ', $str);

        $str = trim($str, '\"');

        return trim($str);
    }

    /**
     * Simple function that compares two strings of text
     * Returns percentage of similarity.
     *
     * @param $ourName
     * @param $scrapeName
     * @param $probability
     *
     * @return int|float
     */
    public function checkMatch($ourName, $scrapeName, $probability)
    {
        similar_text($ourName, $scrapeName, $matchpct);

        if ($matchpct >= $probability) {
            return $matchpct;
        }

        return 0;
    }

    //

    /**
     * Convert 2012-24-07 to 2012-07-24, there is probably a better way.
     *
     * This shouldn't ever happen as I've never heard of a date starting with year being followed by day value.
     * Could this be a mistake? i.e. trying to solve the mm-dd-yyyy/dd-mm-yyyy confusion into a yyyy-mm-dd?
     *
     * @param string|bool|null $date
     *
     * @return string
     */
    public function checkDate($date): string
    {
        if (! empty($date)) {
            $chk = explode(' ', $date);
            $chkd = explode('-', $chk[0]);
            if ($chkd[1] > 12) {
                $date = date('Y-m-d H:i:s', strtotime($chkd[1].' '.$chkd[2].' '.$chkd[0]));
            }
        } else {
            $date = null;
        }

        return $date;
    }

    /**
     * Checks API response returns have all REQUIRED attributes set
     * Returns true or false.
     *
     * @param $array
     * @param int $type
     *
     * @return bool
     */
    public function checkRequiredAttr($array, $type): bool
    {
        $required = ['failedToMatchType'];

        switch ($type) {
            case 'tvdbS':
                $required = ['id', 'seriesName', 'overview', 'firstAired'];
                break;
            case 'tvdbE':
                $required = ['episodeName', 'airedSeason', 'airedEpisode', 'firstAired', 'overview'];
                break;
            case 'tvmazeS':
                $required = ['id', 'name', 'summary', 'premiered', 'country'];
                break;
            case 'tvmazeE':
                $required = ['name', 'season', 'number', 'airdate', 'summary'];
                break;
            case 'tmdbS':
                $required = ['id', 'original_name', 'overview', 'first_air_date', 'origin_country'];
                break;
            case 'tmdbE':
                $required = ['name', 'season_number', 'episode_number', 'air_date', 'overview'];
                break;
            case 'traktS':
                $required = ['title', 'ids', 'overview', 'first_aired', 'airs', 'country'];
                break;
            case 'traktE':
                $required = ['title', 'season', 'episode', 'overview', 'first_aired'];
                break;
        }

        if (\is_array($required)) {
            foreach ($required as $req) {
                if (! \in_array($type, ['tmdbS', 'tmdbE', 'traktS', 'traktE'], false)) {
                    if (! isset($array->$req)) {
                        return false;
                    }
                } else {
                    if (! isset($array[$req])) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
