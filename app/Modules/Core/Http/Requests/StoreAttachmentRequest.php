<?php

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised against the target record in the controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'attachable_type' => ['required', 'string', 'max:100'],
            'attachable_id' => ['required', 'uuid'],
            // Size, type and content are checked by AttachmentService; this only rejects a missing file.
            'file' => ['required', 'file'],
        ];
    }
}
