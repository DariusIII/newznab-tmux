<?php
require_once(WWW_DIR."/lib/site.php");

/*
 * General util functions.
 * Class Utility
 */
class Utility
{
	/**
	 *  Regex for detecting multi-platform path. Use it where needed so it can be updated in one location as required characters get added.
	 */
	const PATH_REGEX = '(?P<drive>[A-Za-z]:|)(?P<path>[/\w.-]+|)';

	/**
	 * Replace all white space chars for a single space.
	 *
	 * @param string $text
	 *
	 * @return string
	 *
	 * @static
	 * @access public
	 */
	static public function collapseWhiteSpace($text)
	{
		// Strip leading/trailing white space.
		return trim(
		// Replace 2 or more white space for a single space.
			preg_replace('/\s{2,}/',
				' ',
				// Replace all literal and non literal new lines and carriage returns.
				str_replace(["\n", '\n', "\r", '\r'], ' ', $text)
			)
		);
	}

	/**
	 * Removes the preceeding or proceeding portion of a string
	 * relative to the last occurrence of the specified character.
	 * The character selected may be retained or discarded.
	 *
	 * @param string $character      the character to search for.
	 * @param string $string         the string to search through.
	 * @param string $side           determines whether text to the left or the right of the character is returned.
	 *                               Options are: left, or right.
	 * @param bool   $keep_character determines whether or not to keep the character.
	 *                               Options are: true, or false.
	 *
	 * @return string
	 */
	static public function cutStringUsingLast($character, $string, $side, $keep_character = true)
	{
		$offset = ($keep_character ? 1 : 0);
		$whole_length = strlen($string);
		$right_length = (strlen(strrchr($string, $character)) - 1);
		$left_length = ($whole_length - $right_length - 1);
		switch ($side) {
			case 'left':
				$piece = substr($string, 0, ($left_length + $offset));
				break;
			case 'right':
				$start = (0 - ($right_length + $offset));
				$piece = substr($string, $start);
				break;
			default:
				$piece = false;
				break;
		}

		return ($piece);
	}

