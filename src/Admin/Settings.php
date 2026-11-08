<?php

declare(strict_types=1);

namespace Returns\Admin;

defined('ABSPATH') || exit;

use Returns\Contract\HasHooks;
use Returns\PostType\ReturnRequest;
use Returns\Support\Options;

/**
 * Admin settings page registered as a WooCommerce submenu.
 *
 * Stores everything in the `returns_settings` option (array): the master
 * toggle, the eligible order statuses, the return window (days) and the
 * complaint and repair window (months). All output is
 * escaped; all input is sanitised on save. The page also links to the saved
 * Return Requests list.
 */
final class Settings implements HasHooks
{
    private const PAGE  = 'returns-settings';
    private const GROUP = 'returns_settings_group';

    private ?ProUpsell $proUpsell = null;

    private function proUpsell(): ProUpsell
    {
        return $this->proUpsell ??= new ProUpsell();
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        $this->proUpsell()->registerHooks();
    }

    public function enqueueAssets(string $hook): void
    {
        // Load the shared admin stylesheet on our settings page and on the
        // return-request CPT screens (for the status badges).
        $screen   = function_exists('get_current_screen') ? get_current_screen() : null;
        $isCpt    = $screen instanceof \WP_Screen && ReturnRequest::POST_TYPE === $screen->post_type;
        $isOurs   = 'woocommerce_page_' . self::PAGE === $hook;

        if (! $isCpt && ! $isOurs) {
            return;
        }

        wp_enqueue_style(
            'returns-admin',
            RETURNS_URL . 'assets/css/admin.css',
            [],
            \Returns\VERSION,
        );
    }

