<?php
namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class FileUploadService
{
    public function handle(Request $request, Model $record, string $model, User $user = null): void
    {
        if (! method_exists($record, 'fileFields')) {
            return;
        }

        foreach ($record::fileFields() as $field) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $file = $request->file($field);
            $path = "uploads/{$model}/{$field}";

            if ($field === 'photo') {
                $this->storePhoto($file, $path);
            } else {
                $file->store($path, 'public');
            }
        }
    }

    protected function storePhoto($file, $path): void
    {
        $hashed = pathinfo($file->hashName(), PATHINFO_FILENAME);
        $final  = "{$path}/{$hashed}.jpg";

        // delegate to your existing logic
    }
}
