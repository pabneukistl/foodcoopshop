<?php
declare(strict_types=1);

use App\Model\Entity\Cart;
use App\Services\SumUp\SumUpService;
use Cake\Core\Configure;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 4.1.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */

if (!Configure::read('app.selfServiceEasyModeEnabled')) {
    $this->element('addScript', ['script' => Configure::read('app.jsNamespace').".Cart.initCartFinish();"]);
    return;
}

$title = '';
$html = '';
$dialogButtons = [];
$selfServicePaymentTypes = Configure::read('app.selfServicePaymentTypes');

if (empty($selfServicePaymentTypes) || !$identity->isSelfServiceCustomer()) {
    $title = __('Confirm_self_service_purchase_dialog') .'?';
    $html = '<p>' . __('Confirm_self_service_purchase') . '</p>';
    $dialogButtons[] = [
        'classes' => 'btn-success',
        'title' => __('Confirm_self_service_purchase_button'),
        'faIcon' => 'fa-fw fas fa-check',
        'isCloseButton' => null
    ];
} else {
    $title = __('Choose_paymenttype_for_self_service_purchase_dialog');
    $html = '<p>' . __('Confirm_self_service_purchase_with_paymenttypes') . '</p>';
    $sumUpEnabled = (new SumUpService())->isEnabled();
    foreach($selfServicePaymentTypes as $selfServicePaymentType) {
        $paymentLabelLower = mb_strtolower((string) ($selfServicePaymentType['payment_type'] ?? ''));
        $isCard = !empty($selfServicePaymentType['is_sumup'])
            || !empty($selfServicePaymentType['is_card'])
            || str_contains($paymentLabelLower, 'karte')
            || str_contains($paymentLabelLower, 'bankomat')
            || str_contains($paymentLabelLower, 'card');
        $paymentTypeCode = $isCard ? Cart::SELF_SERVICE_PAYMENT_TYPE_CARD : Cart::SELF_SERVICE_PAYMENT_TYPE_CASH;
        $useSumUp = $sumUpEnabled && (
            array_key_exists('is_sumup', $selfServicePaymentType)
                ? (bool) $selfServicePaymentType['is_sumup']
                : $isCard
        );
        $dialogButtons[] = [
            'classes' => 'btn-success no-auto-bind',
            'title' => $selfServicePaymentType['payment_type'],
            'faIcon' => $isCard ? 'fa-fw fas fa-credit-card' : 'fa-fw fas fa-check',
            'isCloseButton' => true,
            'value' => $selfServicePaymentType['payment_text'],
            'paymentTypeCode' => $paymentTypeCode,
            'isSumUp' => $useSumUp,
        ];
    }
}

$dialogButtons[] = [
    'classes' => 'btn-outline-light',
    'title' => __('Deny_self_service_purchase_button'),
    'faIcon' => null,
    'isCloseButton' => true
];

$this->element('addScript', ['script' => Configure::read('app.jsNamespace').".ModalSelfServiceConfirmDialog.init('$title', '$html', '".json_encode($dialogButtons)."');" ]);
