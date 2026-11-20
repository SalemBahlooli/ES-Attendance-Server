<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\User;
use App\Support\Permissions\PermissionTree;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Sanity check for config/permission_tree.php:
 *   - every permission name is unique across the tree
 *   - every permission the code checks (can:, @can, hasAccess, Gate::) exists in the tree
 *   - groups / users don't hold permissions that no longer exist
 */
class CheckPermissions extends Command
{
    protected $signature = 'permissions:check';

    protected $description = 'Check that the permission tree and the code agree';

    public function handle(): int
    {
        $problems = 0;
        $seen = [];

        foreach (PermissionTree::areas() as $area) {
            foreach ($area['sections'] ?? [] as $section) {
                $this->collect($section['permissions'] ?? [], $seen, $problems);
            }
        }
        $this->info(count($seen).' permissions in the tree.');

        // Permissions used in code
        $used = [];
        $files = array_merge(File::allFiles(app_path()), File::allFiles(resource_path('views')), File::allFiles(base_path('routes')));
        foreach ($files as $file) {
            $text = $this->withoutComments($file->getContents(), $file->getFilename());
            preg_match_all("/(?:can:|@can(?:any)?\\(|hasAccess\\(|Gate::(?:allows|denies|authorize)\\()\\s*['\"]([a-z0-9_.]+)['\"]/i", $text, $m);
            foreach ($m[1] as $name) {
                $used[$name][] = $file->getRelativePathname();
            }
        }
        foreach ($used as $name => $where) {
            if (! PermissionTree::exists($name)) {
                $this->error("Used in code but missing from the tree: {$name}  (".implode(', ', array_unique($where)).')');
                $problems++;
            }
        }

        // Stale keys in the database
        try {
            foreach (Group::all() as $group) {
                foreach (array_keys((array) $group->permissions) as $key) {
                    if (! PermissionTree::exists($key)) {
                        $this->warn("Group \"{$group->name}\" holds an unknown permission: {$key}");
                    }
                }
            }
            foreach (User::whereNotNull('permissions')->get() as $user) {
                foreach (array_keys((array) $user->permissions) as $key) {
                    if (! PermissionTree::exists($key)) {
                        $this->warn("User #{$user->id} holds an unknown permission: {$key}");
                    }
                }
            }
        } catch (\Throwable) {
            $this->line('(database not reachable: skipped the group/user check)');
        }

        if ($problems === 0) {
            $this->info('All good.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /** Examples in comments are not real checks. */
    private function withoutComments(string $text, string $filename): string
    {
        $text = preg_replace('/\{\{--.*?--\}\}/s', '', $text);
        if (! str_ends_with($filename, '.php') || str_ends_with($filename, '.blade.php')) {
            return $text;
        }

        $out = '';
        foreach (token_get_all($text) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function collect(array $nodes, array &$seen, int &$problems): void
    {
        foreach ($nodes as $name => $node) {
            if (isset($seen[$name])) {
                $this->error("Duplicate permission name: {$name}");
                $problems++;
            }
            $seen[$name] = true;
            if (empty($node['label'])) {
                $this->warn("Permission without a label: {$name}");
            }
            if (! empty($node['children'])) {
                $this->collect($node['children'], $seen, $problems);
            }
        }
    }
}
