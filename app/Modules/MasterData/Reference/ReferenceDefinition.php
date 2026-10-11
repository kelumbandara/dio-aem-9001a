<?php

namespace App\Modules\MasterData\Reference;

use App\Modules\MasterData\Exceptions\MasterDataException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Describes one kind of reference data (units, categories, currencies, tax codes, payment terms) so a
 * single API controller and a single set of screens can serve all of them.
 */
abstract class ReferenceDefinition
{
    /** URL segment: /api/v1/{key}, /masterdata/reference/{key}. */
    abstract public function key(): string;

    /** @return class-string<Model> */
    abstract public function modelClass(): string;

    /**
     * Columns shown in lists: field => type (text|number|bool|badge).
     *
     * @return array<string, string>
     */
    abstract public function columns(): array;

    /**
     * Form fields: name => ['type' => text|number|select|checkbox, 'required' => bool, 'options' => [value => label], 'create_only' => bool].
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public function fields(): array;

    /** @return array<string, mixed> validation rules */
    abstract public function rules(?Model $existing): array;

    /** @param  array<string, mixed>  $data */
    abstract public function save(array $data, ?Model $existing): Model;

    /** @return array<string, mixed> */
    abstract public function present(Model $model): array;

    /**
     * Default ordering for lists.
     *
     * @return Builder<covariant Model>
     */
    public function query(): Builder
    {
        return ($this->modelClass())::query()->orderBy('code');
    }

    /** Find by public id (uuid) or by code. */
    public function find(string $id): Model
    {
        $query = ($this->modelClass())::query();
        $isUuid = Str::isUuid($id);

        $model = $isUuid && $this->hasPublicId()
            ? $query->where('public_id', $id)->first()
            : $query->where('code', strtoupper($id))->first();

        return $model ?? throw MasterDataException::make('masterdata.not_found', 'Record not found.', 404);
    }

    /**
     * The record being edited, checked to be of the model this definition manages.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    protected function narrow(?Model $model, string $class): ?Model
    {
        if ($model !== null && ! $model instanceof $class) {
            throw new \InvalidArgumentException('Expected a '.$class.', got '.$model::class.'.');
        }

        return $model;
    }

    protected function hasPublicId(): bool
    {
        return true;
    }

    /** Route-visible identifier. */
    public function identifier(Model $model): string
    {
        return $this->hasPublicId() ? (string) $model->getAttribute('public_id') : (string) $model->getAttribute('code');
    }

    /** @return array<string, mixed> */
    protected function codeRule(int $max, ?Model $existing): array
    {
        return $existing === null
            ? ['code' => ['required', 'string', 'max:'.$max, 'regex:/^[A-Za-z0-9._\\-]+$/']]
            : ['code' => ['sometimes', 'string', 'max:'.$max]];
    }
}
