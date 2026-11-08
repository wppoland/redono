<?php

declare(strict_types=1);

namespace Returns\Support;

defined('ABSPATH') || exit;

/**
 * Typed accessor for the plugin's settings, merging stored values over the
 * shipped defaults. Centralises option reads so every service sees the same
 * shape and sane fallbacks even when the option is missing or malformed.
 */
final class Options
{
    public const OPTION = 'returns_settings';

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        if (! is_array($stored)) {
            $stored = [];
        }

        /** @var array<string, mixed> $defaults */
        $defaults = require RETURNS_DIR . 'config/defaults.php';

        return array_merge($defaults, $stored);
    }

    public static function isEnabled(): bool
    {
        return (bool) (self::all()['enabled'] ?? false);
    }

    /**
     * @return list<string>
     */
    public static function eligibleStatuses(): array
    {
        $statuses = self::all()['eligible_statuses'] ?? [];

        if (! is_array($statuses)) {
            return ['completed'];
        }

        $clean = array_values(array_filter(array_map('strval', $statuses)));

        return [] === $clean ? ['completed'] : $clean;
    }

    public static function windowDays(): int
    {
        return max(0, (int) (self::all()['window_days'] ?? 0));
    }

    public static function complaintWindowMonths(): int
    {
        return max(0, (int) (self::all()['complaint_window_months'] ?? 24));
    }

    /**
     * Whether a request of the given type may still be filed for an order
     * created at $created. Complaint and repair (the types with a remedy) use
     * the months window, every other type the days window. 0 means no limit.
     * $repaired adds 12 months to the months window once: repair as the remedy extends the
     * seller's liability period (Article 10(2a) of Directive (EU) 2019/771, inserted by
     * Article 16(2)(a) of Directive (EU) 2024/1799), as transposed nationally.
     */
    public static function windowOpen(int $created, int $now, string $type, bool $repaired = false): bool
    {
        if (Types::hasRemedy($type)) {
            $months = self::complaintWindowMonths();
            $months += 0 !== $months && $repaired ? 12 : 0;

            if (0 === $months) {
                return true;
            }

            $deadline = (new \DateTimeImmutable('@' . $created))->add(new \DateInterval('P' . $months . 'M'));

            return $now <= $deadline->getTimestamp();
        }

        $days = self::windowDays();

        return 0 === $days || (int) floor(($now - $created) / DAY_IN_SECONDS) <= $days;
    }
}
