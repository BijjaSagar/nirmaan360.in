<?php

namespace App\Services\Reports;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/** A generated file as an HTTP response. */
final class Download
{
    public const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const PDF = 'application/pdf';

    public static function file(string $bytes, string $filename, string $mime, bool $inline = false): Response
    {
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename), '-') ?: 'download';

        return new Response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $safe, $safe),
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
