<?php

use App\Modules\Approvals\Services\ApprovalEngine;
use App\Modules\Core\Contracts\AttachmentScanner;
use App\Modules\Core\Contracts\AttachmentStore;
use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\AttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const TINY_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

function upload(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function officeZip(string $marker): string
{
    $path = tempnam(sys_get_temp_dir(), 'ooxml');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
    $zip->addFromString($marker, '<xml/>');
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

beforeEach(function () {
    Storage::fake('attachments');
    $this->requester = userWithRole('sales');
    $this->approver = userWithRole('finance-approver');
    $this->outsider = userWithRole('storekeeper');
    paymentPolicy();
    $this->request = approvalRequest(requestPayment($this->requester));

    $this->post = fn (UploadedFile $file, $user = null) => actingAsApi($user ?? $this->requester)
        ->postJson('/api/v1/attachments', ['attachable_type' => 'approval_request', 'attachable_id' => $this->request->public_id, 'file' => $file]);
});

/** Small helper: authenticate the next API call as this user. */
function actingAsApi(User $user)
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($user);

    return test();
}

describe('accepting files', function () {
    it('stores a PDF privately under a random name and records its checksum', function () {
        $response = ($this->post)(upload('Quotation Rev 2.pdf', TINY_PDF))->assertCreated()
            ->assertJsonPath('data.name', 'Quotation Rev 2.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.scan_status', 'skipped');

        $attachment = Attachment::sole();
        expect($attachment->path)->toMatch('#^\d{4}/\d{2}/[0-9a-f-]{36}\.pdf$#')
            ->and($attachment->path)->not->toContain('Quotation')
            ->and($attachment->sha256)->toBe(hash('sha256', TINY_PDF))
            ->and($response->json('data.sha256'))->toBe(hash('sha256', TINY_PDF))
            ->and($attachment->size_bytes)->toBe(strlen(TINY_PDF))
            ->and($attachment->uploaded_by)->toBe($this->requester->id)
            ->and($attachment->attachable_type)->toBe('approval_request')
            ->and($attachment->attachable_id)->toBe($this->request->id);
        Storage::disk('attachments')->assertExists($attachment->path);
        expect(AuditLog::where('event', 'attachment.uploaded')->sole()->metadata['sha256'])->toBe(hash('sha256', TINY_PDF));
    });

    it('accepts images, text, csv and Office files', function () {
        foreach ([
            ['photo.png', base64_decode(TINY_PNG), 'image/png'],
            ['notes.txt', "hello\nworld\n", 'text/plain'],
            ['rows.csv', "a,b\n1,2\n", 'text/csv'],
            ['letter.docx', officeZip('word/document.xml'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['sheet.xlsx', officeZip('xl/workbook.xml'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ] as [$name, $bytes, $mime]) {
            ($this->post)(upload($name, $bytes))->assertCreated()->assertJsonPath('data.mime_type', $mime);
        }
        expect(Attachment::count())->toBe(5);
    });

    it('keeps Sinhala and Tamil file names readable but never trusts them as paths', function () {
        ($this->post)(upload('../../../ගිවිසුම.pdf', TINY_PDF))->assertCreated()->assertJsonPath('data.name', 'ගිවිසුම.pdf');
        ($this->post)(upload('..\\..\\ஒப்பந்தம்.pdf', TINY_PDF))->assertCreated()->assertJsonPath('data.name', 'ஒப்பந்தம்.pdf');

        foreach (Attachment::all() as $attachment) {
            expect($attachment->path)->not->toContain('..')->and($attachment->path)->toMatch('#^\d{4}/\d{2}/[0-9a-f-]{36}\.pdf$#');
        }
    });
});

describe('rejecting files', function () {
    it('judges the content, not the name or the client\'s claimed type', function (string $name, string $content) {
        ($this->post)(upload($name, $content))->assertStatus(422)->assertJsonPath('error.code', 'attachment.type_not_allowed');

        expect(Attachment::count())->toBe(0)->and(Storage::disk('attachments')->allFiles())->toBe([]);
    })->with([
        'PHP renamed to pdf' => ['invoice.pdf', '<?php system($_GET["c"]); ?>'],
        'HTML renamed to txt' => ['readme.txt', '<html><script>alert(1)</script></html>'],
        'PDF renamed to png' => ['picture.png', TINY_PDF],
        'zip renamed to docx' => ['report.docx', officeZip('not-word/file.xml')],
        'executable renamed to pdf' => ['setup.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF"],
        'svg with script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'plain html' => ['page.html', '<html></html>'],
        'no extension' => ['README', "plain text\n"],
    ]);

    it('rejects dangerous words anywhere in the name, e.g. double extensions', function (string $name) {
        ($this->post)(upload($name, TINY_PDF))->assertStatus(422)->assertJsonPath('error.code', 'attachment.type_not_allowed');
    })->with(['invoice.php.pdf', 'archive.exe.pdf', 'x.JS.pdf', 'a.phtml.pdf', 'report.html.pdf']);

    it('rejects empty and oversized files', function () {
        ($this->post)(upload('empty.pdf', ''))->assertStatus(422)->assertJsonPath('error.code', 'attachment.upload_failed');

        config(['core.attachments.max_kb' => 1]);
        ($this->post)(upload('big.pdf', TINY_PDF.str_repeat('x', 3000)))->assertStatus(422)->assertJsonPath('error.code', 'attachment.too_large');
        expect(Attachment::count())->toBe(0);
    });

    it('blocks a file the virus scanner flags, stores nothing, and audits it', function () {
        app()->instance(AttachmentScanner::class, new class implements AttachmentScanner
        {
            public function scan(string $absolutePath): string
            {
                return self::INFECTED;
            }
        });
        app()->forgetInstance(AttachmentStore::class);
        app()->singleton(AttachmentStore::class, AttachmentService::class);

        ($this->post)(upload('invoice.pdf', TINY_PDF))->assertStatus(422)->assertJsonPath('error.code', 'attachment.infected');

        expect(Attachment::count())->toBe(0)->and(Storage::disk('attachments')->allFiles())->toBe([])
            ->and(AuditLog::where('event', 'attachment.infected_blocked')->count())->toBe(1);
    });

    it('does not accept uploads for unknown or non-attachable targets', function () {
        actingAsApi($this->requester)->postJson('/api/v1/attachments', ['attachable_type' => 'branch', 'attachable_id' => (string) Str::uuid(), 'file' => upload('a.pdf', TINY_PDF)])
            ->assertStatus(422)->assertJsonPath('error.code', 'attachment.unknown_target');
        actingAsApi($this->requester)->postJson('/api/v1/attachments', ['attachable_type' => 'approval_request', 'attachable_id' => (string) Str::uuid(), 'file' => upload('a.pdf', TINY_PDF)])
            ->assertNotFound();
        actingAsApi($this->requester)->postJson('/api/v1/attachments', ['attachable_type' => 'approval_request', 'attachable_id' => 'not-a-uuid'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    });
});

describe('access control', function () {
    it('lets the requester and approvers attach and list, and hides everything from outsiders', function () {
        ($this->post)(upload('a.pdf', TINY_PDF))->assertCreated();
        ($this->post)(upload('b.pdf', TINY_PDF), $this->approver)->assertCreated();
        ($this->post)(upload('c.pdf', TINY_PDF), $this->outsider)->assertForbidden()->assertJsonPath('error.code', 'auth.forbidden');

        $list = fn ($user) => actingAsApi($user)->getJson('/api/v1/attachments?attachable_type=approval_request&attachable_id='.$this->request->public_id);
        expect($list($this->requester)->assertOk()->json('data'))->toHaveCount(2);
        $list($this->approver)->assertOk();
        $list($this->outsider)->assertForbidden();
    });

    it('shows an attachment only to people who can see its record', function () {
        ($this->post)(upload('a.pdf', TINY_PDF));
        $attachment = Attachment::sole();

        actingAsApi($this->requester)->getJson("/api/v1/attachments/{$attachment->public_id}")->assertOk()->assertJsonStructure(['data' => ['id', 'name', 'download_url']]);
        actingAsApi($this->outsider)->getJson("/api/v1/attachments/{$attachment->public_id}")->assertForbidden();
        actingAsApi($this->outsider)->deleteJson("/api/v1/attachments/{$attachment->public_id}")->assertForbidden();
    });

    it('lets users with the view-all permission see attachments, and super-admin pass', function () {
        ($this->post)(upload('a.pdf', TINY_PDF));
        $id = Attachment::sole()->public_id;

        actingAsApi(userWithPermissions('approvals.request.view-all'))->getJson("/api/v1/attachments/{$id}")->assertOk();
        actingAsApi(adminUser('super-admin'))->getJson("/api/v1/attachments/{$id}")->assertOk();
    });

    it('stops attaching to a request once it is closed', function () {
        app(ApprovalEngine::class)->reject($this->request->public_id, $this->approver->id, 'not approved');

        ($this->post)(upload('late.pdf', TINY_PDF))->assertForbidden();
        // viewing what was attached before stays possible for the people involved
        actingAsApi($this->requester)->getJson('/api/v1/attachments?attachable_type=approval_request&attachable_id='.$this->request->public_id)->assertOk();
    });

    it('requires authentication', function () {
        $this->postJson('/api/v1/attachments', [])->assertUnauthorized();
        $this->getJson('/api/v1/attachments/'.Str::uuid().'/download')->assertUnauthorized();
    });
});

describe('downloading', function () {
    beforeEach(function () {
        ($this->post)(upload('Quotation.pdf', TINY_PDF));
        $this->attachment = Attachment::sole();
        $this->url = actingAsApi($this->requester)->getJson("/api/v1/attachments/{$this->attachment->public_id}")->json('data.download_url');
    });

    it('serves the file only as a download with safe headers, using the type detected at upload', function () {
        $response = actingAsApi($this->requester)->get($this->url)->assertOk();

        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')->toContain('Quotation.pdf')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->streamedContent())->toBe(TINY_PDF)
            ->and(AuditLog::where('event', 'attachment.downloaded')->count())->toBe(1);
    });

    it('rejects a tampered or expired signature', function () {
        actingAsApi($this->requester)->get($this->url.'x')->assertForbidden();
        actingAsApi($this->requester)->get(str_replace('signature=', 'signature=0', $this->url))->assertForbidden();

        $this->travel(6)->minutes();
        actingAsApi($this->requester)->get($this->url)->assertForbidden();
    });

    it('still checks who is asking: a valid link is not enough', function () {
        actingAsApi($this->outsider)->get($this->url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get($this->url)->assertUnauthorized();   // a valid signature alone never serves the file
    });

    it('refuses infected files and reports a missing stored file', function () {
        Storage::disk('attachments')->delete($this->attachment->path);
        actingAsApi($this->requester)->get($this->url)->assertNotFound()->assertJsonPath('error.code', 'attachment.missing');

        $this->attachment->forceFill(['scan_status' => 'infected'])->save();
        actingAsApi($this->requester)->get($this->url)->assertStatus(423)->assertJsonPath('error.code', 'attachment.infected');
    });

    it('serves the web download route to a signed-in user only with permission', function () {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->requester)->get("/attachments/{$this->attachment->public_id}/download")->assertOk();
        $this->actingAs($this->outsider)->get("/attachments/{$this->attachment->public_id}/download")->assertForbidden();
    });
});

describe('removing', function () {
    it('soft-deletes, hides it from lists, and purges the file later', function () {
        ($this->post)(upload('a.pdf', TINY_PDF));
        $attachment = Attachment::sole();

        actingAsApi($this->requester)->deleteJson("/api/v1/attachments/{$attachment->public_id}")->assertNoContent();

        expect(Attachment::count())->toBe(0)->and(Attachment::withTrashed()->count())->toBe(1);
        Storage::disk('attachments')->assertExists($attachment->path);          // evidence is kept for a while
        actingAsApi($this->requester)->getJson('/api/v1/attachments?attachable_type=approval_request&attachable_id='.$this->request->public_id)->assertJsonCount(0, 'data');

        $this->artisan('attachments:purge')->expectsOutput('Purged 0 attachment(s).');
        $this->travel(31)->days();
        $this->artisan('attachments:purge')->expectsOutput('Purged 1 attachment(s).');

        Storage::disk('attachments')->assertMissing($attachment->path);
        expect(Attachment::withTrashed()->count())->toBe(0)
            ->and(AuditLog::whereIn('event', ['attachment.deleted', 'attachment.purged'])->count())->toBe(2);
    });
});

describe('web upload', function () {
    it('attaches a file from the approval page and shows it', function () {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->requester)
            ->post('/attachments', ['attachable_type' => 'approval_request', 'attachable_id' => $this->request->public_id, 'file' => upload('quote.pdf', TINY_PDF)])
            ->assertRedirect()->assertSessionHas('status');

        $this->actingAs($this->requester)->get(route('approvals.show', $this->request))->assertOk()->assertSee('quote.pdf');
    });

    it('shows a readable error for a rejected file', function () {
        $this->actingAs($this->requester)->from('/x')
            ->post('/attachments', ['attachable_type' => 'approval_request', 'attachable_id' => $this->request->public_id, 'file' => upload('evil.php', '<?php ?>')])
            ->assertRedirect('/x')->assertSessionHasErrors('file');
    });
});
