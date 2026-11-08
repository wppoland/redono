<?php

declare(strict_types=1);

namespace Returns\Service;

use Returns\Contract\HasHooks;
use Returns\PostType\ReturnRequest;
use Returns\Support\Reasons;
use Returns\Support\Options;
use Returns\Support\Types;

defined('ABSPATH') || exit;

/**
 * The customer-facing "Request a return" flow inside WooCommerce My Account.
 *
 * Adds an action to each eligible order, registers a `request-return` account
 * endpoint, renders the item-picker form, and processes the submission. Every
 * step is ownership-checked: only the logged-in owner of an order may open a
 * return for it (no IDOR). A guest order is reached instead through an order
 * number + billing email lookup on the same endpoint, which binds the visitor
 * to that one order (see GuestAccess). All output is escaped; all input is
 * sanitised and nonce-verified.
 */
final class ReturnRequestForm implements HasHooks
{
    public const ENDPOINT = 'request-return';

    private const NONCE = 'returns_submit_request';

    private const LOOKUP_NONCE = 'returns_guest_lookup';

    /** Query/form parameter carrying the signed guest access token. */
    private const GUEST_PARAM = 'returns_guest';

    /** @var array<string, string> Validation errors keyed by field. */
    private array $errors = [];

    /** @var array<string, string> Submitted values retained for the error re-render. */
    private array $submitted = [];

    public function __construct(
        private readonly ReturnRequest $requests,
        private readonly GuestAccess $guest,
    ) {
    }

