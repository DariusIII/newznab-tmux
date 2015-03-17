<?php
require_once(WWW_DIR."/lib/framework/db.php");
require_once(WWW_DIR."/lib/amazon.php");
require_once(WWW_DIR."/lib/category.php");
require_once(WWW_DIR."/lib/site.php");
require_once(WWW_DIR."/lib/util.php");
require_once(WWW_DIR."/lib/releaseimage.php");
require_once(NN_TMUX . 'lib' . DS . 'Enzebe.php');

/*
 * Class for processing book info.
 */
class Books
{
	/**
	 * @var DB
	 */
	public $pdo;

	/**
	 * @var bool
	 */
	public $echooutput;

	/**
	 * @var array|bool|string
	 */
	public $pubkey;

	/**
	 * @var array|bool|string
	 */
	public $privkey;

	/**
	 * @var array|bool|string
	 */
	public $asstag;

	/**
	 * @var array|bool|int|string
	 */
	public $bookqty;

	/**
	 * @var array|bool|int|string
	 */
	public $sleeptime;

	/**
	 * @var string
	 */
	public $imgSavePath;

	/**
	 * @var array|bool|int|string
	 */
	public $bookreqids;

	/**
	 * @var string
	 */
	public $renamed;

	/**
	 * @param array $options Class instances / Echo to cli.
	 */
	public function __construct(array $options = array())
	{
		$defaults = [
			'Echo'     => false,
			'Settings' => null,
		];
		$options += $defaults;

		$this->echooutput = ($options['Echo'] && NN_ECHOCLI);
		$this->pdo = ($options['Settings'] instanceof DB ? $options['Settings'] : new DB());
		$s = new Sites();
		$this->site = $s->get();

		$this->pubkey = $this->site->amazonpubkey;
		$this->privkey = $this->site->amazonprivkey;
		$this->asstag = $this->site->amazonassociatetag;
		$this->bookqty = ($this->site->maxbooksprocessed != '') ? $this->site->maxbooksprocessed : 300;
		$this->sleeptime = ($this->site->amazonsleep != '') ? $this->site->amazonsleep : 1000;
		$this->imgSavePath = NN_COVERS . 'book' . DS;
		$this->bookreqids = ($this->site->book_reqids == null || $this->site->book_reqids == "") ? 7010 : $this->site->book_reqids;
		$this->renamed = '';
		if ($this->site->lookupbooks == 2) {
			$this->renamed = 'AND isrenamed = 1';
		}
	}

	public function getBookInfo($id)
	{
		return $this->pdo->queryOneRow(sprintf('SELECT bookinfo.* FROM bookinfo WHERE bookinfo.ID = %d', $id));
	}

	public function getBookInfoByName($author, $title)
	{
		$pdo = $this->pdo;
		$like = 'ILIKE';
		if ($pdo->dbSystem() === 'mysql') {
			$like = 'LIKE';
		}

		//only used to get a count of words
		$searchwords = $searchsql = '';
		$ft = $pdo->queryDirect("SHOW INDEX FROM bookinfo WHERE key_name = 'ix_bookinfo_author_title_ft'");
		if ($ft->rowCount() !== 2) {
			$searchsql .= sprintf(" author LIKE %s AND title %s %s'", $pdo->escapeString('%' . $author . '%'), $like, $pdo->escapeString('%' . $title . '%'));
		} else {
			$title = preg_replace('/( - | -|\(.+\)|\(|\))/', ' ', $title);
			$title = preg_replace('/[^\w ]+/', '', $title);
			$title = trim(preg_replace('/\s\s+/i', ' ', $title));
			$title = trim($title);
			$words = explode(' ', $title);

			foreach ($words as $word) {
				$word = trim(rtrim(trim($word), '-'));
				if ($word !== '' && $word !== '-') {
					$word = '+' . $word;
					$searchwords .= sprintf('%s ', $word);
				}
			}
			$searchwords = trim($searchwords);
			$searchsql .= sprintf(" MATCH(author, title) AGAINST(%s IN BOOLEAN MODE)", $pdo->escapeString($searchwords));
		}
		return $pdo->queryOneRow(sprintf("SELECT * FROM bookinfo WHERE %s", $searchsql));
	}


