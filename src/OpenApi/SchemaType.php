<?php

declare(strict_types=1);

namespace Tsitsishvili\Documentator\OpenApi;

/**
 * Tiny internal type helper used by source-analysis extractors before they
 * become OpenAPI schemas. It keeps new AST inference from scattering the same
 * name/method heuristics across strategies.
 */
final class SchemaType
{
    private function __construct(
        private readonly string $type,
        private readonly ?string $format = null,
        private readonly ?self $items = null,
    ) {}

    public static function string(?string $format = null): self
    {
        return new self('string', $format);
    }

    public static function integer(): self
    {
        return new self('integer');
    }

    public static function number(): self
    {
        return new self('number');
    }

    public static function boolean(): self
    {
        return new self('boolean');
    }

    public static function array(?self $items = null): self
    {
        return new self('array', items: $items ?? self::string());
    }

    public static function fromRequestAccessor(string $method, string $name): self
    {
        return match (strtolower($method)) {
            'integer', 'int' => self::integer(),
            'float', 'double', 'number' => self::number(),
            'boolean', 'bool' => self::boolean(),
            'array', 'collect' => self::array(),
            'date' => self::string('date-time'),
            'file' => self::string('binary'),
            default => self::fromName($name),
        };
    }

    public static function fromName(string $name): self
    {
        $normalized = strtolower($name);
        $leaf = preg_replace('/^.*\[([^\]]+)\]$/', '$1', $normalized) ?: $normalized;

        return match (true) {
            $leaf === 'id', str_ends_with($leaf, '_id'), str_ends_with($leaf, 'ids') => self::integer(),
            str_ends_with($leaf, '_at'), str_ends_with($leaf, '_date') => self::string('date-time'),
            $leaf === 'email', str_ends_with($leaf, '_email') => self::string('email'),
            str_ends_with($leaf, '_url'), str_ends_with($leaf, '_uri') => self::string('uri'),
            str_starts_with($leaf, 'is_'), str_starts_with($leaf, 'has_'), str_starts_with($leaf, 'can_') => self::boolean(),
            default => self::string(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $schema = ['type' => $this->type];

        if ($this->format !== null) {
            $schema['format'] = $this->format;
        }

        if ($this->items !== null) {
            $schema['items'] = $this->items->toSchema();
        }

        return $schema;
    }
}