    public function registerHooks(): void
    {
        if (! Options::isEnabled()) {
            return;
        }

        add_action('init', [$this, 'addEndpoint']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_filter('woocommerce_get_query_vars', [$this, 'addQueryVar']);
        add_filter('woocommerce_account_menu_items', [$this, 'hideEndpointFromMenu'], 10, 1);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'renderEndpoint']);

        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'orderActions'], 10, 2);
        add_action('woocommerce_order_details_after_order_table', [$this, 'orderViewButton'], 10, 1);

        add_action('template_redirect', [$this, 'maybeHandleSubmit']);
        add_action('template_redirect', [$this, 'maybeHandleLookup']);

        // Logged-out visitors get the login form on every account URL, so the
        // guest lookup lives above it.
        add_action('woocommerce_before_customer_login_form', [$this, 'renderGuest']);
    }

    public function addEndpoint(): void
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    /**
     * Load the storefront stylesheet on My Account pages only.
     */
    public function enqueueAssets(): void
    {
        if (! function_exists('is_account_page') || ! is_account_page()) {
            return;
        }

        wp_enqueue_style(
            'returns',
            RETURNS_URL . 'assets/css/returns.css',
            [],
            \Returns\VERSION,
        );

        // Progressive enhancement only: the form works with this script absent,
        // the server validates the reason against the chosen type regardless.
        wp_enqueue_script(
            'returns',
            RETURNS_URL . 'assets/js/returns.js',
            [],
            \Returns\VERSION,
            true,
        );
    }

    /**
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    public function addQueryVar(array $vars): array
    {
        $vars[self::ENDPOINT] = self::ENDPOINT;

        return $vars;
    }

    /**
     * Keep the endpoint out of the account navigation, it is reached only via
     * the per-order "Request a return" action.
     *
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public function hideEndpointFromMenu(array $items): array
    {
        unset($items[self::ENDPOINT]);

        return $items;
    }

    /**
     * Add a "Request a return" action to eligible orders in the orders list.
     *
     * @param array<string, array{url: string, name: string}> $actions
     * @return array<string, array{url: string, name: string}>
     */
    public function orderActions(array $actions, \WC_Order $order): array
    {
        // WooCommerce 10.9 started feeding this filter into order-details.php,
        // which also renders the order-received screen. A return link belongs on
        // My Account, not on the page a shopper lands on straight after paying.
        // The single order view already has orderViewButton(), so adding the
        // action there too would print the link twice.
        if ((function_exists('is_order_received_page') && is_order_received_page()) || is_wc_endpoint_url('view-order')) {
            return $actions;
        }

        if (! $this->isEligible($order)) {
            return $actions;
        }

        $actions['returns_request'] = [
            'url'  => $this->endpointUrl((int) $order->get_id()),
            'name' => __('Request a return', 'redono'),
        ];

        return $actions;
    }

    /**
     * Show a "Request a return" button on the single order view page.
     */
    public function orderViewButton(\WC_Order $order): void
    {
        if (! is_account_page() || ! $this->isEligible($order)) {
            return;
        }

        if ($this->allTypesSubmitted((int) $order->get_id())) {
            printf(
                '<p class="returns-existing">%s</p>',
                esc_html__('A request of every type has already been submitted for this order.', 'redono'),
            );

            return;
        }

        printf(
            '<p class="returns-request-cta"><a class="button" href="%1$s">%2$s</a></p>',
            esc_url($this->endpointUrl((int) $order->get_id())),
            esc_html__('Request a return', 'redono'),
        );
    }

    /**
     * Whether every request type has already been used for an order, so there
     * is nothing left to file. Kept per-type: a return no longer blocks a later
     * complaint or repair for the same order.
     */
    private function allTypesSubmitted(int $orderId): bool
    {
        foreach (array_keys(Types::all()) as $type) {
            if (! $this->requests->existsForOrderType($orderId, (string) $type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render the return request form for the order in the endpoint URL.
     */
    public function renderEndpoint(mixed $value = null): void
    {
        unset($value);

        $orderId = $this->endpointOrderId();
        $order   = $orderId > 0 ? wc_get_order($orderId) : null;

        echo '<div class="returns-form-wrap">';

        if (! $order instanceof \WC_Order || ! $this->ownsOrder($order)) {
            $this->renderNotice(__('That order could not be found, or you do not have permission to return it.', 'redono'), 'error');
            echo '</div>';

            return;
        }

        if ($this->allTypesSubmitted($orderId)) {
            $this->renderNotice(__('A request of every type has already been submitted for this order.', 'redono'), 'info');
            echo '</div>';

            return;
        }

        if (! $this->isEligible($order)) {
            $this->renderNotice(__('This order is not eligible for a return.', 'redono'), 'info');
            echo '</div>';

            return;
        }

        $this->renderForm($order);

        echo '</div>';
    }

    /**
     * Handle the POSTed return request early, before output, so we can redirect.
     */
    public function maybeHandleSubmit(): void
    {
        if (! isset($_POST['returns_submit'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below.
            return;
        }

        $nonce = isset($_POST['returns_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['returns_nonce']))
            : '';

        if (! wp_verify_nonce($nonce, self::NONCE)) {
            $this->errors['_form'] = __('Your session expired. Please try again.', 'redono');

            return;
        }

        $guestToken = isset($_POST[self::GUEST_PARAM]) ? GuestAccess::clean(sanitize_text_field(wp_unslash($_POST[self::GUEST_PARAM]))) : '';

        if ('' !== $guestToken) {
            // Guest path: the order comes from the signed token only, the
            // posted order id is ignored.
            $order = $this->guestOrder($guestToken);
        } elseif (! is_user_logged_in()) {
            $this->errors['_form'] = __('Please log in to request a return.', 'redono');

            return;
        } else {
            $orderId = isset($_POST['returns_order_id']) ? absint(wp_unslash($_POST['returns_order_id'])) : 0;
            $order   = $orderId > 0 ? wc_get_order($orderId) : null;
            $order   = $order instanceof \WC_Order && $this->ownsOrder($order) ? $order : null;
        }

        $type = isset($_POST['returns_type']) ? sanitize_key(wp_unslash($_POST['returns_type'])) : '';

        if (! $order instanceof \WC_Order || ! $this->isEligible($order)) {
            $this->errors['_form'] = __('That order could not be found, or you do not have permission to return it.', 'redono');

            return;
        }

        $orderId = (int) $order->get_id();
        $items  = $this->collectItems($order);
        $reason = isset($_POST['returns_reason']) ? sanitize_text_field(wp_unslash($_POST['returns_reason'])) : '';
        $note   = isset($_POST['returns_note']) ? sanitize_textarea_field(wp_unslash($_POST['returns_note'])) : '';
        $remedy = isset($_POST['returns_remedy']) ? sanitize_key(wp_unslash($_POST['returns_remedy'])) : '';

        // Retain the choices so a validation re-render keeps what was entered.
        $this->submitted = [
            'type'   => $type,
            'reason' => $reason,
            'remedy' => $remedy,
            'note'   => $note,
        ];

        if (! Types::isValid($type)) {
            $this->errors['type'] = __('Please choose the kind of request you want to make.', 'redono');
        } elseif (! $this->isEligible($order, $type)) {
            $this->errors['type'] = __('The time limit for this kind of request has passed for this order.', 'redono');
        }

        if ([] === $items) {
            $this->errors['items'] = __('Please select at least one item.', 'redono');
        }

        if ('' === $reason) {
            $this->errors['reason'] = __('Please choose a reason.', 'redono');
        } elseif (Types::isValid($type) && ! Reasons::isValidForType($reason, $type)) {
            $this->errors['reason'] = __('That reason does not match the chosen request type.', 'redono');
        }

        // A preferred remedy is required for complaint and repair requests only.
        if (Types::isValid($type) && Types::hasRemedy($type)) {
            if (! Types::isValidRemedy($remedy)) {
                $this->errors['remedy'] = __('Please choose a preferred remedy.', 'redono');
            }
        } else {
            $remedy = '';
        }

        if (Types::isValid($type) && $this->requests->existsForOrderType($orderId, $type)) {
            $this->errors['_form'] = sprintf(
                /* translators: %s: request type label, e.g. "Return", "Complaint" or "Repair". */
                __('A %s request has already been submitted for this order.', 'redono'),
                Types::label($type),
            );
        }

        if ([] !== $this->errors) {
            return;
        }

        $postId = $this->requests->create($orderId, '' === $guestToken ? get_current_user_id() : 0, $items, $reason, $note, $type, $remedy);

        if ($postId > 0) {
            $this->notifyMerchant($postId, $order, $items, $reason, $note, $type, $remedy);
            $this->notifyCustomer($postId, $order, $items, $type);
        }

        // A guest has no orders screen, so they land back on the lookup page.
        $target = '' === $guestToken ? wc_get_account_endpoint_url('orders') : wc_get_account_endpoint_url(self::ENDPOINT);

        wp_safe_redirect(add_query_arg('returns_sent', '1', $target));
        exit;
    }

    /**
     * Handle the guest lookup (order number + billing email). On a match the
     * visitor is redirected to the form with a token bound to that order.
     */
    public function maybeHandleLookup(): void
    {
        if (! isset($_POST['returns_lookup'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below.
            return;
        }

        $nonce = isset($_POST['returns_lookup_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['returns_lookup_nonce']))
            : '';

        if (! wp_verify_nonce($nonce, self::LOOKUP_NONCE)) {
            $this->errors['_lookup'] = __('Your session expired. Please try again.', 'redono');

            return;
        }

        $number = isset($_POST['returns_lookup_order']) ? sanitize_text_field(wp_unslash($_POST['returns_lookup_order'])) : '';
        $email  = isset($_POST['returns_lookup_email']) ? sanitize_text_field(wp_unslash($_POST['returns_lookup_email'])) : '';

        $this->submitted['lookup_order'] = $number;
        $this->submitted['lookup_email'] = $email;

        // Counted before the lookup, so a match and a miss cost the same.
        if ($this->guest->throttled($this->clientIp())) {
            $this->errors['_lookup'] = __('Too many attempts. Please wait 15 minutes and try again.', 'redono');

            return;
        }

        $order = $this->guest->lookup($number, $email);

        // One message for every failure: wrong number, wrong email, an order
        // that belongs to an account, or an order past its windows.
        if (! $order instanceof \WC_Order || ! $this->isEligible($order)) {
            $this->errors['_lookup'] = __('We could not find an eligible order with that order number and email address.', 'redono');

            return;
        }

        wp_safe_redirect(add_query_arg(self::GUEST_PARAM, $this->guest->issue((int) $order->get_id()), wc_get_account_endpoint_url(self::ENDPOINT)));
        exit;
    }

    /**
     * The guest view, shown above the login form to logged-out visitors: a link
     * on the plain login page, the lookup or the request form on the endpoint.
     */
    public function renderGuest(): void
    {
        if (is_user_logged_in()) {
            return;
        }

        if (! is_wc_endpoint_url(self::ENDPOINT)) {
            printf(
                '<p class="returns-guest-link"><a href="%1$s">%2$s</a></p>',
                esc_url(wc_get_account_endpoint_url(self::ENDPOINT)),
                esc_html__('Ordered without an account? Request a return with your order number.', 'redono'),
            );

            return;
        }

        echo '<div class="returns-form-wrap">';

        // Read-only flags; the token is verified by resolve().
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $sent  = isset($_GET['returns_sent']);
        $token = isset($_GET[self::GUEST_PARAM]) ? GuestAccess::clean(sanitize_text_field(wp_unslash($_GET[self::GUEST_PARAM]))) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($sent) {
            printf(
                '<div class="returns-notice returns-notice--success" role="status"><strong>%1$s</strong> %2$s</div>',
                esc_html__('Return requested.', 'redono'),
                esc_html__('We have received your return request and will be in touch shortly.', 'redono'),
            );
        }

        $order = '' !== $token ? $this->guestOrder($token) : null;

        if ($order instanceof \WC_Order && $this->isEligible($order)) {
            if ($this->allTypesSubmitted((int) $order->get_id())) {
                $this->renderNotice(__('A request of every type has already been submitted for this order.', 'redono'), 'info');
            } else {
                $this->renderForm($order, $token);
            }

            echo '</div>';

            return;
        }

        if ('' !== $token) {
            $this->renderNotice(__('This link has expired. Please look up your order again.', 'redono'), 'info');
        }

        $this->renderLookupForm();

        echo '</div>';
    }

    private function renderLookupForm(): void
    {
        if (isset($this->errors['_lookup'])) {
            $this->renderNotice($this->errors['_lookup'], 'error');
        }
        ?>
        <h2><?php esc_html_e('Request a return for a guest order', 'redono'); ?></h2>
        <p><?php esc_html_e('Enter the order number and the billing email address used at checkout. If you have an account, log in below instead.', 'redono'); ?></p>
        <form method="post" class="returns-form returns-lookup">
            <?php wp_nonce_field(self::LOOKUP_NONCE, 'returns_lookup_nonce'); ?>
            <p class="returns-form__field">
                <label for="returns-lookup-order"><?php esc_html_e('Order number', 'redono'); ?> <span class="returns-form__req" aria-hidden="true">*</span></label>
                <input type="text" id="returns-lookup-order" name="returns_lookup_order" required autocomplete="off"
                    value="<?php echo esc_attr($this->submitted['lookup_order'] ?? ''); ?>" />
            </p>
            <p class="returns-form__field">
                <label for="returns-lookup-email"><?php esc_html_e('Billing email', 'redono'); ?> <span class="returns-form__req" aria-hidden="true">*</span></label>
                <input type="email" id="returns-lookup-email" name="returns_lookup_email" required autocomplete="email"
                    value="<?php echo esc_attr($this->submitted['lookup_email'] ?? ''); ?>" />
            </p>
            <p class="returns-form__submit">
                <button type="submit" name="returns_lookup" value="1" class="button"><?php esc_html_e('Find my order', 'redono'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * The guest order a token grants, or null. Re-checks that the order is still
     * a guest order, so a token can never outlive an order being claimed by an
     * account.
     */
    private function guestOrder(string $token): ?\WC_Order
    {
        $orderId = $this->guest->resolve($token);
        $order   = $orderId > 0 ? wc_get_order($orderId) : null;

        return $order instanceof \WC_Order && 0 === (int) $order->get_customer_id() ? $order : null;
    }

    /**
     * REMOTE_ADDR only: forwarded headers are client-controlled, honouring them
     * would hand out a fresh throttle bucket per request.
     */
    private function clientIp(): string
    {
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }

    /**
     * Sanitise and resolve the submitted items against the order's real lines,
     * so a tampered POST can never inject products the order never contained.
     *
     * @return array<int, array{item_id: int, name: string, qty: int}>
     */
    private function collectItems(\WC_Order $order): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce is verified in maybeHandleSubmit() before this runs.
        $selected = isset($_POST['returns_items']) && is_array($_POST['returns_items'])
            ? map_deep(wp_unslash($_POST['returns_items']), 'absint')
            : [];

        $rawQty = isset($_POST['returns_qty']) && is_array($_POST['returns_qty'])
            ? map_deep(wp_unslash($_POST['returns_qty']), 'absint')
            : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $items = [];

        foreach ($order->get_items() as $itemId => $item) {
            $itemId = (int) $itemId;

            if (! in_array($itemId, $selected, true)) {
                continue;
            }

            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $maxQty   = max(1, (int) $item->get_quantity());
            $wantQty  = isset($rawQty[$itemId]) ? absint($rawQty[$itemId]) : $maxQty;
            $wantQty  = min(max(1, $wantQty), $maxQty);

            $items[] = [
                'item_id' => $itemId,
                'name'    => $item->get_name(),
                'qty'     => $wantQty,
            ];
        }

        return $items;
    }

    private function renderForm(\WC_Order $order, string $guestToken = ''): void
    {
        // Only the types whose time window is still open for this order.
        $types = array_filter(
            Types::all(),
            fn ($typeKey): bool => $this->isEligible($order, (string) $typeKey),
            ARRAY_FILTER_USE_KEY,
        );

        if (isset($this->errors['_form'])) {
            $this->renderNotice($this->errors['_form'], 'error');
        }
        ?>
        <h2><?php esc_html_e('Request a return', 'redono'); ?></h2>
        <p class="returns-form__order">
            <?php
            printf(
                /* translators: %s: order number */
                esc_html__('Order #%s', 'redono'),
                esc_html((string) $order->get_order_number()),
            );
            ?>
        </p>

        <form method="post" class="returns-form" novalidate>
            <?php
            wp_nonce_field(self::NONCE, 'returns_nonce');
            $chosenType   = $this->submitted['type'] ?? Types::RETURN;
            $chosenType   = isset($types[$chosenType]) ? $chosenType : (string) array_key_first($types);
            $chosenReason = $this->submitted['reason'] ?? '';
            $chosenRemedy = $this->submitted['remedy'] ?? '';
            $chosenNote   = $this->submitted['note'] ?? '';
            ?>
            <input type="hidden" name="returns_order_id" value="<?php echo esc_attr((string) $order->get_id()); ?>" />
            <?php if ('' !== $guestToken) : ?>
                <input type="hidden" name="<?php echo esc_attr(self::GUEST_PARAM); ?>" value="<?php echo esc_attr($guestToken); ?>" />
            <?php endif; ?>

            <fieldset class="returns-form__type">
                <legend><?php esc_html_e('What would you like to do?', 'redono'); ?> <span class="returns-form__req" aria-hidden="true">*</span></legend>
                <?php if (isset($this->errors['type'])) : ?>
                    <span class="returns-form__error" role="alert"><?php echo esc_html($this->errors['type']); ?></span>
                <?php endif; ?>
                <?php foreach ($types as $typeKey => $typeLabel) :
                    $descId = 'returns-type-desc-' . $typeKey;
                    ?>
                    <label class="returns-type">
                        <input type="radio" name="returns_type" value="<?php echo esc_attr((string) $typeKey); ?>"
                            <?php checked($chosenType, $typeKey); ?> required
                            aria-describedby="<?php echo esc_attr($descId); ?>" />
                        <span class="returns-type__body">
                            <span class="returns-type__label"><?php echo esc_html($typeLabel); ?></span>
                            <span class="returns-type__desc" id="<?php echo esc_attr($descId); ?>"><?php echo esc_html(Types::description((string) $typeKey)); ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <fieldset class="returns-form__items">
                <legend><?php esc_html_e('Which items would you like to return?', 'redono'); ?></legend>
                <?php if (isset($this->errors['items'])) : ?>
                    <span class="returns-form__error" role="alert"><?php echo esc_html($this->errors['items']); ?></span>
                <?php endif; ?>

                <table class="returns-form__table">
                    <thead>
                        <tr>
                            <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Select', 'redono'); ?></span></th>
                            <th scope="col"><?php esc_html_e('Product', 'redono'); ?></th>
                            <th scope="col"><?php esc_html_e('Quantity', 'redono'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order->get_items() as $itemId => $item) :
                            if (! $item instanceof \WC_Order_Item_Product) {
                                continue;
                            }
                            $itemId = (int) $itemId;
                            $maxQty = max(1, (int) $item->get_quantity());
                            ?>
                            <tr>
                                <td data-label="<?php esc_attr_e('Select', 'redono'); ?>">
                                    <input type="checkbox" id="returns-item-<?php echo esc_attr((string) $itemId); ?>"
                                        name="returns_items[]" value="<?php echo esc_attr((string) $itemId); ?>" />
                                </td>
                                <td data-label="<?php esc_attr_e('Product', 'redono'); ?>">
                                    <label for="returns-item-<?php echo esc_attr((string) $itemId); ?>">
                                        <?php echo esc_html($item->get_name()); ?>
                                    </label>
                                </td>
                                <td data-label="<?php esc_attr_e('Quantity', 'redono'); ?>">
                                    <label class="screen-reader-text" for="returns-qty-<?php echo esc_attr((string) $itemId); ?>">
                                        <?php esc_html_e('Quantity to return', 'redono'); ?>
                                    </label>
                                    <input type="number" min="1" step="1" max="<?php echo esc_attr((string) $maxQty); ?>"
                                        id="returns-qty-<?php echo esc_attr((string) $itemId); ?>"
                                        name="returns_qty[<?php echo esc_attr((string) $itemId); ?>]"
                                        value="<?php echo esc_attr((string) $maxQty); ?>"
                                        class="returns-form__qty" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </fieldset>

            <p class="returns-form__field">
                <label for="returns-reason"><?php esc_html_e('Reason', 'redono'); ?> <span class="returns-form__req" aria-hidden="true">*</span></label>
                <select id="returns-reason" name="returns_reason" required
                    <?php echo isset($this->errors['reason']) ? 'aria-invalid="true" aria-describedby="returns-reason-error"' : ''; ?>>
                    <option value=""><?php esc_html_e('Select a reason…', 'redono'); ?></option>
                    <?php foreach (Types::all() as $typeKey => $typeLabel) : ?>
                        <optgroup label="<?php echo esc_attr($typeLabel); ?>" data-type="<?php echo esc_attr((string) $typeKey); ?>">
                            <?php foreach (Reasons::forType((string) $typeKey) as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" data-type="<?php echo esc_attr((string) $typeKey); ?>"
                                    <?php selected($chosenReason === $value && $chosenType === $typeKey); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($this->errors['reason'])) : ?>
                    <span class="returns-form__error" id="returns-reason-error" role="alert"><?php echo esc_html($this->errors['reason']); ?></span>
                <?php endif; ?>
            </p>

            <p class="returns-form__field returns-remedy" data-returns-remedy>
                <label for="returns-remedy"><?php esc_html_e('Preferred remedy', 'redono'); ?> <span class="returns-form__req" aria-hidden="true">*</span></label>
                <select id="returns-remedy" name="returns_remedy"
                    <?php echo isset($this->errors['remedy']) ? 'aria-invalid="true" aria-describedby="returns-remedy-error"' : ''; ?>>
                    <option value=""><?php esc_html_e('Select a remedy…', 'redono'); ?></option>
                    <?php foreach (Types::remedies() as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($chosenRemedy, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="returns-form__hint"><?php esc_html_e('Applies to complaint and repair requests. Under EU rules you can ask for a repair of a faulty item.', 'redono'); ?></span>
                <?php if (isset($this->errors['remedy'])) : ?>
                    <span class="returns-form__error" id="returns-remedy-error" role="alert"><?php echo esc_html($this->errors['remedy']); ?></span>
                <?php endif; ?>
            </p>

            <p class="returns-form__field">
                <label for="returns-note"><?php esc_html_e('Additional details', 'redono'); ?></label>
                <textarea id="returns-note" name="returns_note" rows="4"><?php echo esc_textarea($chosenNote); ?></textarea>
            </p>

            <p class="returns-form__submit">
                <button type="submit" name="returns_submit" value="1" class="button alt"><?php esc_html_e('Submit return request', 'redono'); ?></button>
                <a class="returns-form__cancel" href="<?php echo esc_url('' === $guestToken ? wc_get_account_endpoint_url('orders') : wc_get_account_endpoint_url(self::ENDPOINT)); ?>"><?php esc_html_e('Cancel', 'redono'); ?></a>
            </p>
        </form>
        <?php
    }

    private function renderNotice(string $message, string $type): void
    {
        printf(
            '<div class="returns-notice returns-notice--%1$s" role="%2$s">%3$s</div>',
            esc_attr($type),
            esc_attr('error' === $type ? 'alert' : 'status'),
            esc_html($message),
        );
    }

    /**
     * Email the merchant about a new return request.
     *
     * @param array<int, array{item_id: int, name: string, qty: int}> $items
     */
    private function notifyMerchant(int $postId, \WC_Order $order, array $items, string $reason, string $note, string $type, string $remedy): void
    {
        $recipient = (string) get_option('admin_email');

        $lines   = [];
        $lines[] = sprintf(
            /* translators: 1: request type label, 2: order number, 3: site name */
            __('A new %1$s request was submitted for order #%2$s on %3$s.', 'redono'),
            Types::label($type),
            (string) $order->get_order_number(),
            wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
        );
        $lines[] = '';
        $lines[] = __('Customer:', 'redono') . ' ' . trim($order->get_formatted_billing_full_name());
        $lines[] = __('Type:', 'redono') . ' ' . Types::label($type);
        $lines[] = __('Reason:', 'redono') . ' ' . Reasons::label($reason);

        if ('' !== $remedy) {
            $lines[] = __('Preferred remedy:', 'redono') . ' ' . Types::remedyLabel($remedy);
        }

        $lines[] = '';
        $lines[] = __('Requested items:', 'redono');

        foreach ($items as $item) {
            $lines[] = sprintf('- %1$s x %2$d', $item['name'], $item['qty']);
        }

        if ('' !== $note) {
            $lines[] = '';
            $lines[] = __('Customer note:', 'redono');
            $lines[] = $note;
        }

        // Not get_edit_post_link(): it checks the current user, and here that is
        // the customer, who cannot edit the record, so the link was always empty.
        $lines[] = '';
        $lines[] = __('Manage in admin:', 'redono') . ' ' . admin_url('post.php?post=' . $postId . '&action=edit');

        $subject = sprintf(
            /* translators: 1: request type label, 2: order number */
            __('New %1$s request for order #%2$s', 'redono'),
            Types::label($type),
            (string) $order->get_order_number(),
        );

        wp_mail($recipient, $subject, implode("\n", $lines));
    }

    /**
     * Email the customer a receipt for the request they just filed.
     *
     * @param array<int, array{item_id: int, name: string, qty: int}> $items
     */
    private function notifyCustomer(int $postId, \WC_Order $order, array $items, string $type): void
    {
        $recipient = (string) $order->get_billing_email();

        if (! is_email($recipient)) {
            return;
        }

        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);

        $lines   = [];
        $lines[] = sprintf(
            /* translators: 1: request type label, 2: order number */
            __('We have received your %1$s request for order #%2$s.', 'redono'),
            Types::label($type),
            (string) $order->get_order_number(),
        );
        $lines[] = '';
        $lines[] = __('Request number:', 'redono') . ' #' . $postId;
        $lines[] = __('Type:', 'redono') . ' ' . Types::label($type);
        $lines[] = '';
        $lines[] = __('Requested items:', 'redono');

        foreach ($items as $item) {
            $lines[] = sprintf('- %1$s x %2$d', $item['name'], $item['qty']);
        }

        $lines[] = '';
        $lines[] = __('We will review your request and get back to you.', 'redono');

        if ((int) $order->get_customer_id() > 0) {
            $lines[] = __('You can follow its status in your account:', 'redono') . ' ' . wc_get_account_endpoint_url('orders');
        }

        $lines[] = '';
        $lines[] = $site;

        $subject = sprintf(
            /* translators: 1: site name, 2: request type label, 3: request number */
            __('[%1$s] Your %2$s request #%3$d has been received', 'redono'),
            $site,
            Types::label($type),
            $postId,
        );

        wp_mail($recipient, $subject, implode("\n", $lines));
    }

    /**
     * Whether an order is in an eligible status and inside the time window of
     * $type. With no type: whether any request type is still open for it.
     */
    private function isEligible(\WC_Order $order, ?string $type = null): bool
    {
        if (! in_array($order->get_status(), Options::eligibleStatuses(), true)) {
            return false;
        }

        $created = $order->get_date_created();

        if (! $created instanceof \WC_DateTime) {
            return true;
        }

        $types    = null === $type ? array_keys(Types::all()) : [$type];
        $repaired = 'yes' === $order->get_meta(ReturnRequest::META_ORDER_REPAIRED);

        foreach ($types as $candidate) {
            if (Options::windowOpen($created->getTimestamp(), time(), (string) $candidate, $repaired)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ownership check: the current user must own the order (no IDOR).
     */
    private function ownsOrder(\WC_Order $order): bool
    {
        $userId = get_current_user_id();

        return $userId > 0 && (int) $order->get_customer_id() === $userId;
    }

    private function endpointOrderId(): int
    {
        // Read-only navigation parameter; no state change occurs here.
        return isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    private function endpointUrl(int $orderId): string
    {
        return add_query_arg('order_id', $orderId, wc_get_account_endpoint_url(self::ENDPOINT));
    }
}
