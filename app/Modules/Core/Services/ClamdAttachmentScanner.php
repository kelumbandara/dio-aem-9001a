<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\AttachmentScanner;
use App\Support\Exceptions\BusinessException;

/**
 * Scans a file with a ClamAV daemon over its INSTREAM protocol (the file is streamed to clamd, so the daemon needs no
 * access to our disk). Fails closed: if the daemon cannot be reached or answers with something unexpected, the upload
 * is refused with a plain message instead of being stored unscanned.
 */
final class ClamdAttachmentScanner implements AttachmentScanner
{
    private const CHUNK = 8192;

    public function scan(string $absolutePath): string
    {
        $cfg = (array) config('core.attachments.clamav');
        $timeout = (int) ($cfg['timeout'] ?? 20);
        $socket = @stream_socket_client('tcp://'.($cfg['host'] ?? '127.0.0.1').':'.($cfg['port'] ?? 3310), $errno, $errstr, $timeout);
        $file = @fopen($absolutePath, 'rb');
        if ($socket === false || $file === false) {
            if (is_resource($socket)) {
                fclose($socket);
            }
            throw $this->unavailable();
        }

        try {
            stream_set_timeout($socket, $timeout);
            fwrite($socket, "zINSTREAM\0");
            while (! feof($file)) {
                $chunk = fread($file, self::CHUNK);
                if ($chunk === false) {
                    throw $this->unavailable();
                }
                if ($chunk !== '' && fwrite($socket, pack('N', strlen($chunk)).$chunk) === false) {
                    throw $this->unavailable();
                }
            }
            fwrite($socket, pack('N', 0));
            $answer = trim((string) stream_get_contents($socket), "\0 \n\r");
        } finally {
            fclose($file);
            fclose($socket);
        }

        return match (true) {
            str_ends_with($answer, 'OK') => self::CLEAN,
            str_ends_with($answer, 'FOUND') => self::INFECTED,
            default => throw $this->unavailable(),
        };
    }

    private function unavailable(): BusinessException
    {
        return new BusinessException('attachment.scan_unavailable', 'The virus scan is not available right now, so the file was not accepted. Please try again later.', 503);
    }
}
