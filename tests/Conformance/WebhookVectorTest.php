<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Conformance;

use Anis\Partners\Models\WebhookEventType;
use Anis\Partners\Tests\Support\FrozenClock;
use Anis\Partners\Tests\Support\JsonFixture;
use Anis\Partners\Tests\Support\StaticSigningKeySource;
use Anis\Partners\Tests\Support\VectorFiles;
use Anis\Partners\Verification\PartnerWebhookVerifier;
use Anis\Partners\Verification\SigningKeySet;
use Anis\Partners\Verification\UnverifiableWebhookException;
use Anis\Partners\Verification\VerifiableWebhook;
use Anis\Partners\Verification\WebhookVerificationFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Runs the shared webhook corpus, which is vendored byte for byte from the conformance repository. */
final class WebhookVectorTest extends TestCase
{
    private const DIRECTORY = __DIR__ . '/../vectors/webhook';
    private const CORPUS_SHA256 = 'a598d6a274516bc28e4afadf40978048a99bea0f604d84140229494739fc3cfa';

    /** @return list<string> */
    private static function vectorFiles(): array
    {
        return VectorFiles::read(self::DIRECTORY, 'WH-*.json');
    }

    #[Test]
    public function it_matches_the_manifest_counts_and_every_case_entry(): void
    {
        $manifest = JsonFixture::readObject(self::DIRECTORY . '/manifest.json');
        $files = self::vectorFiles();
        self::assertNotEmpty($files);
        self::assertSame('anis-partner-webhook-v1', JsonFixture::string($manifest, 'profile'));
        self::assertSame(300, JsonFixture::integer($manifest, 'lifetimeSeconds'));
        $accept = 0;
        $reject = 0;
        $byId = [];
        foreach (JsonFixture::objectList($manifest, 'vectors') as $entry) {
            $byId[JsonFixture::string($entry, 'id')] = $entry;
        }
        foreach ($files as $file) {
            $vector = JsonFixture::readObject($file);
            $expected = JsonFixture::object($vector['expected'] ?? null);
            $entry = $byId[JsonFixture::string($vector, 'id')] ?? self::fail('A vector file is missing from the manifest.');
            self::assertSame(JsonFixture::string($expected, 'outcome'), JsonFixture::string($entry, 'outcome'));
            self::assertSame(JsonFixture::nullableString($expected, 'reason'), JsonFixture::nullableString($entry, 'reason'));
            if (JsonFixture::string($expected, 'outcome') === 'accept') {
                $accept++;
            } else {
                $reject++;
            }
        }
        self::assertSame(JsonFixture::integer($manifest, 'total'), count($files));
        self::assertSame(JsonFixture::integer($manifest, 'accept'), $accept);
        self::assertSame(JsonFixture::integer($manifest, 'reject'), $reject);
        self::assertSame(count($byId), count($files));
    }

    /** @return iterable<string, array{string, string}> */
    public static function vectors(): iterable
    {
        $files = self::vectorFiles();
        if ($files === []) {
            throw new \UnexpectedValueException('The webhook corpus is empty.');
        }
        foreach ($files as $file) {
            $vector = JsonFixture::readObject($file);
            yield JsonFixture::string($vector, 'id') => [JsonFixture::string($vector, 'id'), $file];
        }
    }

