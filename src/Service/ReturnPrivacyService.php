<?php

declare(strict_types=1);

namespace Returns\Service;

use Returns\Contract\HasHooks;
use Returns\PostType\ReturnRequest;
use Returns\Support\Reasons;
use Returns\Support\Statuses;
use Returns\Support\Types;
use WP_Query;
use WP_User;

defined('ABSPATH') || exit;

/**
 * Personal data exporter and eraser for Return / RMA requests.
 */
final class ReturnPrivacyService implements HasHooks
{
    private const PAGE_SIZE = 100;

    public function registerHooks(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerErasers']);
    }

    /**
     * @param array<string, array<string, mixed>> $exporters
     * @return array<string, array<string, mixed>>
     */
    public function registerExporters(array $exporters): array
    {
        $exporters['returns-requests'] = [
            'exporter_friendly_name' => __('Return & RMA Requests', 'redono'),
            'callback'               => [$this, 'exportRequests'],
        ];

        return $exporters;
    }

    /**
     * @param array<string, array<string, mixed>> $erasers
     * @return array<string, array<string, mixed>>
     */
    public function registerErasers(array $erasers): array
    {
        $erasers['returns-requests'] = [
            'eraser_friendly_name' => __('Return & RMA Requests', 'redono'),
            'callback'             => [$this, 'eraseRequests'],
        ];

        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function exportRequests(string $email, int $page = 1): array
    {
        $page   = max(1, $page);
        $postIds = $this->findRmaPostIds($email, $page);

        $items = [];
        foreach ($postIds as $postId) {
            $orderId    = (int) get_post_meta($postId, ReturnRequest::META_ORDER_ID, true);
            $reasonKey  = (string) get_post_meta($postId, ReturnRequest::META_REASON, true);
            $note       = (string) get_post_meta($postId, ReturnRequest::META_NOTE, true);
            $statusKey  = (string) get_post_meta($postId, ReturnRequest::META_STATUS, true);
            $typeKey    = (string) get_post_meta($postId, ReturnRequest::META_TYPE, true);
            $postDate   = (string) get_the_date('Y-m-d H:i:s', $postId);

            $reason = Reasons::label($reasonKey);
            $status = Statuses::label($statusKey);
            $type   = Types::label($typeKey);

            $data = [
                ['name' => __('Request ID', 'redono'), 'value' => (string) $postId],
                ['name' => __('Order ID', 'redono'), 'value' => (string) $orderId],
                ['name' => __('Type', 'redono'), 'value' => $type],
                ['name' => __('Reason', 'redono'), 'value' => $reason],
                ['name' => __('Customer Note', 'redono'), 'value' => $note],
                ['name' => __('Status', 'redono'), 'value' => $status],
                ['name' => __('Date', 'redono'), 'value' => $postDate],
            ];

            /**
             * Filters the personal data exported for one request, so an add-on
             * can export the fields it stores on the request.
             *
             * @param list<array{name: string, value: string}> $data   Name/value pairs.
             * @param int                                      $postId The request post ID.
             */
            $data = (array) apply_filters('returns/privacy_export_data', $data, $postId);

            $items[] = [
                'group_id'    => 'returns-requests',
                'group_label' => __('Return & RMA Requests', 'redono'),
                'item_id'     => 'returns-rma-' . $postId,
                'data'        => $data,
            ];
        }

        return [
            'data' => $items,
            'done' => count($postIds) < self::PAGE_SIZE,
        ];
    }

    /**
     * @return array{items_removed: int, items_retained: int, messages: list<string>, done: bool}
     */
    public function eraseRequests(string $email, int $page = 1): array
    {
        $page    = max(1, $page);
        $postIds = $this->findRmaPostIds($email, $page);

        $anonymized = 0;
        foreach ($postIds as $postId) {
            update_post_meta($postId, ReturnRequest::META_CUSTOMER_ID, 0);
            update_post_meta($postId, ReturnRequest::META_NOTE, '');
            // The customer also submitted the record, so they are its author.
            wp_update_post(['ID' => $postId, 'post_author' => 0]);

            /**
             * Fires while one request is anonymised by the personal data eraser,
             * so an add-on can erase the fields it stores on the request.
             *
             * @param int $postId The request post ID.
             */
            do_action('returns/privacy_erase', $postId);
            $anonymized++;
        }

        return [
            'items_removed'  => $anonymized,
            'items_retained' => $anonymized, // Retained for accounting/statutory warranty requirements
            'messages'       => $anonymized > 0
                ? [__('Customer personal notes and IDs removed from RMA requests; return records retained for statutory bookkeeping.', 'redono')]
                : [],
            'done'           => count($postIds) < self::PAGE_SIZE,
        ];
    }

    /**
     * @return list<int>
     */
    private function findRmaPostIds(string $email, int $page): array
    {
        $user = get_user_by('email', $email);
        $userId = $user instanceof WP_User ? (int) $user->ID : 0;

        $orderIds = [];
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'billing_email' => $email,
                'limit'         => -1,
                'return'        => 'ids',
            ]);
            if (is_array($orders)) {
                $orderIds = wp_parse_id_list($orders);
            }
        }

        $metaQueries = [];
        if ($userId > 0) {
            $metaQueries[] = [
                'key'     => ReturnRequest::META_CUSTOMER_ID,
                'value'   => $userId,
                'compare' => '=',
            ];
        }
        if (! empty($orderIds)) {
            $metaQueries[] = [
                'key'     => ReturnRequest::META_ORDER_ID,
                'value'   => $orderIds,
                'compare' => 'IN',
            ];
        }

        if (empty($metaQueries)) {
            return [];
        }

        $queryArgs = [
            'post_type'      => ReturnRequest::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => self::PAGE_SIZE,
            'paged'          => $page,
            'fields'         => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A
            // personal-data export or erasure has no other way in: a request is a
            // post and its owner is post meta. This runs only when WordPress
            // processes a privacy request for one person, never on a front-end or
            // admin page load, it is paged at PAGE_SIZE, it asks for ids alone,
            // and both keys are ordinary indexed post meta.
            'meta_query'     => array_merge(['relation' => 'OR'], $metaQueries),
        ];

        $query = new WP_Query($queryArgs);
        /** @var list<int> $posts */
        $posts = is_array($query->posts) ? array_map('intval', $query->posts) : [];

        return $posts;
    }
}
