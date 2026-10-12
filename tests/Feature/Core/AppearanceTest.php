<?php

use App\Modules\Core\Contracts\Appearance;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Core\Support\ColorScale;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('local');
    $this->manager = userWithPermissions('core.appearance.manage');
    $this->save = fn (array $o = []) => $this->actingAs($this->manager)->put('/admin/appearance', array_merge(['primary' => 'indigo', 'sidebar' => 'dark', 'font' => 'system', 'font_size' => 'normal', 'radius' => 'standard'], $o));
});

describe('the look reaches every screen', function () {
    it('uses the standard look until something is chosen', function () {
        $this->actingAs($this->manager)->get('/')->assertOk()->assertSee('--color-indigo-600: #4f46e5;', false)->assertSee('--sidebar-bg: #0f172a;', false)->assertSee('font-size: 16px;', false)->assertDontSee('<link rel="icon"', false);
    });

    it('applies colour, sidebar, font, size, corners and name everywhere once saved', function () {
        ($this->save)(['primary' => 'teal', 'sidebar' => 'brand', 'font' => 'inter', 'font_size' => 'large', 'radius' => 'round', 'app_name' => 'Acme Trading'])->assertRedirect('/admin/appearance')->assertSessionHas('status');

        $page = $this->actingAs($this->manager)->get('/')->assertOk()
            ->assertSee('--color-indigo-600: #0f766e;', false)->assertSee('font-size: 18px;', false)->assertSee('--radius-lg: 0.75rem;', false)->assertSee("--font-sans: 'Inter Variable', ui-sans-serif", false)
            ->assertSee('Acme Trading')->assertSee('<title>', false);
        expect($page->getContent())->toContain('--sidebar-bg: '.ColorScale::scale('#0f766e')['900']);

        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('--color-indigo-600: #0f766e;', false)->assertSee('Acme Trading');
    });

    it('accepts your own colour, and refuses one too light for white text or not a colour', function () {
        ($this->save)(['primary' => 'indigo', 'custom_primary' => '#1D4ED8'])->assertRedirect('/admin/appearance');
        expect(app(Appearance::class)->themeCss())->toContain('--color-indigo-600: #1d4ed8;');

        ($this->save)(['custom_primary' => '#ffff00'])->assertSessionHasErrors('primary');
        ($this->save)(['custom_primary' => 'red'])->assertSessionHasErrors('primary');
        ($this->save)(['font' => 'comic'])->assertSessionHasErrors('font');
        ($this->save)(['font_size' => 'huge'])->assertSessionHasErrors('font_size');
        expect(app(Appearance::class)->themeCss())->toContain('--color-indigo-600: #1d4ed8;');   // nothing half-saved
    });

    it('shows the login message and the document footer', function () {
        ($this->save)(['login_message' => 'Welcome to Acme', 'document_footer' => 'Thank you for your business.']);
        auth()->logout();
        $this->get('/login')->assertSee('Welcome to Acme');
        expect(Blade::render('<x-core::branding.document-footer />'))->toContain('Thank you for your business.');
    });

    it('restores the standard look and removes the logo on reset', function () {
        ($this->save)(['primary' => 'rose', 'app_name' => 'Acme']);
        $this->actingAs($this->manager)->post('/admin/appearance', [])->assertStatus(405);
        $this->actingAs($this->manager)->post('/admin/appearance/reset')->assertRedirect('/admin/appearance');

        expect(app(Appearance::class)->themeCss())->toContain('--color-indigo-600: #4f46e5;')->and(app(Appearance::class)->appName())->not->toBe('Acme');
    });
});

describe('the logo', function () {
    it('is stored privately, shown on every screen and served with long caching', function () {
        ($this->save)(['logo' => UploadedFile::fake()->image('logo.png', 300, 100)])->assertRedirect('/admin/appearance');
        $url = app(Appearance::class)->logoUrl();

        expect($url)->toContain('/branding/logo?v=');
        Storage::disk('local')->assertExists(Storage::disk('local')->allFiles('branding')[0]);
        $this->actingAs($this->manager)->get('/')->assertSee($url, false)->assertSee('<link rel="icon"', false);

        auth()->logout();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->get('/login')->assertSee($url, false);
        expect(Blade::render('<x-core::branding.document-logo />'))->toContain($url);
    });

    it('replaces the old file when a new logo is uploaded and removes it on request', function () {
        ($this->save)(['logo' => UploadedFile::fake()->image('a.png', 200, 80)]);
        ($this->save)(['logo' => UploadedFile::fake()->image('b.jpg', 200, 80)]);
        expect(Storage::disk('local')->allFiles('branding'))->toHaveCount(1)->and(Storage::disk('local')->allFiles('branding')[0])->toEndWith('.jpg');

        ($this->save)(['remove_logo' => '1']);
        expect(Storage::disk('local')->allFiles('branding'))->toBe([])->and(app(Appearance::class)->logoUrl())->toBeNull();
        auth()->logout();
        $this->get('/branding/logo')->assertNotFound();
    });

    it('refuses pictures that are not safe or sensible', function () {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $fakePng = UploadedFile::fake()->createWithContent('logo.png', '<?php echo "hello"; ?>');
        foreach ([$svg, $fakePng, UploadedFile::fake()->image('tiny.png', 10, 10), UploadedFile::fake()->image('huge.png', 3000, 100), UploadedFile::fake()->create('big.png', 2048, 'image/png')] as $file) {
            ($this->save)(['logo' => $file])->assertSessionHasErrors('logo');
        }
        expect(app(Appearance::class)->logoUrl())->toBeNull()->and(Storage::disk('local')->allFiles('branding'))->toBe([]);
    });
});

