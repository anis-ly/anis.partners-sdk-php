<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Signing;

use Anis\Partners\Enrollment\EnrollmentClient;
use Anis\Partners\Enrollment\EnrollmentProof;
use Anis\Partners\Signing\PartnerRequestSigner;
use Anis\Partners\Signing\PemP256Signer;
use Anis\Partners\Signing\RequestSigner;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Signing\SignatureInputs;
use Anis\Partners\Signing\SignatureProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SensitiveParameterTest extends TestCase
{
    #[Test]
    public function it_redacts_a_pem_argument_from_a_failure_trace(): void
    {
        try {
            PemP256Signer::fromPem('private-pem-sentinel');
            self::fail('Invalid PEM should be refused.');
        } catch (\InvalidArgumentException $exception) {
            $frame = $exception->getTrace()[0] ?? [];
            $args = $frame['args'] ?? [];
            self::assertInstanceOf(\SensitiveParameterValue::class, $args[0] ?? null);
        }
    }

    #[Test]
    public function it_redacts_an_enrollment_token_from_a_failure_trace(): void
    {
        try {
            EnrollmentClient::create('https://partners.example', 'bad-invitation', 'token-sentinel');
            self::fail('Invalid invitation id should be refused.');
        } catch (\InvalidArgumentException $exception) {
            foreach ($exception->getTrace() as $frame) {
                if (($frame['class'] ?? null) === EnrollmentClient::class && $frame['function'] === 'create') {
                    $args = $frame['args'] ?? [];
                    self::assertInstanceOf(\SensitiveParameterValue::class, $args[2] ?? null);

                    return;
                }
            }

            self::fail('The enrollment call was absent from the trace.');
        }
    }

    #[Test]
    public function it_redacts_a_challenge_argument_from_a_proof_failure_trace(): void
    {
        try {
            EnrollmentProof::message('bad-id', 1, 'challenge-sentinel', 'thumbprint');
            self::fail('Invalid key id should be refused.');
        } catch (\InvalidArgumentException $exception) {
            foreach ($exception->getTrace() as $frame) {
                if (($frame['class'] ?? null) === EnrollmentProof::class && $frame['function'] === 'message') {
                    $args = $frame['args'] ?? [];
                    self::assertInstanceOf(\SensitiveParameterValue::class, $args[2] ?? null);

                    return;
                }
            }

            self::fail('The proof message call was absent from the trace.');
        }
    }

    #[Test]
    public function it_redacts_signature_inputs_from_a_signer_failure_trace(): void
    {
        $signer = new class implements RequestSigner {
            public function sign(#[\SensitiveParameter] string $data): string
            {
                throw new \RuntimeException('signing failed');
            }

            public function keyId(): string
            {
                return '8d4b1e73-9a25-4c60-8f37-6b2e9d5a1c48';
            }
        };

        try {
            (new PartnerRequestSigner($signer))->sign(
                SignatureProfile::BodylessNonceMutation,
                new SignatureInputs('POST', 'partners.example', '/v1/owned-cards/{id}/reveal', '', '2026-10-06T00:00:00Z', 'sha-256=:digest:', nonce: 'nonce-sentinel'),
                1,
                2,
            );
            self::fail('The signer failure should be wrapped.');
        } catch (RequestSigningException $exception) {
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
            foreach ($exception->getTrace() as $frame) {
                if (($frame['class'] ?? null) === PartnerRequestSigner::class && $frame['function'] === 'sign') {
                    $args = $frame['args'] ?? [];
                    self::assertInstanceOf(\SensitiveParameterValue::class, $args[1] ?? null);

                    return;
                }
            }

            self::fail('The signing call was absent from the trace.');
        }
    }
}
