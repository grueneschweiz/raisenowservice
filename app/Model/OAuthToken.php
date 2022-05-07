<?php
declare(strict_types=1);

namespace RaiseNowConnector\Model;

use AESGCM\AESGCM;
use DateTimeImmutable;
use JsonException;
use RaiseNowConnector\Exception\ConfigException;
use RaiseNowConnector\Exception\OAuthTokenException;
use RaiseNowConnector\Exception\PersistanceException;
use RaiseNowConnector\Util\Config;
use RaiseNowConnector\Util\Persistable;

class OAuthToken extends Persistable
{
    private const STORAGE_DIR = BASE_PATH . '/storage/app/tokens';

    /**
     * @throws OAuthTokenException
     */
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public string $refreshToken,
        public string $scope,
        public ?DateTimeImmutable $renewAt = null,
        ?int $expiresIn = null
    ) {
        if (!$expiresIn && !$this->renewAt) {
            throw new OAuthTokenException('Either the parameter $expiresIn or $renewAt must be given.');
        }

        if ($expiresIn) {
            $renewTimestamp = (int)(time() + ceil($expiresIn / 2));
            $this->renewAt = date_create_immutable_from_format('U', (string)$renewTimestamp);
        }

        if (!$this->renewAt) {
            throw new OAuthTokenException('Failed to create $renewAt date.');
        }
    }

    protected static function getStoragePath(string $identifier): string
    {
        return self::STORAGE_DIR . DIRECTORY_SEPARATOR . $identifier . '.json.enc';
    }

    /**
     * @throws ConfigException
     * @throws PersistanceException
     */
    protected static function fromString(string $serialized): Persistable
    {
        $decrypted = self::decrypt($serialized);

        if (!$decrypted) {
            $className = static::class;
            throw new PersistanceException("Can't load $className. Failed to decrypt serialized object.");
        }

        return self::fromJson($decrypted);
    }

    /**
     * @throws ConfigException
     */
    private static function decrypt(string $data): string|false
    {
        return AESGCM::decrypt($data, Config::get('tokenEncryptionKey'));
    }

    /**
     * @throws OAuthTokenException
     */
    private static function fromJson($json): self
    {
        try {
            $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OAuthTokenException("Failed to deserialize OAuthTokenException JSON: {$e->getMessage()}");
        }

        return new OAuthToken(
            $data->accessToken,
            $data->tokenType,
            $data->refreshToken,
            $data->scope,
            date_create_immutable($data->renewAt)
        );
    }

    public function dueForRenewal(): bool
    {
        return $this->renewAt < date_create_immutable();
    }

    public function getToken(): string
    {
        return "$this->tokenType $this->accessToken";
    }

    /**
     * @throws OAuthTokenException
     * @throws ConfigException
     */
    public function __toString(): string
    {
        return self::encrypt($this->asJson());
    }

    /**
     * @throws ConfigException
     */
    private static function encrypt(string $plaintext): string
    {
        return AESGCM::encrypt($plaintext, Config::get('tokenEncryptionKey'));
    }

    /**
     * @throws OAuthTokenException
     */
    private function asJson(): string
    {
        try {
            $json = json_encode([
                'accessToken' => $this->accessToken,
                'tokenType' => $this->tokenType,
                'refreshToken' => $this->refreshToken,
                'scope' => $this->scope,
                'renewAt' => $this->renewAt->format(DATE_ATOM),
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new OAuthTokenException("Failed to serialize OAuthToken as JSON: {$e->getMessage()}");
        }

        return $json;
    }
}