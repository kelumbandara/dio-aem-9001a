<?php

namespace App\Modules\MasterData\Reference;

use App\Modules\MasterData\Exceptions\MasterDataException;
use Illuminate\Contracts\Container\Container;

final class ReferenceRegistry
{
    /** @var array<string, class-string<ReferenceDefinition>> */
    private const DEFINITIONS = [
        'units-of-measure' => UnitsDefinition::class,
        'item-categories' => CategoriesDefinition::class,
        'currencies' => CurrenciesDefinition::class,
        'tax-codes' => TaxCodesDefinition::class,
        'payment-terms' => PaymentTermsDefinition::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function get(string $key): ReferenceDefinition
    {
        $class = self::DEFINITIONS[$key] ?? throw MasterDataException::make('masterdata.not_found', 'Unknown reference data.', 404);

        return $this->container->make($class);
    }

    /** @return list<ReferenceDefinition> */
    public function all(): array
    {
        return array_map(fn (string $k) => $this->get($k), $this->keys());
    }
}
