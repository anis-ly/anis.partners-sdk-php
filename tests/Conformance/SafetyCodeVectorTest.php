<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Conformance;

use Anis\Partners\Enrollment\KeyThumbprint;
use Anis\Partners\Enrollment\SafetyCode;
use Anis\Partners\Tests\Support\JsonFixture;
use Anis\Partners\Verification\PartnerJwk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SafetyCodeVectorTest extends TestCase
{
    #[Test]
    public function it_counts_all_safety_code_vectors(): void
    {
        self::assertCount(4, self::vectorFiles());
    }

    #[Test]
    public function it_matches_the_manifest_to_vectors_on_disk(): void
    {
        $directory = dirname(__DIR__) . '/vectors/safety-code';
        $manifest = JsonFixture::readObject($directory . '/manifest.json');
        $listed = array_map(
            static fn(array $vector): string => JsonFixture::string($vector, 'id'),
            JsonFixture::objectList($manifest, 'vectors'),
        );
        $onDisk = array_map('basename', self::vectorFiles());
        $onDisk = array_map(static fn(string $file): string => substr($file, 0, -5), $onDisk);
        sort($listed, SORT_STRING);
        sort($onDisk, SORT_STRING);

        self::assertSame($onDisk, $listed);
        self::assertCount(count($onDisk), $onDisk);
        self::assertSame(count($onDisk), JsonFixture::integer($manifest, 'total'));
    }

    /** @return iterable<string, array{string}> */
    public static function vectors(): iterable
    {
        foreach (self::vectorFiles() as $file) {
            $vector = JsonFixture::readObject($file);
            yield JsonFixture::string($vector, 'id') => [$file];
        }
    }

    #[Test]
    #[DataProvider('vectors')]
    public function it_computes_one_safety_code_vector(string $file): void
    {
        $vector = JsonFixture::readObject($file);
        $jwk = PartnerJwk::fromArray(JsonFixture::object($vector['publicJwk'] ?? null));
        $thumbprint = KeyThumbprint::compute($jwk);
        self::assertSame(JsonFixture::string($vector, 'thumbprint'), $thumbprint);
        self::assertSame(JsonFixture::string($vector, 'expectedCode'), SafetyCode::fromThumbprint($thumbprint));
        foreach (JsonFixture::objectList($vector, 'inputs') as $input) {
            self::assertSame(
                JsonFixture::boolean($input, 'matches'),
                SafetyCode::matches(JsonFixture::string($input, 'entered'), $thumbprint),
                JsonFixture::string($input, 'case'),
            );
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function invalidThumbprints(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'malformed' => ['not-a-thumbprint'];
        yield 'padded' => ['AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='];
    }

    #[Test]
    #[DataProvider('invalidThumbprints')]
    public function it_refuses_to_derive_a_code_from_an_invalid_thumbprint(?string $thumbprint): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SafetyCode::fromThumbprint($thumbprint ?? '');
    }

    /** @return list<string> */
    private static function vectorFiles(): array
    {
        $files = glob(dirname(__DIR__) . '/vectors/safety-code/SC-*.json');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
