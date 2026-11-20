<?php

namespace App\Support\Permissions;

/**
 * One reader for config/permission_tree.php.
 *
 * The tree is the single source of truth: areas → sections → permissions,
 * where a permission may hold finer `children`. Everything else (the group
 * and user permission pages, the Gate, the parent-deny rule in
 * User::hasAccess(), the permissions:check command) asks this class.
 */
class PermissionTree
{
    /** @var array<string, array>|null  name => meta, built once per request */
    private static ?array $index = null;

    /** The tree as written in config/permission_tree.php (empty sections dropped). */
    public static function areas(): array
    {
        $areas = config('permission_tree') ?: [];

        return array_filter($areas, fn ($area) => ! empty($area['sections']));
    }

    /**
     * Every permission: name => meta
     *   label, note, section, area, parent (nearest permission above it), depth, sensitive, hidden.
     */
    public static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];
        foreach (self::areas() as $areaKey => $area) {
            foreach ($area['sections'] ?? [] as $sectionName => $section) {
                self::walk($section['permissions'] ?? [], function ($name, $node, $parent, $depth) use (&$index, $areaKey, $sectionName) {
                    $index[$name] = [
                        'label' => $node['label'] ?? $name,
                        'note' => $node['note'] ?? '',
                        'section' => $sectionName,
                        'area' => $areaKey,
                        'parent' => $parent,
                        'depth' => $depth,
                        'sensitive' => ! empty($node['sensitive']),
                        'hidden' => ! empty($node['hidden']),
                    ];
                });
            }
        }

        return self::$index = $index;
    }

    /** Permission names only. */
    public static function names(): array
    {
        return array_keys(self::index());
    }

    public static function exists(string $name): bool
    {
        return array_key_exists($name, self::index());
    }

    public static function label(string $name): string
    {
        return __(self::index()[$name]['label'] ?? $name);
    }

    public static function parent(string $name): ?string
    {
        return self::index()[$name]['parent'] ?? null;
    }

    /** Permissions above this one, nearest first. */
    public static function ancestors(string $name): array
    {
        $out = [];
        $seen = [];
        while (($name = self::parent($name)) !== null && ! isset($seen[$name])) {
            $seen[$name] = true;
            $out[] = $name;
        }

        return $out;
    }

    /** Permissions anywhere below this one. */
    public static function descendants(string $name): array
    {
        $out = [];
        foreach (self::index() as $child => $meta) {
            if (in_array($name, self::ancestors($child), true)) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /**
     * Keep only known permissions with a valid value (1 or -1); drop "inherit" (0 / '').
     *
     * @param  array<string, mixed>|null  $submitted
     * @return array<string, int>
     */
    public static function normalize(?array $submitted): array
    {
        $out = [];
        foreach ((array) $submitted as $key => $value) {
            if (! is_string($key) || ! self::exists($key)) {
                continue;
            }
            $value = (int) $value;
            if ($value === 1 || $value === -1) {
                $out[$key] = $value;
            }
        }
        ksort($out);

        return $out;
    }

    /** Forget the cached index (tests change config at runtime). */
    public static function flush(): void
    {
        self::$index = null;
    }

    /**
     * Depth-first walk: $visit($name, $node, $parentPermission, $depth).
     */
    private static function walk(array $nodes, callable $visit, ?string $parent = null, int $depth = 0): void
    {
        foreach ($nodes as $name => $node) {
            $visit($name, $node, $parent, $depth);
            if (! empty($node['children'])) {
                self::walk($node['children'], $visit, $name, $depth + 1);
            }
        }
    }
}
