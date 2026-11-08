<?php

declare(strict_types=1);

namespace Returns\PostType;

use Returns\Contract\HasHooks;
use Returns\Support\Reasons;
use Returns\Support\Statuses;
use Returns\Support\Types;

defined('ABSPATH') || exit;

/**
 * The private custom post type that stores submitted return (RMA) requests.
 *
 * Requests are not public, the CPT is registered with public => false and is
 * surfaced only in wp-admin under the WooCommerce menu. Each post links to a
 * WooCommerce order and stores the requested line items, the customer's reason
 * and note, and a workflow status (requested/approved/rejected/completed).
 *
 * All meta writes happen through create()/updateStatus(); the admin status box
 * is the only editable surface and is nonce- and capability-guarded.
 */
final class ReturnRequest implements HasHooks
{
    public const POST_TYPE = 'returns_rma';

    public const META_ORDER_ID    = '_returns_order_id';
    public const META_CUSTOMER_ID = '_returns_customer_id';
    public const META_ITEMS       = '_returns_items';
    public const META_REASON      = '_returns_reason';
    public const META_NOTE        = '_returns_note';
    public const META_STATUS      = '_returns_status';
    public const META_TYPE        = '_returns_type';
    public const META_REMEDY      = '_returns_remedy';

    /** Order meta set once a repair request on the order is completed; extends the complaint window. */
    public const META_ORDER_REPAIRED = '_returns_repaired';

    private const STATUS_NONCE = 'returns_save_status';

