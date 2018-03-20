<?php

namespace Blacklight;

use ApaiIO\ApaiIO;
use App\Models\Genre;
use Blacklight\db\DB;
use GuzzleHttp\Client;
use App\Models\Release;
use App\Models\Category;
use App\Models\Settings;
use App\Models\ConsoleInfo;
use ApaiIO\Operations\Search;
use Illuminate\Support\Carbon;
use ApaiIO\Configuration\Country;
use ApaiIO\Request\GuzzleRequest;
use Illuminate\Support\Facades\Cache;
use ApaiIO\Configuration\GenericConfiguration;
use ApaiIO\ResponseTransformer\XmlToSimpleXmlObject;

/**
 * Class Console.
 */
class Console
{
    public const CONS_UPROC = 0; // Release has not been processed.
    public const CONS_NTFND = -2;

    protected const MATCH_PERCENT = 60;

    /**
     * @var \Blacklight\db\DB
     */
    public $pdo;

    /**
     * @var bool
     */
    public $echooutput;

    /**
     * @var null|string
     */
    public $pubkey;

    /**
     * @var null|string
     */
    public $privkey;

    /**
     * @var null|string
     */
    public $asstag;

    /**
     * @var int|null|string
     */
    public $gameqty;

    /**
     * @var int|null|string
     */
    public $sleeptime;

    /**
     * @var string
     */
    public $imgSavePath;

    /**
     * @var string
     */
    public $renamed;

    /**
     * @var string
     */
    public $catWhere;

    /**
     * Store names of failed Amazon lookup items.
     * @var array
     */
    public $failCache;

    /**
     * @param array $options Class instances / Echo to cli.
     * @throws \Exception
     */
    public function __construct(array $options = [])
    {
        $defaults = [
            'Echo'     => false,
            'Settings' => null,
        ];
        $options += $defaults;

        $this->echooutput = ($options['Echo'] && config('nntmux.echocli'));
        $this->pdo = ($options['Settings'] instanceof DB ? $options['Settings'] : new DB());

        $this->pubkey = Settings::settingValue('APIs..amazonpubkey');
        $this->privkey = Settings::settingValue('APIs..amazonprivkey');
        $this->asstag = Settings::settingValue('APIs..amazonassociatetag');
        $this->gameqty = (Settings::settingValue('..maxgamesprocessed') !== '') ? (int) Settings::settingValue('..maxgamesprocessed') : 150;
        $this->sleeptime = (Settings::settingValue('..amazonsleep') !== '') ? (int) Settings::settingValue('..amazonsleep') : 1000;
        $this->imgSavePath = NN_COVERS.'console'.DS;
        $this->renamed = (int) Settings::settingValue('..lookupgames') === 2;

        $this->failCache = [];
    }

    /**
     * @param $id
     * @return \Illuminate\Database\Eloquent\Model|null|static
     */
    public function getConsoleInfo($id)
    {
        return ConsoleInfo::query()->where('consoleinfo.id', $id)->select('consoleinfo.*', 'genres.title as genres')->leftJoin('genres', 'genres.id', '=', 'consoleinfo.genres_id')->first();
    }

