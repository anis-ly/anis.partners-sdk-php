<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Internal;

use Anis\Partners\Internal\Base64Url;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Base64UrlTest extends TestCase
{
    #[Test]
    public function it_refuses_noncanonical_base64url_padding_bits(): void
    {
        self::assertNull(Base64Url::decode('AB'));
        self::assertSame("\0", Base64Url::decode('AA'));
    }
}
