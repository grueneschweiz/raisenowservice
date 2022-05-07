<?php
declare(strict_types=1);

namespace RaiseNowConnector\Util;

use RaiseNowConnector\Exception\ConfigException;

final class Config
{
    private static ?Config $instance;
    private static string $configName;
    private array $config;

    /**
     * @throws ConfigException
     */
    private function __construct()
    {
        $config = include BASE_PATH . '/config.php';

        $configName = self::name();

        if (preg_match('/[^a-zA-Z\d.-]/', $configName)) {
            throw new ConfigException("Invalid url path called: $configName");
        }

        if (!array_key_exists($configName, $config)) {
            throw new ConfigException("No config for url path: $configName");
        }

        $this->config = $config[$configName];
    }

    public static function name(): string
    {
        if (empty(self::$configName)) {
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $path = trim($path, '/');

            self::$configName = explode('/', $path)[0];
        }

        return self::$configName;
    }

    /**
     * @throws ConfigException
     */
    public static function get(string $key)
    {
        $instance = self::getInstance();

        if (!array_key_exists($key, $instance->config)) {
            throw new ConfigException("Config has no key called $key.");
        }

        return $instance->config[$key];
    }

    /**
     * @throws ConfigException
     * @noinspection PhpDocRedundantThrowsInspection
     */
    private static function getInstance(): Config
    {
        if (empty(self::$instance)) {
            self::$instance = new Config();
        }

        return self::$instance;
    }

    public static function exists(): bool
    {
        try {
            self::getInstance();
        } catch (ConfigException) {
            return false;
        }

        return true;
    }
}