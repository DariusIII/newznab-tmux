<?php
//This script is ported from nZEDb and adapted for newznab
require_once('config.php');

// This script is simply so I can show sleep progress in bash script
$consoletools = new ConsoleTools();
if (isset($argv[1]) && is_numeric($argv[1]))
{
	$consoletools->showsleep($argv[1]);
}