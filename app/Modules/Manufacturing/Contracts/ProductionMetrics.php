<?php

namespace App\Modules\Manufacturing\Contracts;

/**
 * Deterministic production and quality figures for Reporting and the AI (read only). Dates are Colombo calendar days
 * (the shift date for output and downtime), `from` and `to` both included; quantities are in each product's base unit.
 * Cancelled output and downtime never count.
 */
interface ProductionMetrics
{
    /**
     * @return array{open_orders: int, released: int, in_progress: int, late_orders: int, completed: int, completed_on_time: int, on_time_rate: string|null, wip_value: string, produced: string, good: string, rejected: string, rework: string, reject_rate: string|null, rework_rate: string|null, first_pass_yield: string|null, received: string, received_value: string}
     */
    public function summary(string $from, string $to): array;

    /**
     * Good units made (made − rejected − rework) for every day of the period, days without output as zero.
     *
     * @return list<array{date: string, good: string}>
     */
    public function dailyGood(string $from, string $to): array;

    /**
     * Machine downtime: minutes in all, by kind of reason, and the biggest reasons and machines.
     *
     * @return array{minutes: string, events: int, by_category: list<array{category: string, minutes: string}>, top_reasons: list<array{code: string, name: string, category: string, minutes: string, events: int}>, top_machines: list<array{code: string, name: string, minutes: string, events: int}>}
     */
    public function downtime(string $from, string $to, int $limit = 5): array;

    /**
     * Good units made against the shift targets (only shifts that have a target count), and the shifts furthest below target.
     *
     * @return array{target: string, good: string, achievement: string|null, shifts: int, worst: list<array{date: string, shift: string, item: string, good: string, target: string, achievement: string}>}
     */
    public function targets(string $from, string $to, int $limit = 5): array;

    /**
     * Quality: what inspection decided, why units were rejected or reworked, and which products reject most.
     *
     * @return array{accepted: string, rejected: string, rework: string, reasons: list<array{reason: string, qty: string}>, products: list<array{code: string, name: string, produced: string, rejected: string, reject_rate: string}>}
     */
    public function quality(string $from, string $to, int $limit = 5): array;

    /**
     * Released or running orders past their planned finish, latest-finishing last.
     *
     * @return list<array{id: string, order_no: string, item: string, status: string, planned_end: string, days_late: int, planned_qty: string, good_qty: string}>
     */
    public function lateOrders(int $limit = 10): array;
}
