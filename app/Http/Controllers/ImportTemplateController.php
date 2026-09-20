<?php

namespace App\Http\Controllers;

use App\Domain\Imports\ImportTemplate;
use App\Enums\Permission;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The spare part import template (PRD FR-SPIMS-005 step 1). It is written for each download, so it
 * always names the columns this version imports.
 */
final class ImportTemplateController
{
    public function __invoke(): BinaryFileResponse
    {
        Gate::authorize(Permission::PartsImport);

        $path = sys_get_temp_dir().'/'.uniqid('spims-template-', true).'.xlsx';
        ImportTemplate::write($path);

        return response()->download($path, 'spims-spare-part-import-template.xlsx')->deleteFileAfterSend();
    }
}
