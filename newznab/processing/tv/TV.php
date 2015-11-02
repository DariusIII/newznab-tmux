<?php
namespace newznab\processing\tv;

use newznab\processing\Videos;

/**
 * Class TV -- abstract extension of Videos
 * Contains functions suitable for re-use in all TV scrapers
 */
abstract class TV extends Videos
{
	// Television Sources
	const SOURCE_NONE    = 0; // No Scrape source
	const SOURCE_TVDB    = 1; // Scrape source was TVDB
	const SOURCE_TVMAZE  = 2; // Scrape source was TVMAZE
	const SOURCE_TRAKT   = 3; // Scrape source was TraktTV
	const SOURCE_IMDB    = 4; // Scrape source was IMDB
	const SOURCE_TMDB    = 5; // Scrape source was TMDB
	const SOURCE_TVRAGE  = 6; // Scrape source was TvRage

	// Anime Sources
	const SOURCE_ANIDB   = 10; // Scrape source was AniDB

	// Processing signifiers
	const PROCESS_TVDB   =  0; // Process TVDB First
	const PROCESS_TVMAZE = -1; // Process TVMaze Second
	const PROCESS_TRAKT  = -2; // Process Trakt Third
	const PROCESS_IMDB   = -3; // Process IMDB Fourth
	const PROCESS_TMDB   = -4; // Process TMDB Fifth
	const PROCESS_TVRAGE = -5; // Process TvRage Sixth
	const NO_MATCH_FOUND = -6; // Failed All Methods

	/**
	 * @var bool
	 */
	public $echooutput;

	/**
	 * @var int
	 */
	public $tvqty;

	/**
	 * @string Path to Save Images
	 */
	public $imgSavePath;

	/**
	 * @var array Site ID columns for TV
	 */
	public $siteColumns;

