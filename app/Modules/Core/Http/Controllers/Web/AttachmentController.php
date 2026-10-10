<?php

namespace App\Modules\Core\Http\Controllers\Web;

use App\Modules\Core\Actions\Attachments\DownloadAttachment;
use App\Modules\Core\Contracts\AttachmentStore;
use App\Modules\Core\Http\Requests\StoreAttachmentRequest;
use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Services\AttachmentAccess;
use App\Support\Exceptions\BusinessException;
use App\Support\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentAccess $access) {}

    public function store(StoreAttachmentRequest $request, AttachmentStore $store): RedirectResponse
    {
        $target = $this->access->resolveTarget($request->validated('attachable_type'), $request->validated('attachable_id'));
        $this->access->authorizeTarget($request->user(), $target, 'attach');

        try {
            $store->store($request->file('file'), $target, $request->user()->id);
        } catch (BusinessException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('status', __('core::ui.attachments.uploaded'));
    }

    public function destroy(Request $request, Attachment $attachment, AttachmentStore $store): RedirectResponse
    {
        $this->access->authorizeAttachment($request->user(), $attachment, 'attach');
        $store->delete($attachment->public_id, $request->user()->id);

        return back()->with('status', __('core::ui.attachments.deleted'));
    }

    public function download(Request $request, Attachment $attachment, DownloadAttachment $download): StreamedResponse
    {
        return $download($request->user(), $attachment);
    }
}
