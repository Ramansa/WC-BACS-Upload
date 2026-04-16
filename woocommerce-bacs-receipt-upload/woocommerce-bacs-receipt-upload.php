<?php
/**
 * Plugin Name: WooCommerce BACS Receipt Upload
 * Description: Allows customers/admin to upload bank transfer proof from order pages and notify configured recipients.
 * Version: 1.1.0
 * Author: Codex
 * Requires Plugins: woocommerce
 * Text Domain: wc-bacs-receipt-upload
 */

if (! defined('ABSPATH')) {
    exit;
}

final class WC_BACS_Receipt_Upload
{
    private const OPTION_RECIPIENTS = 'wc_bacs_receipt_upload_recipients';
    private const OPTION_SUBJECT = 'wc_bacs_receipt_upload_subject';
    private const META_RECEIPT_ID = '_wc_bacs_receipt_upload_attachment_id';
    private const META_VERIFIED = '_wc_bacs_receipt_upload_verified';
    private const MAX_UPLOAD_BYTES = 10485760; // 10 MB.
    private const ALLOWED_RECEIPT_MIMES = [
        'image/jpeg',
        'image/gif',
        'image/png',
        'image/bmp',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
        'application/rtf',
    ];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('woocommerce_view_order', [$this, 'render_customer_upload_form'], 1);
        add_action('woocommerce_thankyou', [$this, 'render_order_received_upload_form'], 25);
        add_action('add_meta_boxes', [$this, 'register_admin_metabox']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render_admin_order_section']);

        add_action('admin_post_wc_bacs_receipt_upload', [$this, 'handle_upload']);
        add_action('admin_post_wc_bacs_receipt_delete', [$this, 'handle_delete']);
        add_action('admin_post_wc_bacs_receipt_verify', [$this, 'handle_verify']);
        add_action('admin_post_wc_bacs_receipt_unverify', [$this, 'handle_unverify']);

        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'add_upload_action_to_my_orders'], 10, 2);
    }

    public function register_settings_page(): void
    {
        add_submenu_page(
            'woocommerce',
            __('BACS Receipt Upload', 'wc-bacs-receipt-upload'),
            __('BACS Receipt Upload', 'wc-bacs-receipt-upload'),
            'manage_woocommerce',
            'wc-bacs-receipt-upload',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'wc_bacs_receipt_upload_settings',
            self::OPTION_RECIPIENTS,
            [
                'type' => 'string',
                'sanitize_callback' => [$this, 'sanitize_recipients'],
                'default' => '',
            ]
        );

        register_setting(
            'wc_bacs_receipt_upload_settings',
            self::OPTION_SUBJECT,
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => __('New bank transfer receipt uploaded', 'wc-bacs-receipt-upload'),
            ]
        );

        add_settings_section(
            'wc_bacs_receipt_upload_main',
            __('Email Notifications', 'wc-bacs-receipt-upload'),
            function (): void {
                echo '<p>' . esc_html__('Set who should receive notification emails when receipts are uploaded.', 'wc-bacs-receipt-upload') . '</p>';
            },
            'wc-bacs-receipt-upload'
        );

        add_settings_field(
            self::OPTION_RECIPIENTS,
            __('Recipient emails', 'wc-bacs-receipt-upload'),
            [$this, 'render_recipients_field'],
            'wc-bacs-receipt-upload',
            'wc_bacs_receipt_upload_main'
        );

        add_settings_field(
            self::OPTION_SUBJECT,
            __('Email subject', 'wc-bacs-receipt-upload'),
            [$this, 'render_subject_field'],
            'wc-bacs-receipt-upload',
            'wc_bacs_receipt_upload_main'
        );
    }

    public function sanitize_recipients(string $value): string
    {
        $emails = array_filter(array_map('trim', explode(',', $value)));
        $emails = array_filter($emails, static fn(string $email): bool => (bool) is_email($email));

        return implode(',', array_unique($emails));
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('BACS Receipt Upload Settings', 'wc-bacs-receipt-upload'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wc_bacs_receipt_upload_settings');
                do_settings_sections('wc-bacs-receipt-upload');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_recipients_field(): void
    {
        $value = (string) get_option(self::OPTION_RECIPIENTS, '');
        printf(
            '<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="admin@example.com, finance@example.com"/>',
            esc_attr(self::OPTION_RECIPIENTS),
            esc_attr($value)
        );
        echo '<p class="description">' . esc_html__('Comma-separated email addresses.', 'wc-bacs-receipt-upload') . '</p>';
    }

    public function render_subject_field(): void
    {
        $value = (string) get_option(self::OPTION_SUBJECT, __('New bank transfer receipt uploaded', 'wc-bacs-receipt-upload'));
        printf(
            '<input type="text" class="regular-text" name="%1$s" value="%2$s"/>',
            esc_attr(self::OPTION_SUBJECT),
            esc_attr($value)
        );
    }

    public function register_admin_metabox(): void
    {
        if ($this->is_hpos_enabled()) {
            return;
        }

        add_meta_box(
            'wc-bacs-receipt-upload-box',
            __('Bank Transfer Receipt', 'wc-bacs-receipt-upload'),
            [$this, 'render_admin_metabox'],
            'shop_order',
            'side',
            'high'
        );
    }

    public function render_admin_metabox(WP_Post $post): void
    {
        if (! current_user_can('edit_shop_orders')) {
            return;
        }

        $order = wc_get_order($post->ID);
        if (! $order instanceof WC_Order || 'bacs' !== $order->get_payment_method()) {
            echo '<p>' . esc_html__('This box appears only for BACS orders.', 'wc-bacs-receipt-upload') . '</p>';
            return;
        }

        $verified = $this->is_verified($order);
        $this->render_shared_receipt_ui($order, true, $verified);
    }

    public function render_admin_order_section(WC_Order $order): void
    {
        if (! current_user_can('edit_shop_orders') || ! $this->is_hpos_enabled()) {
            return;
        }

        if ('bacs' !== $order->get_payment_method()) {
            return;
        }

        echo '<div class="order_data_column" style="width:100%;">';
        echo '<h3>' . esc_html__('Bank Transfer Receipt', 'wc-bacs-receipt-upload') . '</h3>';
        $verified = $this->is_verified($order);
        $this->render_shared_receipt_ui($order, true, $verified);
        echo '</div>';
    }

    public function render_customer_upload_form(int $order_id): void
    {
        if (! is_user_logged_in()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order) {
            return;
        }

        if ((int) $order->get_user_id() !== (int) get_current_user_id()) {
            return;
        }

        if ('bacs' !== $order->get_payment_method()) {
            return;
        }

        $verified = $this->is_verified($order);

        echo '<section class="woocommerce-order-receipt-upload" style="margin-bottom:2em;">';
        echo '<h2>' . esc_html__('Upload Bank Transfer Receipt', 'wc-bacs-receipt-upload') . '</h2>';
        $this->render_bacs_instructions();
        $this->render_bacs_bank_details();
        $this->render_shared_receipt_ui($order, false, $verified);
        echo '</section>';
    }

    public function render_order_received_upload_form(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order || 'bacs' !== $order->get_payment_method()) {
            return;
        }

        if (! $this->can_customer_access_order($order)) {
            return;
        }

        $verified = $this->is_verified($order);

        echo '<section class="woocommerce-order-receipt-upload" style="margin:1.5em 0 0;">';
        echo '<h2>' . esc_html__('Upload BACS Receipt', 'wc-bacs-receipt-upload') . '</h2>';
        $this->render_bacs_instructions();
        $this->render_bacs_bank_details();
        $this->render_shared_receipt_ui($order, false, $verified);
        echo '</section>';
    }

    private function render_bacs_instructions(): void
    {
        $instructions = __('Please transfer to our bank account details below, then upload your transfer receipt for verification.', 'wc-bacs-receipt-upload');

        echo '<div class="woocommerce-info" style="margin-bottom:1em;">' . esc_html($instructions) . '</div>';
    }

    private function render_bacs_bank_details(): void
    {
        $accounts = $this->get_bacs_accounts();
        if (! is_array($accounts) || empty($accounts)) {
            return;
        }

        echo '<div class="woocommerce-info" style="margin-bottom:1em;color:#000;">';
        echo '<strong>' . esc_html__('Bank Account Details', 'wc-bacs-receipt-upload') . '</strong>';

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            echo '<table class="shop_table shop_table_responsive" style="margin-top:0.75em;color:#000;">';
            $rows = [
                __('Account Name', 'wc-bacs-receipt-upload') => $account['account_name'] ?? '',
                __('Bank Name', 'wc-bacs-receipt-upload') => $account['bank_name'] ?? '',
                __('Account Number', 'wc-bacs-receipt-upload') => $account['account_number'] ?? '',
                __('Sort Code', 'wc-bacs-receipt-upload') => $account['sort_code'] ?? '',
                __('Routing Number', 'wc-bacs-receipt-upload') => $account['routing_number'] ?? '',
                __('IBAN', 'wc-bacs-receipt-upload') => $account['iban'] ?? '',
                __('BIC / Swift', 'wc-bacs-receipt-upload') => $account['bic'] ?? '',
            ];

            foreach ($rows as $label => $value) {
                $clean = trim((string) $value);
                if ('' === $clean) {
                    continue;
                }
                echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($clean) . '</td></tr>';
            }

            echo '</table>';
        }

        echo '</div>';
    }

    private function get_bacs_accounts(): array
    {
        $accounts = get_option('woocommerce_bacs_accounts', []);
        if (is_array($accounts) && ! empty($accounts)) {
            return $accounts;
        }

        $settings = get_option('woocommerce_bacs_settings', []);
        if (! is_array($settings) || ! isset($settings['account_details'])) {
            return [];
        }

        $details = maybe_unserialize($settings['account_details']);
        if (! is_array($details)) {
            return [];
        }

        return array_values(array_filter($details, static fn($account): bool => is_array($account)));
    }

    private function render_shared_receipt_ui(WC_Order $order, bool $is_admin, bool $verified): void
    {
        $order_id = $order->get_id();
        $existing_attachment_id = (int) $order->get_meta(self::META_RECEIPT_ID);
        $existing_url = $existing_attachment_id ? wp_get_attachment_url($existing_attachment_id) : '';

        if (! $is_admin && ! empty($_GET['wc_receipt_status'])) {
            $status = sanitize_text_field(wp_unslash($_GET['wc_receipt_status']));
            if ('success' === $status) {
                echo '<div class="woocommerce-message" role="alert">' . esc_html__('Receipt action completed.', 'wc-bacs-receipt-upload') . '</div>';
            }
            if ('error' === $status) {
                echo '<div class="woocommerce-error" role="alert">' . esc_html__('Action failed. Please try again.', 'wc-bacs-receipt-upload') . '</div>';
            }
        }

        if ($verified) {
            echo '<p><strong>' . esc_html__('Payment is verified by admin. Upload is locked for this order.', 'wc-bacs-receipt-upload') . '</strong></p>';
        } else {
            echo '<p>' . esc_html__('Upload or replace proof of bank transfer anytime before verification.', 'wc-bacs-receipt-upload') . '</p>';
        }

        if ($existing_url) {
            $this->render_receipt_preview($existing_attachment_id, $existing_url);
        }

        if (! $verified) {
            $this->render_upload_form($order_id, $is_admin);

            if ($existing_attachment_id) {
                $this->render_delete_form($order_id, $is_admin);
            }
        }

        if ($is_admin) {
            if (! $verified) {
                $this->render_verify_form($order_id);
            } else {
                $this->render_unverify_form($order_id);
            }
        }
    }

    private function render_upload_form(int $order_id, bool $is_admin): void
    {
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wc_bacs_receipt_upload"/>';
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order_id) . '"/>';
        echo '<input type="hidden" name="is_admin_context" value="' . esc_attr($is_admin ? '1' : '0') . '"/>';
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            echo '<input type="hidden" name="order_key" value="' . esc_attr($order->get_order_key()) . '"/>';
        }
        wp_nonce_field('wc_bacs_receipt_upload_' . $order_id);
        echo '<p><input type="file" name="wc_bacs_receipt_file" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.rtf" required/></p>';
        echo '<p><button type="submit" class="button">' . esc_html__('Upload / Replace Receipt', 'wc-bacs-receipt-upload') . '</button></p>';
        echo '</form>';
    }

    private function render_delete_form(int $order_id, bool $is_admin): void
    {
        if ($is_admin) {
            $delete_url = $this->build_admin_action_url('wc_bacs_receipt_delete', $order_id, 'wc_bacs_receipt_delete_' . $order_id);
            echo '<p><a class="button button-secondary" href="' . esc_url($delete_url) . '">' . esc_html__('Delete Receipt', 'wc-bacs-receipt-upload') . '</a></p>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wc_bacs_receipt_delete"/>';
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order_id) . '"/>';
        echo '<input type="hidden" name="is_admin_context" value="' . esc_attr($is_admin ? '1' : '0') . '"/>';
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            echo '<input type="hidden" name="order_key" value="' . esc_attr($order->get_order_key()) . '"/>';
        }
        wp_nonce_field('wc_bacs_receipt_delete_' . $order_id);
        echo '<p><button type="submit" class="button button-secondary">' . esc_html__('Delete Receipt', 'wc-bacs-receipt-upload') . '</button></p>';
        echo '</form>';
    }

    private function render_verify_form(int $order_id): void
    {
        $verify_url = $this->build_admin_action_url('wc_bacs_receipt_verify', $order_id, 'wc_bacs_receipt_verify_' . $order_id);
        echo '<p><a class="button button-primary" href="' . esc_url($verify_url) . '">' . esc_html__('Verify Payment (Lock Upload)', 'wc-bacs-receipt-upload') . '</a></p>';
    }

    private function render_unverify_form(int $order_id): void
    {
        $unverify_url = $this->build_admin_action_url('wc_bacs_receipt_unverify', $order_id, 'wc_bacs_receipt_unverify_' . $order_id);
        echo '<p><a class="button button-secondary" href="' . esc_url($unverify_url) . '">' . esc_html__('Unverify Payment (Unlock Upload)', 'wc-bacs-receipt-upload') . '</a></p>';
    }

    private function render_receipt_preview(int $attachment_id, string $file_url): void
    {
        $is_image = $attachment_id > 0 ? wp_attachment_is_image($attachment_id) : false;
        $filename = basename((string) $file_url);

        if ($is_image) {
            $image = wp_get_attachment_image(
                $attachment_id,
                'medium',
                false,
                [
                    'style' => 'max-width:220px;height:auto;border:1px solid #ddd;border-radius:4px;',
                    'alt' => $filename,
                ]
            );

            if ($image) {
                echo '<p><strong>' . esc_html__('Current image receipt:', 'wc-bacs-receipt-upload') . '</strong></p>';
                echo '<p><a href="' . esc_url($file_url) . '" target="_blank" rel="noopener noreferrer">' . $image . '</a></p>';
                echo '<p><a class="button button-secondary" href="' . esc_url($file_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Open Full Size Image', 'wc-bacs-receipt-upload') . '</a></p>';
                return;
            }
        }

        echo '<p><strong>' . esc_html__('Current document:', 'wc-bacs-receipt-upload') . '</strong> ';
        echo '<a href="' . esc_url($file_url) . '" download>' . esc_html($filename) . '</a></p>';
    }

    public function add_upload_action_to_my_orders(array $actions, WC_Order $order): array
    {
        if ('bacs' !== $order->get_payment_method()) {
            return $actions;
        }

        if ($this->is_verified($order)) {
            return $actions;
        }

        $existing_attachment_id = (int) $order->get_meta(self::META_RECEIPT_ID);
        if ($existing_attachment_id > 0) {
            return $actions;
        }

        $upload_url = '';
        if (isset($actions['view']['url'])) {
            $upload_url = (string) $actions['view']['url'];
        } else {
            $upload_url = $order->get_view_order_url();
        }

        if ('' === $upload_url) {
            return $actions;
        }

        $upload_action = [
            'url' => $upload_url,
            'name' => __('Upload', 'wc-bacs-receipt-upload'),
        ];

        return ['upload' => $upload_action] + $actions;
    }

    public function handle_upload(): void
    {
        $order = $this->get_valid_order_from_request('wc_bacs_receipt_upload_');

        if ($this->is_verified($order)) {
            $this->redirect_with_status($order->get_id(), 'error', $this->is_admin_context());
        }

        if (! isset($_FILES['wc_bacs_receipt_file']) || empty($_FILES['wc_bacs_receipt_file']['name'])) {
            $this->redirect_with_status($order->get_id(), 'error', $this->is_admin_context());
        }

        $file_size = isset($_FILES['wc_bacs_receipt_file']['size']) ? (int) $_FILES['wc_bacs_receipt_file']['size'] : 0;
        if ($file_size <= 0 || $file_size > self::MAX_UPLOAD_BYTES) {
            $this->redirect_with_status($order->get_id(), 'error', $this->is_admin_context());
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        add_filter('upload_mimes', [$this, 'add_allowed_mimes']);
        $attachment_id = media_handle_upload('wc_bacs_receipt_file', 0);
        remove_filter('upload_mimes', [$this, 'add_allowed_mimes']);

        if (is_wp_error($attachment_id)) {
            $this->redirect_with_status($order->get_id(), 'error', $this->is_admin_context());
        }

        if (! $this->is_allowed_receipt_mime((int) $attachment_id)) {
            wp_delete_attachment((int) $attachment_id, true);
            $this->redirect_with_status($order->get_id(), 'error', $this->is_admin_context());
        }

        $old_attachment_id = (int) $order->get_meta(self::META_RECEIPT_ID);
        if ($old_attachment_id) {
            wp_delete_attachment($old_attachment_id, true);
        }

        $order->update_meta_data(self::META_RECEIPT_ID, (int) $attachment_id);
        $actor = current_user_can('edit_shop_orders') ? __('Admin', 'wc-bacs-receipt-upload') : __('Customer', 'wc-bacs-receipt-upload');
        $order->add_order_note(sprintf(__('Bank transfer receipt uploaded by %s.', 'wc-bacs-receipt-upload'), $actor));
        $order->save();

        $this->send_notification_email($order, (int) $attachment_id);

        $this->redirect_with_status($order->get_id(), 'success', $this->is_admin_context());
    }

    public function handle_delete(): void
    {
        $order = $this->get_valid_order_from_request('wc_bacs_receipt_delete_');

        if ($this->is_verified($order)) {
            $this->redirect_with_status($order->get_id(), 'error', $this->is_admin_context());
        }

        $attachment_id = (int) $order->get_meta(self::META_RECEIPT_ID);
        if ($attachment_id) {
            wp_delete_attachment($attachment_id, true);
            $order->delete_meta_data(self::META_RECEIPT_ID);
            $order->add_order_note(__('Bank transfer receipt deleted.', 'wc-bacs-receipt-upload'));
            $order->save();
        }

        $this->redirect_with_status($order->get_id(), 'success', $this->is_admin_context());
    }

    public function handle_verify(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Unauthorized', 'wc-bacs-receipt-upload'));
        }

        $order_id = isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id']) : 0;

        if (! $order_id || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), 'wc_bacs_receipt_verify_' . $order_id)) {
            wp_die(esc_html__('Invalid request', 'wc-bacs-receipt-upload'));
        }

        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order || 'bacs' !== $order->get_payment_method()) {
            wp_die(esc_html__('Invalid order', 'wc-bacs-receipt-upload'));
        }

        $order->update_meta_data(self::META_VERIFIED, 'yes');
        $order->add_order_note(__('Admin verified bank transfer payment. Upload locked.', 'wc-bacs-receipt-upload'));
        $order->save();

        wp_safe_redirect(wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit'));
        exit;
    }

    public function handle_unverify(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Unauthorized', 'wc-bacs-receipt-upload'));
        }

        $order_id = isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id']) : 0;

        if (! $order_id || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? '')), 'wc_bacs_receipt_unverify_' . $order_id)) {
            wp_die(esc_html__('Invalid request', 'wc-bacs-receipt-upload'));
        }

        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order || 'bacs' !== $order->get_payment_method()) {
            wp_die(esc_html__('Invalid order', 'wc-bacs-receipt-upload'));
        }

        $order->update_meta_data(self::META_VERIFIED, 'no');
        $order->add_order_note(__('Admin unverified bank transfer payment. Upload unlocked.', 'wc-bacs-receipt-upload'));
        $order->save();

        wp_safe_redirect(wp_get_referer() ?: admin_url('post.php?post=' . $order_id . '&action=edit'));
        exit;
    }

    public function add_allowed_mimes(array $mimes): array
    {
        $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
        $mimes['gif'] = 'image/gif';
        $mimes['png'] = 'image/png';
        $mimes['bmp'] = 'image/bmp';
        $mimes['webp'] = 'image/webp';
        $mimes['pdf'] = 'application/pdf';
        $mimes['doc'] = 'application/msword';
        $mimes['docx'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $mimes['xls'] = 'application/vnd.ms-excel';
        $mimes['xlsx'] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $mimes['ppt'] = 'application/vnd.ms-powerpoint';
        $mimes['pptx'] = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
        $mimes['txt'] = 'text/plain';
        $mimes['rtf'] = 'application/rtf';

        return $mimes;
    }

    private function is_allowed_receipt_mime(int $attachment_id): bool
    {
        $mime = get_post_mime_type($attachment_id);
        if (! is_string($mime) || '' === $mime) {
            return false;
        }

        return in_array($mime, self::ALLOWED_RECEIPT_MIMES, true);
    }

    private function get_valid_order_from_request(string $nonce_prefix): WC_Order
    {
        $order_id = isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id']) : 0;
        if (! $order_id) {
            wp_die(esc_html__('Invalid request', 'wc-bacs-receipt-upload'));
        }

        $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'] ?? ''));
        if (! wp_verify_nonce($nonce, $nonce_prefix . $order_id)) {
            wp_die(esc_html__('Invalid request', 'wc-bacs-receipt-upload'));
        }

        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order || 'bacs' !== $order->get_payment_method()) {
            wp_die(esc_html__('Invalid order', 'wc-bacs-receipt-upload'));
        }

        if (current_user_can('edit_shop_orders')) {
            return $order;
        }

        if (! $this->can_customer_access_order($order)) {
            wp_die(esc_html__('Unauthorized', 'wc-bacs-receipt-upload'));
        }

        return $order;
    }

    private function can_customer_access_order(WC_Order $order): bool
    {
        if (is_user_logged_in() && (int) $order->get_user_id() === (int) get_current_user_id()) {
            return true;
        }

        $request_order_key = sanitize_text_field(wp_unslash($_REQUEST['order_key'] ?? $_GET['key'] ?? ''));
        if ('' === $request_order_key) {
            return false;
        }

        return hash_equals($order->get_order_key(), $request_order_key);
    }

    private function send_notification_email(WC_Order $order, int $attachment_id): void
    {
        $recipients_string = (string) get_option(self::OPTION_RECIPIENTS, '');
        if ('' === $recipients_string) {
            return;
        }

        $recipients = array_filter(array_map('trim', explode(',', $recipients_string)));
        if (empty($recipients)) {
            return;
        }

        $subject = (string) get_option(self::OPTION_SUBJECT, __('New bank transfer receipt uploaded', 'wc-bacs-receipt-upload'));
        $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $body = sprintf(
            "A bank transfer receipt has been uploaded.\n\nOrder: #%s\nCustomer: %s\nOrder page: %s",
            $order->get_order_number(),
            $customer_name ?: __('N/A', 'wc-bacs-receipt-upload'),
            $order->get_view_order_url()
        );

        $attachment_path = get_attached_file($attachment_id);
        $attachments = $attachment_path ? [$attachment_path] : [];

        wp_mail($recipients, $subject, $body, [], $attachments);
    }

    private function is_admin_context(): bool
    {
        return isset($_REQUEST['is_admin_context']) && '1' === sanitize_text_field(wp_unslash($_REQUEST['is_admin_context']));
    }

    private function is_verified(WC_Order $order): bool
    {
        return 'yes' === (string) $order->get_meta(self::META_VERIFIED);
    }

    private function is_hpos_enabled(): bool
    {
        return class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    private function build_admin_action_url(string $action, int $order_id, string $nonce_action): string
    {
        $url = add_query_arg(
            [
                'action' => $action,
                'order_id' => $order_id,
            ],
            admin_url('admin-post.php')
        );

        return wp_nonce_url($url, $nonce_action);
    }

    private function redirect_with_status(int $order_id, string $status, bool $is_admin_context = false): void
    {
        if ($is_admin_context && current_user_can('edit_shop_orders')) {
            wp_safe_redirect(admin_url('post.php?post=' . $order_id . '&action=edit'));
            exit;
        }

        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order && ! is_user_logged_in()) {
            $url = $order->get_checkout_order_received_url();
        } else {
            $url = wc_get_endpoint_url('view-order', (string) $order_id, wc_get_page_permalink('myaccount'));
        }

        $url = add_query_arg('wc_receipt_status', $status, $url);

        wp_safe_redirect($url);
        exit;
    }
}

new WC_BACS_Receipt_Upload();
