<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /links/import (DEC-041): multipart `file` (Netscape bookmark HTML, CSV or plain text, up to 2 MB)
 * and an optional `offset` to continue a file that has more links than one request handles.
 */
class ImportLinksRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file',
                'max:'.config('stashly.import.max_file_kb'),
                // Both the claimed extension and the content-sniffed type must be a text bookmark file.
                'extensions:html,htm,csv,txt',
                'mimetypes:text/html,text/plain,text/csv,application/csv',
            ],
            'offset' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }
}
