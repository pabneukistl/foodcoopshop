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
foodcoopshop.ModalSelfServiceConfirmDialog = {

    modalSelector: '#self-service-confirm-dialog',

    init : function(title, html, dialogbuttons) {

        var modalSelector = this.modalSelector;
        var buttons = [];
        dialogbuttons = $.parseJSON(dialogbuttons);

        if (dialogbuttons.length == 0) {
            return;
        }

        for (var i = 0; i < dialogbuttons.length; i++) {
            buttons[i] = [foodcoopshop.Modal.createButton([dialogbuttons[i].classes], dialogbuttons[i].title, dialogbuttons[i].faIcon, dialogbuttons[i].isCloseButton, [dialogbuttons[i].value])];
        }

        foodcoopshop.Modal.appendModalToDom(
            modalSelector,
            title,
            html,
            buttons
        );

        // Attach structured payment metadata to each payment type button
        var paymentButtons = $(modalSelector + ' button.btn-success.no-auto-bind');
        var paymentConfigs = dialogbuttons.filter(function(btn) {
            return btn.classes && btn.classes.indexOf('no-auto-bind') !== -1;
        });
        paymentButtons.each(function(index) {
            var cfg = paymentConfigs[index] || {};
            $(this).data('payment-type-code', cfg.paymentTypeCode || '');
            $(this).data('is-sumup', Boolean(cfg.isSumUp));
        });

        foodcoopshop.Modal.bindSuccessButton(modalSelector, function() {
            foodcoopshop.ModalSelfServiceConfirmDialog.getSuccessHandler();
        });

        $('button.btn-order-self-service').on('click', function () {
            foodcoopshop.ModalSelfServiceConfirmDialog.getOpenHandler(modalSelector);
        });

        paymentButtons.on('click', function () {
            var btn = $(this);
            foodcoopshop.ModalSelfServicePaymenttypeDetailsDialog.getOpenHandler(
                '#self-service-confirm-dialog-paymenttype-details',
                btn.text().trim(),
                btn.attr('value') || '',
                {
                    paymentTypeCode: btn.data('payment-type-code'),
                    isSumUp: Boolean(btn.data('is-sumup'))
                }
            );
        });

        $(modalSelector + ' button.btn-outline-light').on('click', function () {
            foodcoopshop.ModalSelfServiceConfirmDialog.getCloseHandler(modalSelector);
        });

        $(modalSelector + ' button.btn-close').on('click', function () {
            foodcoopshop.ModalSelfServiceConfirmDialog.getCloseHandler(modalSelector);
        });
    },

    getSuccessHandler : function() {
        var selfSForm = $('#SelfServiceForm');
        selfSForm.submit();
    },

    getOpenHandler : function(modalSelector) {
        var selector = modalSelector || this.modalSelector;
        var el = document.getElementById(selector.replace(/#/, ''));
        if (el) {
            bootstrap.Modal.getOrCreateInstance(el).show();
        }
    },

    getCloseHandler : function(modalSelector) {
        foodcoopshop.SelfService.setFocusToSearchInputField();
    },

};
