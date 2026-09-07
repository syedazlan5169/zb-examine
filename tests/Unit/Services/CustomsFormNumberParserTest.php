<?php

namespace Tests\Unit\Services;

use App\Exceptions\InvalidCustomsFormNumberInput;
use App\Services\CustomsFormNumberParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CustomsFormNumberParserTest extends TestCase
{
    private CustomsFormNumberParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new CustomsFormNumberParser;
    }

    public function test_it_parses_a_single_complete_number(): void
    {
        $this->assertSame(
            ['B18112068450'],
            $this->parser->parse('B18112068450'),
        );
    }

    public function test_it_normalizes_lowercase_b(): void
    {
        $this->assertSame(
            ['B18112068450'],
            $this->parser->parse('b18112068450'),
        );
    }

    public function test_it_expands_two_digit_shorthand(): void
    {
        $this->assertSame(
            [
                'B18112068450',
                'B18112068451',
                'B18112068452',
                'B18112068453',
            ],
            $this->parser->parse('B18112068450,51,52,53'),
        );
    }

    public function test_it_allows_whitespace_around_input_and_tokens(): void
    {
        $this->assertSame(
            ['B18112068450', 'B18112068451', 'B18112068452'],
            $this->parser->parse('  b18112068450, 51, 52  '),
        );
    }

    public function test_it_preserves_the_order_of_multiple_complete_numbers(): void
    {
        $this->assertSame(
            ['B18112068450', 'B18112068451'],
            $this->parser->parse('B18112068450,B18112068451'),
        );
    }

    public function test_a_later_complete_number_resets_the_shorthand_base(): void
    {
        $this->assertSame(
            [
                'B18112068450',
                'B18112068451',
                'B18112068570',
                'B18112068571',
            ],
            $this->parser->parse('B18112068450,51,B18112068570,71'),
        );
    }

    #[DataProvider('invalidNumberProvider')]
    public function test_it_rejects_invalid_numbers(string $input): void
    {
        $this->assertParseError('invalid_number', $input);
    }

    public static function invalidNumberProvider(): array
    {
        return [
            'invalid prefix' => ['A18112068450'],
            'too short' => ['B1811206845'],
            'too long' => ['B181120684500'],
            'non digit character' => ['B1811206845X'],
            'one digit shorthand' => ['B18112068450,5'],
            'three digit shorthand' => ['B18112068450,500'],
            'leading zero shorthand' => ['B18112068450,051'],
            'alphabetic shorthand' => ['B18112068450,ab'],
            'range syntax' => ['B18112068450,50-53'],
            'space separator' => ['B18112068450 51'],
        ];
    }

    public function test_it_rejects_shorthand_without_a_complete_base(): void
    {
        $this->assertParseError('missing_base_number', '51');
    }

    public function test_it_rejects_empty_input(): void
    {
        $this->assertParseError('empty_input', '   ');
    }

    #[DataProvider('emptyTokenProvider')]
    public function test_it_rejects_empty_tokens(string $input): void
    {
        $this->assertParseError('empty_token', $input);
    }

    public static function emptyTokenProvider(): array
    {
        return [
            'leading comma' => [',B18112068450'],
            'trailing comma' => ['B18112068450,'],
            'double comma' => ['B18112068450,,51'],
        ];
    }

    #[DataProvider('duplicateProvider')]
    public function test_it_rejects_duplicate_numbers(string $input): void
    {
        $this->assertParseError('duplicate_number', $input);
    }

    public function test_it_reports_metadata_for_a_duplicate_complete_number(): void
    {
        try {
            $this->parser->parse('B18112068450,B18112068450');
            $this->fail('Expected a duplicate customs form number exception.');
        } catch (InvalidCustomsFormNumberInput $exception) {
            $this->assertSame('duplicate_number', $exception->getErrorCode());
            $this->assertSame('B18112068450', $exception->getToken());
            $this->assertSame('B18112068450', $exception->getNormalizedValue());
            $this->assertSame(1, $exception->getTokenIndex());
        }
    }

    public function test_it_reports_metadata_for_a_duplicate_created_by_shorthand(): void
    {
        try {
            $this->parser->parse('B18112068450,50');
            $this->fail('Expected a duplicate customs form number exception.');
        } catch (InvalidCustomsFormNumberInput $exception) {
            $this->assertSame('duplicate_number', $exception->getErrorCode());
            $this->assertSame('50', $exception->getToken());
            $this->assertSame('B18112068450', $exception->getNormalizedValue());
            $this->assertSame(1, $exception->getTokenIndex());
        }
    }

    public static function duplicateProvider(): array
    {
        return [
            'duplicate complete number' => ['B18112068450,B18112068450'],
            'duplicate from shorthand' => ['B18112068450,50'],
        ];
    }

    public function test_it_fails_atomically_when_a_later_token_is_invalid(): void
    {
        try {
            $this->parser->parse('B18112068450,51,INVALID,52');
            $this->fail('Expected an invalid customs form number exception.');
        } catch (InvalidCustomsFormNumberInput $exception) {
            $this->assertSame('invalid_number', $exception->getErrorCode());
            $this->assertSame('INVALID', $exception->getToken());
            $this->assertSame(2, $exception->getTokenIndex());
        }
    }

    public function test_the_boundary_format_is_b_followed_by_exactly_eleven_digits(): void
    {
        $this->assertSame(
            ['B00000000000'],
            $this->parser->parse('B00000000000'),
        );
    }

    private function assertParseError(string $errorCode, string $input): void
    {
        $this->expectException(InvalidCustomsFormNumberInput::class);
        $this->expectExceptionMessage($errorCode);

        try {
            $this->parser->parse($input);
        } catch (InvalidCustomsFormNumberInput $exception) {
            $this->assertSame($errorCode, $exception->getErrorCode());

            throw $exception;
        }
    }
}
