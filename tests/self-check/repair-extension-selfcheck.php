<?php

declare(strict_types=1);

/**
 * A completed repair flags its order once; a replacement or an open request does not.
 *
 * Run: php -d zend.assertions=1 tests/self-check/repair-extension-selfcheck.php
 */

namespace {
    ini_set('assert.exception', '1');
    define('ABSPATH', __DIR__ . '/');

    $GLOBALS['meta']  = [];
    $GLOBALS['saves'] = 0;

    function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
    function __($text, $domain = '') { return $text; }
    function apply_filters($tag, $value) { return $value; }

    class WC_Order
    {
        public array $meta = [];
        public function get_meta(string $key) { return $this->meta[$key] ?? ''; }
        public function update_meta_data(string $key, $value): void { $this->meta[$key] = $value; }
        public function save(): void { $GLOBALS['saves']++; }
    }

    $GLOBALS['order'] = new WC_Order();
    function wc_get_order($id) { return 7 === (int) $id ? $GLOBALS['order'] : false; }

    $root = dirname(__DIR__, 2) . '/src/';
    require $root . 'Contract/HasHooks.php';
    require $root . 'Support/Types.php';
    require $root . 'Support/Statuses.php';
    require $root . 'Support/Reasons.php';
    require $root . 'PostType/ReturnRequest.php';

    use Returns\PostType\ReturnRequest;

    $GLOBALS['meta'] = [
        1 => ['_returns_type' => 'complaint', '_returns_remedy' => 'replacement', '_returns_order_id' => '7'],
        2 => ['_returns_type' => 'repair', '_returns_remedy' => '', '_returns_order_id' => '7'],
        3 => ['_returns_type' => 'complaint', '_returns_remedy' => 'repair', '_returns_order_id' => '7'],
    ];

    ReturnRequest::markRepaired(1, 'completed');
    assert('' === $GLOBALS['order']->get_meta('_returns_repaired'), 'replacement does not extend');
    ReturnRequest::markRepaired(2, 'approved');
    assert('' === $GLOBALS['order']->get_meta('_returns_repaired'), 'an approved repair has not taken place yet');
    ReturnRequest::markRepaired(2, 'completed');
    ReturnRequest::markRepaired(3, 'completed');
    assert('yes' === $GLOBALS['order']->get_meta('_returns_repaired') && 1 === $GLOBALS['saves'], 'two repairs extend once');

    echo "OK: repair extension\n";
}
