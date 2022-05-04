<?php

namespace RaiseNowConnector\Util;

use RaiseNowConnector\Exception\ConfigException;

final class Auth
{
    public static function webhookSecretIsValid(): bool
    {
        $givenSecret = self::getWebhookSecretFromUrl();

        if ( ! $givenSecret) {
            return false;
        }

        try {
            $expectedSecret = Config::get('webhookSecret');
        } catch (ConfigException $e) {
            Logger::debug(new LogMessage($e));

            return false;
        }

        return hash_equals($expectedSecret, $givenSecret);
    }

    private static function getWebhookSecretFromUrl(): ?string
    {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = trim($path, '/');

        $parts = explode('/', $path);

        return $parts[1] ?? null;
    }
}