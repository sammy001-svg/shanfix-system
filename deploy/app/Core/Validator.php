<?php
namespace App\Core;

/**
 * Minimal rule-based validator.
 *
 *   $v = new Validator($request->all());
 *   $v->require('name', 'Client name')
 *     ->email('email', 'Email address')
 *     ->numeric('price', 'Selling price')
 *     ->maxLen('notes', 2000, 'Notes');
 *
 *   if ($v->fails()) { ... $v->errors() ... }
 */
class Validator
{
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    private function value(string $field): mixed
    {
        $val = $this->data[$field] ?? null;
        return is_string($val) ? trim($val) : $val;
    }

    private function add(string $field, string $message): void
    {
        // Keep only the first error per field so forms stay readable.
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    private function isBlank(string $field): bool
    {
        $v = $this->value($field);
        return $v === null || $v === '' || (is_array($v) && $v === []);
    }

    public function require(string $field, string $label): self
    {
        if ($this->isBlank($field)) {
            $this->add($field, "{$label} is required.");
        }
        return $this;
    }

    public function email(string $field, string $label, bool $required = false): self
    {
        if ($this->isBlank($field)) {
            if ($required) {
                $this->add($field, "{$label} is required.");
            }
            return $this;
        }

        if (!filter_var($this->value($field), FILTER_VALIDATE_EMAIL)) {
            $this->add($field, "{$label} must be a valid email address.");
        }
        return $this;
    }

    /** Accepts 07XXXXXXXX, 01XXXXXXXX, +2547XXXXXXXX, 2547XXXXXXXX */
    public function phone(string $field, string $label, bool $required = false): self
    {
        if ($this->isBlank($field)) {
            if ($required) {
                $this->add($field, "{$label} is required.");
            }
            return $this;
        }

        $digits = preg_replace('/\D+/', '', (string) $this->value($field));

        if (strlen($digits) < 9 || strlen($digits) > 15) {
            $this->add($field, "{$label} must be a valid phone number.");
        }
        return $this;
    }

    public function numeric(string $field, string $label, bool $required = false): self
    {
        if ($this->isBlank($field)) {
            if ($required) {
                $this->add($field, "{$label} is required.");
            }
            return $this;
        }

        $clean = str_replace(',', '', (string) $this->value($field));
        if (!is_numeric($clean)) {
            $this->add($field, "{$label} must be a number.");
        }
        return $this;
    }

    public function min(string $field, float $min, string $label): self
    {
        if ($this->isBlank($field)) {
            return $this;
        }

        $clean = (float) str_replace(',', '', (string) $this->value($field));
        if ($clean < $min) {
            $this->add($field, "{$label} must be at least " . rtrim(rtrim(number_format($min, 2), '0'), '.') . '.');
        }
        return $this;
    }

    public function maxLen(string $field, int $max, string $label): self
    {
        if ($this->isBlank($field)) {
            return $this;
        }

        if (mb_strlen((string) $this->value($field)) > $max) {
            $this->add($field, "{$label} must not exceed {$max} characters.");
        }
        return $this;
    }

    public function minLen(string $field, int $min, string $label): self
    {
        if ($this->isBlank($field)) {
            return $this;
        }

        if (mb_strlen((string) $this->value($field)) < $min) {
            $this->add($field, "{$label} must be at least {$min} characters.");
        }
        return $this;
    }

    public function in(string $field, array $allowed, string $label): self
    {
        if ($this->isBlank($field)) {
            return $this;
        }

        if (!in_array((string) $this->value($field), $allowed, true)) {
            $this->add($field, "{$label} is not a valid choice.");
        }
        return $this;
    }

    public function date(string $field, string $label, bool $required = false): self
    {
        if ($this->isBlank($field)) {
            if ($required) {
                $this->add($field, "{$label} is required.");
            }
            return $this;
        }

        $val = (string) $this->value($field);
        $d   = \DateTime::createFromFormat('Y-m-d', $val);

        if (!$d || $d->format('Y-m-d') !== $val) {
            $this->add($field, "{$label} must be a valid date.");
        }
        return $this;
    }

    public function matches(string $field, string $otherField, string $label): self
    {
        if ($this->value($field) !== $this->value($otherField)) {
            $this->add($field, "{$label} do not match.");
        }
        return $this;
    }

    /**
     * Fail when a value already exists in $table.$column.
     * $ignoreId lets an edit form keep its own value.
     */
    public function unique(string $field, string $table, string $column, string $label, ?int $ignoreId = null): self
    {
        if ($this->isBlank($field)) {
            return $this;
        }

        $sql    = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :val";
        $params = ['val' => $this->value($field)];

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :ignore';
            $params['ignore'] = $ignoreId;
        }

        if ((int) Database::scalar($sql, $params, 0) > 0) {
            $this->add($field, "{$label} is already in use.");
        }
        return $this;
    }

    /** Fail when a foreign key points at a row that does not exist. */
    public function exists(string $field, string $table, string $label): self
    {
        if ($this->isBlank($field)) {
            return $this;
        }

        $found = (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE id = :id", ['id' => $this->value($field)], 0);

        if ($found === 0) {
            $this->add($field, "The selected {$label} no longer exists.");
        }
        return $this;
    }

    public function custom(string $field, bool $passes, string $message): self
    {
        if (!$passes) {
            $this->add($field, $message);
        }
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors === [] ? null : reset($this->errors);
    }

    /**
     * Flash errors + old input and bounce back to the form.
     */
    public function redirectBack(string $fallback = '/dashboard'): never
    {
        Session::flashErrors($this->errors);
        Session::flashInput($this->data);
        Session::error($this->firstError() ?? 'Please correct the highlighted fields.');
        Response::back($fallback);
    }
}
