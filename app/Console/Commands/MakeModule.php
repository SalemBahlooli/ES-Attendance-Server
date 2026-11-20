<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Scaffold a complete module (CRUD) that follows every convention of this template:
 *
 *   php artisan make:module Project
 *   php artisan make:module Project --fields="name:string, code:string:nullable, budget:decimal, starts_on:date, is_active:boolean, notes:text:nullable"
 *   php artisan make:module Project --icon=fa-folder-open --label="Projects"
 *
 * Creates: model (with activity logging), migration, factory, form request,
 * controller, views (list / form / details), routes/modules/<name>.php,
 * a feature test, a permission block in config/permission_tree.php and a
 * sidebar item in config/menu.php.
 *
 * Field types: string, text, email, integer, decimal, boolean, date, datetime
 * Add ":nullable" to make a field optional. The first field is the record's name.
 */
class MakeModule extends Command
{
    protected $signature = 'make:module
        {name : Singular model name, e.g. Project or PurchaseOrder}
        {--fields= : Comma separated name:type[:nullable] list}
        {--icon=fa-cube : Font Awesome icon for the menu}
        {--label= : Plural label for the menu and pages (default from the name)}
        {--force : Overwrite files that already exist}';

    protected $description = 'Create a full CRUD module: model, migration, controller, views, routes, permissions, menu and test';

    private const TYPES = ['string', 'text', 'email', 'integer', 'decimal', 'boolean', 'date', 'datetime'];

    public function handle(): int
    {
        $model = Str::studly(Str::singular($this->argument('name')));
        $fields = $this->parseFields((string) ($this->option('fields') ?: 'name:string, description:text:nullable'));
        if ($fields === null) {
            return self::FAILURE;
        }

        $plural = Str::pluralStudly($model);
        $vars = [
            'Model' => $model,
            'model' => Str::camel($model),
            'Models' => $plural,
            'models' => Str::camel($plural),
            'table' => Str::snake($plural),
            'route' => Str::kebab($plural),
            'view' => Str::kebab($plural),
            'permission' => Str::snake($plural),
            'label' => Str::headline($model),
            'labelPlural' => $this->option('label') ?: Str::headline($plural),
            'icon' => $this->option('icon'),
            'titleField' => $fields[0]['name'],
        ] + $this->chunks($fields);

        $files = [
            'model.stub' => app_path("Models/{$model}.php"),
            'migration.stub' => database_path('migrations/'.date('Y_m_d_His')."_create_{$vars['table']}_table.php"),
            'factory.stub' => database_path("factories/{$model}Factory.php"),
            'request.stub' => app_path("Http/Requests/{$model}Request.php"),
            'controller.stub' => app_path("Http/Controllers/{$model}Controller.php"),
            'routes.stub' => base_path("routes/modules/{$vars['route']}.php"),
            'index.stub' => resource_path("views/{$vars['view']}/index.blade.php"),
            'form.stub' => resource_path("views/{$vars['view']}/form.blade.php"),
            'show.stub' => resource_path("views/{$vars['view']}/show.blade.php"),
            'test.stub' => base_path("tests/Feature/Modules/{$model}Test.php"),
        ];

        if (! $this->option('force')) {
            foreach ($files as $target) {
                if (File::exists($target)) {
                    $this->error('Already exists: '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $target).' (use --force to overwrite)');

                    return self::FAILURE;
                }
            }
            if (glob(database_path("migrations/*_create_{$vars['table']}_table.php"))) {
                $this->error("A migration for the {$vars['table']} table already exists.");

                return self::FAILURE;
            }
        }

