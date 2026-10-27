<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Parses the Signature-Input and Signature header values of a webhook with the one grammar the profile uses. */
final class WebhookSignatureInputParser
{
    /**
     * Reads a single `label=(components);params` member; several members, repeated parameters or other syntax return null.
     *
     * @return array{label: string, components: list<string>, parameters: string, created: ?int, expires: ?int, keyId: ?string, algorithm: ?string, tag: ?string}|null
     */
    public static function tryParse(string $value): ?array
    {
        if (preg_match('/\A([A-Za-z][A-Za-z0-9_-]*)=(\((?:"[^"\\\;]*"(?: "[^"\\\;]*")*)?\))((?:;[a-z]+=(?:[0-9]+|"[^"\\\\]*"))*)\z/D', $value, $match) !== 1) {
            return null;
        }
        preg_match_all('/"([^"]*)"/', $match[2], $names);
        $created = null;
        $expires = null;
        $keyId = null;
        $algorithm = null;
        $tag = null;
        $seen = [];
        preg_match_all('/;([a-z]+)=([0-9]+|"[^"]*")/', $match[3], $parameters, PREG_SET_ORDER);
        foreach ($parameters as $parameter) {
            $name = $parameter[1];
            if (isset($seen[$name])) {
                return null;
            }
            $seen[$name] = true;
            $text = $parameter[2];
            $quoted = $text[0] === '"';
            $content = $quoted ? substr($text, 1, -1) : $text;
            switch ($name) {
                case 'created':
                case 'expires':
                    $number = self::number($quoted, $content);
                    if ($number === null) {
                        return null;
                    }
                    if ($name === 'created') {
                        $created = $number;
                    } else {
                        $expires = $number;
                    }
                    break;
                case 'keyid':
                case 'alg':
                case 'tag':
                    if (!$quoted) {
                        return null;
                    }
                    if ($name === 'keyid') {
                        $keyId = $content;
                    } elseif ($name === 'alg') {
                        $algorithm = $content;
                    } else {
                        $tag = $content;
                    }
                    break;
                default:
                    return null;
            }
        }

        return [
            'label' => $match[1],
            'components' => $names[1],
            'parameters' => $match[2] . $match[3],
            'created' => $created,
            'expires' => $expires,
            'keyId' => $keyId,
            'algorithm' => $algorithm,
            'tag' => $tag,
        ];
    }

    /**
     * Reads a Signature header value `label=:base64:` into its label and base64 text, or null for any other shape.
     *
     * @return array{label: string, base64: string}|null
     */
    public static function tryParseSignature(string $value): ?array
    {
        if (preg_match('/\A([A-Za-z][A-Za-z0-9_-]*)=:([A-Za-z0-9+\/=]*):\z/D', $value, $match) !== 1) {
            return null;
        }

        return ['label' => $match[1], 'base64' => $match[2]];
    }

    private static function number(bool $quoted, string $content): ?int
    {
        if ($quoted || $content === '') {
            return null;
        }
        $decimal = ltrim($content, '0');
        $decimal = $decimal === '' ? '0' : $decimal;
        $maximum = (string) PHP_INT_MAX;
        if (strlen($decimal) > strlen($maximum) || (strlen($decimal) === strlen($maximum) && strcmp($decimal, $maximum) > 0)) {
            return null;
        }

        return (int) $decimal;
    }
}
