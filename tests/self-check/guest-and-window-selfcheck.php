<?php

declare(strict_types=1);

/**
 * Dependency-free assert script for the per-type time windows (Options) and the
 * guest order lookup (GuestAccess). Stubs the few WP/WC functions they touch.
 *
 * Run: php tests/self-check/guest-and-window-selfcheck.php
 */

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('DAY_IN_SECONDS', 86400);
    define('RETURNS_DIR', dirname(__DIR__, 2) . '/');

    $GLOBALS['opt']        = [];
    $GLOBALS['transients'] = [];
    $GLOBALS['orders']     = [];

    function get_option($key, $default = false) { return $GLOBALS['opt'][$key] ?? $default; }
    function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
    function set_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; return true; }
    function wp_salt($scheme = 'auth') { return 'test-salt'; }
    function apply_filters($tag, $value) { return $value; }
    function absint($v) { return abs((int) $v); }
    function __($text, $domain = '') { return $text; }
    function wc_get_order($id) { return $GLOBALS['orders'][(int) $id] ?? false; }

    class WC_Order
    {
        public function __construct(private int $id, private string $email, private int $customer) {}
        public function get_id(): int { return $this->id; }
        public function get_billing_email(): string { return $this->email; }
        public function get_customer_id(): int { return $this->customer; }
    }

    require RETURNS_DIR . 'src/Support/Types.php';
    require RETURNS_DIR . 'src/Support/Options.php';
    require RETURNS_DIR . 'src/Service/GuestAccess.php';
}

namespace Returns\Tests {
    use Returns\Service\GuestAccess;
    use Returns\Support\Options;

    ini_set('zend.assertions', '1');
    ini_set('assert.exception', '1');
    if ('1' !== ini_get('zend.assertions')) {
        fwrite(STDERR, "Assertions are compiled out; run with: php -d zend.assertions=1\n");
        exit(1);
    }

    $day     = 86400;
    $created = (int) strtotime('2026-01-15 12:00:00 UTC');

    // Window: defaults are 30 days for returns, 24 months for complaint/repair.
    assert(Options::windowOpen($created, $created + 30 * $day, 'return'));
    assert(! Options::windowOpen($created, $created + 31 * $day, 'return'));
    assert(Options::windowOpen($created, $created + 31 * $day, 'complaint'));
    assert(Options::windowOpen($created, (int) strtotime('2028-01-15 12:00:00 UTC'), 'repair'));
    assert(! Options::windowOpen($created, (int) strtotime('2028-01-15 12:00:01 UTC'), 'complaint'));
    // Repair as the remedy extends the months window once by 12 (Art. 10(2a) of Directive (EU) 2019/771).
    assert(Options::windowOpen($created, (int) strtotime('2029-01-15 12:00:00 UTC'), 'complaint', true));
    assert(! Options::windowOpen($created, (int) strtotime('2029-01-15 12:00:01 UTC'), 'complaint', true));
    assert(! Options::windowOpen($created, $created + 31 * $day, 'return', true));

    // Window: saved values are read, 0 means no limit.
    $GLOBALS['opt']['returns_settings'] = ['window_days' => 0, 'complaint_window_months' => 6];
    assert(Options::windowOpen($created, $created + 3650 * $day, 'return'));
    assert(! Options::windowOpen($created, (int) strtotime('2026-07-16 UTC'), 'complaint'));
    $GLOBALS['opt']['returns_settings'] = ['complaint_window_months' => 0];
    assert(Options::windowOpen($created, $created + 3650 * $day, 'repair'));

    // Guest lookup.
    $GLOBALS['orders'][100] = new \WC_Order(100, 'Guest@Example.com', 0);
    $GLOBALS['orders'][200] = new \WC_Order(200, 'member@example.com', 7);
    $GLOBALS['orders'][300] = new \WC_Order(300, 'other@example.com', 0);
    $guest = new GuestAccess();

    assert($guest->lookup('100', ' guest@example.COM ') instanceof \WC_Order, 'email match is case-insensitive');
    assert($guest->lookup('#100', 'guest@example.com')?->get_id() === 100, 'leading # accepted');
    assert(null === $guest->lookup('100', 'wrong@example.com'), 'wrong email');
    assert(null === $guest->lookup('999', 'guest@example.com'), 'wrong number');
    assert(null === $guest->lookup('100', ''), 'empty email');
    assert(null === $guest->lookup('200', 'member@example.com'), 'registered customer order not reachable');

    // Rate limit: 5 attempts per IP in the window, the 6th is refused, a new window resets.
    $now = 1_800_000_000;
    for ($i = 1; $i <= GuestAccess::LIMIT; $i++) {
        assert(! $guest->throttled('203.0.113.9', $now + $i), "attempt $i allowed");
    }
    assert($guest->throttled('203.0.113.9', $now + 10), '6th attempt throttled');
    assert(! $guest->throttled('198.51.100.1', $now + 10), 'other IP has its own bucket');
    assert(! $guest->throttled('203.0.113.9', $now + GuestAccess::WINDOW + 1), 'window resets');

    // Token bound to one order.
    $token = $guest->issue(100, $now);
    assert(100 === $guest->resolve($token, $now + 60));
    assert(0 === $guest->resolve(str_replace('100.', '300.', $token), $now + 60), 'swapped order id rejected');
    assert(0 === $guest->resolve($token . '0', $now + 60), 'tampered signature rejected');
    assert(0 === $guest->resolve($token, $now + GuestAccess::TTL + 1), 'expired token rejected');
    assert(0 === $guest->resolve('garbage', $now), 'malformed token rejected');
    assert(GuestAccess::clean($token) === $token, 'clean keeps a valid token intact');

    echo "OK: window and guest lookup checks passed\n";
}
