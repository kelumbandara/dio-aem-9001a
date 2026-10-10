<?php

namespace App\Modules\Core\Http\Controllers\Api;

use App\Modules\Core\Actions\Attachments\DownloadAttachment;
use App\Modules\Core\Contracts\AttachmentStore;
use App\Modules\Core\DTOs\AttachmentInfo;
use App\Modules\Core\Http\Requests\StoreAttachmentRequest;
use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Services\AttachmentAccess;
use App\Modules\Core\Services\AttachmentService;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentAccess $access,
        private readonly AttachmentService $attachments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', 'string', 'max:100'],
            'attachable_id' => ['required', 'uuid'],
        ]);

        $target = $this->access->resolveTarget($data['attachable_type'], $data['attachable_id']);
        $this->access->authorizeTarget($request->user(), $target, 'view');

        return response()->json(['data' => array_map(
            fn ($info) => $this->payload($info),
            $this->attachments->list($target),
        )]);
    }

    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $target = $this->access->resolveTarget($request->validated('attachable_type'), $request->validated('attachable_id'));
        $this->access->authorizeTarget($request->user(), $target, 'attach');

        $info = $this->attachments->store($request->file('file'), $target, $request->user()->id);

        return response()->json(['data' => $this->payload($info)], 201);
    }

    public function show(Request $request, Attachment $attachment): JsonResponse
    {
        $this->access->authorizeAttachment($request->user(), $attachment, 'view');

        return response()->json(['data' => $this->payload($this->attachments->toInfo($attachment))
            + ['download_url' => $this->attachments->temporaryUrl($attachment->public_id)]]);
    }

    public function destroy(Request $request, Attachment $attachment, AttachmentStore $store): JsonResponse
    {
        $this->access->authorizeAttachment($request->user(), $attachment, 'attach');
        $store->delete($attachment->public_id, $request->user()->id);

        return response()->json(null, 204);
    }

    /** Signed, short-lived URL (see AttachmentService::temporaryUrl); the viewer is still authorised. */
    public function download(Request $request, Attachment $attachment, DownloadAttachment $download): StreamedResponse
    {
        return $download($request->user(), $attachment);
    }

    /** @return array<string, mixed> */
    private function payload(AttachmentInfo $info): array
    {
        return [
            'id' => $info->id,
            'name' => $info->originalName,
            'mime_type' => $info->mimeType,
            'size_bytes' => $info->sizeBytes,
            'sha256' => $info->sha256,
            'scan_status' => $info->scanStatus,
            'created_at' => $info->createdAt->toIso8601String(),
        ];
    }
}
