<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Parses the request signature reference embedded in a response signature. */
final class SignatureInputParser
{
    /**
     * Accepts only the supported grammar so the response remains bound to the sent request.
     * @return array{label: string, identifiers: list<string>, created: int, keyId: string, algorithm: ?string}|null
     */
    public static function tryParse(string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        $equals = strpos($value, '=');
        if ($equals === false || $equals <= 0 || !isset($value[$equals + 1]) || $value[$equals + 1] !== '(') {
            return null;
        }
        $label = substr($value, 0, $equals);
        $index = $equals + 2;
        $identifiers = [];
        while ($index < strlen($value) && $value[$index] !== ')') {
            if ($value[$index] === ' ') {
                $index++;
                continue;
            }
            if ($value[$index] !== '"') {
                return null;
            }
            $end = strpos($value, '"', $index + 1);
            if ($end === false) {
                return null;
            }
            $name = substr($value, $index + 1, $end - $index - 1);
            $index = $end + 1;
            $requestBound = false;
            if (isset($value[$index]) && $value[$index] === ';') {
                if (substr($value, $index, 4) !== ';req') {
                    return null;
                }
                $requestBound = true;
                $index += 4;
            }
            $identifiers[] = $requestBound ? $name . ';req' : $name;
        }
        if (!isset($value[$index]) || $value[$index] !== ')') {
            return null;
        }
        $index++;
        $created = null;
        $keyId = null;
        $algorithm = null;
        while ($index < strlen($value)) {
            if ($value[$index] !== ';') {
                return null;
            }
            $index++;
            $nameEnd = strpos($value, '=', $index);
            if ($nameEnd === false) {
                return null;
            }
            $name = substr($value, $index, $nameEnd - $index);
            $index = $nameEnd + 1;
            if (isset($value[$index]) && $value[$index] === '"') {
                $end = strpos($value, '"', $index + 1);
                if ($end === false) {
                    return null;
                }
                $text = substr($value, $index + 1, $end - $index - 1);
                $index = $end + 1;
                if ($name === 'keyid') {
                    $keyId = $text;
                } elseif ($name === 'alg') {
                    $algorithm = $text;
                }
            } else {
                $end = strpos($value, ';', $index);
                if ($end === false) {
                    $end = strlen($value);
                }
                $text = substr($value, $index, $end - $index);
                if ($text === '' || preg_match('/\A[0-9]+\z/D', $text) !== 1) {
                    return null;
                }
                $decimal = ltrim($text, '0');
                $decimal = $decimal === '' ? '0' : $decimal;
                $maximum = (string) PHP_INT_MAX;
                if (strlen($decimal) > strlen($maximum) || (strlen($decimal) === strlen($maximum) && strcmp($decimal, $maximum) > 0)) {
                    return null;
                }
                $index = $end;
                if ($name === 'created') {
                    $created = (int) $decimal;
                }
            }
        }
        if ($created === null || $keyId === null) {
            return null;
        }

        return ['label' => $label, 'identifiers' => $identifiers, 'created' => $created, 'keyId' => $keyId, 'algorithm' => $algorithm];
    }
}
