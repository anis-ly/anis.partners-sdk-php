<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Names the rule that caused a Partner response to be refused. */
enum ResponseVerificationFailure: string
{
    case SignatureMissing = 'signature_missing';
    case SignatureMalformed = 'signature_malformed';
    case SignatureInvalid = 'signature_invalid';
    case ContentDigestMismatch = 'content_digest_mismatch';
    case CoveredComponentsMismatch = 'covered_components_mismatch';
    case UnknownKey = 'unknown_key';
    case KeyRejected = 'key_rejected';
    case AlgorithmNotSupported = 'algorithm_not_supported';
    case LabelUnexpected = 'label_unexpected';
    case CreatedOutOfWindow = 'created_out_of_window';
}