    public function addMenuPage(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Redono: returns and RMA', 'redono'),
            __('Redono', 'redono'),
            'manage_woocommerce',
            self::PAGE,
            [$this, 'renderPage'],
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            self::GROUP,
            Options::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize'],
            ],
        );

        add_filter(
            'option_page_capability_' . self::GROUP,
            static fn (): string => 'manage_woocommerce',
        );
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $settings = Options::all();
        $eligible = Options::eligibleStatuses();
        $listUrl  = admin_url('edit.php?post_type=' . ReturnRequest::POST_TYPE);
        ?>
        <div class="wrap returns-admin">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors(); ?>

            <?php $this->proUpsell()->banner(); ?>

            <div class="returns-intro">
                <h2><?php esc_html_e('Let customers request returns from their account', 'redono'); ?></h2>
                <p>
                    <?php esc_html_e('Customers open a return from My Account > Orders: they pick items, a quantity, a reason and an optional note. Each request is emailed to you and saved as a private record you manage here, with a status the customer can follow.', 'redono'); ?>
                </p>
                <p>
                    <a class="button" href="<?php echo esc_url($listUrl); ?>"><?php esc_html_e('View return requests', 'redono'); ?></a>
                </p>
            </div>

            <div class="returns-cols">
            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>

                <div class="returns-card">
                    <h2 class="returns-card__title"><?php esc_html_e('Availability', 'redono'); ?></h2>
                    <p class="returns-card__lead"><?php esc_html_e('The master switch for the whole return flow.', 'redono'); ?></p>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable returns', 'redono'); ?></th>
                                <td>
                                    <label for="returns_enabled">
                                        <input type="checkbox" id="returns_enabled"
                                            name="<?php echo esc_attr(Options::OPTION); ?>[enabled]" value="1"
                                            <?php checked((bool) ($settings['enabled'] ?? false), true); ?> />
                                        <?php esc_html_e('Let customers request a return from My Account.', 'redono'); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e('On: a "Request a return" link appears next to eligible orders, and the request form is reachable. Off: the link and form disappear from the storefront, existing requests stay in your records. Ships on.', 'redono'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="returns-card">
                    <h2 class="returns-card__title"><?php esc_html_e('What can be returned', 'redono'); ?></h2>
                    <p class="returns-card__lead"><?php esc_html_e('Decide which orders qualify and for how long. An order must clear both tests before its "Request a return" link shows.', 'redono'); ?></p>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Eligible order statuses', 'redono'); ?></th>
                                <td>
                                    <fieldset>
                                        <legend class="screen-reader-text"><?php esc_html_e('Eligible order statuses', 'redono'); ?></legend>
                                        <?php foreach ($this->orderStatuses() as $key => $label) : ?>
                                            <label class="returns-checkbox">
                                                <input type="checkbox"
                                                    name="<?php echo esc_attr(Options::OPTION); ?>[eligible_statuses][]"
                                                    value="<?php echo esc_attr($key); ?>"
                                                    <?php checked(in_array($key, $eligible, true), true); ?> />
                                                <?php echo esc_html($label); ?>
                                            </label><br />
                                        <?php endforeach; ?>
                                    </fieldset>
                                    <p class="description"><?php esc_html_e('Orders in any other status never show the return link. Most shops pick the statuses that mean "the customer has the goods", usually Completed (and Processing if you fulfil before marking complete). Ships with Completed and Processing ticked; if you untick everything, Completed is kept so the feature still works.', 'redono'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="returns_window_days"><?php esc_html_e('Return window (days)', 'redono'); ?></label>
                                </th>
                                <td>
                                    <input type="number" min="0" step="1" id="returns_window_days" class="small-text"
                                        name="<?php echo esc_attr(Options::OPTION); ?>[window_days]"
                                        value="<?php echo esc_attr((string) ($settings['window_days'] ?? 30)); ?>" />
                                    <p class="description"><?php esc_html_e('Applies to Return requests. Counted from the order date. After it passes, the customer can no longer choose Return for that order. Set 0 to accept returns with no deadline. Ships at 30.', 'redono'); ?></p>
                                    <p class="returns-example">
                                        <?php
                                        printf(
                                            /* translators: %s is the date the return window closes. */
                                            esc_html__('Example: an order placed today can be returned until %s.', 'redono'),
                                            '<strong>' . esc_html($this->windowExample((int) ($settings['window_days'] ?? 30), 'days')) . '</strong>'
                                        );
                                        ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="returns_complaint_window_months"><?php esc_html_e('Complaint and repair window (months)', 'redono'); ?></label>
                                </th>
                                <td>
                                    <input type="number" min="0" step="1" id="returns_complaint_window_months" class="small-text"
                                        name="<?php echo esc_attr(Options::OPTION); ?>[complaint_window_months]"
                                        value="<?php echo esc_attr((string) Options::complaintWindowMonths()); ?>" />
                                    <p class="description"><?php esc_html_e('Applies to Complaint and Repair requests, counted from the order date. A fault can surface long after the return window has closed, and in the EU the legal guarantee runs for at least two years, so this window is separate. Once a repair request on an order is completed, that order gets 12 more months, once, because repair extends the liability of the seller (Article 10(2a) of Directive (EU) 2019/771). Set 0 for no deadline. Ships at 24.', 'redono'); ?></p>
                                    <p class="returns-example">
                                        <?php
                                        printf(
                                            /* translators: %s is the date the complaint and repair window closes. */
                                            esc_html__('Example: an order placed today can receive a complaint or repair request until %s.', 'redono'),
                                            '<strong>' . esc_html($this->windowExample(Options::complaintWindowMonths(), 'months')) . '</strong>'
                                        );
                                        ?>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="returns-card">
                    <h2 class="returns-card__title"><?php esc_html_e('Ecosystem integrations', 'redono'); ?></h2>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><?php esc_html_e('Plogins Withdraw', 'redono'); ?></th>
                                <td>
                                    <?php if (class_exists('\Withdraw\Plugin')) : ?>
                                        <p class="description" style="color:#007017;font-weight:600;">
                                            <?php esc_html_e('Plogins Withdraw is active. It handles right-of-withdrawal requests with its own form and list, separate from the return requests here.', 'redono'); ?>
                                        </p>
                                    <?php else : ?>
                                        <p class="description">
                                            <?php
                                            printf(
                                                /* translators: %s: partner plugin name, linked to its search on the Add Plugins screen. */
                                                esc_html__('Install %s to add a right-of-withdrawal button and form next to your returns flow.', 'redono'),
                                                '<a href="' . esc_url(admin_url('plugin-install.php?s=' . rawurlencode('Plogins Withdraw - Right of Withdrawal Button for WooCommerce') . '&tab=search&type=term')) . '">' . esc_html('Plogins Withdraw - Right of Withdrawal Button for WooCommerce') . '</a>'
                                            );
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Shoplex (EU Compliance)', 'redono'); ?></th>
                                <td>
                                    <?php if (defined('\\Shoplex\\VERSION') || class_exists('\\Shoplex\\Plugin')) : ?>
                                        <p class="description" style="color:#007017;font-weight:600;">
                                            <?php esc_html_e('Shoplex is active. Comprehensive EU Consumer Rights, Omnibus price tracking, and legal compliance notices are active across your store.', 'redono'); ?>
                                        </p>
                                    <?php else : ?>
                                        <p class="description">
                                            <?php
                                            printf(
                                                /* translators: %s: partner plugin name, linked to its search on the Add Plugins screen. */
                                                esc_html__('Install %s to ensure full storewide compliance with the EU Consumer Rights Directive, Omnibus pricing, and VAT display rules.', 'redono'),
                                                '<a href="' . esc_url(admin_url('plugin-install.php?s=' . rawurlencode('Shoplex - EU Compliance for WooCommerce') . '&tab=search&type=term')) . '">' . esc_html('Shoplex - EU Compliance for WooCommerce') . '</a>'
                                            );
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Sizavo (Size Guides)', 'redono'); ?></th>
                                <td>
                                    <?php if (class_exists('\\Sizer\\Plugin')) : ?>
                                        <p class="description" style="color:#007017;font-weight:600;">
                                            <?php esc_html_e('Sizavo is active. Clear size guides help customers choose the right size and prevent returns before they happen.', 'redono'); ?>
                                        </p>
                                    <?php else : ?>
                                        <p class="description">
                                            <?php
                                            printf(
                                                /* translators: %s: partner plugin name, linked to its search on the Add Plugins screen. */
                                                esc_html__('Install %s to reduce return rates by displaying interactive size charts and measurement guides on product pages.', 'redono'),
                                                '<a href="' . esc_url(admin_url('plugin-install.php?s=' . rawurlencode('Sizavo - Size Guide for WooCommerce') . '&tab=search&type=term')) . '">' . esc_html('Sizavo - Size Guide for WooCommerce') . '</a>'
                                            );
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <?php submit_button(); ?>
            </form>

                <?php $this->proUpsell()->aside(); ?>
            </div>

            <?php $this->proUpsell()->cards(); ?>
        </div>
        <?php
    }

    /**
     * Human-readable deadline for the inline window example.
     *
     * Presentation only: turns the saved window ($unit is the strtotime unit,
     * days or months) into "31 July 2026" using the
     * site's date format, or a no-deadline phrase when the window is open.
     */
    private function windowExample(int $count, string $unit): string
    {
        if ($count <= 0) {
            return __('any time later, no deadline', 'redono');
        }

        $format = (string) get_option('date_format', 'F j, Y');
        $when   = strtotime('+' . $count . ' ' . $unit, current_time('timestamp'));

        return (string) wp_date($format, $when ?: null);
    }

    /**
     * The available WooCommerce order statuses, without the wc- prefix.
     *
     * @return array<string, string>
     */
    private function orderStatuses(): array
    {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $clean    = [];

        foreach ($statuses as $key => $label) {
            $clean[(string) preg_replace('/^wc-/', '', (string) $key)] = (string) $label;
        }

        if ([] === $clean) {
            $clean = [
                'processing' => __('Processing', 'redono'),
                'completed'  => __('Completed', 'redono'),
            ];
        }

        return $clean;
    }

    /**
     * Sanitise the submitted settings before save.
     *
     * @param mixed $raw
     * @return array<string, mixed>
     */
    public function sanitize(mixed $raw): array
    {
        if (! is_array($raw)) {
            $raw = [];
        }

        $valid    = array_keys($this->orderStatuses());
        $eligible = [];

        if (isset($raw['eligible_statuses']) && is_array($raw['eligible_statuses'])) {
            foreach ($raw['eligible_statuses'] as $status) {
                $status = sanitize_key((string) $status);
                if (in_array($status, $valid, true)) {
                    $eligible[] = $status;
                }
            }
        }

        if ([] === $eligible) {
            $eligible = ['completed'];
        }

        return [
            'enabled'           => ! empty($raw['enabled']),
            'eligible_statuses' => array_values(array_unique($eligible)),
            'window_days'       => isset($raw['window_days']) ? max(0, absint($raw['window_days'])) : 30,
            'complaint_window_months' => isset($raw['complaint_window_months']) ? absint($raw['complaint_window_months']) : 24,
        ];
    }
}
