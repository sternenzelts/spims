<?php

namespace App\Livewire\Imports;

use App\Domain\Imports\Exceptions\ImportRefused;
use App\Domain\Imports\PartImports;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Title('New spare part import')]
class Upload extends Component
{
    use ActsAsSignedInUser, WithFileUploads;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public function mount(): void
    {
        $this->authorize(Permission::PartsImport);
    }

    public function upload(PartImports $imports): void
    {
        $this->authorize(Permission::PartsImport);

        $this->validate(['file' => ['required', 'file', 'max:10240']]);

        $file = $this->file;

        // validate() has refused a missing file; this tells the type checker so.
        if (! $file instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            $batch = $imports->upload($file, $this->actor());
        } catch (ImportRefused $refused) {
            $this->addError('file', $refused->getMessage());

            return;
        }

        $this->redirectRoute('imports.show', ['batch' => $batch], navigate: true);
    }
}
