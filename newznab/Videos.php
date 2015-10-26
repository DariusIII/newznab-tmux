<?php
namespace newznab;

use newznab\db\Settings;

/**
 * Class Videos -- functions for site interaction
 *
 * @package newznab
 */
Class Videos
{
	/**
	 * @param array $options
	 */
	public function __construct(array $options = []) {
		$defaults = [
			'Echo'         => false,
			'Logger'       => null,
			'Settings'     => null,
		];
		$options += $defaults;
		$this->pdo = ($options['Settings'] instanceof Settings ? $options['Settings'] : new Settings());
		$this->catWhere = 'r.categoryid BETWEEN 5000 AND 5999';
	}

	/**
	 * Get info from tables for the provided ID.
	 *
	 * @param $id
	 *
	 * @return array
	 */
	public function getByVideoID($id)
	{
		return $this->pdo->queryOneRow(
			sprintf("
					SELECT v.*, tvi.summary, tvi.publisher, tvi.image
					FROM videos v
					INNER JOIN tv_info tvi ON v.id = tvi.videos_id
					WHERE id = %d",
					$id
			)
		);
	}

	/**
	 * Retrieves a range of all shows for the show-edit admin list
	 *
	 * @param        $start
	 * @param        $num
	 * @param string $showname
	 *
	 * @return array
	 */
	public function getRange($start, $num, $showname = "")
	{
		if ($start === false) {
			$limit = "";
		} else {
			$limit = "LIMIT " . $num . " OFFSET " . $start;
		}

		$rsql = '';
		if ($showname != "") {
			$rsql .= sprintf("AND v.title LIKE %s ", $this->pdo->escapeString("%" . $showname . "%"));
		}

		return $this->pdo->query(
			sprintf("
						SELECT v.*,
							tvi.summary, tvi.publisher, tvi.image
						FROM videos v
						INNER JOIN tv_info tvi ON v.id = tvi.videos_id
						WHERE 1=1 %s
						ORDER BY v.id ASC %s",
				$rsql,
				$limit
			)
		);
	}

	/**
	 * Returns a count of all shows -- usually used by pager
	 *
	 * @param string $showname
	 *
	 * @return mixed
	 */
	public function getCount($showname = "")
	{
		$rsql = '';
		if ($showname != "") {
			$rsql .= sprintf("AND v.title LIKE %s ", $this->pdo->escapeString("%" . $showname . "%"));
		}
		$res = $this->pdo->queryOneRow(
			sprintf("
						SELECT COUNT(v.id) AS num
						FROM videos v
						INNER JOIN tv_info tvi ON v.id = tvi.videos_id
						WHERE 1=1 %s",
				$rsql
			)
		);
		return $res["num"];
	}

	/**
	 * Retrieves and returns a list of shows with eligible releases
	 *
	 * @param        $uid
	 * @param string $letter
	 * @param string $showname
	 *
	 * @return array
	 */
	public function getSeriesList($uid, $letter = "", $showname = "")
	{
		$rsql = '';
		if ($letter != "") {
			if ($letter == '0-9') {
				$letter = '[0-9]';
			}

			$rsql .= sprintf("AND v.title REGEXP %s", $this->pdo->escapeString('^' . $letter));
		}
		$tsql = '';
		if ($showname != '') {
			$tsql .= sprintf("AND v.title LIKE %s", $this->pdo->escapeString("%" . $showname . "%"));
		}

		$qry = 	sprintf("
			SELECT v.* FROM
				(SELECT v.*,
					tve.firstaired AS prevdate, tve.title AS previnfo,
					tvi.publisher,
					us.id AS userseriesid
				FROM videos v
				INNER JOIN releases r ON r.videos_id = v.id
				INNER JOIN tv_info tvi ON r.videos_id = tvi.videos_id
				INNER JOIN tv_episodes tve ON v.id = tve.videos_id AND tve.firstaired <= NOW()
				LEFT OUTER JOIN userseries us ON v.id = us.videos_id AND us.userid = %d
				WHERE %s
				%s %s
				ORDER BY tve.firstaired DESC) v
			GROUP BY v.id
			ORDER BY v.title ASC",
			$uid,
			$this->catWhere,
			$rsql,
			$tsql
		);

		$sql = $this->pdo->query($qry);
		return $sql;
	}
}
