<?php

namespace Tests\Unit\Services;

use App\Exceptions\InvalidCustomsFormNumberInput;
use App\Services\CustomsFormNumberNormalizer;
use PHPUnit\Framework\TestCase;

class CustomsFormNumberNormalizerTest extends TestCase
{
    private CustomsFormNumberNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new CustomsFormNumberNormalizer;
    }

    public function test_it_accepts_one_arbitrary_free_form_value(): void
    {
        $this->assertSame(['ABC/2026/123'], $this->normalizer->normalize(['ABC/2026/123']));
    }

    public function test_it_accepts_multiple_different_formats(): void
    {
        $numbers = ['B18106028839', 'ABC/2026/123', 'K8-123456', 'UCUSTOMS-ABC-99', 'ATA 123/2026'];

        $this->assertSame($numbers, $this->normalizer->normalize($numbers));
    }

    public function test_it_trims_surrounding_whitespace(): void
    {
        $this->assertSame(['B18106028839'], $this->normalizer->normalize([' B18106028839 ']));
    }

    public function test_it_preserves_original_casing_after_trim(): void
    {
        $this->assertSame(['k8-AbC-123'], $this->normalizer->normalize([' k8-AbC-123 ']));
    }

    public function test_it_preserves_input_order(): void
    {
        $this->assertSame(['CCC', 'AAA', 'BBB'], $this->normalizer->normalize(['CCC', 'AAA', 'BBB']));
    }

    public function test_it_rejects_an_empty_collection(): void
    {
        try {
            $this->normalizer->normalize([]);
            $this->fail('Expected InvalidCustomsFormNumberInput to be thrown.');
        } catch (InvalidCustomsFormNumberInput $exception) {
            $this->assertSame('empty_input', $exception->getErrorCode());
        }
    }

    public function test_it_rejects_an_empty_child_value(): void
    {
        try {
            $this->normalizer->normalize(['ABC', '   ']);
            $this->fail('Expected InvalidCustomsFormNumberInput to be thrown.');
        } catch (InvalidCustomsFormNumberInput $exception) {
            $this->assertSame('empty_token', $exception->getErrorCode());
            $this->assertSame(1, $exception->getTokenIndex());
        }
    }

    public function test_it_rejects_a_value_over_one_hundred_characters(): void
    {
        try {
            $this->normalizer->normalize([str_repeat('A', 101)]);
            $this->fail('Expected InvalidCustomsFormNumberInput to be thrown.');
        } catch (InvalidCustomsFormNumberInput $exception) {
            $this->assertSame('value_too_long', $exception->getErrorCode());
        }
    }

    public function test_it_accepts_a_value_of_exactly_one_hundred_characters(): void
    {
        $value = str_repeat('A', 100);

        $this->assertSame([$value], $this->normalizer->normalize([$value]));
    }

    public function test_it_rejects_an_exact_duplicate(): void
    {
        try {
            $this->normalizer->normalize(['B18106028839', 'B18106028839']);
            $this->fail('Expected InvalidCustomsFormNumberInput to be thrown.');
        } catch (InvalidCustomsFormNumberInput $exception) {
            $this->assertSame('duplicate_number', $exception->getErrorCode());
        }
    }

    public function test_it_rejects_a_case_insensitive_duplicate(): void
    {
        try {
            $this->normalizer->normalize(['B18106028839', 'b18106028839']);
            $this->fail('Expected InvalidCustomsFormNumberInput to be thrown.');
        } catch (InvalidCustomsFormNumberInput $exception) {
            $this->assertSame('duplicate_number', $exception->getErrorCode());
        }
    }

    public function test_it_rejects_a_whitespace_equivalent_duplicate(): void
    {
        try {
            $this->normalizer->normalize(['B18106028839', ' B18106028839 ']);
            $this->fail('Expected InvalidCustomsFormNumberInput to be thrown.');
        } catch (InvalidCustomsFormNumberInput $exception) {
            $this->assertSame('duplicate_number', $exception->getErrorCode());
        }
    }

    public function test_it_does_not_expand_legacy_comma_shorthand(): void
    {
        // A single field containing the old shorthand syntax is now one opaque literal value.
        $this->assertSame(
            ['B18112068450,51'],
            $this->normalizer->normalize(['B18112068450,51']),
        );
    }

    public function test_it_does_not_enforce_the_old_b_prefix_or_digit_count_syntax(): void
    {
        $numbers = ['not-a-b-number', '123', 'xyz'];

        $this->assertSame($numbers, $this->normalizer->normalize($numbers));
    }
}
