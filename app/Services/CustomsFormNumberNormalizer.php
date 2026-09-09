<?php

namespace App\Services;

use App\Exceptions\InvalidCustomsFormNumberInput;

final class CustomsFormNumberNormalizer
{
    private const MAX_LENGTH = 100;

    /**
     * Free-form, opaque customs form numbers: no B-prefix/digit-count syntax,
     * no comma parsing, no shorthand expansion — one submitted value in, one
     * trimmed value out, in the original order, rejecting empty/duplicate/
     * oversized entries.
     *
     * @param  list<string>  $numbers
     * @return list<string>
     *
     * @throws InvalidCustomsFormNumberInput
     */
    public function normalize(array $numbers): array
    {
        if ($numbers === []) {
            throw new InvalidCustomsFormNumberInput('empty_input');
        }

        $result = [];
        $seenNumbers = [];

        foreach ($numbers as $tokenIndex => $rawValue) {
            $value = trim((string) $rawValue);

            if ($value === '') {
                throw new InvalidCustomsFormNumberInput('empty_token', $value, $tokenIndex);
            }

            if (mb_strlen($value) > self::MAX_LENGTH) {
                throw new InvalidCustomsFormNumberInput('value_too_long', $value, $tokenIndex);
            }

            $key = mb_strtolower($value);

            if (isset($seenNumbers[$key])) {
                throw new InvalidCustomsFormNumberInput('duplicate_number', $value, $tokenIndex, $value);
            }

            $seenNumbers[$key] = true;
            $result[] = $value;
        }

        return $result;
    }
}
