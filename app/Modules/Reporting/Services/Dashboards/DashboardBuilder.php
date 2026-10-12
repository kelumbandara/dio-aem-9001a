<?php

namespace App\Modules\Reporting\Services\Dashboards;

use App\Modules\Reporting\Support\Kpi;
use App\Modules\Reporting\Support\Period;

/**
 * One dashboard. A builder only asks the modules' read-only metrics contracts and arranges the answers: headline numbers
 * (with the comparison), charts and tables. It does no arithmetic of its own on records beyond adding and comparing decimal strings.
 */
abstract class DashboardBuilder
{
    /** The dashboard's key in URLs and the API. */
    abstract public function key(): string;

    /** The permission needed to open it (the same permission that opens the module's own screens). */
    abstract public function permission(): string;

    /** @return list<string> modules that must be switched on for it to exist */
    abstract public function modules(): array;

    /** A one-line description for the landing page, translated. */
    public function summary(): string
    {
        return $this->text('d.'.$this->key().'.summary');
    }

    public function title(): string
    {
        return $this->text('d.'.$this->key().'.title');
    }

    /**
     * @return array{kpis: list<array<string, mixed>>, charts: list<array<string, mixed>>, tables: list<array<string, mixed>>}
     */
    abstract public function build(Period $period, ?Period $compare): array;

    /** @param  array<string, string>  $replace */
    protected function text(string $key, array $replace = []): string
    {
        return __("reporting::ui.{$key}", $replace);
    }

    /**
     * @param  array{route?: string, params?: list<string>, url?: string}|null  $link
     * @return array<string, mixed>
     */
    protected function kpi(string $key, string $unit, ?string $value, ?string $previous = null, ?bool $higherIsBetter = true, ?array $link = null, ?string $hint = null): array
    {
        return Kpi::make($key, $this->text('k.'.$key), $unit, $value, $previous, $higherIsBetter, $this->withUrl($link), $hint);
    }

    /**
     * @param  list<array{key: string, type?: string}>  $columns  key, type = text | lkr | qty | count | pct | date | minutes | flag
     * @param  list<array{cells: array<string, mixed>, link?: array<string, mixed>|null}>  $rows
     * @return array<string, mixed>
     */
    protected function table(string $id, array $columns, array $rows): array
    {
        return [
            'id' => $id, 'title' => $this->text('t.'.$id),
            'columns' => array_map(fn (array $c) => ['key' => $c['key'], 'label' => $this->text('col.'.$c['key']), 'type' => $c['type'] ?? 'text'], $columns),
            'rows' => array_map(fn (array $r) => ['cells' => $r['cells'], 'url' => isset($r['link']) ? $this->withUrl($r['link'])['url'] ?? null : null], $rows),
        ];
    }

    /**
     * Horizontal bars: the biggest first, one colour (the order is the story, not the hue).
     *
     * @param  list<array{label: string, value: string, url?: string|null}>  $rows
     * @return array<string, mixed>
     */
    protected function bars(string $id, string $unit, array $rows): array
    {
        return ['id' => $id, 'type' => 'bars', 'title' => $this->text('c.'.$id), 'unit' => $unit, 'rows' => $rows];
    }

    /**
     * A day-by-day (or week/month) series of columns, with the comparison period as a second, grey series when there is one.
     *
     * @param  list<array{date: string, ...}>  $current
     * @param  list<array{date: string, ...}>|null  $previous
     * @return array<string, mixed>
     */
    protected function series(string $id, string $unit, array $current, ?array $previous, string $valueKey, Period $period): array
    {
        $grain = Kpi::grain($period->days());
        $cur = Kpi::bucket($current, $valueKey, $grain);
        $prev = $previous === null ? null : Kpi::bucket($previous, $valueKey, $grain);

        return [
            'id' => $id, 'type' => 'columns', 'title' => $this->text('c.'.$id), 'unit' => $unit, 'grain' => $grain,
            'categories' => array_map(fn (array $b) => $b['label'], $cur),
            'dates' => array_map(fn (array $b) => $b['date'], $cur),
            'current' => array_map(fn (array $b) => $b['value'], $cur),
            'previous' => $prev === null ? null : array_map(fn (array $b) => $b['value'], $prev),
        ];
    }

    /**
     * @param  array{route?: string, params?: list<string>, url?: string}|null  $link
     * @return array{route?: string, params?: list<string>, url?: string}|null
     */
    protected function withUrl(?array $link): ?array
    {
        if ($link === null) {
            return null;
        }
        if (isset($link['route']) && ! isset($link['url'])) {
            $link['url'] = route($link['route'], $link['params'] ?? []);
        }

        return $link;
    }

    /** @return array{route: string, params: list<string>} */
    protected function link(string $route, string ...$params): ?array
    {
        return ['route' => $route, 'params' => $params];
    }
}
