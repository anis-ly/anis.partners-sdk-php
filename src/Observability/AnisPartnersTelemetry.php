<?php

declare(strict_types=1);

namespace Anis\Partners\Observability;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

/** Publishes the stable Anis Partner tracing and measurement names without requiring an SDK package. */
final class AnisPartnersTelemetry
{
    /** Names the shared instrumentation scope consumed by partner dashboards. */
    public const NAME = 'anis-ly/partners';

    /** Creates one client span with the route template so identifiers do not create metric-cardinality leaks. */
    public static function startRequest(string $route, string $method, ?string $operationId = null): SpanInterface
    {
        try {
            $builder = Globals::tracerProvider()->getTracer(self::NAME)->spanBuilder('anis.partners ' . $route)->setSpanKind(SpanKind::KIND_CLIENT);
            $attributes = ['anis.client' => 'default', 'anis.route' => $route, 'http.request.method' => strtoupper($method)];
            if ($operationId !== null) {
                $attributes['anis.operation_id'] = $operationId;
            }
            $builder->setAttributes($attributes);

            return $builder->startSpan();
        } catch (\Throwable) {
            return Span::getInvalid();
        }
    }

    /** Records total call time independently from signer and verification outcomes. */
    public static function requestDuration(float $milliseconds, string $route, string $method, ?int $status = null, ?string $errorType = null, ?string $errorCode = null): void
    {
        $attributes = ['anis.client' => 'default', 'anis.route' => $route, 'http.request.method' => strtoupper($method)];
        if ($status !== null) {
            $attributes['http.response.status_code'] = $status;
        }
        if ($errorType !== null) {
            $attributes['error.type'] = $errorType;
        }
        if ($errorCode !== null) {
            $attributes['anis.error.code'] = $errorCode;
        }
        try {
            Globals::meterProvider()->getMeter(self::NAME)->createHistogram('anis.partners.request.duration', 'ms')->record($milliseconds, $attributes);
        } catch (\Throwable) {
        }
    }

    /** Counts a discarded answer by the public rule that rejected it. */
    public static function verificationFailure(string $reason): void
    {
        try {
            Globals::meterProvider()->getMeter(self::NAME)->createCounter('anis.partners.response.verification.failures')->add(1, ['anis.verification.failure' => $reason]);
        } catch (\Throwable) {
        }
    }

    /** Records signer cost without allowing a host meter to interrupt a partner request. */
    public static function signatureDuration(float $milliseconds, string $profile): void
    {
        try {
            Globals::meterProvider()->getMeter(self::NAME)->createHistogram('anis.partners.signature.duration', 'ms')->record($milliseconds, ['anis.signature.profile' => $profile]);
        } catch (\Throwable) {
        }
    }

    /** Records an order outcome without changing the returned business result. */
    public static function orderOutcome(string $outcome, ?string $reason = null): void
    {
        $attributes = ['anis.client' => 'default', 'anis.order.outcome' => $outcome];
        if ($reason !== null) {
            $attributes['error.type'] = $reason;
        }
        try {
            Globals::meterProvider()->getMeter(self::NAME)->createCounter('anis.partners.order.outcomes')->add(1, $attributes);
        } catch (\Throwable) {
        }
    }

    /** Makes a span update best-effort when a host tracing implementation throws. */
    public static function setAttribute(SpanInterface $span, string $name, mixed $value): void
    {
        if ($name === '' || (!is_scalar($value) && !is_array($value) && $value !== null)) {
            return;
        }
        try {
            $span->setAttribute($name, $value);
        } catch (\Throwable) {
        }
    }

    /** Makes span status reporting best-effort when a host tracer throws. */
    public static function setStatus(SpanInterface $span, string $status, ?string $description = null): void
    {
        try {
            if ($status === StatusCode::STATUS_ERROR) {
                $span->setStatus(StatusCode::STATUS_ERROR, $description);
            } elseif ($status === StatusCode::STATUS_OK) {
                $span->setStatus(StatusCode::STATUS_OK, $description);
            } elseif ($status === StatusCode::STATUS_UNSET) {
                $span->setStatus(StatusCode::STATUS_UNSET, $description);
            }
        } catch (\Throwable) {
        }
    }

    /** Ends a span without allowing the host tracer to affect the request result. */
    public static function end(SpanInterface $span): void
    {
        try {
            $span->end();
        } catch (\Throwable) {
        }
    }
}
