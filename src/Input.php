<?php
declare(strict_types=1);

namespace LearningCloud;

final class Input
{
    public static function string(array $data, string $field, int $max, int $min = 1): string
    {
        $value = $data[$field] ?? null;
        if (is_string($value)) {
            $value = trim($value);
            $length = mb_strlen($value, 'UTF-8');
            if ($length >= $min && $length <= $max && mb_check_encoding($value, 'UTF-8')) {
                return $value;
            }
        }
        throw self::invalid($field);
    }

    public static function integer(array $data, string $field, int $min, int $max = PHP_INT_MAX): int
    {
        $value = $data[$field] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw self::invalid($field);
        }
        return $value;
    }

    public static function boolean(array $data, string $field): bool
    {
        $value = $data[$field] ?? null;
        if (!is_bool($value)) {
            throw self::invalid($field);
        }
        return $value;
    }

    public static function list(array $data, string $field, int $min, int $max): array
    {
        $value = $data[$field] ?? null;
        if (!is_array($value) || !array_is_list($value) || count($value) < $min || count($value) > $max) {
            throw self::invalid($field);
        }
        return $value;
    }

    public static function choice(array $data, string $field, array $allowed): string
    {
        $value = $data[$field] ?? null;
        if (!in_array($value, $allowed, true)) {
            throw self::invalid($field);
        }
        return $value;
    }

    public static function slug(array $data, string $field): string
    {
        $value = self::string($data, $field, 64);
        if (!preg_match('/\A[a-z0-9]+(?:[-_][a-z0-9]+)*\z/', $value)) {
            throw self::invalid($field);
        }
        return $value;
    }

    public static function email(array $data): string
    {
        $value = strtolower(self::string($data, 'email', 254));
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw self::invalid('email');
        }
        return $value;
    }

    public static function password(array $data, bool $new = false): string
    {
        $value = $data['password'] ?? null;
        if (!is_string($value) || strlen($value) < ($new ? 12 : 1) || strlen($value) > 72 || str_contains($value, "\0")) {
            throw self::invalid('password');
        }
        return $value;
    }

    public static function date(array $data, string $field): string
    {
        $value = self::string($data, $field, 10);
        if (!preg_match('/\A([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw self::invalid($field);
        }
        return $value;
    }

    public static function requestKey(?string $key): string
    {
        if ($key === null || !preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $key)) {
            throw self::invalid('Idempotency-Key');
        }
        return $key;
    }

    private static function invalid(string $field): Problem
    {
        return new Problem(422, 'invalid_input', ['field' => $field]);
    }
}