    public function registerHooks(): void
    {
        $this->register();

        if (is_admin()) {
            add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'columns']);
            add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'renderColumn'], 10, 2);
            add_action('add_meta_boxes', [$this, 'addMetaBoxes']);
            add_action('save_post_' . self::POST_TYPE, [$this, 'saveStatus'], 10, 2);
            add_action('restrict_manage_posts', [$this, 'renderTypeFilter']);
            add_action('parse_query', [$this, 'filterByType']);
        }
    }

    /**
     * Register the post type. Called directly (not only via hook) so it is
     * available immediately during boot on the init action.
     */
    public function register(): void
    {
        if (post_type_exists(self::POST_TYPE)) {
            return;
        }

        register_post_type(
            self::POST_TYPE,
            [
                'labels'              => [
                    'name'               => __('Return Requests', 'redono'),
                    'singular_name'      => __('Return Request', 'redono'),
                    'menu_name'          => __('Return Requests', 'redono'),
                    'all_items'          => __('Return Requests', 'redono'),
                    'edit_item'          => __('Return Request', 'redono'),
                    'view_item'          => __('Return Request', 'redono'),
                    'search_items'       => __('Search return requests', 'redono'),
                    'not_found'          => __('No return requests found.', 'redono'),
                    'not_found_in_trash' => __('No return requests in Trash.', 'redono'),
                ],
                'public'              => false,
                'show_ui'             => true,
                'show_in_menu'        => 'woocommerce',
                'show_in_rest'        => false,
                'exclude_from_search' => true,
                'publicly_queryable'  => false,
                'has_archive'         => false,
                'rewrite'             => false,
                'query_var'           => false,
                'hierarchical'        => false,
                'menu_icon'           => 'dashicons-undo',
                'supports'            => ['title'],
                // Requests hold customer names, emails and order details, so
                // every capability resolves to manage_woocommerce instead of
                // the post caps an Editor already has.
                'map_meta_cap'        => false,
                'capabilities'        => [
                    'edit_post'              => 'manage_woocommerce',
                    'read_post'              => 'manage_woocommerce',
                    'delete_post'            => 'manage_woocommerce',
                    'edit_posts'             => 'manage_woocommerce',
                    'edit_others_posts'      => 'manage_woocommerce',
                    'edit_private_posts'     => 'manage_woocommerce',
                    'edit_published_posts'   => 'manage_woocommerce',
                    'publish_posts'          => 'manage_woocommerce',
                    'read_private_posts'     => 'manage_woocommerce',
                    'read'                   => 'manage_woocommerce',
                    'delete_posts'           => 'manage_woocommerce',
                    'delete_private_posts'   => 'manage_woocommerce',
                    'delete_published_posts' => 'manage_woocommerce',
                    'delete_others_posts'    => 'manage_woocommerce',
                    'create_posts'           => 'do_not_allow',
                ],
            ],
        );
    }

    /**
     * Persist a request. Returns the new post ID, or 0 on failure.
     *
     * The request type defaults to RETURN when an unknown value is passed, so
     * older callers that omit it keep the original behaviour. A preferred remedy
     * is only stored for complaint and repair requests; it is dropped otherwise.
     *
     * @param array<int, array{item_id: int, name: string, qty: int}> $items
     */
    public function create(int $orderId, int $customerId, array $items, string $reason, string $note, string $type = Types::RETURN, string $remedy = ''): int
    {
        $type = Types::isValid($type) ? $type : Types::RETURN;

        if (! Types::hasRemedy($type) || ! Types::isValidRemedy($remedy)) {
            $remedy = '';
        }

        $title = sprintf(
            /* translators: 1: request type label, 2: order number, 3: human-readable date */
            __('%1$s for order #%2$s, %3$s', 'redono'),
            Types::label($type),
            (string) $orderId,
            wp_date(get_option('date_format') . ' ' . get_option('time_format')),
        );

        $postId = wp_insert_post(
            [
                'post_type'   => self::POST_TYPE,
                'post_status' => 'private',
                'post_title'  => $title,
            ],
            true,
        );

        if (is_wp_error($postId) || 0 === $postId) {
            return 0;
        }

        update_post_meta($postId, self::META_ORDER_ID, $orderId);
        update_post_meta($postId, self::META_CUSTOMER_ID, $customerId);
        update_post_meta($postId, self::META_ITEMS, $items);
        update_post_meta($postId, self::META_REASON, $reason);
        update_post_meta($postId, self::META_NOTE, $note);
        update_post_meta($postId, self::META_STATUS, Statuses::REQUESTED);
        update_post_meta($postId, self::META_TYPE, $type);
        update_post_meta($postId, self::META_REMEDY, $remedy);

        /**
         * Fires after a new request is stored. Add-ons (e.g. Redono Pro) listen
         * here to run type-specific automation, such as repair intake.
         *
         * @param int    $postId The new request post ID.
         * @param string $type   The request type (a valid Types constant).
         */
        do_action('returns/request_created', (int) $postId, $type);

        return (int) $postId;
    }

    /**
     * The request type of a record (defaults to RETURN for pre-typing records).
     */
    public function type(int $postId): string
    {
        $type = (string) get_post_meta($postId, self::META_TYPE, true);

        return Types::isValid($type) ? $type : Types::RETURN;
    }

    /**
     * The stored preferred remedy, or an empty string when none applies.
     */
    public function remedy(int $postId): string
    {
        $remedy = (string) get_post_meta($postId, self::META_REMEDY, true);

        return Types::isValidRemedy($remedy) ? $remedy : '';
    }

    /**
     * The current workflow status of a request (defaults to "requested").
     */
    /**
     * A completed request whose remedy is repair (a Repair request, or a complaint asking for repair)
     * flags its order, once, so the complaint window gains 12 months.
     * shortcut: per order rather than per item, a superset of the per-item extension in the directive.
     */
    public static function markRepaired(int $postId, string $status): void
    {
        $type   = (string) get_post_meta($postId, self::META_TYPE, true);
        $remedy = (string) get_post_meta($postId, self::META_REMEDY, true);
        if (Statuses::COMPLETED !== $status || (Types::REPAIR !== $type && Types::REMEDY_REPAIR !== $remedy)) {
            return;
        }

        $order = wc_get_order((int) get_post_meta($postId, self::META_ORDER_ID, true));
        if ($order instanceof \WC_Order && 'yes' !== $order->get_meta(self::META_ORDER_REPAIRED)) {
            $order->update_meta_data(self::META_ORDER_REPAIRED, 'yes');
            $order->save();
        }
    }

    public function status(int $postId): string
    {
        $status = (string) get_post_meta($postId, self::META_STATUS, true);

        return Statuses::isValid($status) ? $status : Statuses::REQUESTED;
    }

    /**
     * Whether a given order already has a request of any type.
     */
    public function existsForOrder(int $orderId): bool
    {
        $query = new \WP_Query([
            'post_type'              => self::POST_TYPE,
            'post_status'            => 'private',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // Bounded lookup (1 row) on an indexed meta key, acceptable here.
            'meta_key'               => self::META_ORDER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'             => (string) $orderId, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ]);

        return [] !== $query->posts;
    }

    /**
     * Whether a given order already has a request of a specific type. A record
     * with no stored type counts as RETURN (back-compat), so a second return can
     * still be blocked while a complaint or repair remains available.
     */
    public function existsForOrderType(int $orderId, string $type): bool
    {
        $type = Types::isValid($type) ? $type : Types::RETURN;

        // Match the type meta exactly; for RETURN also match legacy records that
        // predate typing and carry no type meta at all.
        $typeClause = Types::RETURN === $type
            ? [
                'relation' => 'OR',
                ['key' => self::META_TYPE, 'value' => $type],
                ['key' => self::META_TYPE, 'compare' => 'NOT EXISTS'],
            ]
            : [
                ['key' => self::META_TYPE, 'value' => $type],
            ];

        $query = new \WP_Query([
            'post_type'              => self::POST_TYPE,
            'post_status'            => 'private',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // Bounded lookup (1 row) keyed on the order, then narrowed by type.
            'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'relation' => 'AND',
                ['key' => self::META_ORDER_ID, 'value' => (string) $orderId],
                $typeClause,
            ],
        ]);

        return [] !== $query->posts;
    }

    /**
     * Return requests belonging to a specific customer, newest first.
     *
     * @return list<int> post IDs
     */
    public function findByCustomer(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $query = new \WP_Query([
            'post_type'              => self::POST_TYPE,
            'post_status'            => 'private',
            'posts_per_page'         => 50,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'orderby'                => 'date',
            'order'                  => 'DESC',
            // A customer's own returns: a small, capped (50) result set.
            'meta_key'               => self::META_CUSTOMER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value'             => (string) $customerId, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ]);

        /** @var list<int> $ids */
        $ids = array_map('intval', $query->posts);

        return $ids;
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $reordered = [];

        foreach ($columns as $key => $label) {
            if ('date' === $key) {
                $reordered['returns_order']  = __('Order', 'redono');
                $reordered['returns_type']   = __('Type', 'redono');
                $reordered['returns_status'] = __('Status', 'redono');
                $reordered['returns_items']  = __('Items', 'redono');
            }

            $reordered[$key] = $label;
        }

        return $reordered;
    }

    public function renderColumn(string $column, int $postId): void
    {
        switch ($column) {
            case 'returns_order':
                $orderId = (int) get_post_meta($postId, self::META_ORDER_ID, true);
                $link    = $orderId > 0 ? $this->orderEditLink($orderId) : '';
                if ('' !== $link) {
                    printf('<a href="%1$s">#%2$s</a>', esc_url($link), esc_html((string) $orderId));
                } else {
                    echo esc_html($orderId > 0 ? '#' . $orderId : '-');
                }
                break;

            case 'returns_type':
                $type = $this->type($postId);
                printf(
                    '<span class="returns-type-badge returns-type-badge--%1$s">%2$s</span>',
                    esc_attr(Types::slug($type)),
                    esc_html(Types::label($type)),
                );
                break;

            case 'returns_status':
                $status = $this->status($postId);
                printf(
                    '<span class="returns-badge returns-badge--%1$s">%2$s</span>',
                    esc_attr(Statuses::slug($status)),
                    esc_html(Statuses::label($status)),
                );
                break;

            case 'returns_items':
                $items = get_post_meta($postId, self::META_ITEMS, true);
                echo esc_html((string) (is_array($items) ? count($items) : 0));
                break;
        }
    }

    public function addMetaBoxes(): void
    {
        add_meta_box(
            'returns_rma_details',
            __('Return details', 'redono'),
            [$this, 'renderDetailsBox'],
            self::POST_TYPE,
            'normal',
            'high',
        );

        add_meta_box(
            'returns_rma_status',
            __('Status', 'redono'),
            [$this, 'renderStatusBox'],
            self::POST_TYPE,
            'side',
            'high',
        );
    }

    public function renderDetailsBox(\WP_Post $post): void
    {
        $orderId  = (int) get_post_meta($post->ID, self::META_ORDER_ID, true);
        $reason   = (string) get_post_meta($post->ID, self::META_REASON, true);
        $note     = (string) get_post_meta($post->ID, self::META_NOTE, true);
        $type     = $this->type($post->ID);
        $remedy   = $this->remedy($post->ID);
        $items    = get_post_meta($post->ID, self::META_ITEMS, true);
        $items    = is_array($items) ? $items : [];
        $orderUrl = $orderId > 0 ? $this->orderEditLink($orderId) : '';
        ?>
        <table class="widefat striped" style="margin-bottom:1em">
            <tbody>
                <tr>
                    <th scope="row" style="width:160px"><?php esc_html_e('Order', 'redono'); ?></th>
                    <td>
                        <?php if ('' !== $orderUrl) : ?>
                            <a href="<?php echo esc_url($orderUrl); ?>">#<?php echo esc_html((string) $orderId); ?></a>
                        <?php else : ?>
                            <?php echo esc_html($orderId > 0 ? '#' . $orderId : '-'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Type', 'redono'); ?></th>
                    <td>
                        <span class="returns-type-badge returns-type-badge--<?php echo esc_attr(Types::slug($type)); ?>">
                            <?php echo esc_html(Types::label($type)); ?>
                        </span>
                    </td>
                </tr>
                <?php if (Types::hasRemedy($type)) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Preferred remedy', 'redono'); ?></th>
                        <td><?php echo esc_html('' !== $remedy ? Types::remedyLabel($remedy) : '-'); ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Reason', 'redono'); ?></th>
                    <td><?php echo esc_html('' !== $reason ? Reasons::label($reason) : '-'); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Customer note', 'redono'); ?></th>
                    <td><?php echo '' !== $note ? esc_html($note) : '-'; ?></td>
                </tr>
            </tbody>
        </table>

        <h3><?php esc_html_e('Requested items', 'redono'); ?></h3>
        <?php if ([] === $items) : ?>
            <p><?php esc_html_e('No items recorded.', 'redono'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Product', 'redono'); ?></th>
                        <th scope="col" style="width:120px"><?php esc_html_e('Quantity', 'redono'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item) :
                        $name = isset($item['name']) ? (string) $item['name'] : '';
                        $qty  = isset($item['qty']) ? absint($item['qty']) : 1;
                        ?>
                        <tr>
                            <td><?php echo esc_html($name); ?></td>
                            <td><?php echo esc_html((string) $qty); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    public function renderStatusBox(\WP_Post $post): void
    {
        $current = $this->status($post->ID);
        wp_nonce_field(self::STATUS_NONCE, 'returns_status_nonce');
        ?>
        <p>
            <label for="returns-status" class="screen-reader-text"><?php esc_html_e('Return status', 'redono'); ?></label>
            <select id="returns-status" name="returns_status" style="width:100%">
                <?php foreach (Statuses::all() as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($current, $key); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="description">
            <?php esc_html_e('The customer sees this status in their account. Updating it here does not refund the order, process any refund in the order screen.', 'redono'); ?>
        </p>
        <?php
    }

    /**
     * Persist the status when the request is saved in wp-admin.
     */
    public function saveStatus(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $nonce = isset($_POST['returns_status_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['returns_status_nonce']))
            : '';

        if (! wp_verify_nonce($nonce, self::STATUS_NONCE)) {
            return;
        }

        $status = isset($_POST['returns_status'])
            ? sanitize_key(wp_unslash($_POST['returns_status']))
            : '';

        if (! Statuses::isValid($status)) {
            return;
        }

        $previous = $this->status($postId);

        if ($status === $previous) {
            return;
        }

        update_post_meta($postId, self::META_STATUS, $status);
        self::markRepaired($postId, $status);

        /**
         * Fires after a return request's workflow status changes in wp-admin.
         *
         * Add-ons (e.g. Redono Pro) listen here to notify the customer. Only
         * fired when the status actually changes to a new, valid value.
         *
         * @param int    $postId   The return request post ID.
         * @param string $status   The new status (a valid Statuses constant).
         * @param string $previous The previous status.
         */
        do_action('returns/status_changed', $postId, $status, $previous);
    }

    /**
     * Render a "filter by type" dropdown above the request list table.
     */
    public function renderTypeFilter(string $postType): void
    {
        if (self::POST_TYPE !== $postType || ! current_user_can('manage_woocommerce')) {
            return;
        }

        // Read-only list filter; no state change occurs here.
        $current = isset($_GET[self::META_TYPE]) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key(wp_unslash($_GET[self::META_TYPE])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';
        ?>
        <label for="returns-filter-type" class="screen-reader-text"><?php esc_html_e('Filter by type', 'redono'); ?></label>
        <select id="returns-filter-type" name="<?php echo esc_attr(self::META_TYPE); ?>">
            <option value=""><?php esc_html_e('All types', 'redono'); ?></option>
            <?php foreach (Types::all() as $key => $label) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($current, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Narrow the admin list query to a single request type when the filter is
     * set. RETURN also matches legacy records with no stored type.
     */
    public function filterByType(\WP_Query $query): void
    {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (! $screen instanceof \WP_Screen || self::POST_TYPE !== $screen->post_type || 'edit' !== $screen->base) {
            return;
        }

        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        // Read-only list filter; no state change occurs here.
        $type = isset($_GET[self::META_TYPE]) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key(wp_unslash($_GET[self::META_TYPE])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';

        if (! Types::isValid($type)) {
            return;
        }

        $query->set(
            'meta_query',
            Types::RETURN === $type
                ? [
                    'relation' => 'OR',
                    ['key' => self::META_TYPE, 'value' => $type],
                    ['key' => self::META_TYPE, 'compare' => 'NOT EXISTS'],
                ]
                : [
                    ['key' => self::META_TYPE, 'value' => $type],
                ],
        );
    }

    /**
     * Resolve the admin edit URL for an order across HPOS and legacy storage.
     */
    private function orderEditLink(int $orderId): string
    {
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($orderId);
            if ($order instanceof \WC_Order && method_exists($order, 'get_edit_order_url')) {
                return (string) $order->get_edit_order_url();
            }
        }

        $link = get_edit_post_link($orderId, 'raw');

        return is_string($link) ? $link : '';
    }
}
