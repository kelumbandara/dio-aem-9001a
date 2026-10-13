<?php

use App\Modules\Core\Contracts\AttachmentScanner;
use App\Modules\Core\Services\ClamdAttachmentScanner;
use App\Modules\Core\Services\NullAttachmentScanner;
use App\Support\Exceptions\BusinessException;

/** Starts the stand-in daemon in another process; returns [process, pipes, port]. */
function startFakeClamd(string $answer): array
{
    $proc = proc_open([PHP_BINARY, base_path('tests/Support/fake-clamd.php'), $answer], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));

    return [$proc, $pipes, $port];
}

function finishFakeClamd(array $daemon): string
{
    $line = trim((string) fgets($daemon[1][1]));
    fclose($daemon[1][1]);
    fclose($daemon[1][2]);
    proc_close($daemon[0]);

    return $line;
}

beforeEach(function () {
    $this->file = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($this->file, str_repeat('invoice line ', 2000));      // 26,000 bytes: several chunks
});
afterEach(fn () => @unlink($this->file));

it('streams the whole file to the daemon and reports it clean', function () {
    $d = startFakeClamd('stream: OK');
    config(['core.attachments.clamav' => ['enabled' => true, 'host' => '127.0.0.1', 'port' => $d[2], 'timeout' => 5]]);
    expect((new ClamdAttachmentScanner)->scan($this->file))->toBe(AttachmentScanner::CLEAN);
    expect(finishFakeClamd($d))->toBe('zINSTREAM 26000');
});

it('reports a file the daemon flags as infected', function () {
    $d = startFakeClamd('stream: Eicar-Test-Signature FOUND');
    config(['core.attachments.clamav' => ['enabled' => true, 'host' => '127.0.0.1', 'port' => $d[2], 'timeout' => 5]]);
    expect((new ClamdAttachmentScanner)->scan($this->file))->toBe(AttachmentScanner::INFECTED);
    finishFakeClamd($d);
});

it('refuses the upload, rather than storing it unscanned, when the daemon says ERROR or cannot be reached', function () {
    $d = startFakeClamd('INSTREAM size limit exceeded. ERROR');
    config(['core.attachments.clamav' => ['enabled' => true, 'host' => '127.0.0.1', 'port' => $d[2], 'timeout' => 5]]);
    expect(fn () => (new ClamdAttachmentScanner)->scan($this->file))->toThrow(BusinessException::class, 'virus scan is not available');
    finishFakeClamd($d);

    config(['core.attachments.clamav.port' => 1]);          // nothing listens here
    try {
        (new ClamdAttachmentScanner)->scan($this->file);
        $this->fail('Expected a refusal.');
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe('attachment.scan_unavailable')->and($e->status)->toBe(503);
    }
});

it('is used only when switched on: otherwise scans are recorded as skipped', function () {
    config(['core.attachments.clamav.enabled' => false]);
    app()->forgetInstance(AttachmentScanner::class);
    expect(app(AttachmentScanner::class))->toBeInstanceOf(NullAttachmentScanner::class);
    config(['core.attachments.clamav.enabled' => true]);
    app()->forgetInstance(AttachmentScanner::class);
    expect(app(AttachmentScanner::class))->toBeInstanceOf(ClamdAttachmentScanner::class);
});

it('keeps the Horizon dashboard for the administrator only', function () {
    expect(Gate::forUser(userWithPermissions('core.module.manage'))->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(userWithPermissions('sales.view'))->allows('viewHorizon'))->toBeFalse();
});
