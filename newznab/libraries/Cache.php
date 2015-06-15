<?php
namespace newznab\libraries;

/**
 * Class Cache
 *
 * Class for connecting to a memcached or redis server to cache data.
 *
 * @package newznab\libraries
 */
class Cache
{
	const SERIALIZER_PHP      = 0;
	const SERIALIZER_IGBINARY = 1;
	const SERIALIZER_NONE     = 2;

	const TYPE_DISABLED  = 0;
	const TYPE_MEMCACHED = 1;
	const TYPE_REDIS     = 2;
	const TYPE_APC       = 3;

	/**
	 * @var \Memcached|\Redis
	 */
	private $server = null;

	/**
	 * Are we connected to the cache server?
	 * @var bool
	 */
	private $connected = false;

	/**
	 * Optional socket file location.
	 * @var bool|string
	 */
	private $socketFile;

	/**
	 * Does the user have igBinary support and wants to use it?
	 * @var bool
	 */
	private $IgBinarySupport = false;

	/**
	 * Store data on the cache server.
	 *
	 * @param string       $key        Key we can use to retrieve the data.
	 * @param string|array $data       Data to store on the cache server.
	 * @param int          $expiration Time before the data expires on the cache server.
	 *
	 * @return bool Success/Failure.
	 * @access public
	 */
	public function set($key, $data, $expiration)
	{
		if ($this->ping()) {
			$this->serializeData($data);
			switch (NN_CACHE_TYPE) {
				case self::TYPE_REDIS:
				case self::TYPE_MEMCACHED:
					return $this->server->set($key, $data, $expiration);
				case self::TYPE_APC:
					return apc_add($key, $data, $expiration);
			}
		}
		return false;
	}

	/**
	 * Serialize data, or not based on admin setting.
	 *
	 * @param string|array $data
	 */
	private function serializeData(&$data)
	{
		switch (NN_CACHE_SERIALIZER) {
			case self::SERIALIZER_IGBINARY:
				if ($this->IgBinarySupport) {
					$data = igbinary_serialize($data);
				} else {
					$data = serialize($data);
				}
				break;
			case self::SERIALIZER_PHP:
				$data = serialize($data);
				break;
			case self::SERIALIZER_NONE:
			default:
				break;
		}
	}

	/**
	 * Attempt to retrieve a value from the cache server, if not set it.
	 *
	 * @param string $key Key we can use to retrieve the data.
	 *
	 * @return bool|string False on failure or String, data belonging to the key.
	 * @access public
	 */
	public function get($key)
	{
		if ($this->ping()) {
			$data = '';
			switch (NN_CACHE_TYPE) {
				case self::TYPE_REDIS:
				case self::TYPE_MEMCACHED:
					$data = $this->server->get($key);
					break;
				case self::TYPE_APC:
					$data = apc_fetch($key);
					break;
			}
			$this->unserializeData($data);
			return $data;
		}
		return false;
	}

	/**
	 * Un-serialize data, or not based on admin setting.
	 *
	 * @param string $data
	 */
	private function unserializeData(&$data)
	{
		switch (NN_CACHE_SERIALIZER) {
			case self::SERIALIZER_IGBINARY:
				if ($this->IgBinarySupport) {
					$data = igbinary_unserialize($data);
				} else {
					$data = unserialize($data);
				}
				break;
			case self::SERIALIZER_PHP:
				$data = unserialize($data);
				break;
			case self::SERIALIZER_NONE:
			default:
				break;
		}
	}

	/**
	 * Delete data tied to a key on the cache server.
	 *
	 * @param string $key Key we can use to retrieve the data.
	 *
	 * @return bool True if deleted, false if not.
	 * @access public
	 */
	public function delete($key)
	{
		if ($this->ping()) {
			switch (NN_CACHE_TYPE) {
				case self::TYPE_REDIS:
				case self::TYPE_MEMCACHED:
					return (bool)$this->server->delete($key);
				case self::TYPE_APC:
					return apc_delete($key);
			}
		}
		return false;
	}

	/**
	 * Flush all data from the cache server?
	 */
	public function flush()
	{
		if ($this->ping()) {
			switch (NN_CACHE_TYPE) {
				case self::TYPE_REDIS:
					$this->server->flushAll();
					break;
				case self::TYPE_MEMCACHED:
					$this->server->flush();
					break;
				case self::TYPE_APC:
					apc_clear_cache("user");
					apc_clear_cache();
					break;
			}
		}
	}

	/**
	 * Create a SHA1 hash from a string which can be used to store/retrieve data.
	 *
	 * @param string $string
	 *
	 * @return string SHA1 hash of the input string.
	 * @access public
	 */
	public function createKey($string)
	{
		return sha1($string);
	}

	/**
	 * Get cache server statistics.
	 *
	 * @return array
	 * @access public
	 */
	public function serverStatistics()
	{
		if ($this->ping()) {
			switch (NN_CACHE_TYPE) {
				case self::TYPE_REDIS:
					return $this->server->info();
				case self::TYPE_MEMCACHED:
					return $this->server->getStats();
				case self::TYPE_APC:
					return apc_cache_info();
			}
		}
		return array();
	}

