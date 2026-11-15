<?php

namespace roadsterworks\craftshopifysync\enums;

/**
 * Select options for backed enums that have a `label(): string` method.
 */
trait HasOptions
{
    /**
     * @return array<int, array{label: string, value: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn(self $case): array => ['label' => $case->label(), 'value' => $case->value],
            self::cases(),
        );
    }
}
