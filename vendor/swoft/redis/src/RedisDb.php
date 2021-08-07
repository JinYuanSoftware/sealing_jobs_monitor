<?php declare(strict_types=1);
/**
 * This file is part of Swoft.
 *
 * @link     https://swoft.org
 * @document https://swoft.org/docs
 * @contact  group@swoft.org
 * @license  https://github.com/swoft-cloud/swoft/blob/master/LICENSE
 */

namespace Swoft\Redis;

use function bean;
use Swoft\Redis\Connection\Connection;
use Swoft\Redis\Connection\PhpRedisConnection;
use Swoft\Redis\Connector\PhpRedisConnector;
use Swoft\Redis\Contract\ConnectorInterface;
use Swoft\Redis\Exception\RedisException;
use Swoft\Stdlib\Helper\Arr;

/**
 * Class RedisDb
 *
 * @since 2.0
 */
class RedisDb
{
    /**
     * Php redis
     */
    public const PHP_REDIS = 'phpredis';

    /**
     * P redis
     */
    public const P_REDIS = 'predis';

    /**
     * @var string
     */
    private $driver = self::PHP_REDIS;

    /**
     * @var string
     */
    private $host = '127.0.0.1';

    /**
     * @var int
     */
    private $port = 6379;

    /**
     * @var int
     */
    private $database = 0;

    /**
     * @var string
     */
    private $password = '';

    /**
     * @var float
     */
    private $timeout = 0.0;

    /**
     * @var int
     */
    private $retryInterval = 10;

    /**
     * read timeout
     * - float, value in seconds (optional, default is 0 meaning unlimited)
     *
     * @var int
     */
    private $readTimeout = 0;

    /**
     * Set client option.
     *
     * @var array
     *
     * @example
     * [
     *     'serializer ' => Redis::SERIALIZER_PHP/Redis::SERIALIZER_NONE/Redis::SERIALIZER_IGBINARY,
     *     'prefix' => 'xxx',
     *     'tcp_keepalive' => , // see Redis::TCP_KEEPALIVE
     * ]
     */
    private $option = [];

    /**
     * @var array
     *
     * @example
     * [
     *     [
     *         'host' => '127.0.0.1',
     *         'port' => 6379,
     *         'database' => 1,
     *         'password' => 'xxx',
     *         'prefix' => 'xxx',
     *         'read_timeout' => 1,
     *     ],
     *     ...
     * ]
     */
    private $clusters = [];

    /**
     * @var array
     */
    private $connectors = [];

    /**
     * @var array
     */
    protected $connections = [];

    /**
     * @param Pool $pool
     *
     * @return Connection
     * @throws RedisException
     */
    public function createConnection(Pool $pool): Connection
    {
        $connection = $this->getConnection();
        $connection->initialize($pool, $this);
        $connection->create();

        return $connection;
    }

    /**
     * @return ConnectorInterface
     * @throws RedisException
     */
    public function getConnector(): ConnectorInterface
    {
        $connectors = Arr::merge($this->defaultConnectors(), $this->connectors);
        $connector  = $connectors[$this->driver] ?? null;

        if (!$connector instanceof ConnectorInterface) {
            throw new RedisException(sprintf('Connector(driver=%s) is not exist', $this->driver));
        }

        return $connector;
    }

    /**
     * @return Connection
     * @throws RedisException
     */
    public function getConnection(): Connection
    {
        $connections = Arr::merge($this->defaultConnections(), $this->connections);
        $connection  = $connections[$this->driver] ?? null;

        if (!$connection instanceof Connection) {
            throw new RedisException(sprintf('Connection(driver=%s) is not exist', $this->driver));
        }

        return $connection;
    }

    /**
     * @return array
     */
    public function defaultConnectors(): array
    {
        return [
            self::PHP_REDIS => bean(PhpRedisConnector::class)
        ];
    }

    /**
     * @return array
     */
    public function defaultConnections(): array
    {
        return [
            self::PHP_REDIS => bean(PhpRedisConnection::class)
        ];
    }

    /**
     * @return string
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return int
     */
    public function getDatabase(): int
    {
        return $this->database;
    }

    /**
     * @return float
     */
    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /**
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @return int
     */
    public function getRetryInterval(): int
    {
        return $this->retryInterval;
    }

    /**
     * @return int
     */
    public function getReadTimeout(): int
    {
        return $this->readTimeout;
    }

    /**
     * @return array
     */
    public function getOption(): array
    {
        return $this->option;
    }

    /**
     * @return array
     */
    public function getClusters(): array
    {
        return $this->clusters;
    }
}
