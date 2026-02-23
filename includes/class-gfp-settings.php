<?php

if (!defined('ABSPATH')) {
    exit;
}

class GFP_Settings
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_settings_page()
    {
        add_menu_page(
            'PayPal Multi-Account',
            'PayPal Multi-Account',
            'manage_options',
            'gf-paypal-multi-account',
            array($this, 'render_settings_page'),
            'dashicons-networking'
        );
    }

    public function register_settings()
    {
        // print_r( $_POST );
        register_setting('gfp_multi_account_group', 'gfp_sec_client_id_live');
        register_setting('gfp_multi_account_group', 'gfp_sec_secret_live', array('sanitize_callback' => array($this, 'validate_live_secret')));
        register_setting('gfp_multi_account_group', 'gfp_sec_client_id_sandbox');
        register_setting('gfp_multi_account_group', 'gfp_sec_secret_sandbox', array('sanitize_callback' => array($this, 'validate_sandbox_secret')));
        register_setting('gfp_multi_account_group', 'gfp_mode');

        register_setting('gfp_multi_account_group', 'gfp_trigger_form_id');
        register_setting('gfp_multi_account_group', 'gfp_trigger_field_id');
        register_setting('gfp_multi_account_group', 'gfp_trigger_value');
    }

    public function validate_live_secret($secret)
    {
        // Skip validation if secret is empty
        if (empty($secret)) {
            return $secret;
        }

        $client_id = isset($_POST['gfp_sec_client_id_live']) ? sanitize_text_field($_POST['gfp_sec_client_id_live']) : get_option('gfp_sec_client_id_live');
        
        // If we don't have a Client ID to pair with, we can't validate.
        if (empty($client_id)) {
            return $secret;
        }

        $token = GFP_Core::get_access_token($client_id, $secret, 'live');

        if (is_wp_error($token)) {
            add_settings_error(
                'gfp_sec_secret_live',
                'invalid_live_creds',
                'Error validating Live credentials: ' . $token->get_error_message(),
                'error'
            );
             // We return the secret anyway so the user doesn't lose it, but the error will show.
             // Alternatively, you could return the old value if you want to be strict.
        } else {
             add_settings_error(
                'gfp_sec_secret_live',
                'valid_live_creds',
                'Live credentials validated successfully.',
                'success'
            );
        }

        return $secret;
    }

    public function validate_sandbox_secret($secret)
    {
        // Skip validation if secret is empty
        if (empty($secret)) {
            return $secret;
        }

        $client_id = isset($_POST['gfp_sec_client_id_sandbox']) ? sanitize_text_field($_POST['gfp_sec_client_id_sandbox']) : get_option('gfp_sec_client_id_sandbox');
        
        if (empty($client_id)) {
            return $secret;
        }

        $token = GFP_Core::get_access_token($client_id, $secret, 'sandbox');

        if (is_wp_error($token)) {
            add_settings_error(
                'gfp_sec_secret_sandbox',
                'invalid_sandbox_creds',
                'Error validating Sandbox credentials: ' . $token->get_error_message(),
                'error'
            );
        } else {
             add_settings_error(
                'gfp_sec_secret_sandbox',
                'valid_sandbox_creds',
                'Sandbox credentials validated successfully.',
                'success'
            );
        }

        return $secret;
    }

    public function render_settings_page()
    {
        if (!GFCommon::current_user_can_any('gravityforms_edit_settings')) {
            wp_die('You do not have permission to access this page');
        }

        $saved_mode = get_option('gfp_mode', 'sandbox');
        
        // Prepare Data for Sandbox
        $sb_client = get_option('gfp_sec_client_id_sandbox');
        $sb_secret = get_option('gfp_sec_secret_sandbox');
        $sb_connected = !empty($sb_client) && !empty($sb_secret);
        $sb_webhook = get_option('gfp_webhook_id_sandbox');

        // Prepare Data for Live
        $live_client = get_option('gfp_sec_client_id_live');
        $live_secret = get_option('gfp_sec_secret_live');
        $live_connected = !empty($live_client) && !empty($live_secret);
        $live_webhook = get_option('gfp_webhook_id_live');

        ?>
                <div class="wrap gforms_edit_form gforms_settings_page">
                    <h1 class="wp-heading-inline">PayPal Multi-Account Settings</h1>

                    <form method="post" action="options.php">
                        <?php settings_fields('gfp_multi_account_group'); ?>

                        <!-- Account Status Panel -->
                        <div class="gform-settings-panel">
                            <header class="gform-settings-panel__header">
                                <h4 class="gform-settings-panel__title">PayPal Account</h4>
                            </header>
                            <div class="gform-settings-panel__content">
                                <p>
                                    PayPal Checkout is an all-in-one global solution. This integration handles your <strong>Secondary Account</strong>.
                                </p>
                        
                                <!-- Environment -->
                                <div class="gform-settings-field">
                                    <div class="gform-settings-field__header">
                                        <label class="gform-settings-label">Environment</label>
                                    </div>
                                    <div class="gform-settings-input__container">
                                        <label class="gform-settings-choice">
                                            <input type="radio" name="gfp_mode" value="sandbox" <?php checked($saved_mode, 'sandbox'); ?>>
                                            <span>Sandbox</span>
                                        </label>
                                        <label class="gform-settings-choice">
                                            <input type="radio" name="gfp_mode" value="live" <?php checked($saved_mode, 'live'); ?>>
                                            <span>Live</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- SANDBOX VIEW -->
                                <div id="gfp-view-sandbox" style="<?php echo $saved_mode === 'sandbox' ? '' : 'display:none;'; ?>">
                                    <!-- Connection Status -->
                                    <?php if ($sb_connected): ?>
                                            <div class="gfp-status-card gfp-connected">
                                                <div class="gfp-status-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                                                <div class="gfp-status-details">
                                                    <strong>Connected to PayPal (Sandbox)</strong>
                                                    <p>Account ID: <?php echo esc_html(substr($sb_client, 0, 10) . '...'); ?></p>
                                                </div>
                                                <button type="button" class="button gfp-toggle-creds-sb">Edit Credentials</button>
                                            </div>
                                    <?php else: ?>
                                            <div class="gfp-status-card gfp-disconnected">
                                                <div class="gfp-status-icon"><span class="dashicons dashicons-warning"></span></div>
                                                <div class="gfp-status-details">
                                                    <strong>Not Connected (Sandbox)</strong>
                                                    <p>Please enter your Sandbox Client ID and Secret.</p>
                                                </div>
                                            </div>
                                    <?php endif; ?>

                                    <!-- Credentials Input -->
                                    <div id="gfp-creds-wrapper-sb" style="<?php echo $sb_connected ? 'display:none;' : ''; ?> margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #eee;">
                                        <div class="gform-settings-field">
                                            <label class="gform-settings-label">Sandbox Client ID</label>
                                            <input type="text" name="gfp_sec_client_id_sandbox" value="<?php echo esc_attr($sb_client); ?>" class="regular-text" style="width:100%"/>
                                        </div>
                                        <div class="gform-settings-field">
                                            <label class="gform-settings-label">Sandbox Secret</label>
                                            <input type="password" name="gfp_sec_secret_sandbox" value="<?php echo esc_attr($sb_secret); ?>" class="regular-text" style="width:100%"/>
                                        </div>
                                    </div>

                                    <!-- Webhook Status -->
                                    <div class="gform-settings-field" style="margin-top: 20px;">
                                        <label class="gform-settings-label">Webhook (Sandbox)</label>
                                        <?php if (!empty($sb_webhook)): ?>
                                                <div class="gfp-webhook-status gfp-active" style="border: 1px solid #46b450; padding: 10px; background: #fff; color: #46b450; display: flex; align-items: center; justify-content: space-between;">
                                                    <div style="display: flex; align-items: center;">
                                                        <span class="dashicons dashicons-yes-alt" style="margin-right: 10px; font-size: 24px;"></span>
                                                        <div>
                                                            <strong>Webhook is active</strong>
                                                            <div style="font-size: 12px; color: #666; margin-top: 2px;">ID: <?php echo esc_html($sb_webhook); ?></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <button type="button" class="button button-small gfp_activate_webhook_btn">Reconnect / Update</button>
                                                        <span class="spinner gfp_webhook_spinner" style="float:none;"></span>
                                                    </div>
                                                </div>
                                        <?php else: ?>
                                                <div id="gfp-webhook-container-sb">
                                                    <div class="gfp-webhook-status" style="border: 1px solid #ddd; padding: 15px; background: #fff;">
                                                        <p>Webhooks allow PayPal to communicate with your site.</p>
                                                        <?php if ($sb_connected): ?>
                                                                <button type="button" class="button button-secondary gfp_activate_webhook_btn">Automatically Setup Webhook</button>
                                                                <span class="spinner gfp_webhook_spinner" style="float:none;"></span>
                                                        <?php else: ?>
                                                                <p style="color: #d63638;">Please save your API credentials above to enable setup.</p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                        <?php endif; ?>
                                        <p class="description" style="margin-top: 10px;">
                                            <a href="#" onclick="jQuery('#gfp-manual-webhook-sb').toggle(); return false;">Manually set the webhook ID</a>
                                        </p>
                                        <div id="gfp-manual-webhook-sb" style="display:none;">
                                            <input type="text" readonly value="<?php echo esc_url(home_url('/?gfp_webhook=paypal_account_2')); ?>" class="regular-text" style="background: #f1f1f1; width: 100%; margin-bottom: 5px;" />
                                            <span class="description">Copy this URL to your PayPal Developer Dashboard.</span>
                                        </div>
                                    </div>
                                </div> 
                                <!-- End Sandbox View -->

                                <!-- LIVE VIEW -->
                                <div id="gfp-view-live" style="<?php echo $saved_mode === 'live' ? '' : 'display:none;'; ?>">
                                    <!-- Connection Status -->
                                    <?php if ($live_connected): ?>
                                            <div class="gfp-status-card gfp-connected">
                                                <div class="gfp-status-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                                                <div class="gfp-status-details">
                                                    <strong>Connected to PayPal (Live)</strong>
                                                    <p>Account ID: <?php echo esc_html(substr($live_client, 0, 10) . '...'); ?></p>
                                                </div>
                                                <button type="button" class="button gfp-toggle-creds-live">Edit Credentials</button>
                                            </div>
                                    <?php else: ?>
                                            <div class="gfp-status-card gfp-disconnected">
                                                <div class="gfp-status-icon"><span class="dashicons dashicons-warning"></span></div>
                                                <div class="gfp-status-details">
                                                    <strong>Not Connected (Live)</strong>
                                                    <p>Please enter your Live Client ID and Secret.</p>
                                                </div>
                                            </div>
                                    <?php endif; ?>

                                    <!-- Credentials Input -->
                                    <div id="gfp-creds-wrapper-live" style="<?php echo $live_connected ? 'display:none;' : ''; ?> margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #eee;">
                                        <div class="gform-settings-field">
                                            <label class="gform-settings-label">Live Client ID</label>
                                            <input type="text" name="gfp_sec_client_id_live" value="<?php echo esc_attr($live_client); ?>" class="regular-text" style="width:100%"/>
                                        </div>
                                        <div class="gform-settings-field">
                                            <label class="gform-settings-label">Live Secret</label>
                                            <input type="password" name="gfp_sec_secret_live" value="<?php echo esc_attr($live_secret); ?>" class="regular-text" style="width:100%"/>
                                        </div>
                                    </div>

                                    <!-- Webhook Status -->
                                    <div class="gform-settings-field" style="margin-top: 20px;">
                                        <label class="gform-settings-label">Webhook (Live)</label>
                                        <?php if (!empty($live_webhook)): ?>
                                                <div class="gfp-webhook-status gfp-active" style="border: 1px solid #46b450; padding: 10px; background: #fff; color: #46b450; display: flex; align-items: center; justify-content: space-between;">
                                                    <div style="display: flex; align-items: center;">
                                                        <span class="dashicons dashicons-yes-alt" style="margin-right: 10px; font-size: 24px;"></span>
                                                        <div>
                                                            <strong>Webhook is active</strong>
                                                            <div style="font-size: 12px; color: #666; margin-top: 2px;">ID: <?php echo esc_html($live_webhook); ?></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <button type="button" class="button button-small gfp_activate_webhook_btn">Reconnect / Update</button>
                                                        <span class="spinner gfp_webhook_spinner" style="float:none;"></span>
                                                    </div>
                                                </div>
                                        <?php else: ?>
                                                <div id="gfp-webhook-container-live">
                                                    <div class="gfp-webhook-status" style="border: 1px solid #ddd; padding: 15px; background: #fff;">
                                                        <p>Webhooks allow PayPal to communicate with your site.</p>
                                                        <?php if ($live_connected): ?>
                                                                <button type="button" class="button button-secondary gfp_activate_webhook_btn">Automatically Setup Webhook</button>
                                                                <span class="spinner gfp_webhook_spinner" style="float:none;"></span>
                                                        <?php else: ?>
                                                                <p style="color: #d63638;">Please save your API credentials above to enable setup.</p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                        <?php endif; ?>
                                        <p class="description" style="margin-top: 10px;">
                                            <a href="#" onclick="jQuery('#gfp-manual-webhook-live').toggle(); return false;">Manually set the webhook ID</a>
                                        </p>
                                        <div id="gfp-manual-webhook-live" style="display:none;">
                                            <input type="text" readonly value="<?php echo esc_url(home_url('/?gfp_webhook=paypal_account_2')); ?>" class="regular-text" style="background: #f1f1f1; width: 100%; margin-bottom: 5px;" />
                                            <span class="description">Copy this URL to your PayPal Developer Dashboard.</span>
                                        </div>
                                    </div>
                                </div>
                                <!-- End Live View -->

                            </div>
                        </div>

                        <!-- Trigger Logic Panel -->
                        <div class="gform-settings-panel">
                            <header class="gform-settings-panel__header">
                                <h4 class="gform-settings-panel__title">Trigger Logic</h4>
                            </header>
                            <div class="gform-settings-panel__content">
                                <div class="gform-settings-field">
                                    <div class="gform-settings-field__header">
                                        <label class="gform-settings-label">Target Form ID</label>
                                    </div>
                                    <div class="gform-settings-input__container">
                                        <input type="number" name="gfp_trigger_form_id" value="<?php echo esc_attr(get_option('gfp_trigger_form_id')); ?>" class="small-text" />
                                    </div>
                                </div>
                                <div class="gform-settings-field">
                                    <div class="gform-settings-field__header">
                                        <label class="gform-settings-label">Trigger Field ID</label>
                                    </div>
                                    <div class="gform-settings-input__container">
                                        <input type="number" name="gfp_trigger_field_id" value="<?php echo esc_attr(get_option('gfp_trigger_field_id')); ?>" class="small-text" />
                                    </div>
                                </div>
                                <div class="gform-settings-field">
                                    <div class="gform-settings-field__header">
                                        <label class="gform-settings-label">Trigger Value</label>
                                    </div>
                                    <div class="gform-settings-input__container">
                                        <input type="text" name="gfp_trigger_value" value="<?php echo esc_attr(get_option('gfp_trigger_value')); ?>" class="regular-text" />
                                        <p class="description">Enter the value that should switch the payment to Account 2.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php submit_button(); ?>
                    </form>
            
                    <script>
                    jQuery(document).ready(function($){
                        // Toggle mode
                        $('input[name="gfp_mode"]').change(function(){
                            if($(this).val() == 'live'){
                                $('#gfp-view-sandbox').hide();
                                $('#gfp-view-live').show();
                            } else {
                                $('#gfp-view-live').hide();
                                $('#gfp-view-sandbox').show();
                            }
                        });

                        // Toggle Edit Creds (Sandbox)
                        $('.gfp-toggle-creds-sb').click(function(e){ e.preventDefault(); $('#gfp-creds-wrapper-sb').slideToggle(); });
                        // Toggle Edit Creds (Live)
                        $('.gfp-toggle-creds-live').click(function(e){ e.preventDefault(); $('#gfp-creds-wrapper-live').slideToggle(); });

                        // Activate Webhook AJAX
                        $('.gfp_activate_webhook_btn').click(function(e){
                            e.preventDefault();
                            
                            // Determine environment from visible container
                            var env = $('#gfp-view-live').is(':visible') ? 'live' : 'sandbox';
                            var spinner_sel = env === 'live' ? '#gfp-webhook-container-live .gfp_webhook_spinner' : '#gfp-webhook-container-sb .gfp_webhook_spinner';
                            var container_sel = env === 'live' ? '#gfp-webhook-container-live' : '#gfp-webhook-container-sb';

                            var $btn = $(this);
                            $btn.prop('disabled', true);
                            $(spinner_sel).addClass('is-active');

                            $.post(ajaxurl, {
                                action: 'gfp_activate_webhook',
                                nonce: '<?php echo wp_create_nonce("gfp_nonce"); ?>'
                            }, function(response) {
                                $(spinner_sel).removeClass('is-active');
                                $btn.prop('disabled', false);

                                if(response.success) {
                                    $(container_sel).html(
                                        '<div class="gfp-webhook-status gfp-active" style="border: 1px solid #46b450; padding: 10px; background: #fff; color: #46b450; display: flex; align-items: center; justify-content: space-between;">' +
                                        '<div style="display: flex; align-items: center;">' +
                                        '<span class="dashicons dashicons-yes-alt" style="margin-right: 10px; font-size: 24px;"></span>' +
                                        '<div><strong>Webhook is active</strong><div style="font-size: 12px; color: #666; margin-top: 2px;">ID: ' + response.data.id + '</div></div></div>' +
                                        '<div><button type="button" class="button button-small gfp_activate_webhook_btn">Reconnect / Update</button><span class="spinner gfp_webhook_spinner" style="float:none;"></span></div></div>'
                                    );
                                    
                                    // Robust confirmation with URL
                                    var msg = response.data.message;
                                    if(response.data.url) {
                                        msg += '\n\nVerified URL: ' + response.data.url;
                                    }
                                    alert(msg);
                                } else {
                                    alert('Error: ' + response.data.message);
                                }
                            });
                        });
                    });
                    </script>

                    <style>
                        .gform-settings-panel { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-top: 20px; }
                        .gform-settings-panel__header { padding: 15px 20px; border-bottom: 1px solid #ccd0d4; background: #f8f9fa; }
                        .gform-settings-panel__title { margin: 0; font-size: 14px; font-weight: 600; text-transform: uppercase; color: #23282d; }
                        .gform-settings-panel__content { padding: 20px; }
                        .gform-settings-field { margin-bottom: 20px; }
                        .gform-settings-label { font-weight: 600; display: block; margin-bottom: 5px; }
                        .gform-settings-choice { display: inline-block; margin-right: 15px; }
                
                        .gfp-status-card { border: 1px solid #ccd0d4; padding: 15px; background: #fff; display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; }
                        .gfp-status-icon { margin-right: 15px; font-size: 24px; }
                        .gfp-status-details { flex-grow: 1; }
                        .gfp-status-details strong { display: block; font-size: 14px; }
                        .gfp-status-details p { margin: 0; color: #666; }
                        .gfp-connected .gfp-status-icon { color: #46b450; }
                        .gfp-disconnected .gfp-status-icon { color: #dc3232; }
                    </style>
                </div>
                <?php
    }
}
