<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company logos and personnel photos, served from the configured disk (design §3.6 rule 3) to
 * signed-in users only. The route admits one generated file name inside an upload folder, so no
 * other file on the disk can be reached.
 */
final class MediaController
{
    public function __invoke(string $path): StreamedResponse
    {
        abort_unless(Storage::exists($path), 404);

        return Storage::response($path);
    }
}
