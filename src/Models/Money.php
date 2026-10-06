<?php

declare(strict_types=1);

namespace Anis\Partners\Models;

/** Keeps prices in thousandths so order totals cannot drift through binary floating point. */
final readonly class Money implements \JsonSerializable
{
    public int $thousandths;
    public string $currency;
    public ?\DateTimeImmutable $asOf;

    public function __construct(
        mixed $thousandths,
        mixed $currency,
        ?\DateTimeImmutable $asOf = null,
    ) {
        if (!is_int($thousandths) || !is_string($currency)) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('Money requires integer thousandths and a string currency.');
        }
        $this->thousandths = $thousandths;
        $this->currency = $currency;
        $this->asOf = $asOf;
    }

    /**
     * Parses only decimal strings; the broad native parameter blocks PHP weak callers from coercing numbers.
     * @param numeric-string $amount Must be a decimal string because a float or integer may already have lost decimal intent.
     * @param string $currency ISO currency code carried by the price.
     */
    public static function of(mixed $amount, mixed $currency): self
    {
        $amount = self::decimalString($amount);
        $currency = self::currencyString($currency);
        if (preg_match('/\A([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))\z/D', $amount, $matches) !== 1) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('Money amounts must be decimal strings.');
        }

        $whole = ltrim($matches[2] ?? '0', '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = ($matches[3] ?? '') !== '' ? $matches[3] : ($matches[4] ?? '');
        if (strlen($fraction) > 3) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('Anis amounts have at most three decimal places');
        }

        $threePlaces = str_pad($fraction, 3, '0');
        $scaled = ltrim($whole . $threePlaces, '0');
        $scaled = $scaled === '' ? '0' : $scaled;
        $negativeLimit = self::incrementDigits((string) PHP_INT_MAX);
        $limit = $matches[1] === '-' ? $negativeLimit : (string) PHP_INT_MAX;
        if (self::compareDigits($scaled, $limit) > 0) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('The money amount exceeds the supported integer range.');
        }
        if ($matches[1] === '-' && $scaled === $negativeLimit) {
            $units = PHP_INT_MIN;
        } else {
            $units = (int) $scaled;
            if ($matches[1] === '-') {
                $units = -$units;
            }
        }

        return new self($units, $currency);
    }

    /**
     * Reads the decimal-string representation and refuses JSON numbers before they lose precision.
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;
        if (!is_string($amount) || !is_string($currency)) {
            throw new \Anis\Partners\Errors\AnisPartnersUnexpectedValueException('Money amount and currency must be strings.');
        }
        $asOf = ModelData::dateTime($data, 'asOf');
        $money = self::of(self::decimalString($amount), $currency);

        return new self($money->thousandths, $money->currency, $asOf);
    }

    /** Multiplies by a whole quantity without losing precision or carrying a stale balance timestamp. */
    public function multiply(mixed $quantity): self
    {
        if (!is_int($quantity)) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('Money multiplication quantity must be an integer.');
        }
        if ($quantity === -1 && $this->thousandths === PHP_INT_MIN) {
            throw new \Anis\Partners\Errors\AnisPartnersOverflowException('The money multiplication exceeds the supported integer range.');
        }
        if ($quantity > 0 && ($this->thousandths > intdiv(PHP_INT_MAX, $quantity) || $this->thousandths < intdiv(PHP_INT_MIN, $quantity))) {
            throw new \Anis\Partners\Errors\AnisPartnersOverflowException('The money multiplication exceeds the supported integer range.');
        }
        if ($quantity < -1 && $this->thousandths > 0 && $this->thousandths > intdiv(PHP_INT_MIN, $quantity)) {
            throw new \Anis\Partners\Errors\AnisPartnersOverflowException('The money multiplication exceeds the supported integer range.');
        }
        if ($quantity < -1 && $this->thousandths < 0 && $this->thousandths < intdiv(PHP_INT_MAX, $quantity)) {
            throw new \Anis\Partners\Errors\AnisPartnersOverflowException('The money multiplication exceeds the supported integer range.');
        }

        return new self($this->thousandths * $quantity, $this->currency);
    }

    /** Returns a decimal string with exactly three places for the wire contract. */
    public function amount(): string
    {
        $negative = $this->thousandths < 0;
        $digits = ltrim((string) $this->thousandths, '-');
        $digits = str_pad($digits, 4, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -3);
        $fraction = substr($digits, -3);

        $sign = $negative ? '-' : '';

        return $sign . $whole . '.' . $fraction;
    }

    /**
     * Returns wire member names and UTC seconds so encoding produces the contract's Money object.
     * @return array{amount: string, currency: string, asOf?: string}
     */
    public function toArray(): array
    {
        $data = ['amount' => $this->amount(), 'currency' => $this->currency];
        if ($this->asOf !== null) {
            $data['asOf'] = $this->asOf->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        return $data;
    }

    /**
     * Serializes the exact monetary wire representation, not the internal thousandths counter.
     * @return array{amount: string, currency: string, asOf?: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Renders the wire JSON with the same escaping choices used for signed request bodies. */
    public function toJson(): string
    {
        return ModelData::json($this->toArray());
    }

    private static function compareDigits(string $left, string $right): int
    {
        $lengthComparison = strlen($left) <=> strlen($right);
        if ($lengthComparison !== 0) {
            return $lengthComparison;
        }

        return strcmp($left, $right);
    }

    private static function incrementDigits(string $digits): string
    {
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return $digits;
            }
            $digits[$index] = '0';
        }

        return '1' . $digits;
    }

    /** @return numeric-string */
    private static function decimalString(mixed $amount): string
    {
        if (!is_string($amount)) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('Money amounts must be supplied as decimal strings.');
        }
        if (!is_numeric($amount) || preg_match('/\A[+-]?(?:(?:\d+)(?:\.\d*)?|\.\d+)\z/D', $amount) !== 1) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('Money amounts must be decimal strings.');
        }

        return $amount;
    }

    private static function currencyString(mixed $currency): string
    {
        if (!is_string($currency)) {
            throw new \Anis\Partners\Errors\AnisPartnersInvalidArgumentException('Money currency must be supplied as a string.');
        }

        return $currency;
    }

}