    /**
     * @param $title
     * @param $platform
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getConsoleInfoByName($title, $platform)
    {
        //only used to get a count of words
        $searchWords = '';

        $title = preg_replace('/( - | -|\(.+\)|\(|\))/', ' ', $title);
        $title = preg_replace('/[^\w ]+/', '', $title);
        $title = trim(trim(preg_replace('/\s\s+/i', ' ', $title)));
        $words = explode(' ', $title);

        foreach ($words as $word) {
            $word = trim(rtrim(trim($word), '-'));
            if ($word !== '' && $word !== '-') {
                $word = '+'.$word;
                $searchWords .= sprintf('%s ', $word);
            }
        }
        $searchWords = trim($searchWords);

        return ConsoleInfo::search($searchWords, $platform)->first();
    }

    /**
     * @param       $cat
     * @param       $start
     * @param       $num
     * @param       $orderBy
     * @param array $excludedcats
     *
     * @return array
     * @throws \Exception
     */
    public function getConsoleRange($cat, $start, $num, $orderBy, array $excludedcats = []): array
    {
        $browseBy = $this->getBrowseBy();

        $catsrch = '';
        if (\count($cat) > 0 && (int) $cat[0] !== -1) {
            $catsrch = Category::getCategorySearch($cat, '', false);
        }

        $exccatlist = '';
        if (\count($excludedcats) > 0) {
            $exccatlist = ' AND r.categories_id NOT IN ('.implode(',', $excludedcats).')';
        }

        $order = $this->getConsoleOrder($orderBy);

        $calcSql = sprintf(
                    "
					SELECT SQL_CALC_FOUND_ROWS
						con.id,
						GROUP_CONCAT(r.id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_id
					FROM consoleinfo con
					LEFT JOIN releases r ON con.id = r.consoleinfo_id
					WHERE r.nzbstatus = 1
					AND con.title != ''
					AND con.cover = 1
					AND r.passwordstatus %s
					%s %s %s
					GROUP BY con.id
					ORDER BY %s %s %s",
                        Releases::showPasswords(),
                        $browseBy,
                        $catsrch,
                        $exccatlist,
                        $order[0],
                        $order[1],
                        ($start === false ? '' : ' LIMIT '.$num.' OFFSET '.$start)
                );

        $cached = Cache::get(md5($calcSql));
        if ($cached !== null) {
            $consoles = $cached;
        } else {
            $consoles = $this->pdo->queryCalc($calcSql);
            $expiresAt = Carbon::now()->addSeconds(config('nntmux.cache_expiry_medium'));
            Cache::put(md5($calcSql), $consoles, $expiresAt);
        }

        $consoleIDs = $releaseIDs = false;

        if (\is_array($consoles['result'])) {
            foreach ($consoles['result'] as $console => $id) {
                $consoleIDs[] = $id['id'];
                $releaseIDs[] = $id['grp_release_id'];
            }
        }

        $sql = sprintf(
                    "
				SELECT
					GROUP_CONCAT(r.id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_id,
					GROUP_CONCAT(r.rarinnerfilecount ORDER BY r.postdate DESC SEPARATOR ',') as grp_rarinnerfilecount,
					GROUP_CONCAT(r.haspreview ORDER BY r.postdate DESC SEPARATOR ',') AS grp_haspreview,
					GROUP_CONCAT(r.passwordstatus ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_password,
					GROUP_CONCAT(r.guid ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_guid,
					GROUP_CONCAT(rn.releases_id ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_nfoid,
					GROUP_CONCAT(g.name ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grpname,
					GROUP_CONCAT(r.searchname ORDER BY r.postdate DESC SEPARATOR '#') AS grp_release_name,
					GROUP_CONCAT(r.postdate ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_postdate,
					GROUP_CONCAT(r.size ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_size,
					GROUP_CONCAT(r.totalpart ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_totalparts,
					GROUP_CONCAT(r.comments ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_comments,
					GROUP_CONCAT(r.grabs ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grabs,
					GROUP_CONCAT(df.failed ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_failed,
				con.*,
				r.consoleinfo_id,
				g.name AS group_name,
				genres.title AS genre,
				rn.releases_id AS nfoid
				FROM releases r
				LEFT OUTER JOIN groups g ON g.id = r.groups_id
				LEFT OUTER JOIN release_nfos rn ON rn.releases_id = r.id
				LEFT OUTER JOIN dnzb_failures df ON df.release_id = r.id
				INNER JOIN consoleinfo con ON con.id = r.consoleinfo_id
				INNER JOIN genres ON con.genres_id = genres.id
				WHERE con.id IN (%s)
				AND r.id IN (%s)
				%s
				GROUP BY con.id
				ORDER BY %s %s",
                        (\is_array($consoleIDs) ? implode(',', $consoleIDs) : -1),
                        (\is_array($releaseIDs) ? implode(',', $releaseIDs) : -1),
                        $catsrch,
                        $order[0],
                        $order[1]
                );

        $return = Cache::get(md5($sql));
        if ($return !== null) {
            return $return;
        }

        $return = $this->pdo->query($sql);
        if (! empty($return)) {
            $return[0]['_totalcount'] = $consoles['total'] ?? 0;
        }

        $expiresAt = Carbon::now()->addSeconds(config('nntmux.cache_expiry_long'));
        Cache::put(md5($sql), $return, $expiresAt);

        return $return;
    }

    /**
     * @param $orderBy
     * @return array
     */
    public function getConsoleOrder($orderBy): array
    {
        $order = ($orderBy === '') ? 'r.postdate' : $orderBy;
        $orderArr = explode('_', $order);
        switch ($orderArr[0]) {
            case 'title':
                $orderfield = 'con.title';
                break;
            case 'platform':
                $orderfield = 'con.platform';
                break;
            case 'releasedate':
                $orderfield = 'con.releasedate';
                break;
            case 'genre':
                $orderfield = 'con.genres_id';
                break;
            case 'size':
                $orderfield = 'r.size';
                break;
            case 'files':
                $orderfield = 'r.totalpart';
                break;
            case 'stats':
                $orderfield = 'r.grabs';
                break;
            case 'posted':
            default:
                $orderfield = 'r.postdate';
                break;
        }
        $ordersort = (isset($orderArr[1]) && preg_match('/^asc|desc$/i', $orderArr[1])) ? $orderArr[1] : 'desc';

        return [$orderfield, $ordersort];
    }

    /**
     * @return array
     */
    public function getConsoleOrdering(): array
    {
        return ['title_asc', 'title_desc', 'posted_asc', 'posted_desc', 'size_asc', 'size_desc', 'files_asc', 'files_desc', 'stats_asc', 'stats_desc', 'platform_asc', 'platform_desc', 'releasedate_asc', 'releasedate_desc', 'genre_asc', 'genre_desc'];
    }

    /**
     * @return array
     */
    public function getBrowseByOptions(): array
    {
        return ['platform' => 'platform', 'title' => 'title', 'genre' => 'genres_id'];
    }

    /**
     * @return string
     */
    public function getBrowseBy(): string
    {
        $browseBy = ' ';
        $browsebyArr = $this->getBrowseByOptions();
        foreach ($browsebyArr as $bbk => $bbv) {
            if (isset($_REQUEST[$bbk]) && ! empty($_REQUEST[$bbk])) {
                $bbs = stripslashes($_REQUEST[$bbk]);
                $browseBy .= 'AND con.'.$bbv.' '.$this->pdo->likeString($bbs);
            }
        }

        return $browseBy;
    }

    /**
     * @param $id
     * @param $title
     * @param $asin
     * @param $url
     * @param $salesrank
     * @param $platform
     * @param $publisher
     * @param $releasedate
     * @param $esrb
     * @param $cover
     * @param $genreID
     * @param string $review
     */
    public function update($id, $title, $asin, $url, $salesrank, $platform, $publisher, $releasedate, $esrb, $cover, $genreID, $review = 'review'): void
    {
        ConsoleInfo::query()
            ->where('id', $id)
            ->update(
                [
                    'title' => $title,
                    'asin' => $asin,
                    'url' => $url,
                    'salesrank' => $salesrank,
                    'platform' => $platform,
                    'publisher' => $publisher,
                    'releasedate' => $releasedate !== '' ? $releasedate : 'null',
                    'esrb' => $esrb,
                    'cover' => $cover,
                    'genres_id' => $genreID,
                    'review' => $review === 'review' ? $review : substr($review, 0, 3000),
                ]
            );
    }

    /**
     * @param $gameInfo
     * @return int|mixed
     * @throws \Exception
     */
    public function updateConsoleInfo($gameInfo)
    {
        $consoleId = self::CONS_NTFND;

        $amaz = $this->fetchAmazonProperties($gameInfo['title'], $gameInfo['node']);

        if ($amaz) {
            $gameInfo['platform'] = $this->_replacePlatform($gameInfo['platform']);

            $con = $this->_setConBeforeMatch($amaz, $gameInfo);

            // Basically the XBLA names contain crap, this is to reduce the title down far enough to be usable.
            if (stripos('xbla', $gameInfo['platform']) !== false) {
                $gameInfo['title'] = substr($gameInfo['title'], 0, 10);
                $con['substr'] = $gameInfo['title'];
            }

            if ($this->_matchConToGameInfo($gameInfo, $con) === true) {
                $con += $this->_setConAfterMatch($amaz);
                $con += $this->_matchGenre($amaz);

                // Set covers properties
                $con['coverurl'] = (string) $amaz->LargeImage->URL;

                if ($con['coverurl'] !== '') {
                    $con['cover'] = 1;
                } else {
                    $con['cover'] = 0;
                }

                $consoleId = $this->_updateConsoleTable($con);

                if ($this->echooutput && $consoleId !== -2) {
                    ColorCLI::doEcho(
                        ColorCLI::header('Added/updated game: ').
                        ColorCLI::alternateOver('   Title:    ').
                        ColorCLI::primary($con['title']).
                        ColorCLI::alternateOver('   Platform: ').
                        ColorCLI::primary($con['platform']).
                        ColorCLI::alternateOver('   Genre: ').
                        ColorCLI::primary($con['consolegenre']),
                        true
                    );
                }
            }
        }

        return $consoleId;
    }

    /**
     * @param array $gameInfo
     * @param array $con
     * @return bool
     */
    protected function _matchConToGameInfo(array $gameInfo = [], array $con = []): bool
    {
        $matched = false;

        // This actual compares the two strings and outputs a percentage value.
        $titlepercent = $platformpercent = '';

        //Remove import tags from console title for match
        $con['title'] = trim(preg_replace('/(\[|\().{2,} import(\]|\))$/i', '', $con['title']));

        similar_text(strtolower($gameInfo['title']), strtolower($con['title']), $titlepercent);
        similar_text(strtolower($gameInfo['platform']), strtolower($con['platform']), $platformpercent);

        // Since Wii Ware games and XBLA have inconsistent original platforms, as long as title is 50% its ok.
        if (preg_match('/wiiware|xbla/i', trim($gameInfo['platform'])) && $titlepercent >= 50) {
            $titlepercent = 100;
            $platformpercent = 100;
        }

        // If the release is DLC matching will be difficult, so assume anything over 50% is legit.
        if ($titlepercent >= 50 && isset($gameInfo['dlc']) && (int) $gameInfo['dlc'] === 1) {
            $titlepercent = 100;
            $platformpercent = 100;
        }

        if ($titlepercent < 70) {
            $gameInfo['title'] .= ' - '.$gameInfo['platform'];
            similar_text(strtolower($gameInfo['title']), strtolower($con['title']), $titlepercent);
        }

        // Platform must equal 100%.

        if ((int) $platformpercent === 100 && (int) $titlepercent >= 70) {
            $matched = true;
        }

        return $matched;
    }

    /**
     * @param $amaz
     * @param $gameInfo
     * @return array
     */
    protected function _setConBeforeMatch($amaz, $gameInfo): array
    {
        $con = [];
        $con['platform'] = (string) $amaz->ItemAttributes->Platform;
        if (empty($con['platform'])) {
            $con['platform'] = $gameInfo['platform'];
        }

        if (stripos('Super', $con['platform']) !== false) {
            $con['platform'] = 'SNES';
        }

        $con['title'] = (string) $amaz->ItemAttributes->Title;
        if (empty($con['title'])) {
            $con['title'] = $gameInfo['title'];
        }

        // Remove Download strings
        $dlStrings = [' [Online Game Code]', ' [Download]', ' [Digital Code]', ' [Digital Download]'];
        $con['title'] = str_ireplace($dlStrings, '', $con['title']);

        return $con;
    }

    /**
     * @param $amaz
     * @return array
     */
    protected function _setConAfterMatch($amaz): array
    {
        $con = [];
        $con['asin'] = (string) $amaz->ASIN;

        $con['url'] = (string) $amaz->DetailPageURL;
        $con['url'] = str_replace('%26tag%3Dws', '%26tag%3Dopensourceins%2D21', $con['url']);

        $con['salesrank'] = (string) $amaz->SalesRank;
        if ($con['salesrank'] === '') {
            $con['salesrank'] = 'null';
        }

        $con['publisher'] = (string) $amaz->ItemAttributes->Publisher;
        $con['esrb'] = (string) $amaz->ItemAttributes->ESRBAgeRating;
        $con['releasedate'] = (string) $amaz->ItemAttributes->ReleaseDate;

        if (! isset($con['releasedate'])) {
            $con['releasedate'] = '';
        }

        if ($con['releasedate'] === "''") {
            $con['releasedate'] = '';
        }

        $con['review'] = '';
        if (isset($amaz->EditorialReviews)) {
            $con['review'] = trim(strip_tags((string) $amaz->EditorialReviews->EditorialReview->Content));
        }

        return $con;
    }

    /**
     * @param $amaz
     *
     * @return array
     * @throws \Exception
     */
    protected function _matchGenre($amaz): array
    {
        $genreName = '';

        if (isset($amaz->BrowseNodes)) {
            //had issues getting this out of the browsenodes obj
            //workaround is to get the xml and load that into its own obj
            $amazGenresXml = $amaz->BrowseNodes->asXml();
            $amazGenresObj = simplexml_load_string($amazGenresXml);
            $amazGenres = $amazGenresObj->xpath('//Name');

            foreach ($amazGenres as $amazGenre) {
                $currName = trim($amazGenre[0]);
                if (empty($genreName)) {
                    $genreMatch = $this->matchBrowseNode($currName);
                    if ($genreMatch !== false) {
                        $genreName = $genreMatch;
                        break;
                    }
                }
            }
        }

        if ($genreName === '' && isset($amaz->ItemAttributes->Genre)) {
            $a = (string) $amaz->ItemAttributes->Genre;
            $b = str_replace('-', ' ', $a);
            $tmpGenre = explode(' ', $b);

            foreach ($tmpGenre as $tg) {
                $genreMatch = $this->matchBrowseNode(ucwords($tg));
                if ($genreMatch !== false) {
                    $genreName = $genreMatch;
                    break;
                }
            }
        }

        if (empty($genreName)) {
            $genreName = 'Unknown';
        }

        $genreKey = $this->_getGenreKey($genreName);

        return ['consolegenre' => $genreName, 'consolegenreid' => $genreKey];
    }

    /**
     * @param $genreName
     *
     * @return false|int|string
     * @throws \Exception
     */
    protected function _getGenreKey($genreName)
    {
        $genreassoc = $this->_loadGenres();

        if (\in_array(strtolower($genreName), $genreassoc, false)) {
            $genreKey = array_search(strtolower($genreName), $genreassoc, false);
        } else {
            $genreKey = Genre::query()->insertGetId(['title' => $genreName, 'type' => Genres::CONSOLE_TYPE]);
        }

        return $genreKey;
    }

    /**
     * @return array
     * @throws \Exception
     */
    protected function _loadGenres(): array
    {
        $gen = new Genres(['Settings' => $this->pdo]);

        $defaultGenres = $gen->getGenres(Genres::CONSOLE_TYPE);
        $genreassoc = [];
        foreach ($defaultGenres as $dg) {
            $genreassoc[$dg['id']] = strtolower($dg['title']);
        }

        return $genreassoc;
    }

    /** This function sets the platform retrieved
     *  from the release to the Amazon equivalent.
     *
     * @param string $platform
     *
     *
     * @return string
     */
    protected function _replacePlatform($platform): string
    {
        switch (strtoupper($platform)) {

            case 'X360':
            case 'XBOX360':
                $platform = 'Xbox 360';
                break;
            case 'XBOXONE':
            case 'XBOX ONE':
                $platform = 'Xbox One';
                break;
            case 'DSi':
            case 'NDS':
                $platform = 'Nintendo DS';
                break;
            case '3DS':
                $platform = 'Nintendo 3DS';
                break;
            case 'PS2':
                $platform = 'PlayStation2';
                break;
            case 'PS3':
                $platform = 'PlayStation 3';
                break;
            case 'PS4':
                $platform = 'PlayStation 4';
                break;
            case 'PSP':
                $platform = 'Sony PSP';
                break;
            case 'PSVITA':
                $platform = 'PlayStation Vita';
                break;
            case 'PSX':
            case 'PSX2PSP':
                $platform = 'PlayStation';
                break;
            case 'WIIU':
                $platform = 'Nintendo Wii U';
                break;
            case 'WII':
                $platform = 'Nintendo Wii';
                break;
            case 'NGC':
                $platform = 'GameCube';
                break;
            case 'N64':
                $platform = 'Nintendo 64';
                break;
            case 'NES':
                $platform = 'Nintendo NES';
                break;
            case 'SUPER NINTENDO':
            case 'NINTENDO SUPER NES':
            case 'SNES':
                $platform = 'SNES';
                break;
        }

        return $platform;
    }

    /**
     * @param array $con
     * @return int|mixed
     */
    protected function _updateConsoleTable(array $con = [])
    {
        $ri = new ReleaseImage();

        $check = ConsoleInfo::query()->where('asin', $con['asin'])->first();

        if ($check === null) {
            $consoleId = ConsoleInfo::query()
                ->insertGetId(
                    [
                        'title' => $con['title'],
                        'asin' => $con['asin'],
                        'url' => $con['url'],
                        'salesrank' => $con['salesrank'],
                        'platform' => $con['platform'],
                        'publisher' => $con['publisher'],
                        'genres_id' => (int) $con['consolegenreid'] === -1 ? 'null' : $con['consolegenreid'],
                        'esrb' => $con['esrb'],
                        'releasedate' => $con['releasedate'] !== '' ? $con['releasedate'] : 'null',
                        'review' => substr($con['review'], 0, 3000),
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]
                );
            if ($con['cover'] === 1) {
                $con['cover'] = $ri->saveImage($consoleId, $con['coverurl'], $this->imgSavePath, 250, 250);
            }
        } else {
            $consoleId = $check['id'];

            if ($con['cover'] === 1) {
                $con['cover'] = $ri->saveImage($consoleId, $con['coverurl'], $this->imgSavePath, 250, 250);
            }

            $this->update(
                $consoleId,
                $con['title'],
                $con['asin'],
                $con['url'],
                $con['salesrank'],
                $con['platform'],
                $con['publisher'],
                $con['releasedate'] ?? null,
                $con['esrb'],
                $con['cover'],
                $con['consolegenreid'],
                $con['review'] ?? null
            );
        }

        return $consoleId;
    }

    /**
     * @param $title
     * @param $node
     * @return bool|mixed
     * @throws \Exception
     */
    public function fetchAmazonProperties($title, $node)
    {
        $conf = new GenericConfiguration();
        $client = new Client();
        $request = new GuzzleRequest($client);

        try {
            $conf
                ->setCountry(Country::INTERNATIONAL)
                ->setAccessKey($this->pubkey)
                ->setSecretKey($this->privkey)
                ->setAssociateTag($this->asstag)
                ->setRequest($request)
                ->setResponseTransformer(new XmlToSimpleXmlObject());
        } catch (\Exception $e) {
            echo $e->getMessage();
        }

        $search = new Search();
        $search->setCategory('VideoGames');
        $search->setKeywords($title);
        $search->setBrowseNode($node);
        $search->setResponseGroup(['Large']);

        $apaiIo = new ApaiIO($conf);

        ColorCLI::doEcho(ColorCLI::info('Trying to find info on Amazon'), true);
        $responses = $apaiIo->runOperation($search);

        if ($responses === false) {
            throw new \RuntimeException('Could not connect to Amazon');
        }

        foreach ($responses->Items->Item as $response) {
            similar_text($title, $response->ItemAttributes->Title, $percent);
            if ($percent > self::MATCH_PERCENT && isset($response->ItemAttributes->Title)) {
                ColorCLI::doEcho(ColorCLI::info('Found matching info on Amazon: '.$response->ItemAttributes->Title), true);

                return $response;
            }
        }

        ColorCLI::doEcho(ColorCLI::info('Could not find match on Amazon'), true);

        return false;
    }

    /**
     * @throws \Exception
     */
    public function processConsoleReleases(): void
    {
        $query = Release::query()->select(['searchname', 'id'])->whereBetween('categories_id', [Category::GAME_ROOT, Category::GAME_OTHER])->where('nzbstatus', '=', NZB::NZB_ADDED)->whereNull('consoleinfo_id');
        if ($this->renamed === true) {
            $query->where('isrenamed', '=', 1);
        }
        $res = $query->limit($this->gameqty)->orderBy('postdate')->get();

        $releaseCount = $res->count();
        if ($res instanceof \Traversable && $releaseCount > 0) {
            if ($this->echooutput) {
                ColorCLI::doEcho(ColorCLI::header('Processing '.$releaseCount.' console release(s).'), true);
            }

            foreach ($res as $arr) {
                $startTime = microtime(true);
                $usedAmazon = false;
                $gameId = self::CONS_NTFND;
                $gameInfo = $this->parseTitle($arr['searchname']);

                if ($gameInfo !== false) {
                    if ($this->echooutput) {
                        ColorCLI::doEcho(
                            ColorCLI::headerOver('Looking up: ').
                            ColorCLI::primary(
                                $gameInfo['title'].
                                ' ('.
                                $gameInfo['platform'].')'
                            ),
                            true
                        );
                    }

                    // Check for existing console entry.
                    $gameCheck = $this->getConsoleInfoByName($gameInfo['title'], $gameInfo['platform']);

                    if ($gameCheck === null && \in_array($gameInfo['title'].$gameInfo['platform'], $this->failCache, false)) {
                        // Lookup recently failed, no point trying again
                        if ($this->echooutput) {
                            ColorCLI::doEcho(ColorCLI::headerOver('Cached previous failure. Skipping.'), true);
                        }
                        $gameId = -2;
                    } elseif ($gameCheck === null) {
                        $gameId = $this->updateConsoleInfo($gameInfo);
                        $usedAmazon = true;
                        if ($gameId === null) {
                            $gameId = -2;
                            $this->failCache[] = $gameInfo['title'].$gameInfo['platform'];
                        }
                    } else {
                        if ($this->echooutput) {
                            ColorCLI::doEcho(
                                ColorCLI::headerOver('Found Local: ').
                                ColorCLI::primary("{$gameCheck['title']} - {$gameCheck['platform']}"),
                                true
                            );
                        }
                        $gameId = $gameCheck['id'];
                    }
                } elseif ($this->echooutput) {
                    echo '.';
                }

                // Update release.
                Release::query()->where('id', $arr['id'])->update(['consoleinfo_id'=> $gameId]);

                // Sleep to not flood amazon.
                $diff = floor((microtime(true) - $startTime) * 1000000);
                if ($this->sleeptime * 1000 - $diff > 0 && $usedAmazon === true) {
                    usleep($this->sleeptime * 1000 - $diff);
                }
            }
        } elseif ($this->echooutput) {
            ColorCLI::doEcho(ColorCLI::header('No console releases to process.'), true);
        }
    }

    /**
     * @param $releasename
     *
     * @return array|bool
     */
    public function parseTitle($releasename)
    {
        $releasename = preg_replace('/\sMulti\d?\s/i', '', $releasename);
        $result = [];

        // Get name of the game from name of release.
        if (preg_match('/^(.+((abgx360EFNet|EFNet\sFULL|FULL\sabgxEFNet|abgx\sFULL|abgxbox360EFNet)\s|illuminatenboard\sorg|Place2(hom|us)e.net|united-forums? co uk|\(\d+\)))?(?P<title>.*?)[\.\-_ ](v\.?\d\.\d|PAL|NTSC|EUR|USA|JP|ASIA|JAP|JPN|AUS|MULTI(\.?\d{1,2})?|PATCHED|FULLDVD|DVD5|DVD9|DVDRIP|PROPER|REPACK|RETAIL|DEMO|DISTRIBUTION|REGIONFREE|[\. ]RF[\. ]?|READ\.?NFO|NFOFIX|PSX(2PSP)?|PS[2-4]|PSP|PSVITA|WIIU|WII|X\-?BOX|XBLA|X360|3DS|NDS|N64|NGC)/i', $releasename, $matches)) {
            $title = $matches['title'];

            // Replace dots, underscores, or brackets with spaces.
            $result['title'] = str_replace(['.', '_', '%20', '[', ']'], ' ', $title);
            $result['title'] = str_replace([' RF ', '.RF.', '-RF-', '_RF_'], ' ', $result['title']);
            //Remove format tags from release title for match
            $result['title'] = trim(preg_replace('/PAL|MULTI(\d)?|NTSC-?J?|\(JAPAN\)/i', '', $result['title']));
            //Remove disc tags from release title for match
            $result['title'] = trim(preg_replace('/Dis[ck] \d.*$/i', '', $result['title']));

            // Needed to add code to handle DLC Properly.
            if (stripos('dlc', $result['title']) !== false) {
                $result['dlc'] = '1';
                if (stripos('Rock Band Network', $result['title']) !== false) {
                    $result['title'] = 'Rock Band';
                } elseif (strpos('-', $result['title']) !== false) {
                    $dlc = explode('-', $result['title']);
                    $result['title'] = $dlc[0];
                } elseif (preg_match('/(.*? .*?) /i', $result['title'], $dlc)) {
                    $result['title'] = $dlc[0];
                }
            }
        } else {
            $title = '';
        }

        // Get the platform of the release.
        if (preg_match('/[\.\-_ ](?P<platform>XBLA|WiiWARE|N64|SNES|NES|PS[2-4]|PS 3|PSX(2PSP)?|PSP|WIIU|WII|XBOX360|XBOXONE|X\-?BOX|X360|3DS|NDS|N?GC)/i', $releasename, $matches)) {
            $platform = $matches['platform'];

            if (preg_match('/^N?GC$/i', $platform)) {
                $platform = 'NGC';
            }

            if (stripos('PSX2PSP', $platform) === 0) {
                $platform = 'PSX';
            }

            if (! empty($title) && stripos('XBLA', $platform) === 0) {
                if (stripos('dlc', $title) !== false) {
                    $platform = 'XBOX360';
                }
            }

            $browseNode = $this->getBrowseNode($platform);
            $result['platform'] = $platform;
            $result['node'] = $browseNode;
        }
        $result['release'] = $releasename;
        array_map('trim', $result);

        /* Make sure we got a title and platform otherwise the resulting lookup will probably be shit.
           Other option is to pass the $release->categories_id here if we don't find a platform but that
           would require an extra lookup to determine the name. In either case we should have a title at the minimum. */

        return (isset($result['title']) && ! empty($result['title']) && isset($result['platform'])) ? $result : false;
    }

    /**
     * @param $platform
     *
     * @return string
     */
    public function getBrowseNode($platform): string
    {
        switch ($platform) {
            case 'PS2':
                $nodeId = '301712';
                break;
            case 'PS3':
                $nodeId = '14210751';
                break;
            case 'PS4':
                $nodeId = '6427814011';
                break;
            case 'PSP':
                $nodeId = '11075221';
                break;
            case 'PSVITA':
                $nodeId = '3010556011';
                break;
            case 'PSX':
                $nodeId = '294940';
                break;
            case 'WII':
            case 'Wii':
                $nodeId = '14218901';
                break;
            case 'WIIU':
            case 'WiiU':
                $nodeId = '3075112011';
                break;
            case 'XBOX360':
            case 'X360':
                $nodeId = '14220161';
                break;
            case 'XBOXONE':
                $nodeId = '6469269011';
                break;
            case 'XBOX':
            case 'X-BOX':
                $nodeId = '537504';
                break;
            case 'NDS':
                $nodeId = '11075831';
                break;
            case '3DS':
                $nodeId = '2622269011';
                break;
            case 'GC':
            case 'NGC':
                $nodeId = '541022';
                break;
            case 'N64':
                $nodeId = '229763';
                break;
            case 'SNES':
                $nodeId = '294945';
                break;
            case 'NES':
                $nodeId = '566458';
                break;
            default:
                $nodeId = '468642';
                break;
        }

        return $nodeId;
    }

    /**
     * @param $nodeName
     *
     * @return bool|string
     */
    public function matchBrowseNode($nodeName)
    {
        $str = '';

        //music nodes above mp3 download nodes
        switch ($nodeName) {
            case 'Action_shooter':
            case 'Action_Games':
            case 'Action_games':
                $str = 'Action';
                break;
            case 'Action/Adventure':
            case 'Action\Adventure':
            case 'Adventure_games':
                $str = 'Adventure';
                break;
            case 'Boxing_games':
            case 'Sports_games':
                $str = 'Sports';
                break;
            case 'Fantasy_action_games':
                $str = 'Fantasy';
                break;
            case 'Fighting_action_games':
                $str = 'Fighting';
                break;
            case 'Flying_simulation_games':
                $str = 'Flying';
                break;
            case 'Horror_action_games':
                $str = 'Horror';
                break;
            case 'Kids & Family':
                $str = 'Family';
                break;
            case 'Role_playing_games':
                $str = 'Role-Playing';
                break;
            case 'Shooter_action_games':
                $str = 'Shooter';
                break;
            case 'Singing_games':
                $str = 'Music';
                break;
            case 'Action':
            case 'Adventure':
            case 'Arcade':
            case 'Board Games':
            case 'Cards':
            case 'Casino':
            case 'Collections':
            case 'Family':
            case 'Fantasy':
            case 'Fighting':
            case 'Flying':
            case 'Horror':
            case 'Music':
            case 'Puzzle':
            case 'Racing':
            case 'Rhythm':
            case 'Role-Playing':
            case 'Simulation':
            case 'Shooter':
            case 'Shooting':
            case 'Sports':
            case 'Strategy':
            case 'Trivia':
                $str = $nodeName;
                break;
        }

        return ($str !== '') ? $str : false;
    }
}
