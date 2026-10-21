<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validation déclarative : ['champ' => 'required|max:120|numeric'].
 * Règles : required, max:n, min:n (numérique), numeric, int, email, in:a,b,c, date.
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     */
    public function __construct(private readonly array $data, private readonly array $rules)
    {
        $this->run();
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $empty = $value === null || $value === '';
            foreach (explode('|', $ruleString) as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                if ($name !== 'required' && $empty) {
                    continue;
                }
                $error = match ($name) {
                    'required' => $empty ? t('validation.required') : null,
                    'max' => mb_strlen((string) $value) > (int) $arg ? t('validation.max', ['max' => $arg]) : null,
                    'numeric' => !is_numeric($value) ? t('validation.numeric') : null,
                    'int' => filter_var($value, FILTER_VALIDATE_INT) === false ? t('validation.int') : null,
                    'min' => !is_numeric($value) || (float) $value < (float) $arg ? t('validation.min', ['min' => $arg]) : null,
                    'gt' => !is_numeric($value) || (float) $value <= (float) $arg ? t('validation.gt', ['min' => $arg]) : null,
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false ? t('validation.email') : null,
                    'in' => !in_array((string) $value, explode(',', (string) $arg), true) ? t('validation.in') : null,
                    'date' => !self::isDate((string) $value) ? t('validation.date') : null,
                    default => throw new \InvalidArgumentException("Règle inconnue : $name"),
                };
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break;
                }
            }
        }
    }

    /** createFromFormat accepte « 2026-13-40 » en débordant : on exige l'aller-retour exact. */
    private static function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d !== false && $d->format('Y-m-d') === $value;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