describe('who may change it', function () {
    it('needs the permission to see or change the look, and offers the vendor options only to the vendor', function () {
        $reader = userWithPermissions('core.branch.view');
        $this->actingAs($reader)->get('/admin/appearance')->assertForbidden();
        $this->actingAs($reader)->put('/admin/appearance', ['primary' => 'rose'])->assertForbidden();
        $this->actingAs($reader)->post('/admin/appearance/reset')->assertForbidden();
        $this->actingAs($reader)->post('/admin/appearance/preview', [])->assertForbidden();

        $this->actingAs($this->manager)->get('/admin/appearance')->assertOk()->assertDontSee('Vendor options');
        $this->actingAs(adminUser('super-admin'))->get('/admin/appearance')->assertOk()->assertSee('Vendor options');
    });

    it('keeps the “Powered by” line unless the vendor switches it off; a customer admin cannot', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        expect(Role::findByName('admin', 'web')->permissions->pluck('name'))->toContain('core.appearance.manage')->not->toContain('core.appearance.vendor');

        expect(app(Appearance::class)->poweredBy())->toBe('Powered by DIO ERP');
        ($this->save)(['powered_by_enabled' => '0', 'powered_by_text' => 'Hacked']);   // a customer's admin: ignored
        expect(app(Appearance::class)->poweredBy())->toBe('Powered by DIO ERP');
        $this->actingAs($this->manager)->get('/')->assertSee('Powered by DIO ERP');

        $vendor = adminUser('super-admin');
        $this->actingAs($vendor)->put('/admin/appearance', ['primary' => 'indigo', 'powered_by_text' => 'Run by Acme Hosting', 'powered_by_enabled' => '1'])->assertRedirect();
        expect(app(Appearance::class)->poweredBy())->toBe('Run by Acme Hosting');
        $this->actingAs($vendor)->put('/admin/appearance', ['primary' => 'indigo', 'powered_by_text' => '', 'powered_by_enabled' => '0'])->assertRedirect();
        expect(app(Appearance::class)->poweredBy())->toBeNull();
        $this->actingAs($this->manager)->get('/')->assertDontSee('Powered by');
        auth()->logout();
        $this->get('/login')->assertDontSee('Powered by');
    });
});

describe('documents and the API', function () {
    it('colours printed documents with the brand, or in black and white', function () {
        ($this->save)(['primary' => 'rose']);
        expect(Blade::render('<x-core::branding.document-style />'))->toContain('--accent: #be123c;')->toContain('--font:');
        ($this->save)(['primary' => 'rose', 'print_mono' => '1']);
        expect(Blade::render('<x-core::branding.document-style />'))->toContain('--accent: #111111;');
    });

    it('previews the choices without saving them', function () {
        $css = $this->actingAs($this->manager)->postJson('/admin/appearance/preview', ['primary' => 'green', 'custom_primary' => '', 'sidebar' => 'light', 'font' => 'roboto', 'font_size' => 'small', 'radius' => 'square'])
            ->assertOk()->json('css');
        expect($css)->toContain('--color-indigo-600: #15803d;')->toContain('--sidebar-bg: #ffffff;')->toContain("'Roboto'")->toContain('--radius-lg: 0;')->toContain('font-size: 14px;');
        expect(app(Appearance::class)->themeCss())->toContain('--color-indigo-600: #4f46e5;');
    });

    it('reads and writes the look over the API with the same rules', function () {
        asApi($this->manager)->getJson('/api/v1/appearance')->assertOk()->assertJsonPath('data.primary', '#4f46e5')->assertJsonPath('data.logo_url', null);
        asApi(userWithPermissions('core.branch.view'))->getJson('/api/v1/appearance')->assertOk();
        asApi(userWithPermissions('core.branch.view'))->patchJson('/api/v1/appearance', ['primary' => 'rose'])->assertForbidden();

        asApi($this->manager)->patchJson('/api/v1/appearance', ['primary' => 'rose', 'font' => 'noto', 'app_name' => 'Acme'])->assertOk()->assertJsonPath('data.primary', '#be123c')->assertJsonPath('data.app_name', 'Acme');
        asApi($this->manager)->patchJson('/api/v1/appearance', ['primary' => '#ffff00'])->assertStatus(422)->assertJsonPath('error.code', 'core.appearance_invalid');
        asApi($this->manager)->postJson('/api/v1/appearance/logo', ['logo' => UploadedFile::fake()->image('l.png', 200, 80)])->assertCreated()->assertJsonPath('data.logo_url', fn ($v) => str_contains($v, '/branding/logo'));
        asApi($this->manager)->postJson('/api/v1/appearance/logo', ['logo' => UploadedFile::fake()->createWithContent('x.png', 'not a picture')])->assertStatus(422);
        asApi($this->manager)->deleteJson('/api/v1/appearance/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        asApi($this->manager)->postJson('/api/v1/appearance/reset')->assertOk()->assertJsonPath('data.primary', '#4f46e5');
    });
});

describe('the screen', function () {
    it('opens in every language without raw translation keys', function (string $locale) {
        $this->manager->forceFill(['locale' => $locale])->save();
        $body = $this->actingAs($this->manager->fresh())->get('/admin/appearance')->assertOk()->getContent();
        expect($body)->not->toContain('core::')->not->toContain('core.ui.', 'raw key');
    })->with(['en', 'si', 'ta']);

    it('has identical translation keys in every language', function () {
        $keys = fn (string $locale) => collect(array_keys(Arr::dot(require app_path("Modules/Core/Lang/{$locale}/ui.php"))))->sort()->values()->all();
        foreach (['si', 'ta'] as $locale) {
            expect($keys($locale))->toBe($keys('en'), "{$locale} differs from en");
        }
    });
});
