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

    isSumUp: false,

    paymentTypeId: null,

    pollCount: 0,

    maxPollCount: 150, // approx 5 minutes

    getSuccessHandler : function() {
        if (this.paymentTypeId) {
            $('#self-service-payment-type').val(this.paymentTypeId);
        }
        if (this.isSumUp) {
            this.handleSumUpPayment();
        } else {
            var selfSForm = $('#SelfServiceForm');
            selfSForm.submit();
        }
    },

    getOpenHandler : function(modalSelector, paymentName, paymentText, isSumUp, paymentTypeId) {
        this.isSumUp = isSumUp;
        this.paymentTypeId = paymentTypeId;
        var modalSelector = '#self-service-confirm-dialog-paymenttype-details';
        var buttons = [
            foodcoopshop.Modal.createButton(['btn-success'], foodcoopshop.LocalizedJs.cart.selfServiceConfirmPurchaseCompletedButton, 'fa-fw fas fa-check'),
            foodcoopshop.Modal.createButton(['btn-outline-light'], foodcoopshop.LocalizedJs.cart.selfServiceDenyPurchaseButton, null, true)
        ];

        foodcoopshop.Modal.appendModalToDom(
            modalSelector,
            '',
            '',
            buttons
        );

        var amount = $('p.total-sum-wrapper > span.sum').html();
        $(modalSelector + ' .modal-title').text(paymentName);
        $(modalSelector + ' .modal-body').html('<p>' + foodcoopshop.LocalizedJs.cart.selfServiceAmountToBePaid + ': <b>' + amount + '</b><br /><br />' + paymentText + '</p>');

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

    handleSumUpPayment: function() {
        var modalSelector = '#self-service-confirm-dialog-paymenttype-details';
        this.pollCount = 0;

        var successButton = foodcoopshop.Modal.getSuccessButton(modalSelector);

        $(modalSelector + ' .modal-body').append('<p class="sumup-status">' + foodcoopshop.LocalizedJs.cart.selfServiceSumUpWaitingForPayment + ' <i class="fas fa-circle-notch fa-spin"></i></p>');
        foodcoopshop.Helper.disableButton($(modalSelector + ' .btn-outline-light'));

        foodcoopshop.Helper.ajaxCall(
            '/' + foodcoopshop.LocalizedJs.helper.routeSelfService + '/sumUpCreateCheckout',
            {},
            {
                onOk: function (data) {
                    if (data.id) {
                        foodcoopshop.ModalSelfServicePaymenttypeDetailsDialog.pollSumUpStatus(data.id);
                    } else {
                        foodcoopshop.ModalSelfServicePaymenttypeDetailsDialog.handleSumUpError(data.message || 'Error creating checkout');
                    }
                },
                onError: function (data) {
                    foodcoopshop.ModalSelfServicePaymenttypeDetailsDialog.handleSumUpError(data.msg);
                }
            }
        );
    },

    pollSumUpStatus: function(checkoutId) {
        var modalSelector = '#self-service-confirm-dialog-paymenttype-details';
        this.pollCount++;

        if (this.pollCount > this.maxPollCount) {
            this.handleSumUpError('Timeout waiting for payment');
            return;
        }

        setTimeout(function() {
            foodcoopshop.Helper.ajaxCall(
                '/' + foodcoopshop.LocalizedJs.helper.routeSelfService + '/sumUpGetCheckoutStatus?id=' + checkoutId,
                {},
                {
                    method: 'GET',
                    onOk: function (data) {
                        if (data.status === 'PAID') {
                            $(modalSelector + ' .sumup-status').html(foodcoopshop.LocalizedJs.cart.selfServiceSumUpPaymentSuccessful);
                            var selfSForm = $('#SelfServiceForm');
                            selfSForm.submit();
                        } else if (data.status === 'FAILED' || data.status === 'CANCELLED' || data.status === 'ERROR') {
                             foodcoopshop.ModalSelfServicePaymenttypeDetailsDialog.handleSumUpError(data.status + (data.message ? ': ' + data.message : ''));
                        } else {
                            foodcoopshop.ModalSelfServicePaymenttypeDetailsDialog.pollSumUpStatus(checkoutId);
                        }
                    },
                    onError: function (data) {
                         foodcoopshop.ModalSelfServicePaymenttypeDetailsDialog.handleSumUpError(data.msg);
                    }
                }
            );
        }, 2000);
    },

    handleSumUpError: function(message) {
        var modalSelector = '#self-service-confirm-dialog-paymenttype-details';
        $(modalSelector + ' .sumup-status').remove();
        foodcoopshop.Helper.showErrorMessage(message);
        var successButton = foodcoopshop.Modal.getSuccessButton(modalSelector);
        foodcoopshop.Helper.removeSpinnerFromButton(successButton, 'fa-check');
        foodcoopshop.Helper.enableButton(successButton);
        foodcoopshop.Helper.enableButton($(modalSelector + ' .btn-outline-light'));
    },

    getCloseHandler : function(modalSelector) {
        foodcoopshop.Modal.destroy($('#self-service-confirm-dialog-paymenttype-details'));
        foodcoopshop.SelfService.setFocusToSearchInputField();
    },
    
};
