<?php

/*
 * ernestdefoe/page-builder's block contract, for PHPStan only (listed under
 * scanFiles, never loaded). Page Builder is an optional, private package, so it
 * cannot be a dev dependency; LeaderboardBlock is registered only when it is
 * installed. This mirrors the two types it extends, as Page Builder declares
 * them, and the extender extend.php registers the block with.
 */

namespace Ernestdefoe\PageBuilder\Block;

use Flarum\User\User;

interface BlockInterface
{
    public function type(): string;

    public function name(): string;

    public function icon(): string;

    public function category(): string;

    /** @return array<string, mixed> */
    public function defaultSettings(): array;

    /** @return list<array<string, mixed>> */
    public function settingsSchema(): array;

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function resolve(array $settings, User $actor): array;
}

abstract class AbstractBlock implements BlockInterface
{
    public function category(): string
    {
        return 'content';
    }

    public function defaultSettings(): array
    {
        return [];
    }

    public function settingsSchema(): array
    {
        return [];
    }

    public function resolve(array $settings, User $actor): array
    {
        return [];
    }
}

namespace Ernestdefoe\PageBuilder\Extend;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Illuminate\Contracts\Container\Container;

class PageBuilderBlock implements ExtenderInterface
{
    public function __construct(protected string $blockClass)
    {
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
    }
}
