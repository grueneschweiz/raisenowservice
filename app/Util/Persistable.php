<?php declare(strict_types=1);

namespace RaiseNowConnector\Util;

use RaiseNowConnector\Exception\PersistanceException;

abstract class Persistable
{
    abstract protected static function getStoragePath(string $identifier): string;

    abstract protected static function fromString(string $serialized): Persistable;

    abstract public function __toString(): string;

    /**
     * @throws PersistanceException
     */
    public static function loadFromFile(string $identifier): static
    {
        $filePath = static::getStoragePath($identifier);

        if ( ! file_exists($filePath)) {
            $className = static::class;
            throw new PersistanceException("Can't load $className. File does not exist: $filePath");
        }

        $content = file_get_contents($filePath);

        if (false === $content) {
            $className = static::class;
            throw new PersistanceException("Can't load $className. File not readable: $filePath");
        }

        return static::fromString($content);
    }

    /**
     * @throws PersistanceException
     */
    public function persist(string $identifier): void
    {
        $filePath  = static::getStoragePath($identifier);
        $content   = (string) $this;
        $className = static::class;

        if ((file_exists($filePath) && ! is_writable($filePath)) || ! is_writable(dirname($filePath))) {
            throw new PersistanceException("Can't persist $className. File not writeable: $filePath");
        }

        if ( ! $fileHandler = fopen($filePath, 'wb')) {
            throw new PersistanceException("Can't persist $className. Cannot open file: $filePath");
        }

        if (false === fwrite($fileHandler, $content)) {
            throw new PersistanceException("Can't persist $className. Cannot write to file: $filePath");
        }

        fclose($fileHandler);
    }
}