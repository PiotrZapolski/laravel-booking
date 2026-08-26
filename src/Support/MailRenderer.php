<?php

namespace Zapol\Booking\Support;

use Illuminate\Support\Facades\Blade;
use RuntimeException;

/**
 * Compiles + renders a Blade template by reading the file directly and
 * passing it through `Blade::render()`. Bypasses Laravel's view factory.
 *
 * Why: on October CMS hosts the `view()` factory routes package views
 * through Twig (October replaces it for CMS pages), which chokes on the
 * `<?php ?>` blocks in our email layouts. Rendering through `Blade::render()`
 * uses Laravel's BladeCompiler directly - always works regardless of which
 * templating engine the host CMS prefers.
 *
 * Two-pass to support a shared layout: the inner template renders into a
 * `$content` variable, then the layout renders with that content.
 */
class MailRenderer
{
    public static function render(string $template, array $vars): string
    {
        $base = __DIR__ . '/../../resources/views/emails/';
        $inner = $base . $template . '.blade.php';
        $layout = $base . '_layout.blade.php';

        if (!is_file($inner)) {
            throw new RuntimeException("Booking mail template missing: {$inner}");
        }

        $content = Blade::render(file_get_contents($inner), $vars);

        if (!is_file($layout)) {
            return $content;
        }

        return Blade::render(file_get_contents($layout), $vars + ['content' => $content]);
    }
}
