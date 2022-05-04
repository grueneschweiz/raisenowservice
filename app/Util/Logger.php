<?php declare(strict_types=1);

namespace RaiseNowConnector\Util;

use Monolog\Handler\StreamHandler;
use Monolog\Logger as Monolog;
use RaiseNowConnector\Exception\ConfigException;

final class Logger
{
    private static Logger $instance;
    private Monolog $logger;

    private function __construct()
    {
        $logFilePath = BASE_PATH.'/storage/logs/app.log';

        $this->logger = new Monolog('logger');
        $this->logger->pushHandler(new StreamHandler($logFilePath, $this->getLogLevel()));
    }

    private function getLogLevel(): int
    {
        try {
            $configuredLevel = Config::get('logLevel');
        } catch (ConfigException) {
            $configuredLevel = 'DEBUG';
        }

        $logLevel = constant('\Monolog\Logger::'.$configuredLevel);

        if (is_int($logLevel)) {
            return $logLevel;
        }

        return Monolog::DEBUG;
    }

    private static function getInstance(): Logger
    {
        if ( ! isset(self::$instance)) {
            self::$instance = new Logger();
        }

        return self::$instance;
    }

    public static function emergency(LogMessage $message, array $context = []): void
    {
        self::getInstance()->logger->emergency($message, $context);
    }

    public static function alert(LogMessage $message, array $context = []): void
    {
        self::getInstance()->logger->alert($message, $context);
    }

    public static function critical(LogMessage $message, array $context = []): void
    {
        self::getInstance()->logger->critical($message, $context);
    }

    public static function error(LogMessage $message, array $context = []): void
    {
        self::getInstance()->logger->error($message, $context);
    }

    public static function warning(LogMessage $message, array $context = []): void
    {
        self::getInstance()->logger->warning($message, $context);
    }

    public static function notice(LogMessage $message, array $context = []): void
    {
        self::getInstance()->logger->notice($message, $context);
    }

    public static function info(LogMessage $message, array $context = []): void
    {
        self::getInstance()->logger->info($message, $context);
    }

    public static function debug(LogMessage $message, array $context = []): void
    {
        self::getInstance()->logger->debug($message, $context);
    }

    public static function log($level, LogMessage $message, array $context = []): void
    {
        self::getInstance()->logger->log($level, $message, $context);
    }
}