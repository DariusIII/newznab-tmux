<?php

spl_autoload_register(
	function($className)
	{
		if ($className == 'Smarty') {
			$className = 'Smarty.class';
		}

		$paths = array(
			SMARTY_DIR,
			NN_WWW . 'pages' . DIRECTORY_SEPARATOR,
			SMARTY_DIR . 'plugins' . DIRECTORY_SEPARATOR,
			SMARTY_DIR . 'sysplugins' . DIRECTORY_SEPARATOR
		);

		foreach ($paths as $path)
		{
			$spec = str_replace('\\', DIRECTORY_SEPARATOR, $path . strtolower($className) . '.php');
			if (file_exists($spec)) {
				require_once $spec;
				break;
			} else if (NN_LOGAUTOLOADER) {
				var_dump($spec);
			}
		}
	}
);

?>
