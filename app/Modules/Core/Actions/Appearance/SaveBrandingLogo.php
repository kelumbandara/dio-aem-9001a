<?php

namespace App\Modules\Core\Actions\Appearance;

use App\Modules\Core\Contracts\SettingsService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores the company logo. The file's real type is read from its content, never from its name or what the browser
 * says: only PNG, JPEG and WebP pictures of a sensible size are accepted (no SVG, which can carry scripts). It is kept
 * on the private disk under a generated name and shown through the /branding/logo route, never from a public folder.
 */
final class SaveBrandingLogo
{
    public const MAX_KB = 1024;

    public const MIN_SIDE = 32;

    public const MAX_SIDE = 2400;

    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(private readonly SettingsService $settings) {}

    /** @throws BusinessException core.appearance_invalid */
    public function __invoke(UploadedFile $file): void
    {
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > self::MAX_KB * 1024) {
            throw $this->invalid('The logo must be a picture of at most '.self::MAX_KB.' KB.');
        }
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $size = @getimagesizefromstring($bytes);
        if (! isset(self::TYPES[$mime]) || $size === false || $size['mime'] !== $mime) {
            throw $this->invalid('The logo must be a PNG, JPEG or WebP picture.');
        }
        if (min($size[0], $size[1]) < self::MIN_SIDE || max($size[0], $size[1]) > self::MAX_SIDE) {
            throw $this->invalid('The logo must be between '.self::MIN_SIDE.' and '.self::MAX_SIDE.' pixels on each side.');
        }

        $disk = Storage::disk(config('core.branding.disk', 'local'));
        $path = 'branding/logo-'.Str::lower(Str::random(16)).'.'.self::TYPES[$mime];
        $disk->put($path, $bytes);

        $old = $this->settings->get('appearance.logo');
        $this->settings->set('appearance.logo', ['path' => $path, 'mime' => $mime, 'version' => time()]);
        if (is_array($old) && isset($old['path'])) {
            $disk->delete($old['path']);
        }
    }

    private function invalid(string $message): BusinessException
    {
        return new BusinessException('core.appearance_invalid', $message, 422, [['field' => 'logo', 'message' => $message]]);
    }
}
