<?php
require_once(WWW_DIR . "/lib/framework/db.php");
require_once(WWW_DIR . "/lib/binaries.php");
require_once(WWW_DIR . "/lib/groups.php");
require_once(WWW_DIR . "/lib/nntp.php");
require_once(WWW_DIR . "/lib/site.php");


/**
 * This class handles storage and retrieval of releaseregex rows.
 */
class ReleaseRegex
{

	public function __construct()
	{
		$this->regexes = [];
	}

	/**
	 * Get all releaseregex rows matching filters.
	 */
	public function get($activeonly = true, $groupname = "-1", $blnIncludeReleaseCount = false, $userReleaseRegex = null)
	{
		if (!empty($this->regexes))
			return $this->regexes;

		$db = new DB();

		$where = "";
		if ($activeonly)
			$where .= " and releaseregex.status = 1";

		if ($groupname == "all")
			$where .= " and releaseregex.groupname is null";
		elseif ($groupname != "-1")
			$where .= sprintf(" and releaseregex.groupname = %s", $db->escapeString($groupname));

		if ($userReleaseRegex === true) {
			$where .= ' AND releaseregex.id >= 100000';
		} else if ($userReleaseRegex === false) {
			$where .= ' AND releaseregex.id < 100000';
		}

		$relcountjoin = "";
		$relcountcol = "";
		if ($blnIncludeReleaseCount) {
			$relcountcol = " , coalesce(x.count, 0) as num_releases, coalesce(x.adddate, 'n/a') as max_releasedate ";
			$relcountjoin = " left outer join (  select regexid, max(adddate) adddate, count(id) as count from releases group by regexid) x on x.regexid = releaseregex.id ";
		}

		$this->regexes = $db->query("SELECT releaseregex.id, releaseregex.categoryid, category.title as categoryTitle, releaseregex.status, releaseregex.description, releaseregex.groupname AS groupname, releaseregex.regex,
												groups.id AS groupid, releaseregex.ordinal " . $relcountcol . "
												FROM releaseregex
												left outer JOIN groups ON groups.name = releaseregex.groupname
												left outer join category on category.id = releaseregex.categoryid
												" . $relcountjoin . "
												where 1=1 " . $where . "
												ORDER BY groupname LIKE '%*' ASC, coalesce(groupname,'zzz') DESC, ordinal ASC"
		);

		return $this->regexes;
	}

	/**
	 * Get all groups used by releaseregexes for use in dropdownlist.
	 */
	public function getGroupsForSelect()
	{

		$db = new DB();
		$categories = $db->query("SELECT distinct coalesce(groupname,'all') as groupname from releaseregex order by groupname ");
		$temp_array = array();

		$temp_array[-1] = "--Please Select--";

		foreach ($categories as $category)
			$temp_array[$category["groupname"]] = $category["groupname"];

		return $temp_array;
	}

	/**
	 * Get a releaseregex row by id.
	 */
	public function getByID($id)
	{
		$db = new DB();

		return $db->queryOneRow(sprintf("select * from releaseregex where id = %d ", $id));
	}

	/**
	 * Get a releaseregex row by id.
	 */
	public function getForGroup($groupname)
	{
		$ret = array();
		$groupRegexes = $this->get(true);
		if ($groupRegexes) {
			foreach ($groupRegexes as $groupRegex) {
				$outcome = @preg_match("/^" . $groupRegex["groupname"] . "$/i", $groupname);
				if ($outcome)
					$ret[] = $groupRegex;
				elseif ($outcome === false)
					echo "ERROR: " . ($groupRegex["id"] < 10000 ? "System" : "Custom") . " release regex '" . $groupRegex["id"] . "'. Group name '" . $groupRegex["groupname"] . "' should be a valid regex.\n";
			}
		}

		return $ret;
	}

	/**
	 * Delete a releaseregex row.
	 */
	public function delete($id)
	{
		$db = new DB();

		return $db->queryExec(sprintf("DELETE from releaseregex where id = %d", $id));
	}

	/**
	 * Update a releaseregex row.
	 */
	public function update($regex)
	{
		$db = new DB();

		$groupname = $regex["groupname"];
		if ($groupname == "")
			$groupname = "null";
		else
			$groupname = sprintf("%s", $db->escapeString($regex["groupname"]));

		$catid = $regex["category"];
		if ($catid == "-1")
			$catid = "null";
		else
			$catid = sprintf("%d", $regex["category"]);

		$db->queryExec(sprintf("update releaseregex set groupname=%s, regex=%s, ordinal=%d, status=%d, description=%s, categoryid=%s where id = %d ",
				$groupname, $db->escapeString($regex["regex"]), $regex["ordinal"], $regex["status"], $db->escapeString($regex["description"]), $catid, $regex["id"]
			)
		);
	}

