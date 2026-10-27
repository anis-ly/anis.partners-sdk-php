<?php

declare(strict_types=1);

namespace Anis\Partners\Verification;

/** Names the rule that caused a webhook delivery to be refused; it is separate from the response failures. */
enum WebhookVerificationFailure: string
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
    case ProfileUnexpected = 'profile_unexpected';
    case KeyCompromised = 'key_compromised';
    case KeyRetired = 'key_retired';
    case KeyNotYetValid = 'key_not_yet_valid';
    case SignatureExpired = 'signature_expired';
    case ValidityWindowInvalid = 'validity_window_invalid';
    case TargetUriMismatch = 'target_uri_mismatch';
    case ApplicationIdMismatch = 'application_id_mismatch';
    case KeyPurposeMismatch = 'key_purpose_mismatch';
    case MethodUnexpected = 'method_unexpected';
    case EventIdMismatch = 'event_id_mismatch';
    case EnvelopeInvalid = 'envelope_invalid';
}
