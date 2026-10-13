<?php

use App\Exceptions\InvalidProductPriceException;
use App\Support\PriceParser;

test('a price is read whatever way it was written', function (mixed $written, string $amount) {
    expect(PriceParser::parse($written))->toBe($amount);
})->with([
    'a whole amount' => ['85000', '85000'],
    'thousands with a dot' => ['85.000', '85000'],
    'currency and spaces around it' => ['$ 85.000', '85000'],
    'thousands and cents' => ['85.000,50', '85000.5'],
    'cents with a comma' => ['85,50', '85.5'],
    'cents with a dot' => ['85.50', '85.5'],
    'millions with dots' => ['1.234.567', '1234567'],
    'the other way around' => ['1,234,567.89', '1234567.89'],
    'a number as Excel reads it' => [85000, '85000'],
    'a decimal as Excel reads it' => [85000.5, '85000.5'],
]);

test('a value that cannot be a price is refused', function (mixed $written) {
    expect(fn () => PriceParser::parse($written))->toThrow(InvalidProductPriceException::class);
})->with([
    'empty' => '',
    'letters' => 'abc',
    'zero' => '0',
    'zero written out' => '0.000',
    'negative' => '-100',
    'four cents' => '85.1234',
    'an ambiguous comma' => '1,2345',
    'nothing at all' => [null],
]);
