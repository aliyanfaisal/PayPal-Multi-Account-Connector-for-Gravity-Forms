<?php

if (!defined('ABSPATH')) {
    exit;
}

class GFP_Core
{

    private $is_second_account_active = false;

    public function __construct()
    {
        add_action('init', array($this, 'init_hooks'));
    }

    private function log($message)
    {
        $log_file = plugin_dir_path(dirname(__FILE__)) . 'gfp_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $formatted_message = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($log_file, $formatted_message, FILE_APPEND);
    }

    public function init_hooks()
    {
        add_action('gform_pre_submission', array($this, 'check_submission_trigger'));

        add_action('init', array($this, 'check_ajax_trigger'), 5);

        add_action('gform_enqueue_scripts', array($this, 'enqueue_scripts'), 10, 2);

        add_action('wp_ajax_gfp_create_order', array($this, 'gfp_create_order'));
        add_action('wp_ajax_nopriv_gfp_create_order', array($this, 'gfp_create_order'));
        add_action('wp_ajax_gfp_capture_order', array($this, 'gfp_capture_order'));
        add_action('wp_ajax_nopriv_gfp_capture_order', array($this, 'gfp_capture_order'));
        add_action('wp_ajax_gfp_activate_webhook', array($this, 'gfp_activate_webhook'));

        add_filter('pre_option_gravityformsaddon_gravityformspaypalcheckout_settings', array($this, 'filter_paypal_settings'), 10, 2);

        add_action('gform_after_submission', array($this, 'save_entry_meta'), 10, 2);
        add_action('gform_entry_detail_content_before', array($this, 'display_entry_account_label'), 10, 2);

        add_action('parse_request', array($this, 'intercept_webhook'));

        add_filter('script_loader_tag', array($this, 'add_paypal_sdk_attributes'), 10, 3);

        add_filter('gform_gravityformsppcp_pre_process_feeds', array($this, 'bypass_ppcp_feeds'), 10, 3);
    }

