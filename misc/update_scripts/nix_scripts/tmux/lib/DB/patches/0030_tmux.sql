UPDATE `tmux` SET VALUE = 'http://reqid.nzedb.com/index.php?reqid=[REQUEST_ID]&group=[GROUP_NM]' WHERE `setting` = 'request_url';

UPDATE `site` set `value` = '30' where `setting` = 'sqlpatch';