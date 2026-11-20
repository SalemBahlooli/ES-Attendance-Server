# Translations and right-to-left

The interface is written in English. Every text goes through Laravel's `__()`, and
**`lang/ar.json`** maps each English text to Arabic. Arabic switches the whole layout to right-to-left.

## Writing text

```blade
<h1>{{ __('Invoices') }}</h1>
<p>{{ __(':count invoices waiting', ['count' => $waiting]) }}</p>
<x-input name="number" :label="__('Invoice number')" />
```

```php
return back()->with('success', __('Invoice approved.'));
```

Labels in `config/permission_tree.php`, `config/settings.php` and `config/menu.php` are translated the same way,
so write them in English there too.

## Finding what is missing

```bash
php artisan lang:missing ar           # list English texts without an Arabic translation
php artisan lang:missing ar --write   # add them to lang/ar.json with the English text as a placeholder
```

Then translate the new entries in `lang/ar.json`. The command scans `app/`, `resources/views/`, `routes/` and the labels in `config/menu.php`, `config/permission_tree.php` and `config/settings.php`.
CI fails when a translation is missing, so nothing ships half translated.

Laravel's own messages (validation, passwords, pagination) are in `lang/ar/*.php` and `lang/en/*.php`.
Add a validation attribute name there under `attributes` when a field's name reads badly in an error.

## Right-to-left

- `<html dir="rtl">` is set from `config/settings.php → locales.<code>.dir`.
- Use Tailwind's logical utilities so spacing flips by itself: `ms-2` / `me-2` (not `ml-2` / `mr-2`),
  `ps-4` / `pe-4`, `start-0` / `end-0`, `text-start` / `text-end`, `rounded-s` / `rounded-e`.
- For icons that point a direction add `rtl:-scale-x-100` to mirror them, e.g. `<i class="fa-solid fa-arrow-right rtl:-scale-x-100"></i>`.
- `is_rtl()` tells you the direction in PHP and Blade.
- Fonts: Inter for Latin text, IBM Plex Sans Arabic for Arabic, both served from your own domain (no Google Fonts request).

## Adding a language

1. Add it to `config/settings.php`:

   ```php
   'locales' => [
       'en' => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr'],
       'ar' => ['name' => 'Arabic',  'native' => 'العربية', 'dir' => 'rtl'],
       'fr' => ['name' => 'French',  'native' => 'Français', 'dir' => 'ltr'],
   ],
   ```

2. `php artisan lang:missing fr --write` creates `lang/fr.json`. Translate it.
3. `php artisan lang:publish` is already done for English; copy `lang/en/*.php` to `lang/fr/` and translate.

The language appears in the language switch, on profiles and in **Settings → General → Default language**.
