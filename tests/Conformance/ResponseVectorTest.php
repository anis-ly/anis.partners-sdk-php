<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Conformance;

use Anis\Partners\Tests\Support\FrozenClock;
use Anis\Partners\Tests\Support\JsonFixture;
use Anis\Partners\Tests\Support\StaticSigningKeySource;
use Anis\Partners\Verification\PartnerResponseVerifier;
use Anis\Partners\Verification\ResponseVerificationFailure;
use Anis\Partners\Verification\SigningKeySet;
use Anis\Partners\Verification\UnverifiableResponseException;
use Anis\Partners\Verification\VerifiableResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseVectorTest extends TestCase
{
    #[Test]
    public function it_counts_all_response_vectors(): void
    {
        self::assertCount(39, self::vectorFiles());
    }

    /** @return iterable<string, array{string, string}> */
    public static function vectors(): iterable
    {
        foreach (self::vectorFiles() as $file) {
            $vector = JsonFixture::readObject($file);
            yield JsonFixture::string($vector, 'id') => [JsonFixture::string($vector, 'id'), $file];
        }
    }

    #[Test]
    #[DataProvider('vectors')]
    public function it_reaches_the_declared_outcome_for_a_response_vector(string $id, string $file): void
    {
        $vector = JsonFixture::readObject($file);
        $response = JsonFixture::object($vector['response'] ?? null);
        $request = JsonFixture::object($vector['request'] ?? null);
        $expected = JsonFixture::object($vector['expected'] ?? null);
        $subject = new VerifiableResponse(
            JsonFixture::integer($response, 'status'),
            JsonFixture::stringMap($response, 'headers'),
            JsonFixture::base64(JsonFixture::string($response, 'bodyBase64')),
            JsonFixture::nullableString($request, 'signatureInput'),
        );
        $keys = SigningKeySet::fromJson(JsonFixture::encode(JsonFixture::object($vector['signingKeys'] ?? null)));
        $verifier = new PartnerResponseVerifier(
            new StaticSigningKeySource($keys),
            new FrozenClock(JsonFixture::integer($vector, 'verifyAt')),
        );
        if (JsonFixture::string($expected, 'outcome') === 'accept') {
            $this->expectNotToPerformAssertions();
            $verifier->verify($subject);

            return;
        }

        $reason = JsonFixture::string($expected, 'reason');
        try {
            $verifier->verify($subject);
            self::fail("Response vector {$id} expected rejection reason {$reason}, but was accepted.");
        } catch (UnverifiableResponseException $error) {
            self::assertSame(
                ResponseVerificationFailure::from($reason),
                $error->failure(),
                "Response vector {$id} expected rejection reason {$reason}.",
            );
        }
    }

    /** @return list<string> */
    private static function vectorFiles(): array
    {
        $files = glob(dirname(__DIR__) . '/vectors/response/RS-*.json');
        if ($files === false) {
            return [];
        }
        sort($files, SORT_STRING);

        return $files;
    }
}
