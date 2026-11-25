<?php

namespace Tests\Unit;

use App\Services\WhatsApp\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function numbers(): array
    {
        return [
            'already normalised' => ['923001234567', '923001234567'],
            'with plus and spaces' => ['+92 300 1234567', '923001234567'],
            'double zero prefix' => ['00923001234567', '923001234567'],
            'pakistani local format' => ['0300-1234567', '923001234567'],
            'foreign number kept' => ['+1 555 000 1111', '15550001111'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_it_normalises_phone_numbers(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }
}