	/**
	 * @param array $options Class instances / Echo to CLI.
	 */
	public function __construct(array $options = [])
	{
		parent::__construct($options);
		$defaults = [
			'Echo'     => false,
			'Settings' => null,
		];
		$options += $defaults;

		$this->echooutput = ($options['Echo'] && NN_ECHOCLI);
		$this->catWhere = 'categoryid BETWEEN 5000 AND 5999 AND categoryid NOT IN (5070)';
		$this->tvqty = ($this->pdo->getSetting('maxrageprocessed') != '') ? $this->pdo->getSetting('maxrageprocessed') : 75;
		$this->imgSavePath = NN_COVERS . 'tvshows' . DS;
		$this->siteColumns = ['tvdb', 'trakt', 'tvrage', 'tvmaze', 'imdb', 'tmdb'];
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
	 * @param integer $siteId
	 * @param integer $series
	 * @param integer $episode
	 *
	 * @return array|false    False on failure, an array of information fields otherwise.
	 */
	abstract protected function getEpisodeInfo($siteId, $series, $episode);

	/**
	 * Retrieve poster image for TV episode from site using its API.
	 *
	 * @param integer $videoId ID from videos table.
	 * @param integer $siteId  ID that this site uses for the programme.
	 *
	 * @return null
	 */
	abstract protected function getPoster($videoId, $siteId);

	/**
	 * Retrieve info of TV programme from site using it's API.
	 *
	 * @param string $name Title of programme to look up. Usually a cleaned up version from releases table.
	 *
	 * @return array|false    False on failure, an array of information fields otherwise.
	 */
	abstract protected function getShowInfo($name);

	/**
	 * Retrieve releases for TV processing
	 * Returns a PDO Object of rows or false if none found
	 *
	 * @param string $groupID -- ID of the usenet group to process
	 * @param string $guidChar -- threading method by first guid character
	 * @param int    $lookupSetting -- whether or not to use the API
	 * @param int    $status -- release processing status of tv_episodes_id
	 *
	 * @return bool|int|\PDOStatement
	 */
	public function getTvReleases($groupID = '', $guidChar = '', $lookupSetting = 1, $status = 0)
	{
		$ret = 0;
		if ($lookupSetting == 0) {
			return $ret;
		}

		$res = $this->pdo->queryDirect(
			sprintf("
				SELECT SQL_NO_CACHE r.searchname, r.id
				FROM releases r
				WHERE r.nzbstatus = 1
				AND r.videos_id = 0
				AND r.tv_episodes_id = %d
				AND r.size > 1048576
				AND %s
				%s %s %s
				ORDER BY r.postdate DESC
				LIMIT %d",
				$status,
				$this->catWhere,
				($groupID === '' ? '' : 'AND r.groupid = ' . $groupID),
				($guidChar === '' ? '' : 'AND r.guid ' . $this->pdo->likeString($guidChar, false, true)),
				($lookupSetting == 2 ? 'AND r.isrenamed = 1' : ''),
				$this->tvqty
			)
		);
		return $res;
	}

	/**
	 * Updates the release when match for the current scraper is found
	 *
	 * @param     $videoId
	 * @param     $releaseId
	 * @param int $episodeId
	 */
	public function setVideoIdFound($videoId, $releaseId, $episodeId) {
		$this->pdo->queryExec(
			sprintf('
				UPDATE releases
				SET videos_id = %d, tv_episodes_id = %d
				WHERE %s
				AND id = %d',
				$videoId,
				$episodeId,
				$this->catWhere,
				$releaseId
			)
		);
	}

	/**
	 * Updates the release tv_episodes_id status when scraper match is not found
	 *
	 * @param $status
	 * @param $Id
	 */
	public function setVideoNotFound($status, $Id)
	{
		$this->pdo->queryExec(
			sprintf('
				UPDATE releases
				SET tv_episodes_id = %d
				WHERE %s
				AND id = %d',
				$status,
				$this->catWhere,
				$Id
			)
		);
	}

	/**
	 * Inserts a new video ID into the database for TV shows
	 * If a duplicate is found it is handle by calling update instead
	 *
	 * @param array $showArr
	 *
	 * @return int
	 */
	public function add($showArr = array())
	{
		$videoId = false;

		// Check if the country is not a proper code and retrieve if not
		if ($showArr['country'] !== '' && strlen($showArr['country']) > 2) {
			$showArr['country'] = $this->countryCode($showArr['country']);
		}

		// Check if video already exists based on site ID info
		// if that fails be sure we're not inserting duplicates by checking the title
		foreach ($this->siteColumns AS $column) {
			if ($showArr[$column] > 0) {
				$videoId = $this->getVideoIDFromSiteID($column, $showArr[$column]);
			}
			if ($videoId !== false) {
				break;
			}
		}

		if ($videoId === false) {
			$videoId = $this->getByTitleQuery($showArr['title'], $showArr['type']);
		}

		if ($videoId === false) {
			// Insert the Show
			$videoId = $this->pdo->queryInsert(
				sprintf('
					INSERT INTO videos
					(type, title, countries_id, started, source, tvdb, trakt, tvrage, tvmaze, imdb, tmdb)
					VALUES (%d, %s, %s, %s, %d, %d, %d, %d, %d, %d, %d)',
					$showArr['type'],
					$this->pdo->escapeString($showArr['title']),
					$this->pdo->escapeString((isset($showArr['country']) ? $showArr['country'] : '')),
					$this->pdo->escapeString($showArr['started']),
					$showArr['source'],
					$showArr['tvdb'],
					$showArr['trakt'],
					$showArr['tvrage'],
					$showArr['tvmaze'],
					$showArr['imdb'],
					$showArr['tmdb']
				)
			);
			// Insert the supplementary show info
			$this->pdo->queryInsert(
				sprintf("
					INSERT INTO tv_info (videos_id, summary, publisher)
					VALUES (%d, %s, %s)",
					$videoId,
					$this->pdo->escapeString($showArr['summary']),
					$this->pdo->escapeString($showArr['publisher'])
				)
			);
			// If we have AKAs\aliases, insert those as well
			if (!empty($showArr['aliases'])) {
				$this->addAliases($videoId, $showArr['aliases']);
			}
		} else {
			// If a local match was found, just update missing video info
			$this->update($videoId, $showArr);
		}
		return (int)$videoId;
	}

	/**
	 * Inserts a new TV episode into the tv_episodes table following a match to a Video ID
	 *
	 * @param int   $videoId
	 * @param array $episodeArr
	 *
	 * @return false|int|string
	 */
	public function addEpisode($videoId, $episodeArr = array())
	{
		$episodeId = $this->getBySeasonEp($videoId, $episodeArr['series'], $episodeArr['episode'], $episodeArr['firstaired']);

		if ($episodeId === false) {
			$episodeId = $this->pdo->queryInsert(
				sprintf('
					INSERT INTO tv_episodes (videos_id, series, episode, se_complete, title, firstaired, summary)
					VALUES (%d, %d, %d, %s, %s, %s, %s)
					ON DUPLICATE KEY update se_complete = %s',
					$videoId,
					$episodeArr['series'],
					$episodeArr['episode'],
					$this->pdo->escapeString($episodeArr['se_complete']),
					$this->pdo->escapeString($episodeArr['title']),
					$this->pdo->escapeString($episodeArr['firstaired']),
					$this->pdo->escapeString($episodeArr['summary']),
					$this->pdo->escapeString($episodeArr['se_complete'])
				)
			);
		}
		return $episodeId;
	}

	/**
	 * Updates the show info with data from the supplied array
	 * Only called when a duplicate show is found during insert
	 *
	 * @param int $videoId
	 * @param array $showArr
	 */
	public function update($videoId, $showArr = array())
	{
		if ($showArr['country'] !== '') {
			$showArr['country'] = $this->countryCode($showArr['country']);
		}

		$ifStringID = 'IF(%s = 0, %s, %s)';
		$ifStringInfo = "IF(%s = '', %s, %s)";

		$this->pdo->queryExec(
			sprintf('
				UPDATE videos v
				LEFT JOIN tv_info tvi ON v.id = tvi.videos_id
				SET v.countries_id = %s, v.tvdb = %s, v.trakt = %s, v.tvrage = %s,
					v.tvmaze = %s, v.imdb = %s, v.tmdb = %s,
					tvi.summary = %s, tvi.publisher = %s
				WHERE v.id = %d',
				sprintf($ifStringInfo, 'v.countries_id', $this->pdo->escapeString($showArr['country']), 'v.countries_id'),
				sprintf($ifStringID, 'v.tvdb', $showArr['tvdb'], 'v.tvdb'),
				sprintf($ifStringID, 'v.trakt', $showArr['trakt'], 'v.trakt'),
				sprintf($ifStringID, 'v.tvrage', $showArr['tvrage'], 'v.tvrage'),
				sprintf($ifStringID, 'v.tvmaze', $showArr['tvmaze'], 'v.tvmaze'),
				sprintf($ifStringID, 'v.imdb', $showArr['imdb'], 'v.imdb'),
				sprintf($ifStringID, 'v.tmdb', $showArr['tmdb'], 'v.tmdb'),
				sprintf($ifStringInfo, 'tvi.summary', $this->pdo->escapeString($showArr['summary']), 'tvi.summary'),
				sprintf($ifStringInfo, 'tvi.publisher', $this->pdo->escapeString($showArr['publisher']), 'tvi.publisher'),
				$videoId
			)
		);
		if (!empty($showArr['aliases'])) {
			$this->addAliases($videoId, $showArr['aliases']);
		}
	}

	/**
	 * Deletes a TV show entirely from all child tables via the Video ID
	 *
	 * @param $id
	 *
	 * @return bool|\PDOStatement
	 */
	public function delete($id)
	{
		return $this->pdo->queryExec(
			sprintf("
				DELETE v, tvi, tve
				FROM videos v
				LEFT JOIN tv_info tvi ON v.id = tvi.videos_id
				LEFT JOIN tv_episodes tve ON v.id = tve.videos_id
				LEFT JOIN videos_akas va ON v.id = va.videos_id
				WHERE v.id = %d",
				$id
			)
		);
	}

	/**
	 * Sets the TV show's image column to found (1)
	 *
	 * @param $videoId
	 */
	public function setCoverFound($videoId)
	{
		$this->pdo->queryExec(
			sprintf("
				UPDATE tv_info
				SET image = 1
				WHERE videos_id = %d",
				$videoId
			)
		);
	}

	/**
	 * Get site ID from a Video ID and the site's respective column.
	 * Returns the ID value or false if none found
	 *
	 * @param string $column
	 * @param int $id
	 *
	 * @return array|bool
	 */
	public function getSiteByID($column, $id)
	{
		$return = false;
		$videoArr = $this->pdo->queryOneRow(
			sprintf("
				SELECT %s
				FROM videos
				WHERE id = %d",
				$column,
				$id
			)
		);
		if ($column === '*') {
			$return = $videoArr;
		} else if ($column !== '*' && isset($videoArr[$column])) {
			$return = $videoArr[$column];
		}
		return $return;
	}

	/**
	 * Retrieves the Episode ID using the Video ID and either:
	 * season/episode numbers OR the airdate
	 *
	 * Returns the Episode ID or false if not found
	 *
	 * @param        $id
	 * @param        $series
	 * @param        $episode
	 * @param string $airdate
	 *
	 * @return int|bool
	 */
	public function getBySeasonEp($id, $series, $episode, $airdate = '')
	{
		if ($episode > 0) {
			$queryString = sprintf('series = %d AND episode = %d', $series, $episode);
		} else if (!empty($airdate) && $airdate !== '') {
			$queryString = sprintf('DATE(firstaired) = %s', $this->pdo->escapeString(date('Y-m-d', strtotime($airdate))));
		} else {
			return false;
		}

		$episodeArr = $this->pdo->queryOneRow(
			sprintf("
				SELECT id
				FROM tv_episodes
				WHERE videos_id = %d
				AND %s",
				$id,
				$queryString
			)
		);
		return (isset($episodeArr['id']) ? $episodeArr['id'] : false);
	}

	/**
	 * Returns (true) if episodes for a given Video ID exist or don't (false)
	 *
	 * @param $videoId
	 *
	 * @return bool
	 */
	public function countEpsByVideoID($videoId)
	{
		$count = $this->pdo->queryOneRow(
			sprintf('
				SELECT count(id) AS num
				FROM tv_episodes
				WHERE videos_id = %d',
				$videoId
			)
		);
		return (isset($count['num']) && (int)$count['num'] > 0 ? true : false);
	}

	/**
	 * Supplementary to parseNameEpSeason
	 * Cleans a derived local 'showname' for better matching probability
	 * Returns the cleaned string
	 *
	 * @param $str
	 *
	 * @return string
	 */
	public function cleanName($str)
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
	 * Parses a release searchname for specific TV show data
	 * Returns an array of show data
	 *
	 * @param $relname
	 *
	 * @return array|bool
	 */
	public function parseNameEpSeason($relname)
	{
		$showInfo = [
			'name'       => '',
			'season'     => '',
			'episode'    => '',
			'seriesfull' => '',
			'airdate'    => '',
			'country'    => '',
			'year'       => '',
			'cleanname'  => ''
		];
		$matches = '';

		$following = 	'[^a-z0-9](\d\d-\d\d|\d{1,3}x\d{2,3}|\(?(19|20)\d{2}\)?|(480|720|1080)[ip]|AAC2?|BD-?Rip|Blu-?Ray|D0?\d' .
			'|DD5|DiVX|DLMux|DTS|DVD(-?Rip)?|E\d{2,3}|[HX][-_. ]?26[45]|ITA(-ENG)?|HEVC|[HPS]DTV|PROPER|REPACK|Season|Episode|' .
			'S\d+[^a-z0-9]?(E\d+)?[ab]?|WEB[-_. ]?(DL|Rip)|XViD)[^a-z0-9]';

		// For names that don't start with the title.
		if (preg_match('/[^a-z0-9]{2,}(?P<name>[\w .-]*?)' . $following . '/i', $relname, $matches)) {
			$showInfo['name'] = $matches[1];
		} else if (preg_match('/^(?P<name>[a-z0-9][\w .-]*?)' . $following . '/i', $relname, $matches)) {
			// For names that start with the title.
			$showInfo['name'] = $matches[1];
		}

		if (!empty($showInfo['name'])) {
			// S01E01-E02 and S01E01-02
			if (preg_match('/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?e(\d{1,3})(?:[e-])(\d{1,3})[^a-z0-9]/i', $relname, $matches)) {
				$showInfo['season'] = intval($matches[2]);
				$showInfo['episode'] = [intval($matches[3]), intval($matches[4])];
			}
			//S01E0102 - lame no delimit numbering, regex would collide if there was ever 1000 ep season.
			else if (preg_match('/^(.*?)[^a-z0-9]s(\d{2})[^a-z0-9]?e(\d{2})(\d{2})[^a-z0-9]/i', $relname, $matches)) {
				$showInfo['season'] = intval($matches[2]);
				$showInfo['episode'] = [intval($matches[3]), intval($matches[4])];
			}
			// S01E01 and S01.E01
			else if (preg_match('/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?e(\d{1,3})[ab]?[^a-z0-9]/i', $relname, $matches)) {
				$showInfo['season'] = intval($matches[2]);
				$showInfo['episode'] = intval($matches[3]);
			}
			// S01
			else if (preg_match('/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]/i', $relname, $matches)) {
				$showInfo['season'] = intval($matches[2]);
				$showInfo['episode'] = 'all';
			}
			// S01D1 and S1D1
			else if (preg_match('/^(.*?)[^a-z0-9]s(\d{1,2})[^a-z0-9]?d\d{1}[^a-z0-9]/i', $relname, $matches)) {
				$showInfo['season'] = intval($matches[2]);
				$showInfo['episode'] = 'all';
			}
			// 1x01
			else if (preg_match('/^(.*?)[^a-z0-9](\d{1,2})x(\d{1,3})[^a-z0-9]/i', $relname, $matches)) {
				$showInfo['season'] = intval($matches[2]);
				$showInfo['episode'] = intval($matches[3]);
			}
			// 2009.01.01 and 2009-01-01
			else if (preg_match('/^(.*?)[^a-z0-9](?P<airdate>(19|20)(\d{2})[.\/-](\d{2})[.\/-](\d{2}))[^a-z0-9]/i', $relname, $matches)) {
				$showInfo['season'] = $matches[4] . $matches[5];
				$showInfo['episode'] = $matches[5] . '/' . $matches[6];
				$showInfo['airdate'] = date('Y-m-d', strtotime(preg_replace('/[^0-9]/i', '/', $matches['airdate']))); //yyyy-mm-dd
			}
			// 01.01.2009
			else if (preg_match('/^(.*?)[^a-z0-9](?P<airdate>(\d{2})[^a-z0-9](\d{2})[^a-z0-9](19|20)(\d{2}))[^a-z0-9]/i', $relname, $matches)) {
				$showInfo['season'] = $matches[5] . $matches[6];
				$showInfo['episode'] = $matches[3] . '/' . $matches[4];
				$showInfo['airdate'] = date('Y-m-d', strtotime(preg_replace('/[^0-9]/i', '/', $matches['airdate']))); //yyyy-mm-dd
			}
			// 01.01.09
			else if (preg_match('/^(.*?)[^a-z0-9](\d{2})[^a-z0-9](\d{2})[^a-z0-9](\d{2})[^a-z0-9]/i', $relname, $matches)) {
				// Add extra logic to capture the proper YYYY year
				$showInfo['season'] = $matches[4] = ($matches[4] <= 99 && $matches[4] > 15) ? '19' . $matches[4] : '20' . $matches[4];
				$showInfo['episode'] = $matches[2] . '/' . $matches[3];
				$tmpAirdate = $showInfo['season'] . '/' . $showInfo['episode'];
				$showInfo['airdate'] = date('Y-m-d', strtotime(preg_replace('/[^0-9]/i', '/', $tmpAirdate))); //yyyy-mm-dd
			}
			// 2009.E01
			else if (preg_match('/^(.*?)[^a-z0-9]20(\d{2})[^a-z0-9](\d{1,3})[^a-z0-9]/i', $relname, $matches)) {
				$showInfo['season'] = '20' . $matches[2];
				$showInfo['episode'] = intval($matches[3]);
			}
			// 2009.Part1
			else if (preg_match('/^(.*?)[^a-z0-9](19|20)(\d{2})[^a-z0-9]Part(\d{1,2})[^a-z0-9]/i', $relname, $matches)) {
				$showInfo['season'] = $matches[2] . $matches[3];
				$showInfo['episode'] = intval($matches[4]);
			}
			// Part1/Pt1
			else if (preg_match('/^(.*?)[^a-z0-9](?:Part|Pt)[^a-z0-9](\d{1,2})[^a-z0-9]/i', $relname, $matches)) {
				$showInfo['season'] = 1;
				$showInfo['episode'] = intval($matches[2]);
			}
			//The.Pacific.Pt.VI.HDTV.XviD-XII / Part.IV
			else if (preg_match('/^(.*?)[^a-z0-9](?:Part|Pt)[^a-z0-9]([ivx]+)/i', $relname, $matches)) {
				$showInfo['season'] = 1;
				$epLow = strtolower($matches[2]);
				$showInfo['episode'] = $this->convertRomanToInt($epLow);
			}
			// Band.Of.Brothers.EP06.Bastogne.DVDRiP.XviD-DEiTY
			else if (preg_match('/^(.*?)[^a-z0-9]EP?[^a-z0-9]?(\d{1,3})/i', $relname, $matches)) {
				$showInfo['season'] = 1;
				$showInfo['episode'] = intval($matches[2]);
			}
			// Season.1
			else if (preg_match('/^(.*?)[^a-z0-9]Seasons?[^a-z0-9]?(\d{1,2})/i', $relname, $matches)) {
				$showInfo['season'] = intval($matches[2]);
				$showInfo['episode'] = 'all';
			}

			$countryMatch = $yearMatch = '';
			// Country or origin matching.
			if (preg_match('/[^a-z0-9](US|UK|AU|NZ|CA|NL|Canada|Australia|America|United[^a-z0-9]States|United[^a-z0-9]Kingdom)[^a-z0-9]/i', $showInfo['name'], $countryMatch)) {
				$currentCountry = strtolower($countryMatch[1]);
				if ($currentCountry == 'canada') {
					$showInfo['country'] = 'CA';
				} else if ($currentCountry == 'australia') {
					$showInfo['country'] = 'AU';
				} else if ($currentCountry == 'america' || $currentCountry == 'united states') {
					$showInfo['country'] = 'US';
				} else if ($currentCountry == 'united kingdom') {
					$showInfo['country'] = 'UK';
				} else {
					$showInfo['country'] = strtoupper($countryMatch[1]);
				}
			} else {
				$showInfo['country'] = '';
			}

			// Clean show name.
			$showInfo['cleanname'] = preg_replace('/ - \d{1,}$/i', '', $this->cleanName($showInfo['name']));

			// Check for dates instead of seasons.
			if (strlen($showInfo['season']) == 4) {
				$showInfo['seriesfull'] = $showInfo['season'] . "/" . $showInfo['episode'];
			} else {
				// Get year if present (not for releases with dates as seasons).
				if (preg_match('/[^a-z0-9](19|20)(\d{2})/i', $relname, $yearMatch)) {
					$showInfo['year'] = $yearMatch[1] . $yearMatch[2];
				}
				// Check for multi episode release.
				if (is_array($showInfo['episode'])) {
					$showInfo['episode'] = $showInfo['episode'][0];
				}

				$showInfo['seriesfull'] = sprintf('S02%d', $showInfo['season']) . sprintf('E02%d', $showInfo['episode']);
			}
			$showInfo['airdate'] = (isset($showInfo['airdate']) && !empty($showInfo['airdate']) ? $showInfo['airdate'] : '');
			return $showInfo;
		}
		return false;
	}

	/**
	 * Simple function that compares two strings of text
	 * Returns percentage of similarity
	 *
	 * @param $ourName
	 * @param $scrapeName
	 * @param $probability
	 *
	 * @return bool|float
	 */
	public function checkMatch($ourName, $scrapeName, $probability)
	{
		similar_text($ourName, $scrapeName, $matchpct);

		if (NN_DEBUG) {
			echo PHP_EOL . sprintf('Match Percentage: %d percent between %s and %s', $matchpct, $ourName, $scrapeName) . PHP_EOL;
		}

		if ($matchpct >= $probability) {
			return $matchpct;
		} else {
			return 0;
		}
	}

	//
	/**
	 * Convert 2012-24-07 to 2012-07-24, there is probably a better way
	 *
	 * This shouldn't ever happen as I've never heard of a date starting with year being followed by day value.
	 * Could this be a mistake? i.e. trying to solve the mm-dd-yyyy/dd-mm-yyyy confusion into a yyyy-mm-dd?
	 *
	 * @param string $date
	 *
	 * @return string
	 */
	public function checkDate($date)
	{
		if (!empty($date)) {
			$chk = explode(" ", $date);
			$chkd = explode("-", $chk[0]);
			if ($chkd[1] > 12) {
				$date = date('Y-m-d H:i:s', strtotime($chkd[1] . " " . $chkd[2] . " " . $chkd[0]));
			}
		} else {
			$date = null;
		}
		return $date;
	}

	/**
	 * Checks API response returns have all REQUIRED attributes set
	 * Returns true or false
	 *
	 * @param array $array
	 * @param int $type
	 *
	 * @return bool
	 */
	public function checkRequired($array = array(), $type)
	{
		$required = ['failedToMatchType'];

		switch ($type) {
			case 'tvdbS':
				$required = ['id', 'name', 'overview', 'firstAired'];
				break;
			case 'tvdbE':
				$required = ['name', 'season', 'number', 'firstAired', 'overview'];
				break;
			case 'tvmazeS':
				$required = ['id', 'name', 'summary', 'premiered', 'country'];
				break;
			case 'tvmazeE':
				$required = ['name', 'season', 'number', 'airdate', 'summary'];
				break;
		}

		if (is_array($required)) {
			foreach ($required as $req) {
				if (!isset($array->$req)) {
					return false;
				}
			}
		}
		return true;
	}
}
