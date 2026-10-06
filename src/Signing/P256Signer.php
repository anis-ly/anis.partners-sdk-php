<?php

declare(strict_types=1);

namespace Anis\Partners\Signing;

/** Signs exact Partner protocol bytes with a P-256 private key. */
interface P256Signer
{
    /** Returns exactly 64 IEEE P1363 bytes (r followed by s), the form the Partner wire protocol verifies. */
    public function sign(#[\SensitiveParameter] string $data): string;
}
