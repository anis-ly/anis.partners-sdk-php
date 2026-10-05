<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

/** Converts between OpenSSL's DER signatures and the fixed-width P1363 bytes the wire carries. */
final class EcdsaSignatureFormat
{
    /** Strictly reads two positive P-256 integers so malformed encodings cannot reach a signature header. */
    public static function derToP1363(string $der): string
    {
        $offset = 0;
        if (self::readByte($der, $offset) !== 0x30) {
            throw new \InvalidArgumentException('ECDSA signature must be a DER SEQUENCE.');
        }
        $sequenceLength = self::readLength($der, $offset);
        if ($sequenceLength !== strlen($der) - $offset) {
            throw new \InvalidArgumentException('ECDSA signature has an invalid or trailing DER length.');
        }

        $r = self::readInteger($der, $offset);
        $s = self::readInteger($der, $offset);
        if ($offset !== strlen($der)) {
            throw new \InvalidArgumentException('ECDSA signature has trailing DER data.');
        }

        return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
    }

    /** Encodes fixed-width r and s with minimal DER integers for OpenSSL verification. */
    public static function p1363ToDer(string $p1363): string
    {
        if (strlen($p1363) !== 64) {
            throw new \InvalidArgumentException('A P-256 P1363 signature must be exactly 64 bytes.');
        }

        $r = self::integer(substr($p1363, 0, 32));
        $s = self::integer(substr($p1363, 32, 32));
        $content = $r . $s;

        return "\x30" . self::length(strlen($content)) . $content;
    }

    private static function readInteger(string $der, int &$offset): string
    {
        if (self::readByte($der, $offset) !== 0x02) {
            throw new \InvalidArgumentException('ECDSA DER SEQUENCE must contain two INTEGERs.');
        }
        $length = self::readLength($der, $offset);
        if ($length === 0 || $offset + $length > strlen($der)) {
            throw new \InvalidArgumentException('ECDSA DER INTEGER length is invalid.');
        }
        $value = substr($der, $offset, $length);
        $offset += $length;
        if ((ord($value[0]) & 0x80) !== 0) {
            throw new \InvalidArgumentException('ECDSA DER INTEGER must be positive.');
        }
        if (strlen($value) > 1 && $value[0] === "\0" && (ord($value[1]) & 0x80) === 0) {
            throw new \InvalidArgumentException('ECDSA DER INTEGER is not minimally encoded.');
        }
        if ($value[0] === "\0") {
            $value = substr($value, 1);
        }
        if ($value === '' || strlen($value) > 32) {
            throw new \InvalidArgumentException('ECDSA DER INTEGER must be positive and no longer than 32 bytes.');
        }

        return str_pad($value, 32, "\0", STR_PAD_LEFT);
    }

    private static function readLength(string $der, int &$offset): int
    {
        $first = self::readByte($der, $offset);
        if (($first & 0x80) === 0) {
            return $first;
        }
        $count = $first & 0x7f;
        if ($count === 0 || $count > 4 || $offset + $count > strlen($der)) {
            throw new \InvalidArgumentException('ECDSA DER length is invalid.');
        }
        if ($der[$offset] === "\0") {
            throw new \InvalidArgumentException('ECDSA DER length is not minimally encoded.');
        }
        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | self::readByte($der, $offset);
        }
        if ($length < 128) {
            throw new \InvalidArgumentException('ECDSA DER length is not minimally encoded.');
        }

        return $length;
    }

    private static function readByte(string $value, int &$offset): int
    {
        if ($offset >= strlen($value)) {
            throw new \InvalidArgumentException('ECDSA DER value is truncated.');
        }

        return ord($value[$offset++]);
    }

    private static function integer(string $value): string
    {
        $value = ltrim($value, "\0");
        if ($value === '') {
            $value = "\0";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\0" . $value;
        }

        return "\x02" . self::length(strlen($value)) . $value;
    }

    private static function length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
