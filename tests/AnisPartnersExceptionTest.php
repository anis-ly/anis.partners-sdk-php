<?php

declare(strict_types=1);

namespace Anis\Partners\Tests;

use Anis\Partners\AnisPartnersException;
use Anis\Partners\Enrollment\EnrollmentKeyMismatchException;
use Anis\Partners\Signing\RequestSigningException;
use Anis\Partners\Verification\ResponseVerificationFailure;
use Anis\Partners\Verification\SigningKeysUnavailableException;
use Anis\Partners\Verification\UnverifiableResponseException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnisPartnersExceptionTest extends TestCase
{
    #[Test]
    public function it_marks_each_sdk_specific_failure_for_a_single_catch_clause(): void
    {
        $exceptions = [
            new RequestSigningException(),
            new UnverifiableResponseException(ResponseVerificationFailure::SignatureInvalid),
            new EnrollmentKeyMismatchException('local', 'server'),
            new SigningKeysUnavailableException(),
        ];

        foreach ($exceptions as $exception) {
            self::assertInstanceOf(AnisPartnersException::class, $exception);
        }
    }
}
