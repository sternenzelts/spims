<?php

use Livewire\Component;
use Symfony\Component\Finder\Finder;

// Livewire's own JavaScript $wire proxy already defines these bare names (see
// vendor/livewire/livewire/dist/livewire.js, the `aliases` map, plus `refresh`, which
// Livewire's docs expose as $wire.refresh()). A PHP action sharing one of these names is
// unreachable from the browser: e.g. wire:submit="upload" resolves to $wire.upload(...),
// the JS file-upload entry point, instead of calling the server action, and fails with
// "Cannot read properties of undefined" before any HTTP request is made. ->call('upload')
// in a Pest test invokes the PHP method directly, bypassing the JS proxy entirely, so this
// class of bug is invisible to the rest of the suite. This is exactly what happened to
// App\Livewire\Imports\Upload::upload(), renamed to save() because of this test.
const LIVEWIRE_JS_PROXY_NAMES = [
    'on', 'el', 'id', 'js', 'get', 'set', 'refs', 'call', 'hook', 'watch', 'dirty',
    'effect', 'commit', 'errors', 'island', 'upload', 'entangle', 'dispatch', 'intercept',
    'interceptAction', 'interceptMessage', 'interceptRequest', 'dispatchTo', 'dispatchSelf',
    'dispatchEl', 'dispatchRef', 'removeUpload', 'cancelUpload', 'uploadMultiple', 'refresh',
];

/**
 * @return list<class-string<Component>>
 */
function livewireComponentClasses(): array
{
    $files = (new Finder)->files()->in(app_path('Livewire'))->name('*.php');

    $classes = [];

    foreach ($files as $file) {
        $relative = str($file->getRelativePathname())
            ->before('.php')
            ->replace(DIRECTORY_SEPARATOR, '\\');

        $class = "App\\Livewire\\{$relative}";

        if (class_exists($class) && is_subclass_of($class, Component::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('never names a Livewire action after a name the $wire JS proxy already defines', function () {
    $offenders = [];

    foreach (livewireComponentClasses() as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip anything not written by this class itself: Livewire\Component's own
            // methods, and any framework trait (WithFileUploads, WithPagination, ...) the
            // component uses directly. Only methods this project actually authored as
            // actions are judged against the reserved list.
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if (in_array($method->getName(), LIVEWIRE_JS_PROXY_NAMES, true)) {
                $offenders[] = sprintf(
                    "%s::%s() — the \$wire JS proxy already defines '%s' (see vendor/livewire/livewire/dist/livewire.js). ".
                    'Any wire:click/wire:submit calling it in the browser invokes Livewire\'s built-in JS method '.
                    'instead of this PHP action, and fails silently before reaching the server. Rename the action.',
                    $class,
                    $method->getName(),
                    $method->getName()
                );
            }
        }
    }

    expect($offenders)->toBe([]);
});