        foreach ($files as $stub => $target) {
            File::ensureDirectoryExists(dirname($target));
            File::put($target, $this->render($stub, $vars));
            $this->line('  <fg=green>created</> '.str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $target));
        }

        $this->insertPermissions($vars);
        $this->insertMenu($vars);

        $this->newLine();
        $this->info("Module {$model} is ready. Next:");
        $this->line('  1. Check the migration, then run:   php artisan migrate');
        $this->line("  2. Give a group the new \"{$vars['labelPlural']}\" permissions (Groups & Permissions → Modules).");
        $this->line('  3. Arabic labels:                  php artisan lang:missing ar --write   (then translate lang/ar.json)');
        $this->line("  4. Run its test:                   php artisan test --filter={$model}Test");

        return self::SUCCESS;
    }

    /** @return array<int, array{name:string, type:string, nullable:bool}>|null */
    private function parseFields(string $spec): ?array
    {
        $fields = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $part) {
            $bits = array_map('trim', explode(':', $part));
            $name = Str::snake($bits[0]);
            $type = strtolower($bits[1] ?? 'string');
            if (! in_array($type, self::TYPES, true)) {
                $this->error("Unknown type \"{$type}\" for {$name}. Use one of: ".implode(', ', self::TYPES));

                return null;
            }
            $fields[] = ['name' => $name, 'type' => $type, 'nullable' => in_array('nullable', $bits, true) || $type === 'boolean'];
        }

        if (! $fields) {
            $this->error('At least one field is needed.');

            return null;
        }

        return $fields;
    }

    /** The generated code fragments that go into the stubs. */
    private function chunks(array $fields): array
    {
        $columns = $fillable = $casts = $rules = $form = $headers = $cells = $show = $factory = $search = [];

        foreach ($fields as $i => $f) {
            [$name, $type, $nullable] = [$f['name'], $f['type'], $f['nullable']];
            $label = Str::headline($name);

            $columns[] = match ($type) {
                'text' => "\$table->text('{$name}')",
                'integer' => "\$table->integer('{$name}')",
                'decimal' => "\$table->decimal('{$name}', 12, 2)",
                'boolean' => "\$table->boolean('{$name}')->default(false)",
                'date' => "\$table->date('{$name}')",
                'datetime' => "\$table->dateTime('{$name}')",
                default => "\$table->string('{$name}')",
            }.($nullable && $type !== 'boolean' ? '->nullable()' : '').($i === 0 && $type === 'string' ? '->index()' : '').';';

            $fillable[] = "'{$name}'";

            if ($cast = match ($type) {
                'integer' => 'integer', 'decimal' => 'decimal:2', 'boolean' => 'boolean', 'date' => 'date', 'datetime' => 'datetime', default => null
            }) {
                $casts[] = "'{$name}' => '{$cast}',";
            }

            $rule = [$nullable ? 'nullable' : 'required'];
            $rule = array_merge($rule, match ($type) {
                'text' => ['string', 'max:10000'],
                'email' => ['email', 'max:255'],
                'integer' => ['integer'],
                'decimal' => ['numeric'],
                'boolean' => ['boolean'],
                'date', 'datetime' => ['date'],
                default => ['string', 'max:255'],
            });
            $rules[] = "'{$name}' => ['".implode("', '", $rule)."'],";

            $required = $nullable ? '' : ' required';
            $form[] = match ($type) {
                'text' => "<x-textarea name=\"{$name}\" :label=\"__('{$label}')\" :value=\"\$record->{$name}\" rows=\"4\" class=\"sm:col-span-2\"{$required} />",
                'boolean' => "<x-toggle name=\"{$name}\" :label=\"__('{$label}')\" :checked=\"(bool) \$record->{$name}\" class=\"sm:col-span-2\" />",
                'integer' => "<x-input name=\"{$name}\" type=\"number\" step=\"1\" :label=\"__('{$label}')\" :value=\"\$record->{$name}\"{$required} />",
                'decimal' => "<x-input name=\"{$name}\" type=\"number\" step=\"0.01\" :label=\"__('{$label}')\" :value=\"\$record->{$name}\"{$required} />",
                'date' => "<x-input name=\"{$name}\" type=\"date\" :label=\"__('{$label}')\" :value=\"\$record->{$name}?->format('Y-m-d')\"{$required} />",
                'datetime' => "<x-input name=\"{$name}\" type=\"datetime-local\" :label=\"__('{$label}')\" :value=\"\$record->{$name}?->format('Y-m-d\\TH:i')\"{$required} />",
                'email' => "<x-input name=\"{$name}\" type=\"email\" :label=\"__('{$label}')\" :value=\"\$record->{$name}\"{$required} />",
                default => "<x-input name=\"{$name}\" :label=\"__('{$label}')\" :value=\"\$record->{$name}\"{$required} />",
            };

            $display = match ($type) {
                'boolean' => "@if (\$record->{$name})<x-badge color=\"green\">{{ __('Yes') }}</x-badge>@else<x-badge>{{ __('No') }}</x-badge>@endif",
                'date' => "{{ format_date(\$record->{$name}) }}",
                'datetime' => "{{ format_datetime(\$record->{$name}) }}",
                'decimal' => "{{ \$record->{$name} !== null ? number_format((float) \$record->{$name}, 2) : '' }}",
                default => "{{ \$record->{$name} }}",
            };

            if ($i > 0 && $type !== 'text' && count($headers) < 3) {
                $headers[] = "<th class=\"hidden md:table-cell\">{{ __('{$label}') }}</th>";
                $cells[] = '<td class="hidden text-slate-600 md:table-cell">'.$display.'</td>';
            }

            $show[] = '<div'.($type === 'text' ? ' class="sm:col-span-2"' : '').">\n                    <dt class=\"text-xs font-medium text-slate-500\">{{ __('{$label}') }}</dt>\n                    <dd class=\"mt-0.5 text-slate-800".($type === 'text' ? ' whitespace-pre-line' : '').'">'.$display."</dd>\n                </div>";

            $factory[] = "'{$name}' => ".match ($type) {
                'text' => 'fake()->paragraph()',
                'email' => 'fake()->unique()->safeEmail()',
                'integer' => 'fake()->numberBetween(1, 1000)',
                'decimal' => 'fake()->randomFloat(2, 1, 10000)',
                'boolean' => 'fake()->boolean()',
                'date' => 'fake()->date()',
                'datetime' => 'fake()->dateTime()',
                default => $i === 0 ? 'fake()->unique()->words(3, true)' : 'fake()->words(2, true)',
            }.',';

            if (in_array($type, ['string', 'email', 'text'], true)) {
                $search[] = "'{$name}'";
            }
        }

        $indent = fn (array $lines, int $spaces) => implode("\n".str_repeat(' ', $spaces), $lines);

        return [
            'migrationColumns' => $indent($columns, 12),
            'fillable' => implode(', ', $fillable),
            'casts' => $casts ? $indent($casts, 12) : '//',
            'rules' => $indent($rules, 12),
            'formFields' => $indent($form, 20),
            'tableHeaders' => $indent($headers, 24),
            'tableCells' => $indent($cells, 32),
            'showFields' => $indent($show, 16),
            'factoryFields' => $indent($factory, 12),
            'searchColumns' => implode(', ', $search ?: ["'".$fields[0]['name']."'"]),
            'firstValue' => match ($fields[0]['type']) {
                'integer', 'decimal' => '123',
                'boolean' => 'true',
                'date' => "'2026-01-31'",
                'datetime' => "'2026-01-31 10:00:00'",
                'email' => "'first@example.com'",
                default => "'First one'",
            },
            'requiredPayload' => $indent(collect($fields)->reject(fn ($f) => $f['nullable'] && $f !== $fields[0])->map(fn ($f) => "'{$f['name']}' => ".match ($f['type']) {
                'integer', 'decimal' => '123',
                'boolean' => 'true',
                'date' => "'2026-01-31'",
                'datetime' => "'2026-01-31 10:00:00'",
                'email' => "'first@example.com'",
                'text' => "'Some text'",
                default => "'First one'",
            }.',')->values()->all(), 12),
        ];
    }

    private function render(string $stub, array $vars): string
    {
        $path = base_path('stubs/module/'.$stub);
        $text = File::get($path);

        foreach ($vars as $key => $value) {
            $text = str_replace('%%'.$key.'%%', (string) $value, $text);
        }

        return $text;
    }

    private function insertPermissions(array $v): void
    {
        $path = config_path('permission_tree.php');
        $marker = '// @module-permissions';
        $text = File::get($path);

        if (str_contains($text, "'{$v['permission']}.view'")) {
            $this->line("  <fg=yellow>skipped</> config/permission_tree.php ({$v['permission']}.view exists)");

            return;
        }

        $block = <<<PHP
            '{$v['labelPlural']}' => [
                            'icon' => '{$v['icon']}',
                            'description' => '{$v['labelPlural']}',
                            'permissions' => [
                                '{$v['permission']}.view' => [
                                    'label' => 'View {$v['labelPlural']}',
                                    'children' => [
                                        '{$v['permission']}.create' => ['label' => 'Create'],
                                        '{$v['permission']}.edit' => ['label' => 'Edit'],
                                        '{$v['permission']}.delete' => ['label' => 'Delete'],
                                    ],
                                ],
                            ],
                        ],

                        {$marker}
            PHP;

        File::put($path, str_replace($marker, ltrim($block), $text));
        $this->line('  <fg=green>updated</> config/permission_tree.php');
    }

    private function insertMenu(array $v): void
    {
        $path = config_path('menu.php');
        $marker = '// @module-menu';
        $text = File::get($path);

        if (str_contains($text, "'{$v['route']}.index'")) {
            $this->line('  <fg=yellow>skipped</> config/menu.php (already there)');

            return;
        }

        $line = "['label' => '{$v['labelPlural']}', 'icon' => '{$v['icon']}', 'route' => '{$v['route']}.index', 'active' => '{$v['route']}.*', 'can' => '{$v['permission']}.view', 'shortcut' => true,\n"
            ."        'create' => ['route' => '{$v['route']}.create', 'can' => '{$v['permission']}.create', 'label' => '{$v['label']}']],\n    {$marker}";
        File::put($path, str_replace($marker, $line, $text));
        $this->line('  <fg=green>updated</> config/menu.php');
    }
}
