<?php

use Illuminate\Database\Eloquent\Model;
use Tests\Support\ModuleBoundaryChecker;

function realChecker(): ModuleBoundaryChecker
{
    return new ModuleBoundaryChecker(
        app_path('Modules'),
        collect(config('modules.modules'))->map(fn ($m) => $m['depends_on'])->all(),
        config('modules.public_namespaces'),
    );
}

it('keeps every real module inside its allowed dependencies and public namespaces', function () {
    expect(realChecker()->violations())->toBe([]);
});

it('only lists dependencies that are themselves declared and never forms a cycle', function () {
    $modules = config('modules.modules');

    foreach ($modules as $key => $module) {
        foreach ($module['depends_on'] as $dependency) {
            expect($modules)->toHaveKey($dependency);
        }
    }

    $visit = function (string $key, array $path) use (&$visit, $modules) {
        expect($path)->not->toContain($key, 'Dependency cycle: '.implode(' -> ', [...$path, $key]));
        foreach ($modules[$key]['depends_on'] as $dep) {
            $visit($dep, [...$path, $key]);
        }
    };
    foreach (array_keys($modules) as $key) {
        $visit($key, []);
    }
});

it('lists each module provider in config and the class exists', function () {
    foreach (config('modules.modules') as $key => $module) {
        expect(class_exists($module['provider']))->toBeTrue("Provider for {$key} missing");
    }
});

arch('contracts are interfaces')->expect('App\Modules\Core\Contracts')->toBeInterfaces();
arch('DTOs are immutable')->expect('App\Modules\Core\DTOs')->toBeFinal()->toBeReadonly();
arch('models are Eloquent models')->expect('App\Modules\Core\Models')->toExtend(Model::class);
arch('no debugging leftovers')->expect(['dd', 'dump', 'var_dump', 'ray', 'die', 'exit'])->not->toBeUsed();
arch('nobody calls env() outside config')->expect('env')->not->toBeUsedIn(['App']);
arch('actions and services do not depend on controllers')
    ->expect(['App\Modules\Core\Actions', 'App\Modules\Core\Services'])
    ->not->toUse('App\Modules\Core\Http\Controllers');
