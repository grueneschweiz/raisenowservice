<?php declare(strict_types=1);

namespace RaiseNowConnector\Util;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

class ClientFactory
{
    private static array $mockHandlers = [];

    public static function create(array $options = []): ClientInterface
    {
        if ( ! empty(self::$mockHandlers)) {
            $mock               = array_shift(self::$mockHandlers);
            $options['handler'] = HandlerStack::create($mock);
        }

        return new Client($options);
    }

    public static function queueMockHandler(MockHandler $mock): void
    {
        self::$mockHandlers[] = $mock;
    }
}