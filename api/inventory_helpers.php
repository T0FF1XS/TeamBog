<?php
declare(strict_types=1);

function inventory_quantity(mixed $value, bool $signed = false): ?string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }

    $value = (string) $value;
    $pattern = $signed
        ? '/^-?(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,3})?$/D'
        : '/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,3})?$/D';
    if (!preg_match($pattern, $value)) {
        return null;
    }

    $negative = str_starts_with($value, '-');
    $absolute = $negative ? substr($value, 1) : $value;
    [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
    $normalized = $whole . '.' . str_pad($fraction, 3, '0');
    if ($signed && $negative && (float) $normalized === 0.0) {
        return null;
    }

    return ($negative ? '-' : '') . $normalized;
}

function inventory_milli(string $quantity): int
{
    $negative = str_starts_with($quantity, '-');
    $absolute = $negative ? substr($quantity, 1) : $quantity;
    [$whole, $fraction] = explode('.', $absolute, 2);
    $milli = (int) $whole * 1000 + (int) $fraction;
    return $negative ? -$milli : $milli;
}

function inventory_decimal(int $milli): string
{
    $negative = $milli < 0;
    $absolute = abs($milli);
    return ($negative ? '-' : '')
        . intdiv($absolute, 1000)
        . '.'
        . str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
}