	/**
	 * Add a releaseregex row.
	 */
	public function add($regex)
	{
		$db = new DB();

		$groupname = $regex["groupname"];
		if ($groupname == "")
			$groupname = "null";
		else
			$groupname = sprintf("%s", $db->escapeString($regex["groupname"]));

		$catid = $regex["category"];
		if ($catid == "-1")
			$catid = "null";
		else
			$catid = sprintf("%d", $regex["category"]);

		return $db->queryInsert(sprintf("insert into releaseregex (groupname, regex, ordinal, status, description, categoryid) values (%s, %s, %d, %d, %s, %s) ",
				$groupname, $db->escapeString($regex["regex"]), $regex["ordinal"], $regex["status"], $db->escapeString($regex["description"]), $catid
			)
		);

	}

	public function performMatch(&$regexArr, $binarySubject)
	{
		$ret = false;

		$outcome = @preg_match($regexArr["regex"], $binarySubject, $matches);
		if ($outcome === false) {
			echo "ERROR: " . ($regexArr["id"] < 10000 ? "System" : "Custom") . " release regex '" . $regexArr["id"] . "' is not a valid regex.\n";
			$db = new DB();
			$db->queryExec(sprintf("update releaseregex set status=0 where id = %d and status=1", $regexArr["id"]));

			return $ret;
		}

		if ($outcome) {
			$cat = new Categorize();
			$matches = array_map("trim", $matches);

			if (isset($matches['reqid']) && (!isset($matches['name']) || empty($matches['name']))) {
				$matches['name'] = $matches['reqid'];
			}

			// Check that the regex provided the correct parameters
			if (!isset($matches['name']) || empty($matches['name'])) {
				//echo "ERROR: Regex applied which didnt return right number of capture groups - '".$regexArr["id"]."'\n";
				return $ret;
			}

			if (!isset($matches['parts']) || empty($matches['parts'])) {
				$matches['parts'] = "00/00";
			}

			if (isset($matches['name']) && isset($matches['parts'])) {
				if (strpos($matches['parts'], '/') === false) {
					$matches['parts'] = str_replace(array('-', '~', ' of '), '/', $matches['parts']);
				}

				$regcatid = "null ";
				if ($regexArr["categoryid"] != "")
					$regcatid = $regexArr["categoryid"];
				//override
				if ($regcatid == Category::CAT_PC_0DAY) {
					if ($cat->isPhone($matches['name']))
						$regcatid = Category::CAT_PC_MOBILEANDROID;
					if ($cat->isPhone($matches['name']))
						$regcatid = Category::CAT_PC_MOBILEIOS;
					if ($cat->isPhone($matches['name']))
						$regcatid = Category::CAT_PC_MOBILEOTHER;
					if ($cat->isIso($matches['name']))
						$regcatid = Category::CAT_PC_ISO;
					if ($cat->isMac($matches['name']))
						$regcatid = Category::CAT_PC_MAC;
					if ($cat->isPcGame($matches['name']))
						$regcatid = Category::CAT_PC_GAMES;
					if ($cat->isEBook($matches['name']))
						$regcatid = Category::CAT_BOOK_EBOOK;
				}

				$reqID = "";
				if (isset($matches['reqid']))
					$reqID = $matches['reqid'];

				//check if post is repost
				if (preg_match('/(repost\d?|re\-?up)/i', $binarySubject, $repost) && !preg_match('/repost|re\-?up/i', $matches['name'])) {
					$matches['name'] .= ' ' . $repost[1];
				}

				$matches['regcatid'] = $regcatid;
				$matches['regexid'] = $regexArr['id'];
				$matches['reqid'] = $reqID;

				$ret = $matches;
			}
		}

		return $ret;
	}

