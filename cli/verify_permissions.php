<?php

if (!isset($argv[1]) || $argv[1] !== 'yes') {
	exit(
		'This will verify path permissions for crucial locations in newznab-tmux for the current user running the script.' . PHP_EOL .
		'If a wrong permission is encountered, you will be alerted.' . PHP_EOL .
		'IT IS STRONGLY RECOMMENDED you run this against your apache/nginx user, in addition to your normal CLI user.' . PHP_EOL .
		'On linux you can run it against the apache/nginx user this way: sudo -u www-data php verify_permissions.php yes' . PHP_EOL .
		'See this page for a quick guide on setting up your permissions in linux: https://github.com/nZEDb/nZEDb/wiki/Setting-permissions-on-linux' . PHP_EOL .
		'If you are ready to run this script, pass yes as the first argument.' . PHP_EOL
	);
}

require_once realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'indexer.php');

define('R', 1);
define('W', 2);
define('E', 4);

// Check All folders up to NN root folder.
$string = DS;
foreach (explode(DS, NN_ROOT) as $folder) {
	if ($folder) {
		$string .= $folder . DS;
		readable($string);
		executable($string);
	}
}

// List of folders to check with required permissions.
$folders = [
	NN_LIBS										=> [R],
	NN_LIBS . 'smarty'							=> [R],
	NN_LIBS . 'smarty' . DS . 'templates_c'		=> [R, W],
	NN_RES										=> [R, W, E],
	NN_RES . 'db'								=> [R, E],
	NN_RES . 'db' . DS . 'patches'				=> [R, E],
	NN_RES . 'nzb'								=> [R],
	NN_LOGS										=> [R, W],
	NN_TMP										=> [R, W],
	NN_TMP . DS . 'unrar'						=> [R, W, E],
	NN_TMP . DS . 'yEnc'							=> [R, W, E],
	NN_VERSIONS									=> [R],
];

// Add nzb folders.
foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 'a', 'b', 'c', 'd', 'e', 'f'] as $identifier) {
	$nzbFolder = NN_RES . 'nzb' . DS . $identifier . DS;
	$folders[$nzbFolder] = [R, W];
}

// Add covers paths.
foreach (['anime', 'audio', 'audiosample', 'book', 'console', 'games', 'movies', 'music', 'preview', 'sample', 'tvrage', 'video', 'xxx'] as $identifier) {
	$nzbFolder = NN_RES . 'covers' . DS . $identifier . DS;
	$folders[$nzbFolder] = [R, W];
}

// Set up covers paths.
if (defined('DB_PASSWORD') && DB_PASSWORD != '') {
	$ri = new ReleaseImage();

	$folders[$ri->audSavePath]      = [R, W];
	$folders[$ri->imgSavePath]      = [R, W];
	$folders[$ri->jpgSavePath]      = [R, W];
	$folders[$ri->movieImgSavePath] = [R, W];
	$folders[$ri->vidSavePath]      = [R, W];
} else {
	echo 'Skipping cover folders check, as you have not set up a database yet. You can rerun this script after running install.' . PHP_EOL;
}

// Check folders.
foreach ($folders as $folder => $check) {
	exists($folder);
	foreach ($check as $type) {
		switch ($type) {
			case R:
				readable($folder);
				break;
			case W:
				writable($folder);
				break;
			case E:
				executable($folder);
				break;
		}
	}
}

echo 'Your permissions seem right for this user. Note, this script does not verify all paths, only the most important ones.' . PHP_EOL;

if (!newznab\utility\Utility::isWin()) {
	$user = posix_getpwuid(posix_geteuid());
	if ($user['name'] !== 'www-data') {
		echo 'If you have not already done so, please rerun this script using the www-data user: sudo -u www-data php verify_permissions.php yes' . PHP_EOL;
	}
}

function readable($folder)
{
	if (!is_readable($folder)) {
		exit('Error: This path is not readable: (' . $folder . ') resolve this and rerun the script.' . PHP_EOL);
	}
}

function writable($folder)
{
	if (!is_writable($folder)) {
		exit('Error: This path is not writable: (' . $folder . ') resolve this and rerun the script.' . PHP_EOL);
	}
}

function executable($folder)
{
	if (!is_executable($folder)) {
		exit('Error: This path is not executable: (' . $folder . ') resolve this and rerun the script.' . PHP_EOL);
	}
}

function exists($folder)
{
	if (!file_exists($folder)) {
		exit('Error: This path (' . $folder . ') does not exist or is not readable. Create it or make it readable.' . PHP_EOL);
	}
}