	/**
	 * Verify the user's cache settings, try to connect to the cache server.
	 */
	public function __construct()
	{
		if (!defined('NN_CACHE_HOSTS')) {
			throw new CacheException(
				'The NN_CACHE_HOSTS is not defined! Define it in settings.php'
			);
		}

		if (!defined('NN_CACHE_TIMEOUT')) {
			throw new CacheException(
				'The NN_CACHE_TIMEOUT is not defined! Define it in settings.php, it is the time in seconds to time out from your cache server.'
			);
		}

		$this->socketFile = false;
		if (defined('NN_CACHE_SOCKET_FILE') && NN_CACHE_SOCKET_FILE != '') {
			$this->socketFile = true;
		}

		$serializer = false;
		if (defined('NN_CACHE_SERIALIZER')) {
			$serializer = true;
		}

		switch (NN_CACHE_TYPE) {

			case self::TYPE_REDIS:
				if (!extension_loaded('redis')) {
					throw new CacheException('The redis extension is not loaded!');
				}
				$this->server = new \Redis();
				$this->connect();
				if ($serializer) {
					$this->server->setOption(\Redis::OPT_SERIALIZER, $this->verifySerializer());
				}
				break;

			case self::TYPE_MEMCACHED:
				if (!extension_loaded('memcached')) {
					throw new CacheException('The memcached extension is not loaded!');
				}
				$this->server = new \Memcached();
				if ($serializer) {
					$this->server->setOption(\Memcached::OPT_SERIALIZER, $this->verifySerializer());
				}
				$this->server->setOption(\Memcached::OPT_COMPRESSION, (defined('NN_CACHE_COMPRESSION') ? NN_CACHE_COMPRESSION : false));
				$this->connect();
				break;

			case self::TYPE_APC:
				if (!function_exists('apc_set')) {
					throw new CacheException('The APC extension is not loaded!');
				}
				$this->connect();
				break;

			case self::TYPE_DISABLED:
			default:
				break;
		}
	}

	/**
	 * Destroy the connections.
	 */
	public function __destruct()
	{
		switch (NN_CACHE_TYPE) {
			case self::TYPE_REDIS:
				$this->server->close();
				break;
			case self::TYPE_MEMCACHED:
				$this->server->quit();
				break;
		}
	}

	/**
	 * Connect to the cache server(s).
	 *
	 * @throws CacheException
	 * @access private
	 */
	private function connect()
	{
		$this->connected = false;
		switch (NN_CACHE_TYPE) {
			case self::TYPE_REDIS:
				if ($this->socketFile === false) {
					$servers = unserialize(NN_CACHE_HOSTS);
					foreach ($servers as $server) {
						if ($this->server->connect($server['host'], $server['port'], (float)NN_CACHE_TIMEOUT) === false) {
							throw new CacheException('Error connecting to the Redis server!');
						} else {
							$this->connected = true;
						}
					}
				} else {
					if ($this->server->connect(NN_CACHE_SOCKET_FILE) === false) {
						throw new CacheException('Error connecting to the Redis server!');
					} else {
						$this->connected = true;
					}
				}
				break;
			case self::TYPE_MEMCACHED:
				$params = ($this->socketFile === false ? unserialize(NN_CACHE_HOSTS) : [[NN_CACHE_SOCKET_FILE, 'port' => 0]]);
				if ($this->server->addServers($params) === false) {
					throw new CacheException('Error connecting to the Memcached server!');
				} else {
					$this->connected = true;
				}
				break;
			case self::TYPE_APC:
				$this->connected = true;
				break;
		}
	}

	/**
	 * Check if we are still connected to the cache server, reconnect if not.
	 *
	 * @return bool
	 */
	private function ping()
	{
		if (!$this->connected) {
			return false;
		}
		switch (NN_CACHE_TYPE) {
			case self::TYPE_REDIS:
				try {
					return (bool)$this->server->ping();
				} catch (\RedisException $error) {
					// nothing to see here, move along
				}
				break;
			case self::TYPE_MEMCACHED:
				$versions = $this->server->getVersion();
				if ($versions) {
					foreach ($versions as $version) {
						if ($version != "255.255.255") {
							return true;
						}
					}
				}
				break;
			case self::TYPE_APC:
				return true;
			default:
				return false;
		}
		$this->connect();
		return $this->connected;
	}

	/**
	 * Verify the user selected serializer, return the memcached or redis appropriate serializer option.
	 *
	 * @return int
	 * @throws CacheException
	 * @access private
	 */
	private function verifySerializer()
	{
		switch (NN_CACHE_SERIALIZER) {
			case self::SERIALIZER_IGBINARY:
				if (!extension_loaded('igbinary')) {
					throw new CacheException('Error: The igbinary extension is not loaded!');
				}

				switch (NN_CACHE_TYPE) {
					case self::TYPE_REDIS:
						// No way to check since phpredis has no constants or functions for this.
						return \Redis::SERIALIZER_IGBINARY;
					case self::TYPE_MEMCACHED:
						if (\Memcached::HAVE_IGBINARY > 0) {
							return \Memcached::SERIALIZER_IGBINARY;
						}
						throw new CacheException('Error: You have not compiled Memcached with igbinary support!');
					case self::TYPE_APC: // No way to check, since apc.serializer can not be fetched using ini_get.
					default:
						return null;
				}

			case self::SERIALIZER_NONE:
				// Only redis supports this.
				if (NN_CACHE_TYPE != self::TYPE_REDIS) {
					throw new CacheException('Error: Disabled serialization is only available on Redis!');
				}
				return \Redis::SERIALIZER_NONE;

			case self::SERIALIZER_PHP:
			default:
				switch (NN_CACHE_TYPE) {
					case self::TYPE_REDIS:
						return \Redis::SERIALIZER_PHP;
					case self::TYPE_MEMCACHED:
						return \Memcached::SERIALIZER_PHP;
					default:
						return null;
				}
		}
	}

}