	public function getRange($start, $num)
	{
		if ($start === false) {
			$limit = '';
		} else {
			$limit = ' LIMIT ' . $num . ' OFFSET ' . $start;
		}

		return $this->pdo->query('SELECT * FROM bookinfo ORDER BY createddate DESC' . $limit);
	}

	public function getCount()
	{
		$res = $this->pdo->queryOneRow('SELECT COUNT(ID) AS num FROM bookinfo');
		return $res['num'];
	}

	public function getBookCount($cat, $maxage = -1, $excludedcats = array())
	{

		$browseby = $this->getBrowseBy();

		$catsrch = '';
		if (count($cat) > 0 && $cat[0] != -1) {
			$catsrch = (new \Category(['Settings' => $this->pdo]))->getCategorySearch($cat);
		}


		if ($maxage > 0) {
			$maxage = sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxage);
		} else {
			$maxage = '';
		}

		$exccatlist = '';
		if (count($excludedcats) > 0) {
			$exccatlist = ' AND r.categoryID NOT IN (' . implode(',', $excludedcats) . ')';
		}

		$res = $this->pdo->queryOneRow(
			sprintf(
				"SELECT COUNT(DISTINCT r.bookinfoID) AS num FROM releases r "
				. "INNER JOIN bookinfo boo ON boo.ID = r.bookinfoID AND boo.title != '' and boo.cover = 1 "
				. "WHERE r.nzbstatus = 1 AND  r.passwordstatus <= (SELECT value FROM site WHERE setting='showpasswordedrelease') "
				. "AND %s %s %s %s", $browseby, $catsrch, $maxage, $exccatlist
			)
		);
		return $res['num'];
	}

	public function getBookRange($cat, $start, $num, $orderby, $excludedcats = array())
	{

		$browseby = $this->getBrowseBy();

		if ($start === false) {
			$limit = '';
		} else {
			$limit = ' LIMIT ' . $num . ' OFFSET ' . $start;
		}

		$catsrch = '';
		if (count($cat) > 0 && $cat[0] != -1) {
			$catsrch = (new \Category(['Settings' => $this->pdo]))->getCategorySearch($cat);
		}

		$maxage = '';
		if ($maxage > 0) {
			$maxage = sprintf(' AND r.postdate > NOW() - INTERVAL %d DAY ', $maxage);
		}

		$exccatlist = '';
		if (count($excludedcats) > 0) {
			$exccatlist = ' AND r.categoryID NOT IN (' . implode(',', $excludedcats) . ')';
		}

		$order = $this->getBookOrder($orderby);
		$sql = sprintf(
			"SELECT GROUP_CONCAT(r.ID ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_id, "
			. "GROUP_CONCAT(r.rarinnerfilecount ORDER BY r.postdate DESC SEPARATOR ',') as grp_rarinnerfilecount, "
			. "GROUP_CONCAT(r.haspreview ORDER BY r.postdate DESC SEPARATOR ',') AS grp_haspreview, "
			. "GROUP_CONCAT(r.passwordstatus ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_password, "
			. "GROUP_CONCAT(r.guid ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_guid, "
			. "GROUP_CONCAT(rn.ID ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_nfoid, "
			. "GROUP_CONCAT(groups.name ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grpname, "
			. "GROUP_CONCAT(r.searchname ORDER BY r.postdate DESC SEPARATOR '#') AS grp_release_name, "
			. "GROUP_CONCAT(r.postdate ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_postdate, "
			. "GROUP_CONCAT(r.size ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_size, "
			. "GROUP_CONCAT(r.totalpart ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_totalparts, "
			. "GROUP_CONCAT(r.comments ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_comments, "
			. "GROUP_CONCAT(r.grabs ORDER BY r.postdate DESC SEPARATOR ',') AS grp_release_grabs, "
			. "boo.*, r.bookinfoID, groups.name AS group_name, rn.ID as nfoid FROM releases r "
			. "LEFT OUTER JOIN groups ON groups.ID = r.groupID "
			. "LEFT OUTER JOIN releasenfo rn ON rn.releaseid = r.ID "
			. "INNER JOIN bookinfo boo ON boo.ID = r.bookinfoID "
			. "WHERE r.nzbstatus = 1 AND boo.cover = 1 AND boo.title != '' AND "
			. "r.passwordstatus <= (SELECT value FROM site WHERE setting='showpasswordedrelease') AND %s %s %s %s "
			. "GROUP BY boo.ID ORDER BY %s %s" . $limit, $browseby, $catsrch, $maxage, $exccatlist, $order[0], $order[1]
		);

		return $this->pdo->queryDirect($sql);
	}

	public function getBookOrder($orderby)
	{
		$order = ($orderby == '') ? 'r.postdate' : $orderby;
		$orderArr = explode('_', $order);
		switch ($orderArr[0]) {
			case 'title':
				$orderfield = 'boo.title';
				break;
			case 'author':
				$orderfield = 'boo.author';
				break;
			case 'publishdate':
				$orderfield = 'boo.publishdate';
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
		return array($orderfield, $ordersort);
	}

	public function getBookOrdering()
	{
		return array(
			'title_asc',
			'title_desc',
			'posted_asc',
			'posted_desc',
			'size_asc',
			'size_desc',
			'files_asc',
			'files_desc',
			'stats_asc',
			'stats_desc',
			'releasedate_asc',
			'releasedate_desc',
			'author_asc',
			'author_desc'
		);
	}

	public function getBrowseByOptions()
	{
		return array('author' => 'author', 'title' => 'title');
	}

	public function getBrowseBy()
	{
		$like = 'LIKE';

		$browseby = ' ';
		$browsebyArr = $this->getBrowseByOptions();
		foreach ($browsebyArr as $bbk => $bbv) {
			if (isset($_REQUEST[$bbk]) && !empty($_REQUEST[$bbk])) {
				$bbs = stripslashes($_REQUEST[$bbk]);
				$browseby .= 'boo.' . $bbv . ' ' . $this->pdo->likeString($bbs, true, true) . ' AND ';
			}
		}
		return $browseby;
	}

	public function fetchAmazonProperties($title)
	{
		$obj = new \AmazonProductAPI($this->pubkey, $this->privkey, $this->asstag);
		try {
			$result = $obj->searchProducts($title, \AmazonProductAPI::BOOKS, 'TITLE');
		} catch (Exception $e) {
			$result = false;
		}
		return $result;
	}

	/**
	 * Process book releases, 1 category at a time.
	 */
	public function processBookReleases()
	{
		$bookids = array();
		if (preg_match('/^\d+$/', $this->bookreqids)) {
			$bookids[] = $this->bookreqids;
		} else {
			$bookids = explode(', ', $this->bookreqids);
		}

		$total = count($bookids);
		if ($total > 0) {
			for ($i = 0; $i < $total; $i++) {
				$this->processBookReleasesHelper(
					$this->pdo->queryDirect(
						sprintf('
						SELECT searchname, ID, categoryID
						FROM releases
						WHERE nzbstatus = 1 %s
						AND bookinfoID IS NULL
						AND categoryID in (%s)
						ORDER BY postdate
						DESC LIMIT %d', $this->renamed, $bookids[$i], $this->bookqty)
					), $bookids[$i]
				);
			}
		}
	}

	/**
	 * Process book releases.
	 *
	 * @param \PDOStatement|bool $res      Array containing unprocessed book SQL data set.
	 * @param int                $categoryID The category id.
	 * @void
	 */
	protected function processBookReleasesHelper($res, $categoryID)
	{
		if ($res instanceof \Traversable && $res->rowCount() > 0) {
			if ($this->echooutput) {
				$this->pdo->log->doEcho($this->pdo->log->header("\nProcessing " . $res->rowCount() . ' book release(s) for category ID ' . $categoryID));
			}

			foreach ($res as $arr) {
				$startTime = microtime(true);
				$usedAmazon = false;
				// audiobooks are also books and should be handled in an identical manor, even though it falls under a music category
				if ($arr['categoryID'] == '3030') {
					// audiobook
					$bookInfo = $this->parseTitle($arr['searchname'], $arr['ID'], 'audiobook');
				} else {
					// ebook
					$bookInfo = $this->parseTitle($arr['searchname'], $arr['ID'], 'ebook');
				}

				if ($bookInfo !== false) {
					if ($this->echooutput) {
						$this->pdo->log->doEcho($this->pdo->log->headerOver('Looking up: ') . $this->pdo->log->primary($bookInfo));
					}

					// Do a local lookup first
					$bookCheck = $this->getBookInfoByName('', $bookInfo);

					if ($bookCheck === false) {
						$bookId = $this->updateBookInfo($bookInfo);
						$usedAmazon = true;
						if ($bookId === false) {
							$bookId = -2;
						}
					} else {
						$bookId = $bookCheck['ID'];
					}

					// Update release.
					$this->pdo->queryExec(sprintf('UPDATE releases SET bookinfoID = %d WHERE ID = %d', $bookId, $arr['ID']));
				} else { // Could not parse release title.
					$this->pdo->queryExec(sprintf('UPDATE releases SET bookinfoID = %d WHERE ID = %d', -2, $arr['ID']));
					if ($this->echooutput) {
						echo '.';
					}
				}
				// Sleep to not flood amazon.
				$diff = floor((microtime(true) - $startTime) * 1000000);
				if ($this->sleeptime * 1000 - $diff > 0 && $usedAmazon === true) {
					usleep($this->sleeptime * 1000 - $diff);
				}
			}
		} else if ($this->echooutput) {
			$this->pdo->log->doEcho($this->pdo->log->header('No book releases to process for category id ' . $categoryID));
		}
	}

	public function parseTitle($release_name, $releaseID, $releasetype)
	{
		$a = preg_replace('/\d{1,2} \d{1,2} \d{2,4}|(19|20)\d\d|anybody got .+?[a-z]\? |[-._ ](Novel|TIA)([-._ ]|$)|( |\.)HQ(-|\.| )|[\(\)\.\-_ ](AVI|AZW3?|DOC|EPUB|LIT|MOBI|NFO|RETAIL|(si)?PDF|RTF|TXT)[\)\]\.\-_ ](?![a-z0-9])|compleet|DAGSTiDNiNGEN|DiRFiX|\+ extra|r?e ?Books?([\.\-_ ]English|ers)?|azw3?|ePu(b|p)s?|html|mobi|^NEW[\.\-_ ]|PDF([\.\-_ ]English)?|Please post more|Post description|Proper|Repack(fix)?|[\.\-_ ](Chinese|English|French|German|Italian|Retail|Scan|Swedish)|^R4 |Repost|Skytwohigh|TIA!+|TruePDF|V413HAV|(would someone )?please (re)?post.+? "|with the authors name right/i', '', $release_name);
		$b = preg_replace('/^(As Req |conversion |eq |Das neue Abenteuer \d+|Fixed version( ignore previous post)?|Full |Per Req As Found|(\s+)?R4 |REQ |revised |version |\d+(\s+)?$)|(COMPLETE|INTERNAL|RELOADED| (AZW3|eB|docx|ENG?|exe|FR|Fix|gnv64|MU|NIV|R\d\s+\d{1,2} \d{1,2}|R\d|Req|TTL|UC|v(\s+)?\d))(\s+)?$/i', '', $a);

		//remove book series from title as this gets more matches on amazon
		$c = preg_replace('/ - \[.+\]|\[.+\]/', '', $b);

		//remove any brackets left behind
		$d = preg_replace('/(\(\)|\[\])/', '', $c);
		$releasename = trim(preg_replace('/\s\s+/i', ' ', $d));

		// the default existing type was ebook, this handles that in the same manor as before
		if ($releasetype == 'ebook') {
			if (preg_match('/^([a-z0-9] )+$|ArtofUsenet|ekiosk|(ebook|mobi).+collection|erotica|Full Video|ImwithJamie|linkoff org|Mega.+pack|^[a-z0-9]+ (?!((January|February|March|April|May|June|July|August|September|O(c|k)tober|November|De(c|z)ember)))[a-z]+( (ebooks?|The))?$|NY Times|(Book|Massive) Dump|Sexual/i', $releasename)) {

				if ($this->echooutput) {
					$this->pdo->log->doEcho(
						$this->pdo->log->headerOver('Changing category to misc books: ') . $this->pdo->log->primary($releasename)
					);
				}
				$this->pdo->queryExec(sprintf('UPDATE releases SET categoryID = %d WHERE ID = %d', 7050, $releaseID));
				return false;
			} else if (preg_match('/^([a-z0-9ü!]+ ){1,2}(N|Vol)?\d{1,4}(a|b|c)?$|^([a-z0-9]+ ){1,2}(Jan( |unar|$)|Feb( |ruary|$)|Mar( |ch|$)|Apr( |il|$)|May(?![a-z0-9])|Jun( |e|$)|Jul( |y|$)|Aug( |ust|$)|Sep( |tember|$)|O(c|k)t( |ober|$)|Nov( |ember|$)|De(c|z)( |ember|$))/i', $releasename) && !preg_match('/Part \d+/i', $releasename)) {

				if ($this->echooutput) {
					$this->pdo->log->doEcho(
						$this->pdo->log->headerOver('Changing category to magazines: ') . $this->pdo->log->primary($releasename)
					);
				}
				$this->pdo->queryExec(sprintf('UPDATE releases SET categoryID = %d WHERE ID = %d', 7030, $releaseID));
				return false;
			} else if (!empty($releasename) && !preg_match('/^[a-z0-9]+$|^([0-9]+ ){1,}$|Part \d+/i', $releasename)) {
				return $releasename;
			} else {
				return false;
			}
		} else if ($releasetype == 'audiobook') {
			if (!empty($releasename) && !preg_match('/^[a-z0-9]+$|^([0-9]+ ){1,}$|Part \d+/i', $releasename)) {
				// we can skip category for audiobooks, since we already know it, so as long as the release name is valid return it so that it is postprocessed by amazon.  In the future, determining the type of audiobook could be added (Lecture or book), since we can skip lookups on lectures, but for now handle them all the same way
				return $releasename;
			} else {
				return false;
			}
		}
	}

	public function updateBookInfo($bookInfo = '', $amazdata = null)
	{
		$ri = new \ReleaseImage($this->pdo);

		$book = array();

		$amaz = false;
		if ($bookInfo != '') {
			$amaz = $this->fetchAmazonProperties($bookInfo);
		} else if ($amazdata != null) {
			$amaz = $amazdata;
		}

		if (!$amaz) {
			return false;
		}

		$book['title'] = (string)$amaz->Items->Item->ItemAttributes->Title;
		$book['author'] = (string)$amaz->Items->Item->ItemAttributes->Author;
		$book['asin'] = (string)$amaz->Items->Item->ASIN;
		$book['isbn'] = (string)$amaz->Items->Item->ItemAttributes->ISBN;
		if ($book['isbn'] == '') {
			$book['isbn'] = 'null';
		}

		$book['ean'] = (string)$amaz->Items->Item->ItemAttributes->EAN;
		if ($book['ean'] == '') {
			$book['ean'] = 'null';
		}

		$book['url'] = (string)$amaz->Items->Item->DetailPageURL;
		$book['url'] = str_replace("%26tag%3Dws", "%26tag%3Dopensourceins%2D21", $book['url']);

		$book['salesrank'] = (string)$amaz->Items->Item->SalesRank;
		if ($book['salesrank'] == '') {
			$book['salesrank'] = 'null';
		}

		$book['publisher'] = (string)$amaz->Items->Item->ItemAttributes->Publisher;
		if ($book['publisher'] == '') {
			$book['publisher'] = 'null';
		}

		$book['publishdate'] = date('Y-m-d', strtotime((string)$amaz->Items->Item->ItemAttributes->PublicationDate));
		if ($book['publishdate'] == '') {
			$book['publishdate'] = 'null';
		}

		$book['pages'] = (string)$amaz->Items->Item->ItemAttributes->NumberOfPages;
		if ($book['pages'] == '') {
			$book['pages'] = 'null';
		}

		if (isset($amaz->Items->Item->EditorialReviews->EditorialReview->Content)) {
			$book['overview'] = strip_tags((string)$amaz->Items->Item->EditorialReviews->EditorialReview->Content);
			if ($book['overview'] == '') {
				$book['overview'] = 'null';
			}
		} else {
			$book['overview'] = 'null';
		}

		if (isset($amaz->Items->Item->BrowseNodes->BrowseNode->Name)) {
			$book['genre'] = (string)$amaz->Items->Item->BrowseNodes->BrowseNode->Name;
			if ($book['genre'] == '') {
				$book['genre'] = 'null';
			}
		} else {
			$book['genre'] = 'null';
		}

		$book['coverurl'] = (string)$amaz->Items->Item->LargeImage->URL;
		if ($book['coverurl'] != '') {
			$book['cover'] = 1;
		} else {
			$book['cover'] = 0;
		}

		$check = $this->pdo->queryOneRow(sprintf('SELECT ID FROM bookinfo WHERE asin = %s', $this->pdo->escapeString($book['asin'])));
		if ($check === false) {
			$bookId = $this->pdo->queryInsert(
				sprintf("
								INSERT INTO bookinfo
									(title, author, asin, isbn, ean, url, salesrank, publisher, publishdate, pages,
									overview, genre, cover, createddate, updateddate)
								VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, now(), now())",
					$this->pdo->escapeString($book['title']), $this->pdo->escapeString($book['author']),
					$this->pdo->escapeString($book['asin']), $this->pdo->escapeString($book['isbn']),
					$this->pdo->escapeString($book['ean']), $this->pdo->escapeString($book['url']),
					$book['salesrank'], $this->pdo->escapeString($book['publisher']),
					$this->pdo->escapeString($book['publishdate']), $book['pages'],
					$this->pdo->escapeString($book['overview']), $this->pdo->escapeString($book['genre']),
					$book['cover']
				)
			);
		} else {
			$bookId = $check['ID'];
			$this->pdo->queryExec(
				sprintf('
							UPDATE bookinfo
							SET title = %s, author = %s, asin = %s, isbn = %s, ean = %s, url = %s, salesrank = %s, publisher = %s,
								publishdate = %s, pages = %s, overview = %s, genre = %s, cover = %d, updateddate = NOW()
							WHERE ID = %d',
					$this->pdo->escapeString($book['title']), $this->pdo->escapeString($book['author']),
					$this->pdo->escapeString($book['asin']), $this->pdo->escapeString($book['isbn']),
					$this->pdo->escapeString($book['ean']), $this->pdo->escapeString($book['url']),
					$book['salesrank'], $this->pdo->escapeString($book['publisher']),
					$this->pdo->escapeString($book['publishdate']), $book['pages'],
					$this->pdo->escapeString($book['overview']), $this->pdo->escapeString($book['genre']),
					$book['cover'], $bookId
				)
			);
		}

		if ($bookId) {
			if ($this->echooutput) {
				$this->pdo->log->doEcho($this->pdo->log->header("Added/updated book: "));
				if ($book['author'] !== '') {
					$this->pdo->log->doEcho($this->pdo->log->alternateOver("   Author: ") . $this->pdo->log->primary($book['author']));
				}
				echo $this->pdo->log->alternateOver("   Title: ") . $this->pdo->log->primary(" " . $book['title']);
				if ($book['genre'] !== 'null') {
					$this->pdo->log->doEcho($this->pdo->log->alternateOver("   Genre: ") . $this->pdo->log->primary(" " . $book['genre']));
				}
			}

			$book['cover'] = $ri->saveImage($bookId, $book['coverurl'], $this->imgSavePath, 250, 250);
		} else {
			if ($this->echooutput) {
				$this->pdo->log->doEcho(
					$this->pdo->log->header('Nothing to update: ') .
					$this->pdo->log->header($book['author'] .
						' - ' .
						$book['title'])
				);
			}
		}
		return $bookId;
	}
}