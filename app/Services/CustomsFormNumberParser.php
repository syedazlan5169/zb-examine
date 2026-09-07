<?php

namespace App\Services;

use App\Exceptions\InvalidCustomsFormNumberInput;

final class CustomsFormNumberParser
{
    /**
     * @return list<string>
     *
     * @throws InvalidCustomsFormNumberInput
     */
    public function parse(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidCustomsFormNumberInput('empty_input');
        }

        $numbers = [];
        $seenNumbers = [];
        $baseNumber = null;

        foreach (explode(',', $input) as $tokenIndex => $rawToken) {
            $token = trim($rawToken);

            if ($token === '') {
                throw new InvalidCustomsFormNumberInput(
                    'empty_token',
                    $token,
                    $tokenIndex,
                );
            }

            $token = strtoupper($token);

            if (preg_match('/^B[0-9]{11}$/', $token) === 1) {
                $number = $token;
                $baseNumber = $number;
            } elseif (preg_match('/^[0-9]{2}$/', $token) === 1) {
                if ($baseNumber === null) {
                    throw new InvalidCustomsFormNumberInput(
                        'missing_base_number',
                        $token,
                        $tokenIndex,
                    );
                }

                $number = substr($baseNumber, 0, -2).$token;
            } else {
                throw new InvalidCustomsFormNumberInput(
                    'invalid_number',
                    $token,
                    $tokenIndex,
                );
            }

            if (isset($seenNumbers[$number])) {
                throw new InvalidCustomsFormNumberInput(
                    'duplicate_number',
                    $token,
                    $tokenIndex,
                    $number,
                );
            }

            $seenNumbers[$number] = true;
            $numbers[] = $number;
        }

        return $numbers;
    }
}
