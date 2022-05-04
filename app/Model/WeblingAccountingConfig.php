<?php declare(strict_types=1);

namespace RaiseNowConnector\Model;

use JsonException;
use RaiseNowConnector\Exception\WeblingAccountingConfigException;
use RaiseNowConnector\Util\Persistable;

class WeblingAccountingConfig extends Persistable
{
    private const STORAGE_DIR = BASE_PATH.'/storage/app/cache';

    public function __construct(
        public int $periodId,
        public int $donationAccountId,
        public int $debtorAccountId,
        public int $bankAccountId,
    ) {
    }

    protected static function getStoragePath(string $identifier): string
    {
        return self::STORAGE_DIR.DIRECTORY_SEPARATOR.$identifier.'.json';
    }

    /**
     * @throws WeblingAccountingConfigException
     */
    public function __toString(): string
    {
        try {
            $json = json_encode([
                'periodId'          => $this->periodId,
                'donationAccountId' => $this->donationAccountId,
                'debtorAccountId'   => $this->debtorAccountId,
                'bankAccountId'     => $this->bankAccountId
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $e) {
            throw new WeblingAccountingConfigException("Failed to serialize WeblingAccountingConfig as JSON: {$e->getMessage()}");
        }

        return $json;
    }

    /**
     * @throws WeblingAccountingConfigException
     */
    protected static function fromString(string $serialized): Persistable
    {
        try {
            $data = json_decode($serialized, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new WeblingAccountingConfigException("Failed to deserialize WeblingAccountingConfig JSON: {$e->getMessage()}");
        }

        return new WeblingAccountingConfig(
            $data->periodId,
            $data->donationAccountId,
            $data->debtorAccountId,
            $data->bankAccountId
        );
    }
}