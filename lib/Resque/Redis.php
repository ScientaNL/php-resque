<?php
/**
 * Wrap Credis to add namespace support and various helper methods.
 *
 * @package		Resque/Redis
 * @author		Chris Boulton <chris@bigcommerce.com>
 * @license		http://www.opensource.org/licenses/mit-license.php
 */
class Resque_Redis
{
	/**
	 * @var Credis_Client|RedisCluster
	 */
	public $driver;

	/**
	 * @var Psr\Log\LoggerInterface
	 */
	public $logger;

	/**
	 * Redis namespace
	 * @var string
	 */
	private static $defaultNamespace = 'resque:';

	/**
	 * A default host to connect to
	 */
	const DEFAULT_HOST = 'localhost';

	/**
	 * The default Redis port
	 */
	const DEFAULT_PORT = 6379;

	/**
	 * The default Redis Database number
	 */
	const DEFAULT_DATABASE = 0;

	/**
	 * @var array List of all commands in Redis that supply a key as their
	 *	first argument. Used to prefix keys with the Resque namespace.
	 */
	private $keyCommands = array(
		'exists',
		'del',
		'type',
		'keys',
		'expire',
		'ttl',
		'move',
		'set',
		'setex',
		'get',
		'getset',
		'setnx',
		'incr',
		'incrby',
		'decr',
		'decrby',
		'rpush',
		'lpush',
		'llen',
		'lrange',
		'ltrim',
		'lindex',
		'lset',
		'lrem',
		'lpop',
		'blpop',
		'rpop',
		'sadd',
		'srem',
		'spop',
		'scard',
		'sismember',
		'smembers',
		'srandmember',
		'zadd',
		'zrem',
		'zrange',
		'zrevrange',
		'zrangebyscore',
		'zcard',
		'zscore',
		'zremrangebyscore',
		'sort',
		'rename',
		'rpoplpush'
	);
	// sinterstore
	// sunion
	// sunionstore
	// sdiff
	// sdiffstore
	// sinter
	// smove
	// mget
	// msetnx
	// mset
	// renamenx

	/**
	 * Set Redis namespace (prefix) default: resque
	 * @param string $namespace
	 */
	public static function prefix($namespace)
	{
	    if (substr($namespace, -1) !== ':') {
	        $namespace .= ':';
	    }
	    self::$defaultNamespace = $namespace;
	}

	/**
	 * @param string|array $server A DSN or array
	 * @param int $database A database number to select. However, if we find a valid database number in the DSN the
	 *                      DSN-supplied value will be used instead and this parameter is ignored.
	 */
    public function __construct($server, $database = null, $logger = null)
	{
        if (!$logger) {
            $this->logger = new Resque_Log();
        }

		$clusterMode = false;
		if (is_array($server)) {
			$server = $server[0];
			$clusterMode = true;
		}

		list($host, $port, $dsnDatabase, $user, $password, $options) = self::parseDsn($server);
		// $user is not used, only $password

		// Look for known Credis_Client options
		$timeout = isset($options['timeout']) ? (int)$options['timeout'] : null;
		$persistent = isset($options['persistent']) ? filter_var($options['persistent'], \FILTER_VALIDATE_BOOLEAN) : false;
		$tlsOptions = isset($options['tls']) ? $options['tls'] : null;

		if ($clusterMode) {
			//host can be a comma-separated list
			$hosts = array_map(function($host) use ($port) {
				return "$host:$port";
			}, explode(',', $host));
			$this->driver = new RedisCluster(
				null,
				$hosts,
				$timeout,
				0,
				$persistent, //possible because the php-c-extension shares connections between fpm-threads
				array(
					'user' => $user,
					'pass' => $password,
				),
				$tlsOptions
			);
			//No db allowed for RedisCluster
			$database = null;
		} else {
			$this->driver = new Credis_Client(
				$host,
				$port,
				$timeout,
				$persistent ? '1' : '',
				0,
				$password,
				$user,
				$tlsOptions
			);
			// If we have found a database in our DSN, use it instead of the `$database`
			// value passed into the constructor.
			if ($dsnDatabase !== false) {
				$database = $dsnDatabase;
			}
		}

		if ($database !== null) {
			$this->driver->select($database);
		}
	}

