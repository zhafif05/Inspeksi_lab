<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Replikasi batasan multer lama sebelum controller dipanggil. */
class LegacyUpload
{
    public static function validateImages(Request $request): ?JsonResponse
    {
        return self::validate(
            $request,
            ['image/jpeg', 'image/png'],
            5 * 1024 * 1024,
            'Hanya file gambar yang diizinkan (JPEG, PNG, GIF)'
        );
    }

    public static function validatePdf(Request $request): ?JsonResponse
    {
        return self::validate(
            $request,
            ['application/pdf'],
            10 * 1024 * 1024,
            'Hanya file PDF yang diizinkan.'
        );
    }

    private static function validate(
        Request $request,
        array $allowedMimes,
        int $maxBytes,
        string $mimeMessage
    ): ?JsonResponse {
        foreach (self::files($request->allFiles()) as [$field, $file]) {
            if (!in_array($file->getClientMimeType(), $allowedMimes, true)) {
                return Api::error($mimeMessage, 500, ['error' => ['storageErrors' => []]]);
            }

            if ($file->getSize() > $maxBytes) {
                return Api::error('File too large', 500, [
                    'error' => [
                        'name' => 'MulterError',
                        'message' => 'File too large',
                        'code' => 'LIMIT_FILE_SIZE',
                        'field' => $field,
                        'storageErrors' => [],
                    ],
                ]);
            }
        }

        return null;
    }

    /** @return array<int, array{0:string,1:UploadedFile}> */
    private static function files(array $files): array
    {
        $result = [];
        foreach ($files as $field => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof UploadedFile) {
                        $result[] = [(string) $field, $item];
                    }
                }
                continue;
            }
            if ($value instanceof UploadedFile) {
                $result[] = [(string) $field, $value];
            }
        }
        return $result;
    }
}
