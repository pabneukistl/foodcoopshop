/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */
foodcoopshop.ModalSelfServiceSumUpFreeAmount = {

    modalSelector: '#self-service-sumup-free-amount-dialog',
    pollTimer: null,
    autoCloseTimer: null,
    pollDeadline: 0,
    pollIntervalMs: 2000,
    consecutivePollErrors: 0,
    maxConsecutivePollErrors: 3,
    isRequestInFlight: false,
    isCancelledByUser: false,
    currentTransactionId: null,
    currentRawAmount: '',
    currentAmountFormatted: '',
    isPaymentPhase: false,

    init : function() {
        var self = this;
        $(document).off('click.sumupFreeAmount', '.btn-sumup-free-amount');
        $(document).on('click.sumupFreeAmount', '.btn-sumup-free-amount', function(e) {
            e.preventDefault();
            self.openModal();
        });
    },

    openModal : function(initialAmount) {
        var self = this;
        this.resetState();
        this.currentRawAmount = typeof initialAmount === 'string' ? initialAmount : '';
        this.isPaymentPhase = false;

        var modalSelector = this.modalSelector;
        foodcoopshop.Modal.destroy($(modalSelector));

        foodcoopshop.Modal.appendModalToDom(
            modalSelector,
            __('SumUp_free_amount_modal_title'),
            '',
            []
        );

        $(modalSelector + ' .modal-title').text(__('SumUp_free_amount_modal_title'));
        this.renderInputView();

        var modalEl = document.getElementById(modalSelector.replace(/#/, ''));
        $(modalEl).off('hidden.bs.modal').on('hidden.bs.modal', function() {
            self.cleanupAfterClose();
        });

        var modalInstance = new bootstrap.Modal(modalEl, {
            backdrop: 'static',
            keyboard: false
        });
        modalInstance.show();

        // Wire modal X button cleanly depending on phase
        $(modalSelector + ' button.btn-close')
            .removeAttr('data-bs-dismiss')
            .off('click')
            .on('click', function() {
                self.handleCloseAction();
            });
    },

    renderInputView : function() {
        var self = this;
        var modalSelector = this.modalSelector;
        this.isPaymentPhase = false;
        this.stopPolling();
        this.clearAutoCloseTimer();

        $(modalSelector + ' button.btn-close').show();

        var bodyHtml =
            '<div class="sumup-free-amount-wrapper">' +
                '<p class="sumup-free-amount-hint">' + __('SumUp_free_amount_hint') + '</p>' +
                '<div class="sumup-free-amount-input-group">' +
                    '<input type="text" inputmode="decimal" autocomplete="off" class="form-control sumup-free-amount-input" placeholder="0,00" value="' + $('<div>').text(this.currentRawAmount).html() + '" />' +
                    '<span class="sumup-free-amount-currency">&euro;</span>' +
                '</div>' +
                '<div class="sumup-free-amount-validation-error" style="display:none;"></div>' +
                '<div class="sumup-free-amount-keypad">' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="7">7</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="8">8</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="9">9</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="4">4</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="5">5</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="6">6</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="1">1</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="2">2</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="3">3</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key=",">,</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="0">0</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key sumup-key-backspace" data-key="backspace"><i class="fas fa-backspace"></i></button>' +
                '</div>' +
            '</div>';

        $(modalSelector + ' .modal-body').html(bodyHtml);

        var payBtn = foodcoopshop.Modal.createButton(
            ['btn-success', 'no-auto-bind', 'btn-sumup-free-pay'],
            __('SumUp_free_amount_pay_button'),
            'fa-fw fas fa-credit-card',
            false
        );
        var cancelBtn = foodcoopshop.Modal.createButton(
            ['btn-outline-light', 'btn-sumup-free-cancel'],
            __('Cancel'),
            'fa-fw fas fa-times',
            false
        );
        $(modalSelector + ' .modal-footer').html(payBtn + cancelBtn);

        var input = $(modalSelector + ' .sumup-free-amount-input');

        input.on('input', function() {
            var sanitized = self.sanitizeAmountInput($(this).val());
            $(this).val(sanitized);
            self.currentRawAmount = sanitized;
            self.hideValidationError();
        });

        input.on('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                self.submitAmount();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                self.closeModal();
            }
        });

        $(modalSelector + ' .sumup-key').on('click', function() {
            var key = String($(this).data('key'));
            var current = String(input.val() || '');
            var next = current;

            if (key === 'backspace') {
                next = current.slice(0, -1);
            } else if (key === ',') {
                if (current.indexOf(',') === -1) {
                    next = current === '' ? '0,' : current + ',';
                }
            } else {
                if (current === '0') {
                    next = key;
                } else {
                    next = current + key;
                }
            }

            next = self.sanitizeAmountInput(next);
            input.val(next);
            self.currentRawAmount = next;
            self.hideValidationError();
        });

        $(modalSelector + ' .btn-sumup-free-pay').on('click', function() {
            self.submitAmount();
        });

        $(modalSelector + ' .btn-sumup-free-cancel').on('click', function() {
            self.closeModal();
        });
    },

    sanitizeAmountInput : function(raw) {
        var str = String(raw || '').replace(/\./g, ',').replace(/[^0-9,]/g, '');
        var firstComma = str.indexOf(',');
        if (firstComma !== -1) {
            var intPart = str.slice(0, firstComma).slice(0, 3);
            var decPart = str.slice(firstComma + 1).replace(/,/g, '').slice(0, 2);
            if (intPart === '') {
                intPart = '0';
            }
            return intPart + ',' + decPart;
        }
        return str.slice(0, 3);
    },

    showValidationError : function(msg) {
        var box = $(this.modalSelector + ' .sumup-free-amount-validation-error');
        box.text(msg).show();
    },

    hideValidationError : function() {
        $(this.modalSelector + ' .sumup-free-amount-validation-error').hide().text('');
    },

    submitAmount : function() {
        var inputVal = $(this.modalSelector + ' .sumup-free-amount-input').val();
        var sanitized = this.sanitizeAmountInput(inputVal);
        this.currentRawAmount = sanitized;

        if (sanitized === '' || sanitized === ',') {
            this.showValidationError(__('SumUp_free_amount_invalid.'));
            return;
        }

        var parsed = parseFloat(sanitized.replace(',', '.'));
        if (isNaN(parsed)) {
            this.showValidationError(__('SumUp_free_amount_invalid.'));
            return;
        }

        var cents = Math.round(parsed * 100);
        if (cents < 100) {
            this.showValidationError(__('SumUp_free_amount_min_error.'));
            return;
        }
        if (cents > 50000) {
            this.showValidationError(__('SumUp_free_amount_max_error.'));
            return;
        }

        this.currentAmountFormatted = parsed.toFixed(2).replace('.', ',') + ' €';
        this.startFreeAmountCheckout(parsed.toFixed(2));
    },

    startFreeAmountCheckout : function(amountValue) {
        var self = this;
        if (this.isRequestInFlight) {
            return;
        }

        this.isRequestInFlight = true;
        this.isCancelledByUser = false;
        this.isPaymentPhase = true;
        this.currentTransactionId = null;

        var modalSelector = this.modalSelector;
        $(modalSelector + ' .modal-body').html(
            '<div class="sumup-payment-box" style="text-align:center; padding: 10px 5px;">' +
                '<p style="font-size: 1.25em; margin-bottom: 14px;">' + __('SumUp_amount_label') + ' <b class="sumup-amount">' + $('<div>').text(this.currentAmountFormatted).html() + '</b></p>' +
                '<div class="sumup-status-message" style="font-size: 1.1em; padding: 12px; border-radius: 6px; background: rgba(0,0,0,0.04);">' +
                    '<i class="fas fa-circle-notch fa-spin" style="margin-right: 8px;"></i>' +
                    '<span class="sumup-status-text">' + __('SumUp_sending_to_terminal') + '</span>' +
                '</div>' +
            '</div>'
        );

        this.renderWaitingFooter();

        foodcoopshop.Helper.ajaxCall(
            '/' + __('route_self_service') + '/sumup/free-amount-start',
            {
                amount: amountValue
            },
            {
                method: 'POST',
                onOk: function(data) {
                    self.isRequestInFlight = false;
                    if (self.isCancelledByUser) {
                        return;
                    }
                    if (!data.transactionId) {
                        self.showPaymentError(__('An_error_occurred'));
                        return;
                    }
                    self.currentTransactionId = data.transactionId;
                    self.pollIntervalMs = data.pollIntervalMs || 2000;
                    var timeoutSeconds = data.timeoutSeconds || 120;
                    self.pollDeadline = Date.now() + (timeoutSeconds * 1000);

                    if (data.amountFormatted) {
                        self.currentAmountFormatted = data.amountFormatted;
                        $(modalSelector + ' .sumup-amount').text(data.amountFormatted);
                    }
                    $(modalSelector + ' .sumup-status-text').text(__('SumUp_waiting_for_terminal'));
                    self.scheduleNextPoll();
                },
                onError: function(data) {
                    self.isRequestInFlight = false;
                    if (self.isCancelledByUser) {
                        return;
                    }
                    self.showPaymentError((data && data.msg) ? data.msg : __('An_error_occurred'));
                }
            }
        );
    },

    renderWaitingFooter : function() {
        var self = this;
        var modalSelector = this.modalSelector;
        $(modalSelector + ' .modal-footer').html(
            foodcoopshop.Modal.createButton(['btn-outline-light', 'btn-sumup-free-abort'], __('Cancel'), 'fa-fw fas fa-times', false)
        );
        $(modalSelector + ' .btn-sumup-free-abort').on('click', function() {
            self.cancelPaymentAndClose();
        });
    },

    scheduleNextPoll : function() {
        var self = this;
        this.stopPolling();

        if (this.isCancelledByUser || !this.currentTransactionId) {
            return;
        }

        if (Date.now() >= this.pollDeadline) {
            this.handleTimeout();
            return;
        }

        this.pollTimer = setTimeout(function() {
            self.pollStatus();
        }, this.pollIntervalMs);
    },

    pollStatus : function() {
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
                        self.showPaymentSuccess();
                        return;
                    }

                    if (paymentStatus === 'FAILED' || paymentStatus === 'CANCELLED') {
                        self.showPaymentError(data.message || __('SumUp_payment_failed.'));
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
                    self.showPaymentError((data && data.msg) ? data.msg : __('An_error_occurred'));
                }
            }
        );
    },

    showPaymentSuccess : function() {
        var self = this;
        this.stopPolling();
        this.currentTransactionId = null;
        this.isPaymentPhase = false;

        var modalSelector = this.modalSelector;
        $(modalSelector + ' .sumup-status-message')
            .css({ background: '#d4edda', color: '#155724' })
            .html(
                '<i class="fas fa-check-circle" style="margin-right: 8px;"></i>' +
                '<span>' + $('<div>').text(__('SumUp_free_amount_success_{0}', this.currentAmountFormatted)).html() + '</span>'
            );

        $(modalSelector + ' .modal-footer').html(
            foodcoopshop.Modal.createButton(['btn-success', 'no-auto-bind', 'btn-sumup-free-done'], __('SumUp_free_amount_close'), 'fa-fw fas fa-check', false)
        );
        $(modalSelector + ' .btn-sumup-free-done').on('click', function() {
            self.closeModal();
        });

        this.clearAutoCloseTimer();
        this.autoCloseTimer = setTimeout(function() {
            self.closeModal();
        }, 5000);
    },

    showPaymentError : function(errorMessage) {
        var self = this;
        this.stopPolling();
        this.currentTransactionId = null;
        this.isPaymentPhase = false;

        if (foodcoopshop.SelfService && typeof foodcoopshop.SelfService.playErrorSound === 'function') {
            foodcoopshop.SelfService.playErrorSound();
        }

        var modalSelector = this.modalSelector;
        $(modalSelector + ' .sumup-status-message')
            .css({ background: '#f8d7da', color: '#721c24' })
            .html('<i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i><span>' + $('<div>').text(errorMessage).html() + '</span>');

        var retryBtn = foodcoopshop.Modal.createButton(
            ['btn-success', 'no-auto-bind', 'btn-sumup-free-retry'],
            __('SumUp_try_again'),
            'fa-fw fas fa-redo',
            false
        );
        var changeBtn = foodcoopshop.Modal.createButton(
            ['btn-outline-light', 'btn-sumup-free-change'],
            __('SumUp_free_amount_change_amount'),
            'fa-fw fas fa-edit',
            false
        );
        var cancelBtn = foodcoopshop.Modal.createButton(
            ['btn-outline-light', 'btn-sumup-free-cancel'],
            __('Cancel'),
            'fa-fw fas fa-times',
            false
        );

        $(modalSelector + ' .modal-footer').html(retryBtn + changeBtn + cancelBtn);

        $(modalSelector + ' .btn-sumup-free-retry').on('click', function() {
            var parsed = parseFloat(self.currentRawAmount.replace(',', '.'));
            if (!isNaN(parsed) && parsed >= 1 && parsed <= 500) {
                self.startFreeAmountCheckout(parsed.toFixed(2));
            } else {
                self.renderInputView();
            }
        });
        $(modalSelector + ' .btn-sumup-free-change').on('click', function() {
            self.renderInputView();
        });
        $(modalSelector + ' .btn-sumup-free-cancel').on('click', function() {
            self.closeModal();
        });
    },

    handleTimeout : function() {
        var self = this;
        this.stopPolling();
        this.terminateOnTerminal(function() {
            self.showPaymentError(__('SumUp_payment_timeout'));
        });
    },

    handleCloseAction : function() {
        if (this.isPaymentPhase || this.currentTransactionId) {
            this.cancelPaymentAndClose();
        } else {
            this.closeModal();
        }
    },

    cancelPaymentAndClose : function() {
        var self = this;
        this.isCancelledByUser = true;
        this.stopPolling();

        var abortBtn = $(this.modalSelector + ' .btn-sumup-free-abort');
        if (abortBtn.length > 0) {
            foodcoopshop.Helper.disableButton(abortBtn);
            foodcoopshop.Helper.addSpinnerToButton(abortBtn, 'fa-times');
        }

        this.terminateOnTerminal(function() {
            self.closeModal();
        });
    },

    terminateOnTerminal : function(doneCallback) {
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

    closeModal : function() {
        this.clearAutoCloseTimer();
        var modalEl = document.getElementById(this.modalSelector.replace(/#/, ''));
        if (modalEl) {
            var instance = bootstrap.Modal.getInstance(modalEl);
            if (instance) {
                instance.hide();
                return;
            }
        }
        this.cleanupAfterClose();
    },

    cleanupAfterClose : function() {
        this.resetState();
        foodcoopshop.Modal.destroy($(this.modalSelector));

        if ($('#barcode').length > 0) {
            $('#barcode').focus();
        } else if (foodcoopshop.SelfService && typeof foodcoopshop.SelfService.setFocusToSearchInputField === 'function') {
            foodcoopshop.SelfService.setFocusToSearchInputField();
        }
    },

    stopPolling : function() {
        if (this.pollTimer !== null) {
            clearTimeout(this.pollTimer);
            this.pollTimer = null;
        }
    },

    clearAutoCloseTimer : function() {
        if (this.autoCloseTimer !== null) {
            clearTimeout(this.autoCloseTimer);
            this.autoCloseTimer = null;
        }
    },

    resetState : function() {
        this.stopPolling();
        this.clearAutoCloseTimer();
        this.pollDeadline = 0;
        this.consecutivePollErrors = 0;
        this.isRequestInFlight = false;
        this.isCancelledByUser = false;
        this.isPaymentPhase = false;
        this.currentTransactionId = null;
    }

};
