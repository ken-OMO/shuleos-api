<?php

declare(strict_types=1);

namespace App\Core\Security\File;

use App\Core\Security\File\Contracts\FileValidator;
use Illuminate\Http\UploadedFile;

/**
 * Executes the ShuleOS File Security Pipeline.
 *
 * This manager coordinates all registered
 * validators and produces a single
 * FileSecurityReport.
 */
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

        FilePolicy $policy

    ): FileSecurityReport {

        $report = new FileSecurityReport();
                /*
        |--------------------------------------------------------------------------
        | Collect Validators
        |--------------------------------------------------------------------------
        */

        $validators = iterator_to_array(

            $this->validators

        );

        /*
        |--------------------------------------------------------------------------
        | Sort Validators
        |--------------------------------------------------------------------------
        */

        usort(

            $validators,

            static fn (

                FileValidator $a,

                FileValidator $b

            ) =>

                $a->priority()

                <=>

                $b->priority()

        );

        /*
        |--------------------------------------------------------------------------
        | Execute Pipeline
        |--------------------------------------------------------------------------
        */

        foreach (

            $validators as $validator

        ) {

            if (

                ! $validator->supports(

                    $policy

                )

            ) {

                continue;

            }

            $validator->validate(

                $file,

                $policy,

                $report

            );

            /*
            |--------------------------------------------------------------------------
            | Stop On Critical Failure
            |--------------------------------------------------------------------------
            */

            if (

                $report->failed()

                &&

                $validator

                    ->name()

                    ->isCritical()

            ) {

                break;

            }

        }

        $report->finish();

        return $report;

    }

}