    #[Test]
    #[DataProvider('vectors')]
    public function it_reaches_the_declared_outcome_for_a_webhook_vector(string $id, string $file): void
    {
        $vector = JsonFixture::readObject($file);
        $receiver = JsonFixture::object($vector['receiver'] ?? null);
        $request = JsonFixture::object($vector['request'] ?? null);
        $expected = JsonFixture::object($vector['expected'] ?? null);
        $headers = [];
        foreach (JsonFixture::object($request['headers'] ?? null) as $name => $value) {
            $headers[$name] = is_string($value) ? $value : self::fail("Vector {$id} has a non-string header.");
        }
        $webhook = new VerifiableWebhook(
            JsonFixture::string($request, 'method'),
            JsonFixture::string($request, 'targetUri'),
            $headers,
            JsonFixture::base64(JsonFixture::string($request, 'bodyBase64')),
        );
        $verifier = new PartnerWebhookVerifier(
            new StaticSigningKeySource(SigningKeySet::fromJson(JsonFixture::encode(JsonFixture::object($vector['signingKeys'] ?? null)))),
            JsonFixture::string($receiver, 'configuredUrl'),
            JsonFixture::string($receiver, 'applicationId'),
            new FrozenClock(JsonFixture::integer($vector, 'verifyAt')),
        );
        $phase = JsonFixture::nullableString($expected, 'phase') ?? 'verify';

        if (JsonFixture::string($expected, 'outcome') === 'accept') {
            $verifier->verify($webhook);
            $envelope = $verifier->verifyAndParse($webhook);
            $declared = JsonFixture::object($expected['envelope'] ?? null);
            self::assertSame(JsonFixture::string($declared, 'id'), $envelope->id);
            self::assertSame(JsonFixture::string($declared, 'type'), $envelope->rawType);
            self::assertSame(JsonFixture::string($declared, 'applicationId'), $envelope->applicationId);
            self::assertSame(JsonFixture::string($declared, 'dataRaw'), $envelope->dataRaw);
            if ($envelope->type->isOperational()) {
                self::assertNotNull($envelope->data, "Vector {$id} must carry typed data for a known operational type.");
            } else {
                self::assertNull($envelope->data);
                self::assertSame(WebhookEventType::Unknown, $envelope->type);
            }

            return;
        }

        $reason = WebhookVerificationFailure::from(JsonFixture::string($expected, 'reason'));
        if ($phase === 'parse') {
            $verifier->verify($webhook);
        }
        try {
            $phase === 'parse' ? $verifier->verifyAndParse($webhook) : $verifier->verify($webhook);
            self::fail("Webhook vector {$id} expected {$reason->value}, but was accepted.");
        } catch (UnverifiableWebhookException $error) {
            self::assertSame($reason, $error->failure(), "Webhook vector {$id} expected {$reason->value}.");
        }
    }

    #[Test]
    public function it_keeps_the_circle_suspended_type_with_current_in_progress_data_accepted(): void
    {
        $file = self::DIRECTORY . '/WH-046-fixture-circle-suspended-after-recipient-fix.json';
        $vector = JsonFixture::readObject($file);
        $receiver = JsonFixture::object($vector['receiver'] ?? null);
        $request = JsonFixture::object($vector['request'] ?? null);
        $headers = [];
        foreach (JsonFixture::object($request['headers'] ?? null) as $name => $value) {
            $headers[$name] = is_string($value) ? $value : '';
        }
        $verifier = new PartnerWebhookVerifier(
            new StaticSigningKeySource(SigningKeySet::fromJson(JsonFixture::encode(JsonFixture::object($vector['signingKeys'] ?? null)))),
            JsonFixture::string($receiver, 'configuredUrl'),
            JsonFixture::string($receiver, 'applicationId'),
            new FrozenClock(JsonFixture::integer($vector, 'verifyAt')),
        );
        $envelope = $verifier->verifyAndParse(new VerifiableWebhook('POST', JsonFixture::string($request, 'targetUri'), $headers, JsonFixture::base64(JsonFixture::string($request, 'bodyBase64'))));

        self::assertSame(WebhookEventType::DirectRechargeSuspended, $envelope->type);
        self::assertInstanceOf(\Anis\Partners\Models\DirectRecharge::class, $envelope->data);
        self::assertSame(\Anis\Partners\Models\DirectRechargeStatus::InProgress, $envelope->data->status);
    }

    #[Test]
    public function it_pins_the_complete_published_corpus_bytes_including_the_manifest(): void
    {
        $files = glob(self::DIRECTORY . '/*.json');
        self::assertIsArray($files);
        sort($files, SORT_STRING);
        self::assertCount(76, $files);
        $hashes = '';
        foreach ($files as $file) {
            $digest = hash_file('sha256', $file);
            self::assertNotFalse($digest);
            $hashes .= $digest . '  ' . basename($file) . "\n";
        }
        // Equivalent to running shasum -a 256 *.json | shasum -a 256 inside the corpus directory.
        self::assertSame(self::CORPUS_SHA256, hash('sha256', $hashes));
        self::assertCount(75, self::vectorFiles());
    }
}
