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
    isCalculatorOpen: false,
    calcTokens: [],
    calcLastExpression: '',
    calcJustEvaluated: false,

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
        this.isCalculatorOpen = false;
        this.resetCalculatorState();

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

        var keypadClasses = 'sumup-free-amount-keypad' + (this.isCalculatorOpen ? ' with-calculator' : '');
        var calcToggleLabel = this.isCalculatorOpen ? __('SumUp_free_amount_calc_hide') : __('SumUp_free_amount_calc_show');

        var bodyHtml =
            '<div class="sumup-free-amount-wrapper">' +
                '<p class="sumup-free-amount-hint">' + __('SumUp_free_amount_hint') + '</p>' +
                '<div class="sumup-calc-expression" style="' + (this.isCalculatorOpen ? '' : 'display:none;') + '"></div>' +
                '<div class="sumup-free-amount-input-group">' +
                    '<input type="text" inputmode="decimal" autocomplete="off" class="form-control sumup-free-amount-input" placeholder="0,00" value="' + $('<div>').text(this.currentRawAmount).html() + '" />' +
                    '<span class="sumup-free-amount-currency">&euro;</span>' +
                '</div>' +
                '<div class="sumup-calc-toolbar">' +
                    '<button type="button" class="btn btn-outline-light btn-sm sumup-calc-toggle">' +
                        '<i class="fas fa-calculator"></i> <span class="sumup-calc-toggle-label">' + $('<div>').text(calcToggleLabel).html() + '</span>' +
                    '</button>' +
                    '<button type="button" class="btn btn-outline-light btn-sm sumup-calc-clear" style="' + (this.isCalculatorOpen ? '' : 'display:none;') + '">' +
                        '<i class="fas fa-eraser"></i> ' + $('<div>').text(__('SumUp_free_amount_calc_clear')).html() +
                    '</button>' +
                '</div>' +
                '<div class="sumup-free-amount-validation-error" style="display:none;"></div>' +
                '<div class="' + keypadClasses + '">' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="7">7</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="8">8</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="9">9</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key sumup-key-calc sumup-key-op" data-op="+">+</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="4">4</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="5">5</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="6">6</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key sumup-key-calc sumup-key-op" data-op="-">&minus;</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="1">1</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="2">2</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="3">3</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key sumup-key-calc sumup-key-op" data-op="*">&times;</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key=",">,</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key" data-key="0">0</button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key sumup-key-backspace" data-key="backspace"><i class="fas fa-backspace"></i></button>' +
                    '<button type="button" class="btn btn-outline-light sumup-key sumup-key-calc sumup-key-equals" data-op="=">=</button>' +
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
            self.calcJustEvaluated = false;
            self.calcLastExpression = '';
            self.hideValidationError();
            self.updateCalcDisplay();
        });

        input.on('keydown', function(e) {
            if (e.key === '+' || e.key === '-' || e.key === '*') {
                e.preventDefault();
                if (!self.isCalculatorOpen) {
                    self.setCalculatorOpen(true);
                }
                self.handleCalcOperator(e.key);
                return;
            }
            if (e.key === '=') {
                e.preventDefault();
                if (self.isCalculatorOpen || self.calcTokens.length > 0) {
                    self.handleCalcEquals();
                }
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                self.submitAmount();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                self.closeModal();
            }
        });

        $(modalSelector + ' .sumup-calc-toggle').on('click', function() {
            self.setCalculatorOpen(!self.isCalculatorOpen);
        });

        $(modalSelector + ' .sumup-calc-clear').on('click', function() {
            self.clearAllAmountAndCalc();
        });

        $(modalSelector + ' .sumup-key[data-key]').on('click', function() {
            var key = String($(this).data('key'));
            self.handleKeypadDigit(key);
        });

        $(modalSelector + ' .sumup-key[data-op]').on('click', function() {
            var op = String($(this).data('op'));
            if (op === '=') {
                self.handleCalcEquals();
            } else {
                self.handleCalcOperator(op);
            }
        });

        $(modalSelector + ' .btn-sumup-free-pay').on('click', function() {
            self.submitAmount();
        });

        $(modalSelector + ' .btn-sumup-free-cancel').on('click', function() {
            self.closeModal();
        });

        this.updateCalcDisplay();
    },

    setCalculatorOpen : function(open) {
        var modalSelector = this.modalSelector;
        if (!open && this.calcTokens.length > 0) {
            this.handleCalcEquals();
        }
        this.isCalculatorOpen = Boolean(open);
        $(modalSelector + ' .sumup-free-amount-keypad').toggleClass('with-calculator', this.isCalculatorOpen);
        $(modalSelector + ' .sumup-calc-expression').toggle(this.isCalculatorOpen);
        $(modalSelector + ' .sumup-calc-clear').toggle(this.isCalculatorOpen);
        $(modalSelector + ' .sumup-calc-toggle-label').text(
            this.isCalculatorOpen ? __('SumUp_free_amount_calc_hide') : __('SumUp_free_amount_calc_show')
        );
        this.updateCalcDisplay();
    },

    resetCalculatorState : function() {
        this.calcTokens = [];
        this.calcLastExpression = '';
        this.calcJustEvaluated = false;
    },

    clearAllAmountAndCalc : function() {
        this.resetCalculatorState();
        this.currentRawAmount = '';
        $(this.modalSelector + ' .sumup-free-amount-input').val('');
        this.hideValidationError();
        this.updateCalcDisplay();
    },

    handleKeypadDigit : function(key) {
        var input = $(this.modalSelector + ' .sumup-free-amount-input');
        var current = String(input.val() || '');

        if (key === 'backspace') {
            if (current.length > 0) {
                current = current.slice(0, -1);
                this.calcJustEvaluated = false;
                this.calcLastExpression = '';
            } else if (this.calcTokens.length >= 2) {
                // Pop trailing operator and restore previous number into input for editing
                this.calcTokens.pop();
                current = String(this.calcTokens.pop() || '');
                this.calcLastExpression = '';
            }
        } else {
            if (this.calcJustEvaluated) {
                current = '';
                this.calcJustEvaluated = false;
                this.calcLastExpression = '';
            }
            if (key === ',') {
                if (current.indexOf(',') === -1) {
                    current = current === '' ? '0,' : current + ',';
                }
            } else {
                if (current === '0') {
                    current = key;
                } else {
                    current = current + key;
                }
            }
        }

        var next = this.sanitizeAmountInput(current);
        input.val(next);
        this.currentRawAmount = next;
        this.hideValidationError();
        this.updateCalcDisplay();
    },

    handleCalcOperator : function(op) {
        var input = $(this.modalSelector + ' .sumup-free-amount-input');
        var current = this.sanitizeAmountInput(input.val());
        this.hideValidationError();

        if (current !== '' && current !== ',') {
            this.calcTokens.push(current);
            this.calcTokens.push(op);
            input.val('');
            this.currentRawAmount = '';
            this.calcJustEvaluated = false;
            this.calcLastExpression = '';
        } else if (this.calcTokens.length > 0) {
            var last = this.calcTokens[this.calcTokens.length - 1];
            if (last === '+' || last === '-' || last === '*') {
                this.calcTokens[this.calcTokens.length - 1] = op;
            } else {
                this.calcTokens.push(op);
            }
        }

        this.updateCalcDisplay();
    },

    handleCalcEquals : function() {
        var input = $(this.modalSelector + ' .sumup-free-amount-input');
        var current = this.sanitizeAmountInput(input.val());
        var tokens = this.calcTokens.slice();

        if (current !== '' && current !== ',') {
            tokens.push(current);
        } else if (tokens.length > 0 && this.isOperator(tokens[tokens.length - 1])) {
            tokens.pop();
        }

        if (tokens.length === 0) {
            return;
        }

        var result = this.evaluateTokens(tokens);
        if (result === null || isNaN(result) || !isFinite(result)) {
            this.showValidationError(__('SumUp_free_amount_invalid.'));
            return;
        }

        var exprString = this.formatTokensForDisplay(tokens) + ' =';
        this.calcTokens = [];

        if (result < 0) {
            this.calcLastExpression = exprString + ' ' + this.formatAmountEuro(result);
            this.calcJustEvaluated = true;
            this.currentRawAmount = '';
            input.val('');
            this.showValidationError(__('SumUp_free_amount_min_error.'));
            this.updateCalcDisplay();
            return;
        }

        var formattedInput = this.formatNumberForInput(result);
        this.calcLastExpression = tokens.length > 1 ? exprString : '';
        this.calcJustEvaluated = true;
        this.currentRawAmount = formattedInput;
        input.val(formattedInput);
        this.hideValidationError();
        this.updateCalcDisplay();
    },

    isOperator : function(token) {
        return token === '+' || token === '-' || token === '*';
    },

    evaluateTokens : function(tokens) {
        if (!tokens || tokens.length === 0) {
            return null;
        }

        var normalized = [];
        for (var i = 0; i < tokens.length; i++) {
            var tok = tokens[i];
            if (this.isOperator(tok)) {
                normalized.push(tok);
            } else {
                var num = parseFloat(String(tok).replace(',', '.'));
                if (isNaN(num)) {
                    return null;
                }
                normalized.push(num);
            }
        }

        // Pass 1: multiplication (standard operator precedence)
        var additive = [normalized[0]];
        for (var j = 1; j < normalized.length; j += 2) {
            var op = normalized[j];
            var rhs = normalized[j + 1];
            if (typeof rhs !== 'number') {
                break;
            }
            if (op === '*') {
                additive[additive.length - 1] = additive[additive.length - 1] * rhs;
            } else {
                additive.push(op);
                additive.push(rhs);
            }
        }

        // Pass 2: addition & subtraction
        var total = additive[0];
        for (var k = 1; k < additive.length; k += 2) {
            var addOp = additive[k];
            var addRhs = additive[k + 1];
            if (addOp === '+') {
                total += addRhs;
            } else if (addOp === '-') {
                total -= addRhs;
            }
        }

        return Math.round(total * 100) / 100;
    },

    formatTokensForDisplay : function(tokens) {
        var parts = [];
        for (var i = 0; i < tokens.length; i++) {
            var tok = tokens[i];
            if (tok === '-') {
                parts.push('−');
            } else if (tok === '*') {
                parts.push('×');
            } else {
                parts.push(tok);
            }
        }
        return parts.join(' ');
    },

    formatNumberForInput : function(num) {
        var rounded = Math.round(num * 100) / 100;
        return rounded.toFixed(2).replace('.', ',');
    },

    formatAmountEuro : function(num) {
        return this.formatNumberForInput(num) + ' €';
    },

    updateCalcDisplay : function() {
        var box = $(this.modalSelector + ' .sumup-calc-expression');
        if (!box.length) {
            return;
        }

        var current = this.sanitizeAmountInput($(this.modalSelector + ' .sumup-free-amount-input').val());

        if (this.calcTokens.length > 0) {
            var previewTokens = this.calcTokens.slice();
            var displayText = this.formatTokensForDisplay(this.calcTokens);
            if (current !== '' && current !== ',') {
                previewTokens.push(current);
                displayText = this.formatTokensForDisplay(previewTokens);
            } else if (this.isOperator(previewTokens[previewTokens.length - 1])) {
                previewTokens.pop();
            }

            var previewTotal = this.evaluateTokens(previewTokens);
            if (previewTotal !== null && previewTokens.length >= 1) {
                displayText += ' = ' + this.formatAmountEuro(previewTotal);
            }
            box.text(displayText);
            return;
        }

        if (this.calcLastExpression !== '') {
            box.text(this.calcLastExpression);
            return;
        }

        box.html('&nbsp;');
    },

    sanitizeAmountInput : function(raw) {
        var str = String(raw || '').replace(/\./g, ',').replace(/[^0-9,]/g, '');
        var firstComma = str.indexOf(',');
        if (firstComma !== -1) {
            var intPart = str.slice(0, firstComma).slice(0, 4);
            var decPart = str.slice(firstComma + 1).replace(/,/g, '').slice(0, 2);
            if (intPart === '') {
                intPart = '0';
            }
            return intPart + ',' + decPart;
        }
        return str.slice(0, 4);
    },

    showValidationError : function(msg) {
        var box = $(this.modalSelector + ' .sumup-free-amount-validation-error');
        box.text(msg).show();
    },

    hideValidationError : function() {
        $(this.modalSelector + ' .sumup-free-amount-validation-error').hide().text('');
    },

    submitAmount : function() {
        // Automatically finalize any open calculation before sending to terminal
        if (this.calcTokens.length > 0) {
            this.handleCalcEquals();
            if ($(this.modalSelector + ' .sumup-free-amount-validation-error').is(':visible')) {
                return;
            }
        }

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
                self.resetCalculatorState();
                self.renderInputView();
            }
        });
        $(modalSelector + ' .btn-sumup-free-change').on('click', function() {
            self.resetCalculatorState();
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
        this.resetCalculatorState();
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