	/**
	 * Parse a DSN string, which can have one of the following formats:
	 *
	 * - host:port
	 * - redis://user:pass@host1,host2,hostN:port/db?option1=val1&option2=val2
	 * - redis://user:pass@host:port/db?option1=val1&option2=val2
	 * - tcp://user:pass@host:port/db?option1=val1&option2=val2
	 * - tls://user:pass@host:port/db?option1=val1&option2=val2
	 *
	 * Note: the 'user' part of the DSN is not used.
	 *
	 * @param string $dsn A DSN string
	 * @return array An array of DSN compotnents, with 'false' values for any unknown components. e.g.
	 *               [host, port, db, user, pass, options]
	 */
	public static function parseDsn($dsn)
	{
		if ($dsn == '') {
			// Use a sensible default for an empty DNS string
			$dsn = 'redis://' . self::DEFAULT_HOST;
		}
		$parts = parse_url($dsn);

		// Check the URI scheme
		$validSchemes = array('redis', 'tcp', 'tls');
		if (isset($parts['scheme']) && ! in_array($parts['scheme'], $validSchemes)) {
			throw new \InvalidArgumentException("Invalid DSN. Supported schemes are " . implode(', ', $validSchemes));
		}

		// Allow simple 'hostname' format, which `parse_url` treats as a path, not host.
		if ( ! isset($parts['host']) && isset($parts['path'])) {
			$parts['host'] = $parts['path'];
			unset($parts['path']);
		}

		$host = $parts['host'];
		if ($parts['scheme'] !== 'redis') {
			$host = "{$parts['scheme']}://$host";
		}

		// Extract the port number as an integer
		$port = isset($parts['port']) ? intval($parts['port']) : self::DEFAULT_PORT;

		// Get the database from the 'path' part of the URI
		$database = false;
		if (isset($parts['path'])) {
			// Strip non-digit chars from path
			$database = intval(preg_replace('/[^0-9]/', '', $parts['path']));
		}

		// Extract any 'user' and 'pass' values
		$user = isset($parts['user']) ? $parts['user'] : false;
		$pass = isset($parts['pass']) ? $parts['pass'] : false;

		// Convert the query string into an associative array
		$options = array();
		if (isset($parts['query'])) {
			// Parse the query string into an array
			parse_str($parts['query'], $options);
		}

		return array(
			$host,
			$port,
			$database,
			$user,
			$pass,
			$options,
		);
	}

	/**
	 * Magic method to handle all function requests and prefix key based
	 * operations with the {self::$defaultNamespace} key prefix.
	 *
	 * @param string $name The name of the method called.
	 * @param array $args Array of supplied arguments to the method.
	 * @return mixed Return value from Resident::call() based on the command.
	 */
	public function __call($name, $args)
	{
		if (in_array($name, $this->keyCommands)) {
			if (is_array($args[0])) {
				foreach ($args[0] AS $i => $v) {
					$args[0][$i] = self::$defaultNamespace . $v;
				}
			}
			else {
				$args[0] = self::$defaultNamespace . $args[0];
			}
		}
		try {
			return call_user_func_array([$this->driver, $name], $args);
		} catch (CredisException | RedisClusterException | RedisException $e) {
            $this->logger->error($this->formatErrorAsString($e));
			return false;
		}
	}

    /**
     * @param string $key
     * @return false|string|null
     */
    public function lpop($key)
    {
        return $this->driver->lPop(self::$defaultNamespace . $key);
    }

    /**
     * @param string[] $keys
     * @param int $timeout
     * @return array|null
     */
    public function blpop(array $keys, $timeout)
    {
        foreach ($keys as $i => $key) {
            $keys[$i] = self::$defaultNamespace . $key;
        }
        return $this->driver->blPop($keys, $timeout);
    }

	public static function getPrefix()
	{
	    return self::$defaultNamespace;
	}

	public static function removePrefix($string)
	{
	    $prefix=self::getPrefix();

	    if (substr($string, 0, strlen($prefix)) == $prefix) {
	        $string = substr($string, strlen($prefix), strlen($string) );
	    }
	    return $string;
	}

    /**
     * Inject the logging object into the worker
     *
     * @param Psr\Log\LoggerInterface $logger
     */
    public function setLogger(Psr\Log\LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param Exception $error
     * @return string
     */
    private function formatErrorAsString(Exception $error)
    {
        return sprintf(
            "Uncaught Exception %s: `%s` at %s:%s",
            basename(str_replace('\\', '/', get_class($error))),
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
		) . PHP_EOL . $error->getTraceAsString();
    }
}
