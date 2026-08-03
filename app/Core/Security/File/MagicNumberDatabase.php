<?php

declare(strict_types=1);

namespace App\Core\Security\File;

/**
 * Official binary signature database.
 *
 * Contains the known magic numbers used to
 * verify the real file type regardless of
 * extension or MIME type.
 */
final class MagicNumberDatabase
{
    /**
     * Prevent instantiation.
     */
    private function __construct() {}

    /**
     * Get all known signatures for an extension.
     *
     * @return list<MagicNumber>
     */
    public static function forExtension(
        string $extension
    ): array {

        $database = self::database();

        return $database[
            strtolower($extension)
        ] ?? [];

    }

    /**
     * Whether an extension has
     * registered signatures.
     */
    public static function supports(
        string $extension
    ): bool {

        return array_key_exists(

            strtolower($extension),

            self::database()

        );

    }

    /**
     * Official magic number database.
     *
     * @return array<string,list<MagicNumber>>
     */
    private static function database(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | PDF
            |--------------------------------------------------------------------------
            */

            'pdf' => [

                new MagicNumber(

                    extension: 'pdf',

                    signature: '25504446'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | PNG
            |--------------------------------------------------------------------------
            */

            'png' => [

                new MagicNumber(

                    extension: 'png',

                    signature: '89504E470D0A1A0A'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | JPEG
            |--------------------------------------------------------------------------
            */

            'jpg' => [

                new MagicNumber(

                    extension: 'jpg',

                    signature: 'FFD8FF'

                ),

            ],

            'jpeg' => [

                new MagicNumber(

                    extension: 'jpeg',

                    signature: 'FFD8FF'

                ),

            ],
            /*
            |--------------------------------------------------------------------------
            | GIF
            |--------------------------------------------------------------------------
            */

            'gif' => [

                new MagicNumber(

                    extension: 'gif',

                    signature: '474946383761'

                ),

                new MagicNumber(

                    extension: 'gif',

                    signature: '474946383961'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | BMP
            |--------------------------------------------------------------------------
            */

            'bmp' => [

                new MagicNumber(

                    extension: 'bmp',

                    signature: '424D'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | TIFF
            |--------------------------------------------------------------------------
            */

            'tif' => [

                new MagicNumber(

                    extension: 'tif',

                    signature: '49492A00'

                ),

                new MagicNumber(

                    extension: 'tif',

                    signature: '4D4D002A'

                ),

            ],

            'tiff' => [

                new MagicNumber(

                    extension: 'tiff',

                    signature: '49492A00'

                ),

                new MagicNumber(

                    extension: 'tiff',

                    signature: '4D4D002A'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | WEBP
            |--------------------------------------------------------------------------
            */

            'webp' => [

                new MagicNumber(

                    extension: 'webp',

                    signature: '52494646'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | Microsoft Office Legacy
            |--------------------------------------------------------------------------
            */

            'doc' => [

                new MagicNumber(

                    extension: 'doc',

                    signature: 'D0CF11E0A1B11AE1'

                ),

            ],

            'xls' => [

                new MagicNumber(

                    extension: 'xls',

                    signature: 'D0CF11E0A1B11AE1'

                ),

            ],

            'ppt' => [

                new MagicNumber(

                    extension: 'ppt',

                    signature: 'D0CF11E0A1B11AE1'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | Microsoft Office Open XML
            |--------------------------------------------------------------------------
            */

            'docx' => [

                new MagicNumber(

                    extension: 'docx',

                    signature: '504B0304'

                ),

            ],

            'xlsx' => [

                new MagicNumber(

                    extension: 'xlsx',

                    signature: '504B0304'

                ),

            ],

            'pptx' => [

                new MagicNumber(

                    extension: 'pptx',

                    signature: '504B0304'

                ),

            ],
            /*
            |--------------------------------------------------------------------------
            | Archives
            |--------------------------------------------------------------------------
            */

            'zip' => [

                new MagicNumber(

                    extension: 'zip',

                    signature: '504B0304'

                ),

            ],

            'rar' => [

                new MagicNumber(

                    extension: 'rar',

                    signature: '526172211A0700'

                ),

            ],

            '7z' => [

                new MagicNumber(

                    extension: '7z',

                    signature: '377ABCAF271C'

                ),

            ],

            'gz' => [

                new MagicNumber(

                    extension: 'gz',

                    signature: '1F8B08'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | Structured Data
            |--------------------------------------------------------------------------
            */

            'csv' => [],

            'json' => [],

            'xml' => [

                new MagicNumber(

                    extension: 'xml',

                    signature: '3C3F786D6C'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | Audio
            |--------------------------------------------------------------------------
            */

            'mp3' => [

                new MagicNumber(

                    extension: 'mp3',

                    signature: '494433'

                ),

            ],

            'wav' => [

                new MagicNumber(

                    extension: 'wav',

                    signature: '52494646'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | Video
            |--------------------------------------------------------------------------
            */

            'mp4' => [

                new MagicNumber(

                    extension: 'mp4',

                    signature: '66747970'

                ),

            ],

            'avi' => [

                new MagicNumber(

                    extension: 'avi',

                    signature: '52494646'

                ),

            ],

            'mov' => [

                new MagicNumber(

                    extension: 'mov',

                    signature: '66747970'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | Executables
            |--------------------------------------------------------------------------
            */

            'exe' => [

                new MagicNumber(

                    extension: 'exe',

                    signature: '4D5A'

                ),

            ],

            'dll' => [

                new MagicNumber(

                    extension: 'dll',

                    signature: '4D5A'

                ),

            ],

            'elf' => [

                new MagicNumber(

                    extension: 'elf',

                    signature: '7F454C46'

                ),

            ],

            /*
            |--------------------------------------------------------------------------
            | Mobile Packages
            |--------------------------------------------------------------------------
            */

            'apk' => [

                new MagicNumber(

                    extension: 'apk',

                    signature: '504B0304'

                ),

            ],
        ];

    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Return the complete magic number database.
     *
     * @return array<string,list<MagicNumber>>
     */
    public static function all(): array
    {
        return self::database();
    }

    /**
     * Return all supported extensions.
     *
     * @return list<string>
     */
    public static function extensions(): array
    {
        return array_keys(

            self::database()

        );
    }

    /**
     * Determine whether an extension
     * has registered magic numbers.
     */
    public static function isSupported(
        string $extension
    ): bool {

        return array_key_exists(

            strtolower($extension),

            self::database()

        );

    }

    /**
     * Count registered file types.
     */
    public static function count(): int
    {
        return count(

            self::database()

        );
    }

    /**
     * Count all registered signatures.
     */
    public static function signatureCount(): int
    {
        return array_sum(

            array_map(

                static fn (array $signatures): int => count($signatures),

                self::database()

            )

        );

    }
}
