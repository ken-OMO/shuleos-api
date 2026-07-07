<?php

declare(strict_types=1);

namespace App\Core\Security\File;

use App\Core\Security\File\Contracts\FileValidator;
use Illuminate\Http\UploadedFile;

final readonly class FileSecurityManager
{
    /**
     * @param iterable<FileValidator> $validators
     */
    public function __construct(
        private iterable $validators
    ) {
    }

    /**
     * Execute the security pipeline.
     */
    public function scan(
        UploadedFile $file,
        FilePolicy $policy,
        string $uploadId,
        string $sha256
    ): FileSecurityResult {

        $result = new FileSecurityResult(

            uploadId: $uploadId,

            fileName: $file->getClientOriginalName(),

            sha256: $sha256

        );

        /*
        |--------------------------------------------------------------------------
        | Sort validators by execution order
        |--------------------------------------------------------------------------
        */

        $validators = iterator_to_array($this->validators);

        usort(

            $validators,

            static fn (
                FileValidator $a,
                FileValidator $b
            ) =>

            $a->validator()->order()

            <=>

            $b->validator()->order()

        );

        /*
        |--------------------------------------------------------------------------
        | Execute Pipeline
        |--------------------------------------------------------------------------
        */

        foreach ($validators as $validator) {

            $validator->validate(

                $file,

                $policy,

                $result

            );

            /*
            |--------------------------------------------------------------------------
            | Stop immediately if upload is no longer allowed.
            |--------------------------------------------------------------------------
            */

            if (! $result->passed()) {

                break;

            }

        }

        $result->finish();

        return $result;

    }
}