    public function enqueue_scripts($form, $is_ajax)
    {
        $trigger_form_id = get_option('gfp_trigger_form_id');
        if ($form['id'] != $trigger_form_id) {
            return;
        }

        $mode = get_option('gfp_mode', 'sandbox');
        $client_id = ($mode === 'live') ? get_option('gfp_sec_client_id_live') : get_option('gfp_sec_client_id_sandbox');
        $client_id = trim($client_id);

        if (!$client_id) {
            return;
        }

        $currency = 'EUR';
        $this->log("Enqueue Scripts - Currency set to: " . $currency);

        $this->log("Enqueue Scripts - Final Currency used for SDK: " . $currency);

        $sdk_params = array(
            'client-id' => $client_id,
            'currency' => $currency,
            'intent' => 'capture',
            'disable-funding' => 'card'
        );
        $sdk_url = add_query_arg($sdk_params, 'https://www.paypal.com/sdk/js');

        wp_enqueue_script(
            'gfp_paypal_sdk_2',
            $sdk_url,
            array(),
            null,
            true
        );

        $script_path = plugin_dir_path(dirname(__FILE__)) . 'assets/js/gfp-frontend.js';
        $script_url = plugins_url('assets/js/gfp-frontend.js', dirname(__FILE__));
        $version = file_exists($script_path) ? filemtime($script_path) : '1.0.0';

        wp_enqueue_script(
            'gfp_frontend_js',
            $script_url,
            array('jquery', 'gfp_paypal_sdk_2'),
            $version,
            true
        );

        wp_localize_script('gfp_frontend_js', 'gfp_frontend_settings', array(
            'trigger_form_id' => $trigger_form_id,
            'trigger_field_id' => get_option('gfp_trigger_field_id'),
            'trigger_value' => get_option('gfp_trigger_value'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gfp_nonce'),
            'is_admin' => current_user_can('manage_options'),
            'sdk_url' => $sdk_url
        ));
    }

    public function add_paypal_sdk_attributes($tag, $handle, $src)
    {
        if ($handle !== 'gfp_paypal_sdk_2') {
            return $tag;
        }

        return str_replace(' src', ' data-namespace="paypalAccount2" src', $tag);
    }

    public function check_submission_trigger($form)
    {
        $this->evaluate_trigger_logic($_POST, $form['id']);
    }

    public function check_ajax_trigger()
    {
        if (!defined('DOING_AJAX') || !DOING_AJAX) {
            return;
        }

        if (isset($_POST['action']) && strpos($_POST['action'], 'gravityforms') !== false) {
            $data_source = $_POST;

            if (isset($_POST['data']) && is_string($_POST['data'])) {
                parse_str($_POST['data'], $parsed_data);
                $data_source = array_merge($data_source, $parsed_data);
            }

            $this->evaluate_trigger_logic($data_source, isset($data_source['form_id']) ? $data_source['form_id'] : null);
        }
    }

    private function evaluate_trigger_logic($data, $context_form_id = null)
    {
        $form = $context_form_id ? GFAPI::get_form($context_form_id) : false;

        $trigger_form_id = get_option('gfp_trigger_form_id');
        $trigger_field_id = get_option('gfp_trigger_field_id');
        $trigger_value = get_option('gfp_trigger_value');

        if (!$trigger_form_id || !$form || ($form['id'] != $trigger_form_id)) {
            return;
        }

        $input_name = 'input_' . $trigger_field_id;

        if (isset($data[$input_name])) {
            $submitted_value = $data[$input_name];

            if (strcasecmp(trim($submitted_value), trim($trigger_value)) === 0) {
                $this->is_second_account_active = true;
            }
        }
    }

    public function filter_paypal_settings($value, $option)
    {

        if (!$this->is_second_account_active) {
            return $value;
        }

        remove_filter('pre_option_gravityformsaddon_gravityformspaypalcheckout_settings', array($this, 'filter_paypal_settings'));
        $original_settings = get_option($option);
        add_filter('pre_option_gravityformsaddon_gravityformspaypalcheckout_settings', array($this, 'filter_paypal_settings'), 10, 2);

        if (!is_array($original_settings)) {
            $original_settings = array();
        }

        $mode = get_option('gfp_mode', 'sandbox');

        if ($mode === 'live') {
            $original_settings['environment'] = 'live';
            $original_settings['live_client_id'] = get_option('gfp_sec_client_id_live');
            $original_settings['live_client_secret'] = get_option('gfp_sec_secret_live');
        } else {
            $original_settings['environment'] = 'sandbox';
            $original_settings['sandbox_client_id'] = get_option('gfp_sec_client_id_sandbox');
            $original_settings['sandbox_client_secret'] = get_option('gfp_sec_secret_sandbox');
        }

        return $original_settings;
    }

    public function save_entry_meta($entry, $form)
    {
        $this->log("save_entry_meta called for Entry ID: " . $entry['id']);
        $this->log("Is second account active? " . ($this->is_second_account_active ? 'Yes' : 'No'));

        if ($this->is_second_account_active) {
            gform_update_meta($entry['id'], '_gf_paypal_account_used', 'secondary');

            // Check specific POST variable
            $transaction_id = isset($_POST['gfp_pp_transaction_id']) ? sanitize_text_field($_POST['gfp_pp_transaction_id']) : '';

            $this->log("Transaction ID from POST: " . $transaction_id);

            if ($transaction_id) {
                gform_update_meta($entry['id'], '_transaction_id', $transaction_id);

                // Update Entry Properties
                $order_total = GFCommon::get_order_total($form, $entry);
                $this->log("Order Total: " . $order_total);
                $entry['payment_status'] = 'Pending';
                $entry['payment_date'] = gmdate('Y-m-d H:i:s');
                $entry['transaction_id'] = $transaction_id;
                $entry['payment_amount'] = $order_total;
                $entry['payment_method'] = 'PayPal (Secondary)';
                $entry['is_fulfilled'] = '1';

                $result = GFAPI::update_entry($entry);
                $this->log("Entry Update Result: " . (is_wp_error($result) ? $result->get_error_message() : 'Success'));
                GFAPI::add_note($entry['id'], 0, 'PayPal (Secondary)', 'Payment completed via Secondary Account. Transaction ID: ' . $transaction_id);
            } else {
                $this->log("WARNING: No Transaction ID found in POST data during submission.");
            }
        } else {
            gform_update_meta($entry['id'], '_gf_paypal_account_used', 'primary');
        }
    }

    public function display_entry_account_label($form, $entry)
    {
        $account_used = gform_get_meta($entry['id'], '_gf_paypal_account_used');

        $color = '#f8f9fa';
        $text = 'Primary (Default)';
        $border = '#ddd';

        if ($account_used === 'secondary') {
            $color = '#d4edda';
            $text = 'Secondary Account';
            $border = '#c3e6cb';
        }

        echo '<div style="background: ' . esc_attr($color) . '; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid ' . esc_attr($border) . ';">';
        echo '<strong style="display:block; margin-bottom: 5px;">PayPal Account</strong>';
        echo '<span style="font-size: 1.1em;">' . esc_html($text) . '</span>';
        echo '</div>';
    }

    public function intercept_webhook()
    {
        $is_target_url = (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'paypal_account_2') !== false);
        $is_target_get = (isset($_GET['gfp_webhook']) && strpos($_GET['gfp_webhook'], 'paypal_account_2') !== false);

        if ($is_target_url || $is_target_get) {

            $this->log("Webhook Endpoint Detection Triggered.");
            $this->log("URI: " . $_SERVER['REQUEST_URI']);
            $this->log("Method: " . $_SERVER['REQUEST_METHOD']);
            if (!isset($_GET['gfp_webhook'])) {
                $_GET['gfp_webhook'] = 'paypal_account_2';
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input = file_get_contents('php://input');
                $this->log("Webhook Received. Payload: " . $input);

                $data = json_decode($input, true);

                if (!$data) {
                    $this->log("Webhook Error: Could not decode JSON.");
                    status_header(200);
                    exit();
                }

                $event_type = isset($data['event_type']) ? $data['event_type'] : '';
                $resource = isset($data['resource']) ? $data['resource'] : [];
                $transaction_id = isset($resource['id']) ? $resource['id'] : '';

                $this->log("Webhook Event: {$event_type}, Transaction ID: {$transaction_id}");

                if ($event_type === 'PAYMENT.CAPTURE.COMPLETED' && $transaction_id) {
                    $search_criteria = array(
                        'field_filters' => array(
                            array(
                                'key' => '_transaction_id',
                                'value' => $transaction_id,
                            )
                        )
                    );

                    $entries = GFAPI::get_entries(0, $search_criteria);
                    $this->log("Found Entries: " . count($entries));

                    if (!empty($entries) && !is_wp_error($entries)) {
                        $entry = $entries[0];
                        $this->log("Updating Entry ID: " . $entry['id']);

                        GFAPI::add_note($entry['id'], 0, 'PayPal Webhook', 'Webhook received: ' . $event_type . ' for Transaction ID: ' . $transaction_id);
                        gform_update_meta($entry['id'], '_gfp_webhook_verified', 'true');
                        if ($entry['payment_status'] !== 'Paid') {
                            $entry['payment_status'] = 'Paid';
                            GFAPI::update_entry($entry);
                            $this->log("Entry status forcibly updated to Paid via Webhook.");
                        }
                    } else {
                        $this->log("No matching entry found for transaction ID.");
                    }
                }

                status_header(200);
                exit();
            }
        }
    }

