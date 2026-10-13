<?php

namespace App\Support;

use App\Exceptions\InvalidProductPriceException;

/**
 * The price a person writes in a spreadsheet, read as the amount it means.
 *
 * The panel asks for a price through a field that already holds the format of
 * the store — thousands with a dot, cents with a comma — but a file is typed by
 * hand and the same amount arrives written in more than one way: `85000`,
 * `85.000`, `$ 85.000` and `85.000,50` are all the price of the same garment.
 * This is the one place that reads them into a single form, so the import does
 * not grow a second way of understanding a price next to the panel's.
 *
 * **What is refused.** Anything that does not say an amount: a value that is not
 * a number, a zero or a negative one, and — above all — a value that can be read
 * two ways. `85,000` is thousands in one shop and eighty-five with three cents in
 * another, so instead of guessing a rule the value is left out and the person who
 * wrote it is asked to write it again. A number that comes from a cell of Excel is
 * taken as it is, because there the spreadsheet has already decided.
 */
final class PriceParser
{
    /**
     * The most `base_price` holds: `decimal(10,2)` has room for eight digits
     * before the point and two after it.
     */
    public const MAX_AMOUNT = 99999999.99;

    /**
     * The amount the value means, as the decimal text a `base_price` holds.
     *
     * It comes back as text and not as a number because that is the form the
     * column is written in: a `85000` and a `85.5` are the same price spelled
     * with and without the cents that the column would otherwise have to guess.
     */
    public static function parse(mixed $value): string
    {
        if (is_int($value)) {
            return self::fromAmount((float) $value);
        }

        if (is_float($value)) {
            return self::fromAmount($value);
        }

        if (! is_string($value)) {
            throw InvalidProductPriceException::forValue($value);
        }

        return self::fromText($value);
    }

    /**
     * Read the amount out of the text that was typed.
     *
     * The text is matched against the ways an amount is written and nothing is
     * stripped before that: a separator is what tells the thousands from the
     * cents, so removing one of them before reading the shape of the number is
     * exactly what would make `85.000` and `85,000` impossible to tell apart.
     */
    private static function fromText(string $text): string
    {
        $text = str_replace(['$', ' ', "\u{00A0}", "\u{202F}"], '', trim($text));

        $normalized = match (true) {
            // Thousands with a dot, cents with a comma: `1.234.567,89`.
            preg_match('/^\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?$/', $text) === 1 => str_replace(['.', ','], ['', '.'], $text),
            // The other way around: `1,234,567.89`.
            preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/', $text) === 1 => str_replace(',', '', $text),
            // Cents with a comma and nothing else: `85,50`.
            preg_match('/^\d+,\d{1,2}$/', $text) === 1 => str_replace(',', '.', $text),
            // Cents with a dot and nothing else: `85.50`.
            preg_match('/^\d+\.\d{1,2}$/', $text) === 1 => $text,
            // A plain whole amount: `85000`.
            preg_match('/^\d+$/', $text) === 1 => $text,
            default => null,
        };

        if ($normalized === null) {
            throw InvalidProductPriceException::forValue($text);
        }

        return self::fromAmount((float) $normalized);
    }

    /**
     * The amount as the column holds it, or a refusal when it is not a price.
     */
    private static function fromAmount(float $amount): string
    {
        if (! is_finite($amount) || $amount <= 0 || $amount > self::MAX_AMOUNT) {
            throw InvalidProductPriceException::forValue($amount);
        }

        return rtrim(rtrim(number_format(round($amount, 2), 2, '.', ''), '0'), '.');
    }
}
