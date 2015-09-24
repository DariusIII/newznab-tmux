<?php
//This script will update all records in the cosole table

require_once dirname(__FILE__) . '/../../www/config.php';

use newznab\db\Settings;

$console = new Console(['Echo' => true, 'Settings' => $pdo]);

$db = new Settings();

$res = $db->queryDirect(sprintf("SELECT searchname, id from releases where consoleinfoid IS NULL and categoryid in ( select ID from category where parentid = %d ) ORDER BY id DESC LIMIT %d", Category::CAT_PARENT_GAME, Console::NUMTOPROCESSPERTIME));

if ($res != null) {
    while ($arr = $db->getAssocArray($res)) {
        $gameInfo = $console->parseTitle($arr['searchname']);
        if ($gameInfo !== false) {
            echo 'Searching ' . $gameInfo['release'] . '<br />';
            $game = $console->updateConsoleInfo($gameInfo);
            if ($game !== false) {
                echo "<pre>";
                print_r($game);
                echo "</pre>";
            } else {
                echo '<br />Game not found<br /><br />';
            }
        }
    }
}