    public function gfp_create_order()
    {
        check_ajax_referer('gfp_nonce', 'nonce');

        $form_data = array();
        parse_str($_POST['form_data'], $form_data);

        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;

        if (!$form_id) {
            $form_id = (int) get_option('gfp_trigger_form_id');
        }
        $original_post = $_POST;
        $_POST = $form_data;

        $_POST['is_submit_' . $form_id] = 1;
        $form = GFAPI::get_form($form_id);

        if (!$form) {
            $_POST = $original_post;
            wp_send_json_error(array('message' => 'Invalid Form ID'));
        }
        $lead = GFFormsModel::create_lead($form);

        $amount = GFCommon::get_order_total($form, $lead);

        $_POST = $original_post;
        $amount = (float) $amount;

        if ($amount <= 0) {
            wp_send_json_error(array('message' => 'Order total is zero.'));
        }


        $mode = get_option('gfp_mode', 'sandbox');
        $client_id = ($mode === 'live') ? get_option('gfp_sec_client_id_live') : get_option('gfp_sec_client_id_sandbox');
        $secret = ($mode === 'live') ? get_option('gfp_sec_secret_live') : get_option('gfp_sec_secret_sandbox');
        $base_url = ($mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        $response = wp_remote_post($base_url . '/v1/oauth2/token', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret),
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => 'grant_type=client_credentials'
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['access_token'])) {
            wp_send_json_error(array('message' => 'Could not authenticate with PayPal Account 2'));
        }
        $access_token = $body['access_token'];

