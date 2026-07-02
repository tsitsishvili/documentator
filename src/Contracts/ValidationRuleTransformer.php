<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\Contracts;

/**
 * Allows host applications and integrations to teach Documentator about custom
 * validation rules without replacing the whole rule parser.
 */
interface ValidationRuleTransformer
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $rules
     * @return array<string, mixed>|null
     */
    public function transform(string $rule, array $schema, array $rules, string $field): ?array;
}
