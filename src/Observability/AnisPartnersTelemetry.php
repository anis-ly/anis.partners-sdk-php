<?php

declare(strict_types=1);

namespace Anis\Partners\Observability;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextKeys;

/** Reports bounded request and order signals without affecting the partner result. */
final class AnisPartnersTelemetry
{
    private const NAME = 'anis-ly/partners';
    private const VERSION = '1.1.0';

    /** @var \WeakMap<MeterInterface, array<string, CounterInterface>>|null */
    private static ?\WeakMap $counters = null;

    /** @var \WeakMap<MeterInterface, array<string, HistogramInterface>>|null */
    private static ?\WeakMap $histograms = null;

    /** Creates a client span with a route template so identifiers do not create metric-cardinality leaks. */
    public static function startRequest(string $route, string $method, ?string $operationId = null, string $client = 'default'): SpanInterface
    {
        try {
            $builder = Globals::tracerProvider()->getTracer(self::NAME, self::VERSION)->spanBuilder('anis.partners ' . $route)->setSpanKind(SpanKind::KIND_CLIENT);
            $attributes = ['anis.client' => $client, 'anis.route' => $route, 'http.request.method' => strtoupper($method)];
            if ($operationId !== null) {
                $attributes['anis.operation_id'] = $operationId;
            }
            $builder->setAttributes($attributes);

            return $builder->startSpan();
        } catch (\Throwable) {
            return Span::getInvalid();
        }
    }

    /** Activates the request span for HTTP-client instrumentation; failure remains best-effort. */
    public static function activate(SpanInterface $span): ?\OpenTelemetry\Context\ScopeInterface
    {
        try {
            return Context::getCurrent()->with(ContextKeys::span(), $span)->activate();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Records total call time independently from signer and verification outcomes. */
    public static function requestDuration(float $milliseconds, string $route, string $method, ?int $status = null, ?string $errorCode = null, ?string $errorType = null, string $client = 'default'): void
    {
        $attributes = ['anis.client' => $client, 'anis.route' => $route, 'http.request.method' => strtoupper($method)];
        if ($status !== null) {
            $attributes['http.response.status_code'] = $status;
        }
        if ($errorCode !== null) {
            $attributes['anis.error.code'] = $errorCode;
        }
        if ($errorType !== null) {
            $attributes['error.type'] = $errorType;
        }
        try {
            self::histogram('anis.partners.request.duration', 'ms', 'Elapsed time spent signing, sending, and verifying one request.')
                ->record($milliseconds, $attributes);
        } catch (\Throwable) {
        }
    }

    /** Counts a discarded answer by the public rule that rejected it. */
    public static function verificationFailure(string $reason, string $client = 'default'): void
    {
        try {
            self::counter('anis.partners.response.verification.failures', 'Count of answers on signed routes discarded by response checks.')
                ->add(1, ['anis.client' => $client, 'anis.verification.failure' => $reason]);
        } catch (\Throwable) {
        }
    }

    /** Counts successful key-document fetches so rotation and cache behavior can be observed. */
    public static function signingKeysFetched(string $reason, int $count, string $client = 'default'): void
    {
        try {
            self::counter('anis.partners.signing_keys.fetches', 'Count of successful Anis signing-key document fetches.')
                ->add(1, ['anis.client' => $client, 'anis.fetch.reason' => $reason]);
        } catch (\Throwable) {
        }
    }

    /** Records signer cost without allowing a host meter to interrupt a partner request. */
    public static function signatureDuration(float $milliseconds, string $profile, string $client = 'default'): void
    {
        try {
            self::histogram('anis.partners.signature.duration', 'ms', 'Time spent building a request signature.')
                ->record($milliseconds, ['anis.client' => $client, 'anis.signature.profile' => $profile]);
        } catch (\Throwable) {
        }
    }

    /** Records an order outcome without changing the returned business result. */
    public static function orderOutcome(string $outcome, ?string $reason = null, string $client = 'default'): void
    {
        $attributes = ['anis.client' => $client, 'anis.order.outcome' => $outcome];
        if ($outcome === 'unknown' && $reason !== null) {
            $attributes['error.type'] = $reason;
        }
        try {
            self::counter('anis.partners.order.outcomes', 'Count of completed, processing, replayed, and unknown order outcomes.')
                ->add(1, $attributes);
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

    private static function counter(string $name, string $description): CounterInterface
    {
        $meter = Globals::meterProvider()->getMeter(self::NAME, self::VERSION);
        self::$counters ??= new \WeakMap();
        $instruments = self::$counters[$meter] ?? [];
        if (!isset($instruments[$name])) {
            $instruments[$name] = $meter->createCounter($name, null, $description);
            self::$counters[$meter] = $instruments;
        }

        return $instruments[$name];
    }

    private static function histogram(string $name, ?string $unit, string $description): HistogramInterface
    {
        $meter = Globals::meterProvider()->getMeter(self::NAME, self::VERSION);
        self::$histograms ??= new \WeakMap();
        $instruments = self::$histograms[$meter] ?? [];
        if (!isset($instruments[$name])) {
            $instruments[$name] = $meter->createHistogram($name, $unit, $description);
            self::$histograms[$meter] = $instruments;
        }

        return $instruments[$name];
    }
}