	public function testRegex($regex, $groupname, $poster, $ignorematched, $matchagainstbins)
	{
		$db = new Db();
		$cat = new Categorize();
		$s = new Sites();
		$site = $s->get();
		$groups = new Groups();
		$groupID = $groups->getByNameByID($groupname);
		$group = $groups->getCBPTableNames($site->tablePerGroup, $groupID);

		$catList = $cat->getForSelect();
		$matches = array();

		if ($groupname === 0)
			$groupname = '.*';

		if ($matchagainstbins !== '')
			$sql = sprintf("select b.*, '0' as size, '0' as blacklistid, g.name as groupname from %s b left join groups g on g.id = b.groupid where b.groupid IN (select g.id from groups g where g.name REGEXP %s) order by b.date desc", $group['bname'], $db->escapeString('^' . $groupname . '$'));
		else
			$sql = sprintf("select rrt.* from releaseregextesting rrt where rrt.groupname REGEXP %s order by rrt.date desc", $db->escapeString('^' . $groupname . '$'));

		$resbin = $db->queryDirect($sql);

		while ($rowbin = $db->getAssocArray($resbin)) {
			if ($ignorematched !== '' && ($rowbin['regexid'] != '' || $rowbin['blacklistid'] == 1))
				continue;

			$regexarr = array("id" => "", 'regex' => $regex, 'poster' => $poster, "categoryid" => "");
			$regexCheck = $this->performMatch($regexarr, $rowbin['name'], $rowbin['fromname']);

			if ($regexCheck !== false) {
				$relname = $regexCheck['name'];
				$relparts = explode("/", $regexCheck['parts']);

				$matches[$relname]['name'] = $relname;
				$matches[$relname]['parts'] = $regexCheck['parts'];

				$matches[$relname]['bincount'] = (isset($matches[$relname]['bincount'])) ? $matches[$relname]['bincount'] + 1 : 1;
				$matches[$relname]['bininfo'][] = $rowbin;

				$matches[$relname]['binsize'][] = $rowbin['size'];
				$matches[$relname]['totalsize'] = array_sum($matches[$relname]['binsize']);

				$matches[$relname]['relparts'][$relparts[1]] = $relparts[1];
				$matches[$relname]['reltotalparts'] = array_sum($matches[$relname]['relparts']);

				$matches[$relname]['regexid'] = $regexCheck['regexid'];

				if (ctype_digit($regexCheck['regcatid']))
					$matches[$relname]['catname'] = $catList[$regexCheck['regcatid']];
				else
					$matches[$relname]['catname'] = $catList[$cat->determineCategory($groupname, $relname)];

			}

		}

		//echo '<pre>';
		//print_r(array_pop($matches));
		//echo '</pre>';
		return $matches;
	}


