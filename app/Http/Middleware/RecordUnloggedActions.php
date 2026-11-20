<?php

namespace App\Http\Middleware;

use App\Support\Activity;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Safety net for the activity log: every signed-in request that changed
 * something, or handed out a file, and did NOT write a log row of its own
 * gets one here.
 *
 *   - a file sent to the browser             → file_downloaded / file_opened (with the file name)
 *   - any other successful POST/PUT/PATCH/DELETE → other, described from its route name
 *
 * Requests that already logged something specific (a model update, a password
 * change...) are left alone, so nothing is counted twice.
 */
class RecordUnloggedActions
{
    /** Background chatter, or logged elsewhere (sign-in / sign-out). */
    private const IGNORE_ROUTES = [
        'login*', 'logout', 'two-factor.*', 'password.*', 'heartbeat', 'notifications.*', 'locale.switch',
        'auth.microsoft*', 'register*',
    ];

    /** Files that are part of a page, not something a person chose to download. */
    private const IGNORE_FILES = ['avatar', 'logo', 'favicon', 'qr', 'thumb', 'build/'];

    public function handle(Request $request, Closure $next): Response
    {
        $before = Activity::$written;

        $response = $next($request);

        try {
            if (Activity::$written === $before && $request->user()) {
                $this->record($request, $response);
            }
        } catch (Throwable) {
            // never break a request over a log line
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            return;
        }
        if ($route->getName() && Str::is(self::IGNORE_ROUTES, $route->getName())) {
            return;
        }

        if ($file = $this->sentFile($response)) {
            if (Str::contains(strtolower($request->path()), self::IGNORE_FILES)) {
                return;
            }
            [$disposition, $name] = $file;
            Activity::log(
                $disposition === 'inline' ? 'file_opened' : 'file_downloaded',
                $this->subject($route) ?? $request->user(),
                $this->describe($route, $request),
                ['file' => $name],
            );

            return;
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        if ($response->getStatusCode() >= 400 || $this->failed($request)) {
            return;
        }

        Activity::log('other', $this->subject($route), $this->describe($route, $request));
    }

    /** [disposition, filename] when the response is a file. */
    private function sentFile(Response $response): ?array
    {
        $header = (string) $response->headers->get('Content-Disposition', '');
        if ($header === '' && ! $response instanceof BinaryFileResponse) {
            return null;
        }

        $disposition = str_starts_with(strtolower(trim($header)), 'attachment') ? 'attachment' : 'inline';
        $name = null;
        if (preg_match("/filename\\*=(?:UTF-8'')?([^;]+)/i", $header, $m)) {
            $name = rawurldecode(trim($m[1], " \"'"));
        } elseif (preg_match('/filename="?([^";]+)"?/i', $header, $m)) {
            $name = trim($m[1]);
        } elseif ($response instanceof BinaryFileResponse) {
            $name = $response->getFile()->getFilename();
        }

        return [$disposition, $name];
    }

    /** A redirect back with errors counts as "nothing happened". */
    private function failed(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }
        $flashed = (array) $request->session()->get('_flash.new', []);

        return (bool) array_intersect($flashed, ['errors', 'error']);
    }

    /** The first bound model in the route, if any. */
    private function subject(Route $route): ?Model
    {
        foreach ($route->parameters() as $value) {
            if ($value instanceof Model) {
                return $value;
            }
        }

        return null;
    }

    /** "Users › Sessions › Delete" from the route name, or "POST /path". */
    private function describe(Route $route, Request $request): string
    {
        $name = (string) $route->getName();
        if ($name === '' || str_starts_with($name, 'generated::')) {
            return $request->method().' /'.$request->path();
        }

        $verbs = [
            'store' => 'Add', 'create' => 'Add', 'update' => 'Edit', 'edit' => 'Edit', 'destroy' => 'Delete',
            'show' => 'Open', 'index' => 'List', 'download' => 'Download',
        ];

        return collect(explode('.', $name))
            ->map(fn ($part) => $verbs[$part] ?? Str::headline($part))
            ->implode(' › ');
    }
}