        $currency_code = rgar($lead, 'currency', 'USD');
        $item_total = 0;
        $shipping = 0;
        $items = array();

        $products = GFCommon::get_product_fields($form, $lead);

        if (!empty($products['products'])) {
            foreach ($products['products'] as $product) {
                $unit_price = GFCommon::to_number($product['price']);
                $quantity = $product['quantity'];

                if ($empty_price = (empty($unit_price) && $unit_price !== '0' && $unit_price !== 0)) {
                    continue;
                }

                $item_total += $unit_price * $quantity;

                $items[] = array(
                    'name' => substr($product['name'], 0, 127),
                    'unit_amount' => array(
                        'currency_code' => $currency_code,
                        'value' => (string) $unit_price
                    ),
                    'quantity' => (string) $quantity,
                    'category' => 'DIGITAL_GOODS'
                );
            }
        }

        if (!empty($products['shipping'])) {
            $shipping = $products['shipping']['price'];
        }

        // Handle negative amounts (discounts are usually handled as line item adjustments or negative total in GF,
        // but PayPal V2 prefers positive item values and a separate discount field isn't distinct in simple purchase_units)
        // For simplicity and robustness like core, we ensure total matches and we pass items if they sum up correctly.

        $calculated_total = $item_total + $shipping;

        $purchase_unit = array(
            'amount' => array(
                'currency_code' => $currency_code,
                'value' => number_format($amount, 2, '.', ''),
                'breakdown' => array(
                    'item_total' => array(
                        'currency_code' => $currency_code,
                        'value' => number_format($item_total, 2, '.', '')
                    ),
                    'shipping' => array(
                        'currency_code' => $currency_code,
                        'value' => number_format($shipping, 2, '.', '')
                    )
                )
            ),
            'items' => $items
        );

        if (abs(($item_total + $shipping) - $amount) > 0.01) {
            $purchase_unit = array(
                'amount' => array(
                    'currency_code' => $currency_code,
                    'value' => number_format($amount, 2, '.', '')
                )
            );
        }

