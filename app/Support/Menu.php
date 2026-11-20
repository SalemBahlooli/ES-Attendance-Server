<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Builds the sidebar from config/menu.php for one user: hides what they
 * can't open, drops empty sections, and marks the active item.
 */
class Menu
{
    /** @return array<int, array> */
    public static function for(?User $user): array
    {
        $items = self::filter(config('menu', []), $user);

        // Drop headings that have nothing under them before the next heading.
        $out = [];
        foreach ($items as $i => $item) {
            if (isset($item['heading'])) {
                $next = $items[$i + 1] ?? null;
                if (! $next || isset($next['heading'])) {
                    continue;
                }
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Items marked 'shortcut' => true: shown as icons in the top bar.
     *
     * @return array<int, array>
     */
    public static function shortcuts(array $menu): array
    {
        return collect($menu)
            ->flatMap(fn ($item) => [$item, ...($item['children'] ?? [])])
            ->filter(fn ($item) => ! empty($item['shortcut']) && isset($item['url']))
            ->values()
            ->all();
    }

    /**
     * Items with a 'create' entry: the top bar's "Create" menu.
     *   'create' => ['route' => 'users.create', 'can' => 'users.create', 'label' => 'User']
     *
     * @return array<int, array{label: string, icon: string, url: string}>
     */
    public static function creatable(array $menu, ?User $user): array
    {
        return collect($menu)
            ->flatMap(fn ($item) => [$item, ...($item['children'] ?? [])])
            ->filter(fn ($item) => ! empty($item['create']['route']) && Route::has($item['create']['route']))
            ->filter(fn ($item) => empty($item['create']['can']) || $user?->hasAccess($item['create']['can']))
            ->map(fn ($item) => [
                'label' => $item['create']['label'] ?? $item['label'],
                'icon' => $item['icon'] ?? 'fa-plus',
                'url' => route($item['create']['route']),
            ])
            ->values()
            ->all();
    }

    /** The icon of the open page's menu item (for the page title). */
    public static function activeIcon(array $menu): ?string
    {
        foreach ($menu as $item) {
            foreach ($item['children'] ?? [] as $child) {
                if (! empty($child['active'])) {
                    return $child['icon'] ?? $item['icon'] ?? null;
                }
            }
            if (! empty($item['active']) && empty($item['children'])) {
                return $item['icon'] ?? null;
            }
        }

        return null;
    }

    private static function filter(array $items, ?User $user): array
    {
        $out = [];
        foreach ($items as $item) {
            if (isset($item['heading'])) {
                $out[] = $item;

                continue;
            }
            if (! empty($item['can']) && ! $user?->hasAccess($item['can'])) {
                continue;
            }

            if (! empty($item['children'])) {
                $item['children'] = self::filter($item['children'], $user);
                if (! $item['children']) {
                    continue;
                }
                $item['active'] = collect($item['children'])->contains('active', true);
            } else {
                if (empty($item['route']) || ! Route::has($item['route'])) {
                    continue;
                }
                $item['url'] = route($item['route']);
                $item['active'] = request()->routeIs(...(array) ($item['active'] ?? [$item['route'], $item['route'].'.*']));
            }

            $out[] = $item;
        }

        return array_values($out);
    }
}
