<?php

declare(strict_types=1);

namespace Anis\Partners;

/** Holds only the authority and safe protocol settings needed to reach Anis. */
final readonly class ClientOptions
{
    /** Validates settings before a client can send a signed request. */
    public function __construct(
        public string $authority,
        public int $signatureLifetimeSeconds = 60,
        public AcceptLanguage $acceptLanguage = AcceptLanguage::Unspecified,
        public int $signingKeyCacheSeconds = 600,
    ) {
        $parts = parse_url($authority);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new \InvalidArgumentException('ClientOptions::$authority must be the absolute authority Anis issued, e.g. https://partners.example.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !$isLoopback) {
            throw new \InvalidArgumentException('Anis partner connections require HTTPS because the unsigned signing-key document could otherwise be replaced in transit and card codes could be read. HTTP is allowed only for loopback testing.');
        }
        if ($signatureLifetimeSeconds < 1 || $signatureLifetimeSeconds > 60) {
            throw new \InvalidArgumentException('ClientOptions::$signatureLifetimeSeconds must be between 1 and 60 seconds. Anis would admit up to 300, but this SDK accepts answers only within 60 seconds: with a longer signature and a slow clock an order can complete and its answer — with the card codes — be discarded.');
        }
        if ($signingKeyCacheSeconds <= 0) {
            throw new \InvalidArgumentException('ClientOptions::$signingKeyCacheSeconds must be positive.');
        }
    }

    /** Reads the camel-case AnisPartners section used by host settings files. */
    /** @param array<string, mixed> $settings */
    public static function fromArray(array $settings): self
    {
        $authority = $settings['authority'] ?? null;
        if (!is_string($authority)) {
            throw new \InvalidArgumentException('ClientOptions::$authority must be the absolute authority Anis issued, e.g. https://partners.example.');
        }
        $language = AcceptLanguage::Unspecified;
        if (isset($settings['acceptLanguage'])) {
            if (!is_string($settings['acceptLanguage'])) {
                throw new \InvalidArgumentException('AcceptLanguage must be Unspecified, Arabic, or English.');
            }
            $language = match (strtolower($settings['acceptLanguage'])) {
                'arabic' => AcceptLanguage::Arabic,
                'english' => AcceptLanguage::English,
                'unspecified', '' => AcceptLanguage::Unspecified,
                default => throw new \InvalidArgumentException('AcceptLanguage must be Unspecified, Arabic, or English.'),
            };
        }
        $lifetime = $settings['signatureLifetimeSeconds'] ?? 60;
        $cacheSeconds = $settings['signingKeyCacheSeconds'] ?? 600;
        if (!is_int($lifetime) || !is_int($cacheSeconds)) {
            throw new \InvalidArgumentException('ClientOptions::$signatureLifetimeSeconds and ClientOptions::$signingKeyCacheSeconds must be whole seconds.');
        }

        return new self($authority, $lifetime, $language, $cacheSeconds);
    }

    /** Returns the header value or null when the host has no presentation preference. */
    public function acceptLanguageHeader(): ?string
    {
        return match ($this->acceptLanguage) {
            AcceptLanguage::Arabic => 'ar',
            AcceptLanguage::English => 'en',
            AcceptLanguage::Unspecified => null,
        };
    }
}
