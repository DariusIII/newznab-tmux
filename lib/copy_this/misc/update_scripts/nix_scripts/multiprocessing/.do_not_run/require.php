<?php
require_once dirname(dirname(dirname(dirname(dirname(__DIR__))))) . DIRECTORY_SEPARATOR . 'www' . DIRECTORY_SEPARATOR . 'config.php';

if (is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'settings.php')) {
	require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'settings.php';
}
