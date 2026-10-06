<?php

declare(strict_types=1);

namespace Anis\Partners\Tests;

use Anis\Partners\AcceptLanguage;
use Anis\Partners\ClientOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientOptionsTest extends TestCase
{
    #[Test]
    public function it_binds_camel_case_partner_settings(): void
    {
        $options = ClientOptions::fromArray(['authority' => 'https://partners.example', 'signatureLifetimeSeconds' => 30, 'acceptLanguage' => 'Arabic', 'signingKeyCacheSeconds' => 90]);

        self::assertSame('https://partners.example', $options->authority);
        self::assertSame(30, $options->signatureLifetimeSeconds);
        self::assertSame(AcceptLanguage::Arabic, $options->acceptLanguage);
        self::assertSame('ar', $options->acceptLanguageHeader());
        self::assertSame(90, $options->signingKeyCacheSeconds);
        self::assertSame('default', $options->name);
    }

    #[Test]
    public function it_uses_safe_defaults_and_allows_explicit_settings_to_override_them(): void
    {
        $defaults = ClientOptions::fromArray(['authority' => 'https://partners.example']);
        $adjusted = ClientOptions::fromArray(['authority' => 'http://localhost:8000', 'signatureLifetimeSeconds' => 1, 'acceptLanguage' => 'English', 'name' => 'orders-worker']);

        self::assertSame(60, $defaults->signatureLifetimeSeconds);
        self::assertSame(600, $defaults->signingKeyCacheSeconds);
        self::assertNull($defaults->acceptLanguageHeader());
        self::assertSame('en', $adjusted->acceptLanguageHeader());
        self::assertSame('orders-worker', $adjusted->name);
    }

    #[Test]
    public function it_refuses_an_invalid_authority_or_lifetime_before_client_creation(): void
    {
        try {
            new ClientOptions('/relative');
            self::fail('A relative URL cannot identify an Anis authority.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('absolute authority', $exception->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        new ClientOptions('https://partners.example', 61);
    }

    #[Test]
    public function it_requires_https_except_for_loopback_authorities(): void
    {
        try {
            new ClientOptions('http://partners.example');
            self::fail('Non-loopback HTTP can expose credentials and allow key replacement.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('require HTTPS', $exception->getMessage());
        }

        self::assertSame('http://localhost:8000', (new ClientOptions('http://localhost:8000'))->authority);
        self::assertSame('http://[::1]:8000', (new ClientOptions('http://[::1]:8000'))->authority);
    }

    #[Test]
    public function it_refuses_an_authority_with_port_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valid port from 1 through 65535');
        new ClientOptions('https://partners.example:0');
    }

    #[Test]
    public function it_refuses_settings_that_the_php_client_cannot_apply(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ClientOptions::fromArray does not support setting $timeout');
        ClientOptions::fromArray(['authority' => 'https://partners.example', 'timeout' => 30]);
    }

    #[Test]
    public function it_names_the_php_class_and_parameter_in_validation_messages(): void
    {
        foreach ([
            [static fn() => new ClientOptions('/relative'), '$authority'],
            [static fn() => new ClientOptions('https://partners.example', 61), '$signatureLifetimeSeconds'],
            [static fn() => new ClientOptions('https://partners.example', signingKeyCacheSeconds: 0), '$signingKeyCacheSeconds'],
        ] as [$construct, $parameter]) {
            try {
                $construct();
                self::fail('Invalid client options must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('ClientOptions', $exception->getMessage());
                self::assertStringContainsString($parameter, $exception->getMessage());
            }
        }
    }
}