	public function fetchTestBinaries($groupname, $numarticles, $clearexistingbins)
	{
		$db = new DB();
		$nntp = new Nntp();
		$binaries = new Binaries();
		$groups = new Groups();

		$ret = array();
		if ($clearexistingbins == true)
			$db->queryExec('truncate releaseregextesting');

		$nntp->doConnect();

		$groupsToFetch = array();
		if (preg_match('/^[a-z]{2,3}(\.[a-z0-9\-]+)+$/', $groupname))
			$groupsToFetch[] = array('name' => $groupname);
		elseif ($groupname === 0)
			$groupsToFetch = $groups->getAll();
		else {
			$newsgroups = $nntp->getGroups();
			foreach ($newsgroups as $ngroup) {
				if (preg_match('/' . $groupname . '/', $ngroup['group']))
					$groupsToFetch[] = array('name' => $ngroup['group']);
			}
		}

		foreach ($groupsToFetch as $groupArr) {

			$group = $groupArr['name'];
			$data = $nntp->selectGroup($group);
			if (PEAR::isError($data)) {
				$ret[] = "Could not select group (doesnt exist on USP): {$group}";
				continue;
			} else {
				$rangeStart = $data['last'] - $numarticles;
				$rangeEnd = $groupEnd = $data['last'];
				$rangeTotal = $rangeEnd - $rangeStart;

				$done = false;

				while ($done === false) {
					if ($rangeTotal > $binaries->messageBuffer) {
						if ($rangeStart + $binaries->messageBuffer > $groupEnd)
							$rangeEnd = $groupEnd;
						else
							$rangeEnd = $rangeStart + $binaries->messageBuffer;
					}


						$msgs = $nntp->getXOver($rangeStart . "-" . $rangeEnd, true, false);

					if (PEAR::isError($msgs)) {
						$ret[] = "Error {$msgs->code}: {$msgs->message} on " . $group;
						continue 2;
					}

					$headers = array();
					if (is_array($msgs)) {
						//loop headers, figure out parts
						foreach ($msgs AS $msg) {
							if (!isset($msg['Number']))
								continue;

							$msgPart = $msgTotalParts = 0;

							$pattern = '|\((\d+)[\/](\d+)\)|i';
							preg_match_all($pattern, $msg['Subject'], $matches, PREG_PATTERN_ORDER);
							$matchcnt = sizeof($matches[0]);
							for ($i = 0; $i < $matchcnt; $i++) {
								//not (int)'d here because of the preg_replace later on
								$msgPart = $matches[1][$i];
								$msgTotalParts = $matches[2][$i];
							}

							if (!isset($msg['Subject']) || $matchcnt == 0) // not a binary post most likely.. continue
								continue;

							if ((int)$msgPart > 0 && (int)$msgTotalParts > 0) {
								$subject = utf8_encode(trim(preg_replace('|\(' . $msgPart . '[\/]' . $msgTotalParts . '\)|i', '', $msg['Subject'])));

								if (!isset($headers[$subject])) {
									$headers[$subject]['Subject'] = $subject;
									$headers[$subject]['From'] = $msg['From'];
									$headers[$subject]['Date'] = strtotime($msg['Date']);
									$headers[$subject]['Message-ID'] = $msg['Message-ID'];
									$headers[$subject]['Size'] = $msg['Bytes'];
								} else
									$headers[$subject]['Size'] += $msg['Bytes'];
							}
						}
						unset($msgs);

						if (isset($headers) && count($headers)) {
							$groupRegexes = $this->getForGroup($group);

							$binSetData = array();

							foreach ($headers as $subject => $data) {
								$binData = array(
									'name'         => $subject,
									'fromname'     => $data['From'],
									'date'         => $data['Date'],
									'binaryhash'   => md5($subject . $data['From'] . $group),
									'groupname'    => $group,
									'regexid'      => "null",
									'categoryid'   => "null",
									'reqid'        => "null",
									'blacklistid'  => 0,
									'size'         => $data['Size'],
									'relname'      => "null",
									'relpart'      => "null",
									'reltotalpart' => "null"
								);

								//Filter binaries based on black/white list
								if ($binaries->isBlackListed($data, $group)) {
									//binary is blacklisted
									$binData['blacklistid'] = 1;
								}

								//Apply Regexes
								$regexMatches = array();
								foreach ($groupRegexes as $groupRegex) {
									$regexCheck = $this->performMatch($groupRegex, $subject, $data['From']);
									if ($regexCheck !== false) {
										$regexMatches = $regexCheck;

										$binData['regexid'] = $regexCheck['regexid'];
										$binData['categoryid'] = $regexCheck['regcatid'];
										$binData['reqid'] = empty($regexCheck['reqid']) ? "null" : $regexCheck['reqid'];
										$binData['relname'] = $regexCheck['name'];
										break;
									}
								}

								$binSetData[] = $binData;
							}

							//insert 500 bins at a time
							$binChunks = array_chunk($binSetData, 500);

							foreach ($binChunks as $binChunk) {
								foreach ($binChunk as $chunk) {
									$binParams[] = sprintf("(%s, %s, FROM_UNIXTIME(%s), %s, %s, %s, %s, %s, %d, %d, now())", $db->escapeString($chunk['name']), $db->escapeString($chunk['fromname']), $db->escapeString($chunk['date']), $db->escapeString($chunk['binaryhash']), $db->escapeString($chunk['groupname']), $chunk['regexid'], $chunk['categoryid'], $chunk['reqid'], $chunk['blacklistid'], $chunk['size']);
								}
								$binSql = "INSERT IGNORE INTO releaseregextesting (name, fromname, date, binaryhash, groupname, regexid, categoryid, reqid, blacklistid, size, dateadded) VALUES " . implode(', ', $binParams);
								//echo $binSql;
								$db->queryExec($binSql);
							}

							$ret[] = "Fetched " . number_format($numarticles) . " articles from " . $group;
						} else {
							$ret[] = "No headers found on " . $group;
							continue;
						}
					} else {
						$ret[] = "Can't get parts from server (msgs not array) on " . $group;
						continue;
					}

					if ($rangeEnd == $groupEnd) {
						$done = true;
					}

					$rangeStart = $rangeEnd + 1;

				}
			}
		}
		$nntp->doQuit();

		return $ret;
	}
}