        $order_response = wp_remote_post($base_url . '/v2/checkout/orders', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'intent' => 'CAPTURE',
                'purchase_units' => array($purchase_unit)
            ))
        ));

        if (is_wp_error($order_response)) {
            wp_send_json_error(array('message' => $order_response->get_error_message()));
        }

        $order_body = json_decode(wp_remote_retrieve_body($order_response), true);

        if (isset($order_body['id'])) {
            wp_send_json_success(array('orderID' => $order_body['id']));
        } else {
            wp_send_json_error(array('message' => 'Could not create PayPal Order'));
        }
    }

    public function gfp_capture_order()
    {
        check_ajax_referer('gfp_nonce', 'nonce');

        $order_id = $_POST['order_id'];
        if (!$order_id) {
            wp_send_json_error(array('message' => 'Missing Order ID'));
        }

        $mode = get_option('gfp_mode', 'sandbox');
        $client_id = ($mode === 'live') ? get_option('gfp_sec_client_id_live') : get_option('gfp_sec_client_id_sandbox');
        $secret = ($mode === 'live') ? get_option('gfp_sec_secret_live') : get_option('gfp_sec_secret_sandbox');
        $base_url = ($mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        $response = wp_remote_post($base_url . '/v1/oauth2/token', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret),
                'Content-Type' => 'application/x-www-form-urlencoded'
            ),
            'body' => 'grant_type=client_credentials'
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Auth Error'));
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $access_token = $body['access_token'];

        $capture_response = wp_remote_post($base_url . '/v2/checkout/orders/' . $order_id . '/capture', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ),
            'body' => '{}'
        ));

        if (is_wp_error($capture_response)) {
            wp_send_json_error(array('message' => $capture_response->get_error_message()));
        }

        $capture_body = json_decode(wp_remote_retrieve_body($capture_response), true);

        if (isset($capture_body['status']) && $capture_body['status'] === 'COMPLETED') {
            $transaction_id = $capture_body['purchase_units'][0]['payments']['captures'][0]['id'];
            wp_send_json_success(array(
                'transaction_id' => $transaction_id,
                'status' => 'COMPLETED'
            ));
        } else {
            wp_send_json_error(array('message' => 'Payment not completed or failed.'));
        }
    }


    public function gfp_activate_webhook()
    {
        check_ajax_referer('gfp_nonce', 'nonce');

        $mode = get_option('gfp_mode', 'sandbox');
        $client_id = ($mode === 'live') ? get_option('gfp_sec_client_id_live') : get_option('gfp_sec_client_id_sandbox');
        $secret = ($mode === 'live') ? get_option('gfp_sec_secret_live') : get_option('gfp_sec_secret_sandbox');

        if (!$client_id || !$secret) {
            wp_send_json_error(array('message' => 'Missing API Credentials.'));
        }

        $access_token = self::get_access_token($client_id, $secret, $mode);

        if (is_wp_error($access_token)) {
            wp_send_json_error(array('message' => $access_token->get_error_message()));
        }

        $webhook_url = home_url('/', 'https') . '?gfp_webhook=paypal_account_2';

        $event_types = array(
            array('name' => 'PAYMENT.CAPTURE.COMPLETED'),
            array('name' => 'PAYMENT.AUTHORIZATION.VOIDED'),
            array('name' => 'PAYMENT.CAPTURE.REFUNDED')
        );

        $api_url = ($mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        $existing_webhooks = $this->get_webhooks($access_token, $mode);

        if (!is_wp_error($existing_webhooks) && !empty($existing_webhooks['webhooks'])) {
            foreach ($existing_webhooks['webhooks'] as $hook) {
                if ($hook['url'] === $webhook_url) {
                    $patch_response = wp_remote_request($api_url . '/v1/notifications/webhooks/' . $hook['id'], array(
                        'method' => 'PATCH',
                        'headers' => array(
                            'Authorization' => 'Bearer ' . $access_token,
                            'Content-Type' => 'application/json',
                        ),
                        'body' => json_encode(array(
                            array(
                                'op' => 'replace',
                                'path' => '/event_types',
                                'value' => $event_types
                            )
                        ))
                    ));

                    if (is_wp_error($patch_response)) {
                        update_option('gfp_webhook_id_' . $mode, $hook['id']);
                        wp_send_json_success(array('message' => 'Webhook ID saved, but could not update events. Error: ' . $patch_response->get_error_message(), 'id' => $hook['id'], 'url' => $webhook_url));
                    }

                    update_option('gfp_webhook_id_' . $mode, $hook['id']);
                    wp_send_json_success(array('message' => 'Webhook found and updated with latest events (Refunds included).', 'id' => $hook['id'], 'url' => $webhook_url));
                }
            }
        }

        $response = wp_remote_post($api_url . '/v1/notifications/webhooks', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'url' => $webhook_url,
                'event_types' => $event_types
            ))
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['id'])) {
            wp_send_json_error(array('message' => 'Failed to create webhook. Response: ' . $body));
        }

        update_option('gfp_webhook_id_' . $mode, $data['id']);
        wp_send_json_success(array('message' => 'Webhook created successfully.', 'id' => $data['id'], 'url' => $webhook_url));
    }

    private function get_webhooks($access_token, $mode)
    {
        $api_url = ($mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $response = wp_remote_get($api_url . '/v1/notifications/webhooks', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            )
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public static function get_access_token($client_id, $secret, $mode)
    {
        $api_url = ($mode === 'live') ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $response = wp_remote_post($api_url . '/v1/oauth2/token', array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => 'grant_type=client_credentials'
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['access_token'])) {
            return new WP_Error('auth_failed', 'Failed to retrieve access token.');
        }

        return $data['access_token'];
    }
    public function bypass_ppcp_feeds($feeds, $entry, $form)
    {
        $this->evaluate_trigger_logic($_POST, $form['id']);

        if ($this->is_second_account_active) {
            return array();
        }

        return $feeds;
    }
}
