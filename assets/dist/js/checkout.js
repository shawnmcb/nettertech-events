/* eslint-disable no-unused-vars -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * NetterTech Events - Checkout Donations
 *
 * Handles donation selection at WooCommerce checkout.
 *
 * @package NetterTechEvents
 */

(function($) {
    'use strict';

    /**
     * Donation handler for checkout.
     */
    var NetterTechEventsDonation = {
        /**
         * Initialize the donation handler.
         */
        init: function() {
            this.bindEvents();
            this.updateCustomAmountVisibility();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            var self = this;

            // Handle donation option change
            $(document.body).on('change', '.nte-donation-option', function() {
                self.handleOptionChange($(this));
            });

            // Handle custom amount input
            $(document.body).on('input', '.nte-donation-custom-amount', function() {
                self.handleCustomAmount($(this));
            });

            // When checkout updates (e.g., shipping changes), refresh round-up amount
            $(document.body).on('updated_checkout', function() {
                self.refreshRoundupAmount();
            });
        },

        /**
         * Handle donation option change.
         *
         * @param {jQuery} $input The selected radio input.
         */
        handleOptionChange: function($input) {
            var option = $input.val();
            var amount = parseFloat($input.data('amount')) || 0;

            // For custom, get value from input field
            if (option === 'custom') {
                var $customInput = $('.nte-donation-custom-amount');
                amount = parseFloat($customInput.val()) || 0;
                $customInput.focus();
            }

            this.updateCustomAmountVisibility();
            this.sendDonationUpdate(option, amount);
        },

        /**
         * Handle custom amount input change.
         *
         * @param {jQuery} $input The custom amount input.
         */
        handleCustomAmount: function($input) {
            var $customRadio = $('input.nte-donation-option[value="custom"]');

            // Select the custom radio if not already selected
            if (!$customRadio.is(':checked')) {
                $customRadio.prop('checked', true);
            }

            var amount = parseFloat($input.val()) || 0;
            var max = parseFloat($input.attr('max')) || 100;

            // Cap at maximum
            if (amount > max) {
                amount = max;
                $input.val(max);
            }

            // Debounce the AJAX call
            clearTimeout(this.customAmountTimeout);
            this.customAmountTimeout = setTimeout(function() {
                this.sendDonationUpdate('custom', amount);
            }.bind(this), 300);
        },

        /**
         * Show/hide custom amount input based on selection.
         */
        updateCustomAmountVisibility: function() {
            var $customRadio = $('input.nte-donation-option[value="custom"]');
            var $customInput = $('.nte-donation-custom-amount');

            if ($customRadio.is(':checked')) {
                $customInput.prop('disabled', false);
            } else {
                $customInput.prop('disabled', true);
            }
        },

        /**
         * Refresh the round-up amount after cart totals change.
         */
        refreshRoundupAmount: function() {
            // Re-sync the round-up amount after totals change (shipping, coupons).
            // Silent: only re-trigger update_checkout when the server-side amount
            // actually moved, otherwise updated_checkout -> AJAX -> update_checkout
            // -> updated_checkout never terminates (NTE-222).
            var $roundupRadio = $('input.nte-donation-option[value="roundup"]');

            if ($roundupRadio.is(':checked') && !this.refreshing) {
                this.sendDonationUpdate('roundup', 0, true);
            }
        },

        /**
         * Last donation amount the server confirmed, used to detect real changes.
         *
         * @type {number|null}
         */
        lastSyncedAmount: null,

        /**
         * Whether a silent refresh is in flight.
         *
         * @type {boolean}
         */
        refreshing: false,

        /**
         * Send donation update to server.
         *
         * @param {string}  option The selected option (roundup, fixed, custom, none).
         * @param {number}  amount The donation amount.
         * @param {boolean} silent Refresh mode: only trigger update_checkout if the amount changed.
         */
        sendDonationUpdate: function(option, amount, silent) {
            var self = this;
            silent = silent === true;

            if (!window.nettertechEventsDonation) {
                console.warn('NetterTechEvents Donation: Missing configuration');
                return;
            }

            // Show loading indicator
            $('#nte-donation-field').addClass('nte-loading');
            if (silent) {
                self.refreshing = true;
            }

            $.ajax({
                url: window.nettertechEventsDonation.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'nettertech_events_update_donation',
                    nonce: window.nettertechEventsDonation.nonce,
                    option: option,
                    amount: amount
                },
                success: function(response) {
                    if (!response.success) {
                        return;
                    }

                    var confirmed = response.data && typeof response.data.amount !== 'undefined'
                        ? parseFloat(response.data.amount)
                        : null;
                    var changed = confirmed === null || confirmed !== self.lastSyncedAmount;
                    self.lastSyncedAmount = confirmed;

                    // Trigger WooCommerce checkout update to recalculate totals —
                    // but a silent refresh only does so when the amount really moved.
                    if (!silent || changed) {
                        $(document.body).trigger('update_checkout');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('NetterTechEvents Donation: Update failed', error);
                },
                complete: function() {
                    self.refreshing = false;
                    $('#nte-donation-field').removeClass('nte-loading');
                }
            });
        }
    };

    /**
     * Initialize on document ready.
     */
    $(function() {
        if ($('#nte-donation-field').length) {
            NetterTechEventsDonation.init();
        }
    });

    // Export for external use
    window.NetterTechEventsDonation = NetterTechEventsDonation;

})(jQuery);
