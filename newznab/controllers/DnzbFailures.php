<?php


use newznab\db\Settings;


/**
 * Class DnzbFailures
 */
class DnzbFailures
{
	/**
	 * @var newznab\db\Settings
	 */
	public $pdo;

	/**
	 * @var ReleaseComments
	 */
	public $rc;

	/**
	 * @var array $options Class instances.
	 */
	public function __construct(array $options = [])
	{
		$defaults = [
			'Settings' => null
		];
		$options += $defaults;

		$this->pdo = ($options['Settings'] instanceof Settings ? $options['Settings'] : new Settings());
		$this->rc = new ReleaseComments(['Settings' => $this->pdo]);
	}

	/**
	 * @param string $guid
	 */
	public function getFailedCount($guid)
	{
		$result = $this->pdo->query(sprintf('SELECT COUNT(userid) AS num FROM dnzb_failures WHERE guid = %s', $this->pdo->escapeString($guid)));
			return $result[0]['num'];
	}

	/**
	 * Retrieve alternate release with same or similar searchname
	 *
	 * @param string $guid
	 * @param string $searchname
	 * @param string $userid
	 * @return string
	 */
	public function getAlternate($guid, $searchname, $userid)
	{
		$this->pdo->queryInsert(sprintf("INSERT IGNORE INTO dnzb_failures (userid, guid) VALUES (%d, %s)",
				$userid,
				$this->pdo->escapeString($guid)
			)
		);
		$rel = $this->pdo->queryOneRow(sprintf('SELECT id, gid FROM releases WHERE guid = %s', $this->pdo->escapeString($guid)));
		$this->postComment($rel['id'], $rel['gid'], $userid);

		$alternate = $this->pdo->queryOneRow(sprintf('SELECT * FROM releases r
			WHERE r.searchname %s
			AND r.guid NOT IN (SELECT guid FROM dnzb_failures WHERE userid = %d)',
				$this->pdo->likeString($searchname),
				$userid
			)
		);
		return $alternate;
	}

	/**
	 * @param $relid
	 * @param $gid
	 * @param $uid
	 */
	public function postComment($relid, $gid, $uid)
	{
		$text = 'This release has failed to download properly. It might fail for other users too.
		This comment is automatically generated.';
		$this->rc->addComment($relid, $gid, $text, $uid, '');
	}
}