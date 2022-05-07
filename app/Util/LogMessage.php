<?php

namespace RaiseNowConnector\Util;

class LogMessage
{
    private array $parts;

    public function __construct(string $msg, array $additionalParts = [])
    {
        $this->parts = $additionalParts;
        $this->parts['msg'] = $msg;
        $this->parts['config'] = Config::name();
    }

    private static function escapeQuotes(string $string): string
    {
        return preg_replace('/(?<!\\\\)(\\\\\\\\)*"/', '\1\"', $string);
    }

    public function __toString(): string
    {
        $parts = array_map([self::class, 'escapeQuotes'], $this->parts);

        // it's handy to always have the same order and the msg always last
        ksort($parts);
        $msg = $parts['msg'];
        unset($parts['msg']);

        $line = '';
        foreach ($this->parts as $k => $v) {
            $line .= "$k=\"$v\" ";
        }

        return "{$line}msg=\"$msg\"";
    }
}