/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 3.1.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */
foodcoopshop.ModalSelfServicePaymenttypeDetailsDialog = {

    modalSelector: '#self-service-confirm-dialog-paymenttype-details',
    pollTimer: null,
    pollDeadline: 0,
    pollIntervalMs: 2000,
    consecutivePollErrors: 0,
    maxConsecutivePollErrors: 3,
    isRequestInFlight: false,
    isCancelledByUser: false,
    currentTransactionId: null,
    currentPaymentName: '',
    currentPaymentText: '',
    currentPaymentTypeCode: '',
    currentIsSumUp: false,

    getSuccessHandler : function() {
        var selfSForm = $('#SelfServiceForm');
        selfSForm.find('input[name="self_service_payment_type"]').val(this.currentPaymentTypeCode || '');
        selfSForm.find('input[name="sumup_transaction_id"]').val('');
        selfSForm.submit();
    },

    getOpenHandler : function(modalSelector, paymentName, paymentText, options) {
        options = options || {};
        this.resetState();
        this.currentPaymentName = paymentName || '';
        this.currentPaymentText = paymentText || '';
        this.currentPaymentTypeCode = options.paymentTypeCode || '';
        this.currentIsSumUp = Boolean(options.isSumUp);

        if (this.currentIsSumUp) {
            this.openSumUpWaitingModal();
            return;
        }

        this.openStandardDetailsModal();
    },

    openStandardDetailsModal : function() {
        var modalSelector = this.modalSelector;
        foodcoopshop.Modal.destroy($(modalSelector));

        var buttons = [
            foodcoopshop.Modal.createButton(['btn-success'], __('Confirm_self_service_purchase_completed_button'), 'fa-fw fas fa-check'),
            foodcoopshop.Modal.createButton(['btn-outline-light'], __('Deny_self_service_purchase_button'), null, true)
        ];

        foodcoopshop.Modal.appendModalToDom(
            modalSelector,
            '',
            '',
            buttons
        );

        var amount = $('p.total-sum-wrapper > span.sum').html();
        $(modalSelector + ' .modal-title').text(this.currentPaymentName);
        $(modalSelector + ' .modal-body').html('<p>' + __('Amount_to_be_paid') + '<b>' + amount + '</b>' + this.currentPaymentText + '</p>');

        foodcoopshop.Modal.bindSuccessButton(modalSelector, function() {
            foodcoopshop.ModalSelfServicePaymenttypeDetailsDialog.getSuccessHandler();
        });

        $(modalSelector + ' button.btn-outline-light').on('click', function () {
            foodcoopshop.ModalSelfServicePaymenttypeDetailsDialog.getCloseHandler(modalSelector);
        });
        $(modalSelector + ' button.btn-close').on('click', function () {
            foodcoopshop.ModalSelfServicePaymenttypeDetailsDialog.getCloseHandler(modalSelector);
        });

        new bootstrap.Modal(document.getElementById(modalSelector.replace(/#/, ''))).show();
    },

    openSumUpWaitingModal : function() {
        var self = this;
        var modalSelector = this.modalSelector;
        foodcoopshop.Modal.destroy($(modalSelector));

        var buttons = [
            foodcoopshop.Modal.createButton(['btn-outline-light', 'btn-sumup-cancel'], __('Cancel'), 'fa-fw fas fa-times', false)
        ];

        foodcoopshop.Modal.appendModalToDom(
            modalSelector,
            this.currentPaymentName,
            '',
            buttons
        );

        var amount = $('p.total-sum-wrapper > span.sum').html() || '';
        $(modalSelector + ' .modal-title').text(this.currentPaymentName);
        $(modalSelector + ' .modal-body').html(
            '<div class="sumup-payment-box" style="text-align:center; padding: 10px 5px;">' +
                '<p style="font-size: 1.15em; margin-bottom: 14px;">' + __('SumUp_amount_label') + ' <b class="sumup-amount">' + amount + '</b></p>' +
                '<div class="sumup-status-message" style="font-size: 1.1em; padding: 12px; border-radius: 6px; background: rgba(0,0,0,0.04);">' +
                    '<i class="fas fa-circle-notch fa-spin" style="margin-right: 8px;"></i>' +
                    '<span class="sumup-status-text">' + __('SumUp_waiting_for_terminal') + '</span>' +
                '</div>' +
            '</div>'
        );

        // Wire both Cancel button and modal X button to terminate on terminal and return to payment selection
        $(modalSelector + ' .btn-sumup-cancel, ' + modalSelector + ' button.btn-close')
            .removeAttr('data-bs-dismiss')
            .off('click')
            .on('click', function() {
                self.cancelSumUpAndReturnToSelection();
            });

        var modalEl = document.getElementById(modalSelector.replace(/#/, ''));
        new bootstrap.Modal(modalEl, {
            backdrop: 'static',
            keyboard: false
        }).show();

        this.startSumUpCheckout();
    },

    startSumUpCheckout : function() {
        var self = this;
        if (this.isRequestInFlight) {
            return;
        }
        this.isRequestInFlight = true;
        this.isCancelledByUser = false;
        this.currentTransactionId = null;

        var modalSelector = this.modalSelector;
        this.setSumUpWaitingView(__('SumUp_sending_to_terminal'));

        foodcoopshop.Helper.ajaxCall(
            '/' + __('route_self_service') + '/sumup/start',
            {},
            {
                method: 'POST',
                onOk: function(data) {
                    self.isRequestInFlight = false;
                    if (self.isCancelledByUser) {
                        return;
                    }
                    if (!data.transactionId) {
                        self.showSumUpError(__('An_error_occurred'));
                        return;
                    }
                    self.currentTransactionId = data.transactionId;
                    self.pollIntervalMs = data.pollIntervalMs || 2000;
                    var timeoutSeconds = data.timeoutSeconds || 120;
                    self.pollDeadline = Date.now() + (timeoutSeconds * 1000);

                    if (data.amountFormatted) {
                        $(modalSelector + ' .sumup-amount').html(data.amountFormatted);
                    }
                    self.setSumUpWaitingView(__('SumUp_waiting_for_terminal'));
                    self.scheduleNextPoll();
                },
                onError: function(data) {
                    self.isRequestInFlight = false;
                    if (self.isCancelledByUser) {
                        return;
                    }
                    self.showSumUpError((data && data.msg) ? data.msg : __('An_error_occurred'));
                }
            }
        );
    },

    scheduleNextPoll : function() {
        var self = this;
        this.stopPolling();

        if (this.isCancelledByUser || !this.currentTransactionId) {
            return;
        }

        if (Date.now() >= this.pollDeadline) {
            this.handleSumUpTimeout();
            return;
        }

        this.pollTimer = setTimeout(function() {
            self.pollSumUpStatus();
        }, this.pollIntervalMs);
    },

    pollSumUpStatus : function() {
        var self = this;
        if (this.isCancelledByUser || !this.currentTransactionId) {
            return;
        }

        foodcoopshop.Helper.ajaxCall(
            '/' + __('route_self_service') + '/sumup/status?transactionId=' + encodeURIComponent(this.currentTransactionId),
            {},
            {
                method: 'GET',
                onOk: function(data) {
                    if (self.isCancelledByUser) {
                        return;
                    }
                    self.consecutivePollErrors = 0;

                    var paymentStatus = data.paymentStatus || 'PENDING';
                    if (paymentStatus === 'SUCCESSFUL') {
                        self.handleSumUpSuccess(self.currentTransactionId);
                        return;
                    }

                    if (paymentStatus === 'FAILED' || paymentStatus === 'CANCELLED') {
                        self.showSumUpError(data.message || __('SumUp_payment_failed.'));
                        return;
                    }

                    if (data.message) {
                        $(self.modalSelector + ' .sumup-status-text').text(data.message);
                    }
                    self.scheduleNextPoll();
                },
                onError: function(data) {
                    if (self.isCancelledByUser) {
                        return;
                    }
                    if (data && data.status === 9 && self.consecutivePollErrors < self.maxConsecutivePollErrors) {
                        self.consecutivePollErrors++;
                        self.scheduleNextPoll();
                        return;
                    }
                    self.showSumUpError((data && data.msg) ? data.msg : __('An_error_occurred'));
                }
            }
        );
    },

    handleSumUpSuccess : function(transactionId) {
        this.stopPolling();
        var modalSelector = this.modalSelector;
        $(modalSelector + ' .modal-footer').empty();
        $(modalSelector + ' .btn-close').hide();
        $(modalSelector + ' .sumup-status-message')
            .css({ background: '#d4edda', color: '#155724' })
            .html('<i class="fas fa-check-circle" style="margin-right: 8px;"></i><span>' + __('SumUp_payment_successful_finishing') + '</span>');

        var selfSForm = $('#SelfServiceForm');
        selfSForm.find('input[name="self_service_payment_type"]').val(this.currentPaymentTypeCode || 3);
        selfSForm.find('input[name="sumup_transaction_id"]').val(transactionId);
        selfSForm.submit();
    },

    handleSumUpTimeout : function() {
        var self = this;
        this.stopPolling();
        this.terminateOnTerminal(function() {
            self.showSumUpError(__('SumUp_payment_timeout'));
        });
    },

    cancelSumUpAndReturnToSelection : function() {
        var self = this;
        this.isCancelledByUser = true;
        this.stopPolling();

        var cancelBtn = $(this.modalSelector + ' .btn-sumup-cancel');
        if (cancelBtn.length > 0) {
            foodcoopshop.Helper.disableButton(cancelBtn);
            foodcoopshop.Helper.addSpinnerToButton(cancelBtn, 'fa-times');
        }

        this.terminateOnTerminal(function() {
            self.closeAndReopenPaymentSelection();
        });
    },

    terminateOnTerminal : function(doneCallback) {
        var self = this;
        var hadTransaction = Boolean(this.currentTransactionId);
        this.currentTransactionId = null;

        if (!hadTransaction) {
            if (doneCallback) {
                doneCallback();
            }
            return;
        }

        foodcoopshop.Helper.ajaxCall(
            '/' + __('route_self_service') + '/sumup/cancel',
            {},
            {
                method: 'POST',
                onOk: function() {
                    if (doneCallback) {
                        doneCallback();
                    }
                },
                onError: function() {
                    if (doneCallback) {
                        doneCallback();
                    }
                }
            }
        );
    },

    setSumUpWaitingView : function(statusText) {
        var self = this;
        var modalSelector = this.modalSelector;
        $(modalSelector + ' .sumup-status-message')
            .css({ background: 'rgba(0,0,0,0.04)', color: '' })
            .html('<i class="fas fa-circle-notch fa-spin" style="margin-right: 8px;"></i><span class="sumup-status-text">' + statusText + '</span>');

        $(modalSelector + ' .modal-footer').html(
            foodcoopshop.Modal.createButton(['btn-outline-light', 'btn-sumup-cancel'], __('Cancel'), 'fa-fw fas fa-times', false)
        );
        $(modalSelector + ' .btn-sumup-cancel').on('click', function() {
            self.cancelSumUpAndReturnToSelection();
        });
    },

    showSumUpError : function(errorMessage) {
        var self = this;
        this.stopPolling();
        this.currentTransactionId = null;

        if (foodcoopshop.SelfService && typeof foodcoopshop.SelfService.playErrorSound === 'function') {
            foodcoopshop.SelfService.playErrorSound();
        }

        var modalSelector = this.modalSelector;
        $(modalSelector + ' .sumup-status-message')
            .css({ background: '#f8d7da', color: '#721c24' })
            .html('<i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i><span>' + $('<div>').text(errorMessage).html() + '</span>');

        var retryBtn = foodcoopshop.Modal.createButton(['btn-success', 'no-auto-bind', 'btn-sumup-retry'], __('SumUp_try_again'), 'fa-fw fas fa-redo', false);
        var switchBtn = foodcoopshop.Modal.createButton(['btn-outline-light', 'btn-sumup-switch'], __('SumUp_choose_other_payment_type'), 'fa-fw fas fa-exchange-alt', false);

        $(modalSelector + ' .modal-footer').html(retryBtn + switchBtn);

        $(modalSelector + ' .btn-sumup-retry').on('click', function() {
            self.startSumUpCheckout();
        });
        $(modalSelector + ' .btn-sumup-switch').on('click', function() {
            self.closeAndReopenPaymentSelection();
        });
    },

    closeAndReopenPaymentSelection : function() {
        var modalEl = document.getElementById(this.modalSelector.replace(/#/, ''));
        if (modalEl) {
            var instance = bootstrap.Modal.getInstance(modalEl);
            if (instance) {
                instance.hide();
            }
        }
        foodcoopshop.Modal.destroy($(this.modalSelector));
        this.resetState();

        if ($('#self-service-confirm-dialog').length > 0) {
            foodcoopshop.ModalSelfServiceConfirmDialog.getOpenHandler('#self-service-confirm-dialog');
        } else {
            foodcoopshop.SelfService.setFocusToSearchInputField();
        }
    },

    stopPolling : function() {
        if (this.pollTimer !== null) {
            clearTimeout(this.pollTimer);
            this.pollTimer = null;
        }
    },

    resetState : function() {
        this.stopPolling();
        this.pollDeadline = 0;
        this.consecutivePollErrors = 0;
        this.isRequestInFlight = false;
        this.isCancelledByUser = false;
        this.currentTransactionId = null;
    },

    getCloseHandler : function(modalSelector) {
        this.resetState();
        foodcoopshop.Modal.destroy($(this.modalSelector));
        foodcoopshop.SelfService.setFocusToSearchInputField();
    },

};
