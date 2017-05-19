<?php
/**
 * Created by IntelliJ IDEA.
 * User: winglechen
 * Date: 16/4/5
 * Time: 11:59
 */

namespace Zan\Framework\Network\Connection;


use Zan\Framework\Foundation\Core\Config;
use Zan\Framework\Network\Common\DnsClient;
use Zan\Framework\Network\Connection\Factory\KVStore;
use Zan\Framework\Network\Connection\Factory\NovaClient;
use Zan\Framework\Network\Connection\Factory\Redis;
use Zan\Framework\Network\Connection\Factory\Syslog;
use Zan\Framework\Network\Connection\Factory\Tcp;
use Zan\Framework\Network\Server\Timer\Timer;
use Zan\Framework\Utilities\DesignPattern\Singleton;
use Zan\Framework\Network\Connection\Factory\Http;
use Zan\Framework\Network\Connection\Factory\Mysqli;
use Zan\Framework\Network\Connection\Factory\Mysql;


class ConnectionInitiator
{
    use Singleton;

    const CONNECT_TIMEOUT = 1000;
    const CONCURRENCY_CONNECTION_LIMIT = 50;

    private $engineMap = [
        'mysqli', 
        'http', 
        'redis', 
        'syslog', 
        'novaClient',
        'kVStore',
        'es',
        'tcp',
    ];

    public $directory = '';

    public $poolName='';

    public function __construct()
    {
    }

    /**
     * @param $directory
     */
    public function init($directory, $server)
    {
        if(!empty($directory)) {
            $this->directory = $directory;
            $config = Config::get($this->directory);
            $this->initConfig($config);
        }
        $connectionManager = ConnectionManager::getInstance();
        $connectionManager->setServer($server);
        $connectionManager->monitor();
        ReconnectionPloy::getInstance()->init();
        $connectionManager->monitorConnectionNum();
    }

    private function initConfig($config)
    {
        if (!is_array($config)) {
            return;
        }
        foreach ($config as $k=>$cf) {
            if (!isset($cf['engine'])) {
                $poolName = $this->poolName;
                $this->poolName = '' === $this->poolName ? $k : $this->poolName . '.' . $k;
                $this->initConfig($cf);
                $this->poolName = $poolName;
                continue;
            }
            if (!isset($cf['pool']) || empty($cf['pool'])) {
                $this->poolName = '';
                continue;
            }

            $this->fixConfig($cf);

            //创建连接池
            $dir = $this->poolName;
            $this->poolName = '' === $this->poolName ? $k : $this->poolName . '.' . $k;
            $factoryType = $cf['engine'];
            if (in_array($factoryType, $this->engineMap)) {
                $factoryType = ucfirst($factoryType);
                $cf['pool']['pool_name'] = $this->poolName;
                if (!filter_var($cf['host'], FILTER_VALIDATE_IP) && isset($cf['host']) && !isset($cf["path"])) {
                    $poolName = $this->poolName;
                    $this->host2Ip($cf, $poolName, $factoryType);
                } else {
                    $this->initPool($factoryType, $cf);
                }

                $fileConfigKeys = array_keys($config);
                $endKey = end($fileConfigKeys);
                $this->poolName = $k == $endKey ? '' : $dir;
            }
        }
    }

    private function host2Ip($cf, $poolName, $factoryType)
    {
        DnsClient::lookup($cf['host'], function ($host, $ip) use ($cf, $poolName, $factoryType) {
            if (empty($ip)) {
                sys_error("dns look up failed: ".$cf['host']);
                Timer::after(500, function() use ($cf, $poolName, $factoryType) {
                    $this->host2Ip($cf, $poolName, $factoryType);
                });
            } else {
                $cf['host'] = $ip;
                $cf['pool']['pool_name'] = $poolName;
                $this->initPool($factoryType, $cf);
            }
        }, function () use ($cf, $poolName, $factoryType) {
            sys_error("dns look up failed: ".$cf['host']);
            Timer::after(500, function() use (&$cf, $poolName, $factoryType) {
                $this->host2Ip($cf, $poolName, $factoryType);
            });
        });
    }

    private function fixConfig(array &$config)
    {
        if (!isset($config['connect_timeout'])) {
            $config['connect_timeout'] = self::CONNECT_TIMEOUT;
        } else {
            $config['connect_timeout'] = intval($config['connect_timeout']);
        }
        if (!isset($config['pool']['maximum-wait-connection'])) {
            $config['pool']['maximum-wait-connection'] = self::CONCURRENCY_CONNECTION_LIMIT;
        } else {
            $config['pool']['maximum-wait-connection'] = intval($config['pool']['maximum-wait-connection']);
        }
    }

    /**
     * @param $factoryType
     * @param $config
     */
    private function initPool($factoryType, $config)
    {
        switch ($factoryType) {
            case 'Redis':
                $factory = new Redis($config);
                break;
            case 'Syslog':
                $factory = new Syslog($config);
                break;
            case 'Http':
                $factory = new Http($config);
                break;
            case 'Mysqli':
                if (swoole2x()) {
                    $factory = new Mysql($config);
                } else {
                    $factory = new Mysqli($config);
                }
                break;
            case 'NovaClient':
                $factory = new NovaClient($config);
                break;
            case 'KVStore':
                $factory = new KVStore($config);
                break;
            case 'Tcp':
                $factory = new Tcp($config);
                break;
            default:
                throw new \RuntimeException("not support connection type: $factoryType");
        }
        $connectionPool = new Pool($factory, $config, $factoryType);
        ConnectionManager::getInstance()->addPool($config['pool']['pool_name'], $connectionPool);
    }
}