	static public function getDirFiles(array $options = null)
	{
		$defaults = [
			'dir'   => false,
			'ext'   => '', // no full stop (period) separator should be used.
			'path'  => '',
			'regex' => '',
		];
		$options += $defaults;

		$files = [];
		$dir = new \DirectoryIterator($options['path']);
		foreach ($dir as $fileinfo) {
			$file = $fileinfo->getFilename();
			switch (true) {
				case $fileinfo->isDot():
					break;
				case !$options['dir'] && $fileinfo->isDir():
					break;
				case !empty($options['ext']) && $fileinfo->getExtension() != $options['ext'];
					break;
				case !preg_match($options['regex'], str_replace('\\', '/', $file)):
					break;
				default:
					$files[] = $fileinfo->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Detect if the command is accessible on the system.
	 *
	 * @param $cmd
	 *
	 * @return bool|null Returns true if found, false if not found, and null if which is not detected.
	 */
	static public function hasCommand($cmd)
	{
		if ('HAS_WHICH') {
			$returnVal = shell_exec("which $cmd");

			return (empty($returnVal) ? false : true);
		} else {
			return null;
		}
	}

	/**
	 * Check for availability of which command
	 */
	static public function hasWhich()
	{
		exec('which which', $output, $error);

		return !$error;
	}

	/**
	 * Check if user is running from CLI.
	 *
	 * @return bool
	 */
	static public function isCLI()
	{
		return ((strtolower(PHP_SAPI) === 'cli') ? true : false);
	}

	static public function isWin()
	{
		return (strtolower(substr(PHP_OS, 0, 3)) === 'win');
	}

	static public function stripBOM(&$text)
	{
		$bom = pack("CCC", 0xef, 0xbb, 0xbf);
		if (0 == strncmp($text, $bom, 3)) {
			$text = substr($text, 3);
		}
	}

	/**
	 * Strips non-printing characters from a string.
	 *
	 * Operates directly on the text string, but also returns the result for situations requiring a
	 * return value (use in ternary, etc.)/
	 *
	 * @param $text        String variable to strip.
	 *
	 * @return string    The stripped variable.
	 */
	static public function stripNonPrintingChars(&$text)
	{
		$lowChars = [
			"\x00", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07",
			"\x08", "\x09", "\x0A", "\x0B", "\x0C", "\x0D", "\x0E", "\x0F",
			"\x10", "\x11", "\x12", "\x13", "\x14", "\x15", "\x16", "\x17",
			"\x18", "\x19", "\x1A", "\x1B", "\x1C", "\x1D", "\x1E", "\x1F",
		];
		$text = str_replace($lowChars, '', $text);

		return $text;
	}

	static public function trailingSlash($path)
	{
		if (substr($path, strlen($path) - 1) != '/') {
			$path .= '/';
		}

		return $path;
	}

	/**
	 * Unzip a gzip file, return the output. Return false on error / empty.
	 *
	 * @param string $filePath
	 *
	 * @return bool|string
	 */
	static public function unzipGzipFile($filePath)
	{
		// String to hold the NZB contents.
		$string = '';

		// Open the gzip file.
		$gzFile = @gzopen($filePath, 'rb', 0);
		if ($gzFile) {
			// Append the decompressed data to the string until we find the end of file pointer.
			while (!gzeof($gzFile)) {
				$string .= gzread($gzFile, 1024);
			}
			// Close the gzip file.
			gzclose($gzFile);
		}

		// Return the string.
		return ($string === '' ? false : $string);
	}

	/**
	 * Creates an array to be used with stream_context_create() to verify openssl certificates
	 * when connecting to a tls or ssl connection when using stream functions (fopen/file_get_contents/etc).
	 *
	 * @param bool $forceIgnore Force ignoring of verification.
	 *
	 * @return array
	 * @static
	 * @access public
	 */
	static public function streamSslContextOptions($forceIgnore = false)
	{
		$options = [
			'verify_peer'       => ($forceIgnore ? false : (bool)NN_SSL_VERIFY_PEER),
			'verify_peer_name'  => ($forceIgnore ? false : (bool)NN_SSL_VERIFY_HOST),
			'allow_self_signed' => ($forceIgnore ? true : (bool)NN_SSL_ALLOW_SELF_SIGNED),
		];
		if (NN_SSL_CAFILE) {
			$options['cafile'] = NN_SSL_CAFILE;
		}
		if (NN_SSL_CAPATH) {
			$options['capath'] = NN_SSL_CAPATH;
		}
		// If we set the transport to tls and the server falls back to ssl,
		// the context options would be for tls and would not apply to ssl,
		// so set both tls and ssl context in case the server does not support tls.
		return ['tls' => $options, 'ssl' => $options];
	}

	/**
	 * Set curl context options for verifying SSL certificates.
	 *
	 * @param bool $verify false = Ignore config.php and do not verify the openssl cert.
	 *                     true  = Check config.php and verify based on those settings.
	 *                     If you know the certificate will be self-signed, pass false.
	 *
	 * @return array
	 * @static
	 * @access public
	 */
	static public function curlSslContextOptions($verify = true)
	{
		$options = [];
		if ($verify && NN_SSL_VERIFY_HOST) {
			$options += [
				CURLOPT_CAINFO         => NN_SSL_CAFILE,
				CURLOPT_CAPATH         => NN_SSL_CAPATH,
				CURLOPT_SSL_VERIFYPEER => (bool)NN_SSL_VERIFY_PEER,
				CURLOPT_SSL_VERIFYHOST => (NN_SSL_VERIFY_HOST ? 2 : 0),
			];
		} else {
			$options += [
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_SSL_VERIFYHOST => 0,
			];
		}
		return $options;
	}

	/**
	 * Use cURL To download a web page into a string.
	 *
	 * @param array $options See details below.
	 *
	 * @return bool|mixed
	 * @access public
	 * @static
	 */
	public static function getUrl(array $options = [])
	{
		$defaults = [
			'url'        => '',    // The URL to download.
			'method'     => 'get', // Http method, get/post/etc..
			'postdata'   => '',    // Data to send on post method.
			'enctype'	 => '',    // Encoding type
			'language'   => '',    // Language in header string.
			'debug'      => false, // Show curl debug information.
			'useragent'  => '',    // User agent string.
			'cookie'     => '',    // Cookie string.
			'verifycert' => true,  /* Verify certificate authenticity?
									  Since curl does not have a verify self signed certs option,
									  you should use this instead if your cert is self signed. */
		];

		$options += $defaults;

		if (!$options['url']) {
			return false;
		}

		switch ($options['language']) {
			case 'fr':
			case 'fr-fr':
				$language = "fr-fr";
				break;
			case 'de':
			case 'de-de':
				$language = "de-de";
				break;
			case 'en-us':
				$language = "en-us";
				break;
			case 'en-gb':
				$language = "en-gb";
				break;
			case '':
			case 'en':
			default:
				$language = 'en';
		}
		$header[] = "Accept-Language: " . $language;

		$ch = curl_init();

		$context = [
			CURLOPT_URL            => $options['url'],
			CURLOPT_HTTPHEADER     => $header,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_FOLLOWLOCATION => 1,
			CURLOPT_TIMEOUT        => 15
		];
		$context += self::curlSslContextOptions($options['verifycert']);
		if ($options['useragent'] !== '') {
			$context += [CURLOPT_USERAGENT => $options['useragent']];
		}
		if ($options['cookie'] !== '') {
			$context += [CURLOPT_COOKIE => $options['cookie']];
		}
		if ($options['method'] === 'post') {
			$context += [
				CURLOPT_POST       => 1,
				CURLOPT_POSTFIELDS => $options['postdata']
			];
		}
		if ($options['enctype'] !== '') {
			$context += [CURLOPT_ENCODING  => $options['enctype']];
		}
		if ($options['debug']) {
			$context += [
				CURLOPT_HEADER      => true,
				CURLINFO_HEADER_OUT => true,
				CURLOPT_NOPROGRESS  => false,
				CURLOPT_VERBOSE     => true
			];
		}
		curl_setopt_array($ch, $context);

		$buffer = curl_exec($ch);
		$err    = curl_errno($ch);
		curl_close($ch);

		if ($err !== 0) {
			return false;
		} else {
			return $buffer;
		}
	}


	/**
	 * Get human readable size string from bytes.
	 *
	 * @param int $bytes     Bytes number to convert.
	 * @param int $precision How many floating point units to add.
	 *
	 * @return string
	 */
	static public function bytesToSizeString($bytes, $precision = 0)
	{
		if ($bytes == 0) {
			return '0B';
		}
		$unit = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];

		return round($bytes / pow(1024, ($i = floor(log($bytes, 1024)))), $precision) . $unit[(int)$i];
	}

	public static function getCoverURL(array $options = [])
	{
		$defaults = [
			'ID' => null,
			'suffix' => '-cover.jpg',
			'type' => '',
		];
		$options += $defaults;
		$fileSpecTemplate = '%s/%s%s';
		$fileSpec = '';

		if (!empty($options['id']) && in_array($options['type'],
				['anime', 'audio', 'audiosample', 'book', 'console',  'games', 'movies', 'music', 'preview', 'sample', 'tvrage', 'video', 'xxx'])) {
			$fileSpec = sprintf($fileSpecTemplate, $options['type'], $options['ID'], $options['suffix']);
			$fileSpec = file_exists(NN_COVERS . $fileSpec) ? $fileSpec :
				sprintf($fileSpecTemplate, $options['type'], 'no', $options['suffix']);
		}

		return $fileSpec;
	}

}

function checkStatus ($code)
{
	return ($code == 0) ? true : false;
}

/**
 * Convert Code page 437 chars to UTF.
 *
 * @param string $str
 *
 * @return string
 */
function cp437toUTF ($str)
{
	$out = '';
	for ($i = 0; $i < strlen($str); $i++) {
		$ch = ord($str{$i});
		switch ($ch) {
			case 128:
				$out .= 'Ç';
				break;
			case 129:
				$out .= 'ü';
				break;
			case 130:
				$out .= 'é';
				break;
			case 131:
				$out .= 'â';
				break;
			case 132:
				$out .= 'ä';
				break;
			case 133:
				$out .= 'à';
				break;
			case 134:
				$out .= 'å';
				break;
			case 135:
				$out .= 'ç';
				break;
			case 136:
				$out .= 'ê';
				break;
			case 137:
				$out .= 'ë';
				break;
			case 138:
				$out .= 'è';
				break;
			case 139:
				$out .= 'ï';
				break;
			case 140:
				$out .= 'î';
				break;
			case 141:
				$out .= 'ì';
				break;
			case 142:
				$out .= 'Ä';
				break;
			case 143:
				$out .= 'Å';
				break;
			case 144:
				$out .= 'É';
				break;
			case 145:
				$out .= 'æ';
				break;
			case 146:
				$out .= 'Æ';
				break;
			case 147:
				$out .= 'ô';
				break;
			case 148:
				$out .= 'ö';
				break;
			case 149:
				$out .= 'ò';
				break;
			case 150:
				$out .= 'û';
				break;
			case 151:
				$out .= 'ù';
				break;
			case 152:
				$out .= 'ÿ';
				break;
			case 153:
				$out .= 'Ö';
				break;
			case 154:
				$out .= 'Ü';
				break;
			case 155:
				$out .= '¢';
				break;
			case 156:
				$out .= '£';
				break;
			case 157:
				$out .= '¥';
				break;
			case 158:
				$out .= '₧';
				break;
			case 159:
				$out .= 'ƒ';
				break;
			case 160:
				$out .= 'á';
				break;
			case 161:
				$out .= 'í';
				break;
			case 162:
				$out .= 'ó';
				break;
			case 163:
				$out .= 'ú';
				break;
			case 164:
				$out .= 'ñ';
				break;
			case 165:
				$out .= 'Ñ';
				break;
			case 166:
				$out .= 'ª';
				break;
			case 167:
				$out .= 'º';
				break;
			case 168:
				$out .= '¿';
				break;
			case 169:
				$out .= '⌐';
				break;
			case 170:
				$out .= '¬';
				break;
			case 171:
				$out .= '½';
				break;
			case 172:
				$out .= '¼';
				break;
			case 173:
				$out .= '¡';
				break;
			case 174:
				$out .= '«';
				break;
			case 175:
				$out .= '»';
				break;
			case 176:
				$out .= '░';
				break;
			case 177:
				$out .= '▒';
				break;
			case 178:
				$out .= '▓';
				break;
			case 179:
				$out .= '│';
				break;
			case 180:
				$out .= '┤';
				break;
			case 181:
				$out .= '╡';
				break;
			case 182:
				$out .= '╢';
				break;
			case 183:
				$out .= '╖';
				break;
			case 184:
				$out .= '╕';
				break;
			case 185:
				$out .= '╣';
				break;
			case 186:
				$out .= '║';
				break;
			case 187:
				$out .= '╗';
				break;
			case 188:
				$out .= '╝';
				break;
			case 189:
				$out .= '╜';
				break;
			case 190:
				$out .= '╛';
				break;
			case 191:
				$out .= '┐';
				break;
			case 192:
				$out .= '└';
				break;
			case 193:
				$out .= '┴';
				break;
			case 194:
				$out .= '┬';
				break;
			case 195:
				$out .= '├';
				break;
			case 196:
				$out .= '─';
				break;
			case 197:
				$out .= '┼';
				break;
			case 198:
				$out .= '╞';
				break;
			case 199:
				$out .= '╟';
				break;
			case 200:
				$out .= '╚';
				break;
			case 201:
				$out .= '╔';
				break;
			case 202:
				$out .= '╩';
				break;
			case 203:
				$out .= '╦';
				break;
			case 204:
				$out .= '╠';
				break;
			case 205:
				$out .= '═';
				break;
			case 206:
				$out .= '╬';
				break;
			case 207:
				$out .= '╧';
				break;
			case 208:
				$out .= '╨';
				break;
			case 209:
				$out .= '╤';
				break;
			case 210:
				$out .= '╥';
				break;
			case 211:
				$out .= '╙';
				break;
			case 212:
				$out .= '╘';
				break;
			case 213:
				$out .= '╒';
				break;
			case 214:
				$out .= '╓';
				break;
			case 215:
				$out .= '╫';
				break;
			case 216:
				$out .= '╪';
				break;
			case 217:
				$out .= '┘';
				break;
			case 218:
				$out .= '┌';
				break;
			case 219:
				$out .= '█';
				break;
			case 220:
				$out .= '▄';
				break;
			case 221:
				$out .= '▌';
				break;
			case 222:
				$out .= '▐';
				break;
			case 223:
				$out .= '▀';
				break;
			case 224:
				$out .= 'α';
				break;
			case 225:
				$out .= 'ß';
				break;
			case 226:
				$out .= 'Γ';
				break;
			case 227:
				$out .= 'π';
				break;
			case 228:
				$out .= 'Σ';
				break;
			case 229:
				$out .= 'σ';
				break;
			case 230:
				$out .= 'µ';
				break;
			case 231:
				$out .= 'τ';
				break;
			case 232:
				$out .= 'Φ';
				break;
			case 233:
				$out .= 'Θ';
				break;
			case 234:
				$out .= 'Ω';
				break;
			case 235:
				$out .= 'δ';
				break;
			case 236:
				$out .= '∞';
				break;
			case 237:
				$out .= 'φ';
				break;
			case 238:
				$out .= 'ε';
				break;
			case 239:
				$out .= '∩';
				break;
			case 240:
				$out .= '≡';
				break;
			case 241:
				$out .= '±';
				break;
			case 242:
				$out .= '≥';
				break;
			case 243:
				$out .= '≤';
				break;
			case 244:
				$out .= '⌠';
				break;
			case 245:
				$out .= '⌡';
				break;
			case 246:
				$out .= '÷';
				break;
			case 247:
				$out .= '≈';
				break;
			case 248:
				$out .= '°';
				break;
			case 249:
				$out .= '∙';
				break;
			case 250:
				$out .= '·';
				break;
			case 251:
				$out .= '√';
				break;
			case 252:
				$out .= 'ⁿ';
				break;
			case 253:
				$out .= '²';
				break;
			case 254:
				$out .= '■';
				break;
			case 255:
				$out .= ' ';
				break;
			default :
				$out .= chr($ch);
		}
	}
	return $out;
}

/**
 * Fetches an embeddable video to a IMDB trailer from http://www.traileraddict.com
 *
 * @param $imdbID
 *
 * @return string
 */
function imdb_trailers($imdbID)
{
	$xml = Utility::getUrl(['http://api.traileraddict.com/?imdb=' . $imdbID]);
	if ($xml !== false) {
		if (preg_match('/(<iframe.+?<\/iframe>)/i', $xml, $html)) {
			return $html[1];
		}
	}
	return '';
}

// Check if O/S is windows.
function isWindows ()
{
	return Utility::isWin();
}

// Convert obj to array.
function objectsIntoArray ($arrObjData, $arrSkipIndices = [])
{
	$arrData = [];

	// If input is object, convert into array.
	if (is_object($arrObjData)) {
		$arrObjData = get_object_vars($arrObjData);
	}

	if (is_array($arrObjData)) {
		foreach ($arrObjData as $index => $value) {
			// Recursive call.
			if (is_object($value) || is_array($value)) {
				$value = objectsIntoArray($value, $arrSkipIndices);
			}
			if (in_array($index, $arrSkipIndices)) {
				continue;
			}
			$arrData[$index] = $value;
		}
	}
	return $arrData;
}

/**
 * Run CLI command.
 *
 * @param string $command
 * @param bool   $debug
 *
 * @return array
 */
function runCmd ($command, $debug = false)
{
	$nl = PHP_EOL;
	if (isWindows() && strpos(phpversion(), "5.2") !== false) {
		$command = "\"" . $command . "\"";
	}

	if ($debug) {
		echo '-Running Command: ' . $nl . '   ' . $command . $nl;
	}

	$output = [];
	$status = 1;
	@exec($command, $output, $status);

	if ($debug) {
		echo '-Command Output: ' . $nl . '   ' . implode($nl . '  ', $output) . $nl;
	}

	return $output;
}

/**
 * Remove unsafe chars from a filename.
 *
 * @param string $filename
 *
 * @return string
 */
function safeFilename ($filename)
{
	return trim(preg_replace('/[^\w\s.-]*/i', '', $filename));
}

// Central function for sending site email.
function sendEmail($to, $subject, $contents, $from)
{
	if (isWindows()) {
		$n = "\r\n";
	} else {
		$n = "\n";
	}
	$body = '<html>' . $n;
	$body .= '<body style=\'font-family:Verdana, Verdana, Geneva, sans-serif; font-size:12px; color:#666666;\'>' . $n;
	$body .= $contents;
	$body .= '</body>' . $n;
	$body .= '</html>' . $n;

	$headers = 'From: ' . $from . $n;
	$headers .= 'Reply-To: ' . $from . $n;
	$headers .= 'Return-Path: ' . $from . $n;
	$headers .= 'X-Mailer: newznab' . $n;
	$headers .= 'MIME-Version: 1.0' . $n;
	$headers .= 'Content-type: text/html; charset=iso-8859-1' . $n;
	$headers .= $n;

	return mail($to, $subject, $body, $headers);
}

function generateUuid()
{
	$key = sprintf
	(
		'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		// 32 bits for "time_low"
		mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
		// 16 bits for "time_mid"
		mt_rand( 0, 0xffff ),
		// 16 bits for "time_hi_and_version",
		// four most significant bits holds version number 4
		mt_rand( 0, 0x0fff ) | 0x4000,
		// 16 bits, 8 bits for "clk_seq_hi_res",
		// 8 bits for "clk_seq_low",
		// two most significant bits holds zero and one for variant DCE1.1
		mt_rand( 0, 0x3fff ) | 0x8000,
		// 48 bits for "node"
		mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
	);

	return $key;
}

function startsWith($haystack, $needle)
{
	$length = strlen($needle);
	return (substr($haystack, 0, $length) === $needle);
}

function endsWith($haystack, $needle)
{
	$length = strlen($needle);
	$start  = $length * -1;
	return (substr($haystack, $start) === $needle);
}

function responseXmlToObject($input)
{
	$input = str_replace('<newznab:', '<', $input);
	$xml = @simplexml_load_string($input);
	return $xml;
}

