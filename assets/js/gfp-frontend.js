jQuery(document).ready(function ($) {
    // Inject Force-Show CSS
    $('head').append('<style>#gfp_secondary_paypal_container.gfp-active { display: block !important; }</style>');

    var gfpSettings = window.gfp_frontend_settings;

    if (!gfpSettings || !gfpSettings.trigger_form_id || !gfpSettings.trigger_field_id) {
        return;
    }

    var formId = gfpSettings.trigger_form_id;
    var fieldId = gfpSettings.trigger_field_id;
    var triggerValue = gfpSettings.trigger_value;


    var smartButtonsSelector = '#gform_ppcp_smart_payment_buttons_' + formId;
    var ppcpContainerSelector = '#gform_ppcp_payment_method_' + formId + ', .gform_ppcp_payment_method, ' + smartButtonsSelector;
    var submitButtonSelector = '#gform_submit_button_' + formId;
    var secondaryContainerId = 'gfp_secondary_paypal_container';
    var fieldContainerSelector = '#field_' + formId + '_' + fieldId;


    var isSecondaryActive = false;

    // --- Core Logic ---

    function getTriggerFieldValue() {
        var $container = $(fieldContainerSelector);
        if ($container.length === 0) {
            var $directInput = $('#input_' + formId + '_' + fieldId);
            if ($directInput.length > 0) return $directInput.val();
            return null;
        }

        var $checked = $container.find('input:checked');
        if ($checked.length > 0) {
            var values = [];
            $checked.each(function () { values.push($(this).val()); });
            return values;
        }

        var $select = $container.find('select');
        if ($select.length > 0) return $select.val();

        var $text = $container.find('input[type="text"], input[type="number"], input[type="email"], textarea');
        if ($text.length > 0) return $text.val();

        return null;
    }

    function checkTriggerCondition() {
        var values = getTriggerFieldValue();
        var match = false;

        if (values === null || values === undefined) {
            match = false;
        } else if (Array.isArray(values)) {
            match = values.some(function (v) {
                return v.toString().trim().toLowerCase() === triggerValue.toString().trim().toLowerCase();
            });
        } else {
            match = values.toString().trim().toLowerCase() === triggerValue.toString().trim().toLowerCase();
        }

        if (match) {
            activateSecondaryAccount();
        } else {
            deactivateSecondaryAccount();
        }
    }

    function activateSecondaryAccount() {
        if (!isSecondaryActive) {
            console.log('Gravity Forms Multi-Account: Activating Secondary Account');
            isSecondaryActive = true;

            ensureSecondaryContainerExists();
            renderPayPalButtons();
        }
    }

    function deactivateSecondaryAccount() {
        if (isSecondaryActive) {
            console.log('Gravity Forms Multi-Account: Deactivating Secondary Account');
            isSecondaryActive = false;
        }
    }

    setInterval(function () {
        var $submitButton = $(submitButtonSelector);
        var $smartBtns = $(smartButtonsSelector);
        var $ppcpContainer = $(ppcpContainerSelector);
        var $paypalRadio = $('#gform_' + formId).find('input[value="PayPal"]');

        var isPayPalChecked = ($paypalRadio.length > 0 && $paypalRadio.is(':checked'));


        if (isSecondaryActive && isPayPalChecked) {


            if ($smartBtns.length && $smartBtns.css('display') === 'none') {
                $smartBtns.css('display', '');
            }


            if ($submitButton.length && $submitButton.css('display') !== 'none') {
                $submitButton.hide();
            }


            if ($ppcpContainer.length) {
                $ppcpContainer.find('iframe, .paypal-buttons-context-iframe')
                    .not('#gfp_secondary_paypal_container iframe')
                    .not('#gfp_secondary_paypal_container .paypal-buttons-context-iframe')
                    .css('display', 'none');
            }


            var $myContainer = ensureSecondaryContainerExists();
            $myContainer.addClass('gfp-active');
            if ($myContainer.css('display') === 'none') {
                $myContainer.css('display', '');
            }

        } else {


            if ($submitButton.length && $submitButton.css('display') === 'none') {
                $submitButton.show();
            }


            if ($ppcpContainer.length) {

                $ppcpContainer.find('iframe, .paypal-buttons-context-iframe').css('display', '');
            }


            var $myContainer = $('#' + secondaryContainerId);
            if ($myContainer.length) {
                $myContainer.removeClass('gfp-active');
                $myContainer.hide();
            }
        }

    }, 500);

    function ensureSecondaryContainerExists() {
        if ($('#' + secondaryContainerId).length > 0) {
            return $('#' + secondaryContainerId);
        }

        var $insertionPoint = $(smartButtonsSelector);
        if ($insertionPoint.length === 0) $insertionPoint = $(smartButtonsSelector);

        // Warning HTML
        var warningHtml = '';
        if (gfpSettings.is_admin) {
            warningHtml = '<div style="background: #fff3cd; color: #856404; padding: 15px; margin-bottom: 20px; border: 1px solid #ffeeba; border-radius: 4px; text-align: center; font-size: 16px;">' +
                '<strong><span class="dashicons dashicons-warning" style="margin-top:2px;"></span> USING SECONDARY PAYPAL ACCOUNT</strong><br>' +
                '<span style="font-size: 14px;">(This payment will be processed via the alternative account)</span>' +
                '</div>';
        }

        if ($insertionPoint.length > 0) {
            $insertionPoint.prepend('<div id="' + secondaryContainerId + '" style="display:none; margin-bottom: 20px;">' + warningHtml + '<div id="gfp_paypal_buttons"></div></div>');
        } else {
            // Fallback
            $insertionPoint = $(ppcpContainerSelector).first();
            if ($insertionPoint.length === 0) $insertionPoint = $(submitButtonSelector).first();
            $insertionPoint.after('<div id="' + secondaryContainerId + '" style="display:none; margin-top: 20px;">' + warningHtml + '<div id="gfp_paypal_buttons"></div></div>');
        }

        return $('#' + secondaryContainerId);
    }

    function renderPayPalButtons(retryCount) {
        if (typeof retryCount === 'undefined') retryCount = 0;
        var ppNamespace = window.paypalAccount2;

        if (!ppNamespace) {
            if (retryCount < 10) {
                setTimeout(function () { renderPayPalButtons(retryCount + 1); }, 500);
            }
            return;
        }

        var $container = ensureSecondaryContainerExists();
        var $btnContainer = $('#gfp_paypal_buttons');

        if ($btnContainer.children().length > 0) return; // Already rendered

        $btnContainer.empty();

        try {
            ppNamespace.Buttons({
                style: { layout: 'vertical', color: 'gold', shape: 'rect', label: 'paypal' },
                createOrder: function (data, actions) {
                    return $.ajax({
                        url: gfpSettings.ajax_url,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'gfp_create_order',
                            nonce: gfpSettings.nonce,
                            form_id: formId,
                            form_data: $('#gform_' + formId).serialize()
                        }
                    }).then(function (response) {
                        console.log('Create Order Response:', response);
                        if (response.success && response.data && response.data.orderID) {
                            return response.data.orderID;
                        }

                        var errorMsg = 'Order creation failed: ' + (response.data ? response.data.message : 'Unknown error');
                        console.error(errorMsg);
                        alert(errorMsg);
                        throw new Error(errorMsg);
                    }).catch(function (error) {
                        console.error('AJAX Error creating order:', error);
                        alert('Failed to create PayPal order. Please try again.');
                        throw error;
                    });
                },
                onApprove: function (data, actions) {
                    return $.ajax({
                        url: gfpSettings.ajax_url,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'gfp_capture_order',
                            nonce: gfpSettings.nonce,
                            order_id: data.orderID,
                            form_id: formId,
                            entry_id: window['gf_submitting_' + formId] || 0
                        }
                    }).then(function (response) {
                        if (response.success) {
                            var $form = $('#gform_' + formId);
                            $form.find('input[name="gfp_pp_transaction_id"]').remove();
                            $form.append('<input type="hidden" name="gfp_pp_transaction_id" value="' + response.data.transaction_id + '">');
                            $form.submit();
                        } else {
                            alert('Payment Failed: ' + (response.data ? response.data.message : 'Unknown'));
                        }
                    });
                },
                onError: function (err) {
                    console.error(err);
                    if (err.toString().indexOf('removed from DOM') === -1) alert('Payment Error. Check console.');
                }
            }).render('#gfp_paypal_buttons');
        } catch (e) { console.error(e); }
    }



    // Normal Changes
    $(document).on('change', fieldContainerSelector + ' input, ' + fieldContainerSelector + ' select, ' + fieldContainerSelector + ' textarea', checkTriggerCondition);
    $('#input_' + formId + '_' + fieldId).on('change keyup', checkTriggerCondition);


    $(document).bind('gform_post_render', function (event, renderedFormId, currentPage) {
        if (renderedFormId == formId) {
            console.log("Gravity Forms Post Render Triggered");

            checkTriggerCondition();
        }
    });

    // Initial check
    setTimeout(checkTriggerCondition, 500);
});
