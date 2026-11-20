# Modules: adding a feature

A "module" is one feature of your project: projects, invoices, assets, tickets...

## The quick way: `make:module`

```bash
php artisan make:module Project \
    --fields="name:string,client_email:email:nullable,budget:decimal:nullable,starts_on:date:nullable,notes:text:nullable,is_done:boolean" \
    --icon=fa-diagram-project \
    --label="Projects"
php artisan migrate
php artisan lang:missing ar --write     # then translate the new keys in lang/ar.json
php artisan test --filter=ProjectTest
```

Field types: `string`, `text`, `email`, `integer`, `decimal`, `boolean`, `date`, `datetime`.
Add `:nullable` to make a field optional. `--force` overwrites files that already exist.

It creates:

| File | What |
|---|---|
| `app/Models/Project.php` | model with `LogsActivity` (every change goes to the activity log) and soft deletes |
| `database/migrations/..._create_projects_table.php` | the table, with `created_by` |
| `database/factories/ProjectFactory.php` | for tests and demo data |
| `app/Http/Requests/ProjectRequest.php` | validation built from the field types |
| `app/Http/Controllers/ProjectController.php` | list with search and pagination, create, show, edit, delete; permissions in `middleware()` |
| `resources/views/projects/{index,form,show}.blade.php` | the pages, using the template's components |
| `routes/modules/projects.php` | `Route::resource('projects', ...)`, loaded automatically |
| `tests/Feature/Modules/ProjectTest.php` | permissions, create, update, delete |

And it edits:

| File | Change |
|---|---|
| `config/permission_tree.php` | `projects.view` with children `projects.create`, `projects.edit`, `projects.delete`, inserted above `// @module-permissions` |
| `config/menu.php` | a menu item shown to people with `projects.view`, also an icon in the top bar and an entry in its blue "Create" menu, inserted above `// @module-menu` |

Then open **Groups & Permissions**, edit a group, and allow the new permissions under **Modules**.
The generated code is a starting point: change it freely.

## By hand

The same steps, if the feature does not fit a list/form/details shape:

1. **Migration and model.** `php artisan make:model Invoice -mf`. Add `use LogsActivity;` to the model and,
   optionally, `activityLabel()` to say how the record appears in the log.
2. **Permissions.** Add a section to `config/permission_tree.php` under `modules` (copy an existing section, or a block written by `make:module`).
   Use dotted names: `invoices.view`, `invoices.approve`.
3. **Routes.** Create `routes/modules/invoices.php`. It is loaded inside the signed-in group, so you only add
   the permission:

   ```php
   Route::middleware('can:invoices.view')->group(function () {
       Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
       Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve'])
           ->middleware('can:invoices.approve')->name('invoices.approve');
   });
   ```

   API routes for a module go in `routes/modules/api/invoices.php` (prefix `/api/v1`, token auth).
4. **Views.** Start from `resources/views/users/index.blade.php` or a generated module. Use
   `<x-layouts.app :title="__('Invoices')">`, `<x-card>`, `<x-input>`, `<x-select>`, `<x-toggle>`,
   `<x-empty>`, `<x-delete-button>`, and `@can('invoices.approve') ... @endcan` around buttons.
5. **Menu.** Add an item to `config/menu.php` with `'can' => 'invoices.view'`. Add `'shortcut' => true` for a top-bar icon and `'create' => ['route' => 'invoices.create', 'can' => 'invoices.create', 'label' => 'Invoice']` for the "Create" menu.
6. **Text.** Write English inside `__('...')` and run `php artisan lang:missing ar --write`.
7. **Tests.** Copy a generated module test. `$this->signInWith(['invoices.view'])` signs in a person with
   exactly those permissions.
8. **Check.** `php artisan permissions:check` makes sure every permission you used exists in the tree.

## Dashboard figures

`DashboardController` collects the figures. Add yours to `figures()` and show them with
`<x-stat :label="__('Open invoices')" :value="$stats['invoices']" icon="fa-file-invoice" />`.
Guard them with `dashboard.view` (or your module's own permission) like the existing ones.

## Removing a module

Delete the files listed above, its block in `config/permission_tree.php` and its line in `config/menu.php`,
then roll back or drop its table. Run `php artisan permissions:check` and the tests.
