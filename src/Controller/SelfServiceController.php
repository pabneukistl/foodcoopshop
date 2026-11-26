<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Cart;
use App\Model\Entity\Customer;
use App\Services\CatalogService;
use App\Services\SumUp\SumUpApiException;
use App\Services\SumUp\SumUpService;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\Routing\Router;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 2.5.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */
class SelfServiceController extends FrontendController
{

    protected ?SumUpService $sumUpService = null;

    protected function getSumUpService(): SumUpService
    {
        if ($this->sumUpService === null) {
            $this->sumUpService = new SumUpService();
        }
        return $this->sumUpService;
    }

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated([
            'index',
            'autoLoginAsSelfServiceCustomer',
            'checkBarcodeType',
            'sumUpStartFreeAmount',
            'sumUpCheckStatus',
            'sumUpCancelCheckout',
            'sumUpWebhook',
        ]);
    }

    public function autoLoginAsSelfServiceCustomer(): ?Response
    {

        $this->disableAutoRender();

        $id = $this->getRequest()->getParam('id');
        if (empty($id)) {
            $pass = $this->getRequest()->getParam('pass');
            $id = $pass[0] ?? 0;
        }
        $id = (int)$id;

        $selfServiceLoginCustomer = array_filter(Configure::read('app.selfServiceLoginCustomers'), function($selfServiceLoginCustomer) use ($id) {
            return $selfServiceLoginCustomer['id'] == $id;
        });
        if (empty($selfServiceLoginCustomer)) {
            $this->Flash->error(__('Signing_in_failed.'));
            return $this->redirect(Configure::read('app.slugHelper')->getHome());
        }

        $customerId =   reset($selfServiceLoginCustomer)['customerId'];
        $customerTable = $this->getTableLocator()->get('Customers');
        $customer = $customerTable->find('auth',
            conditions: [
                $customerTable->aliasField('id_customer') => $customerId,
                $customerTable->aliasField('id_default_group') => Customer::GROUP_SELF_SERVICE_CUSTOMER,
            ],
        )->first();
        if (!empty($customer)) {
            $this->Authentication->setIdentity($customer);
            Router::setRequest($this->getRequest());
        } else {
            $this->Flash->error(__('Signing_in_failed.'));
            return $this->redirect(Configure::read('app.slugHelper')->getHome());
        }

        return $this->redirect(Configure::read('app.slugHelper')->getSelfService());
    }

    public function index(): ?Response
    {
        $page = (int) $this->getRequest()->getQuery('page', 1);

        $categoryId = h($this->getRequest()->getQuery('categoryId', 0));
        $this->set('categoryId', $categoryId);

        $keyword = h(trim($this->getRequest()->getQuery('keyword', '')));

        if ($keyword !== '') {
            $customersTable = $this->getTableLocator()->get('Customers');
            try {
                if (method_exists($customersTable, 'getBarcodeFieldString')) {
                    $barcodeFieldExpr = 'UPPER(' . $customersTable->getBarcodeFieldString() . ')';
                    $keywordUpper = strtoupper($keyword);

                    $customerCard = $customersTable->find()
                        ->select([$customersTable->aliasField('id_customer')])
                        ->where([
                            $barcodeFieldExpr . ' = ' => $keywordUpper,
                            $customersTable->aliasField('active') => APP_ON,
                        ])
                        ->limit(1)
                        ->first();

                    if ($customerCard !== null) {
                        return $this->redirect(Configure::read('app.slugHelper')->getSelfService());
                    }
                }
            } catch (\Throwable $e) {
                // defensive: ignore error so normal search continues
            }
        }

        $this->set('keyword', $keyword);

        if (!empty($this->getRequest()->getQuery('productWithError'))) {
            $keyword = h(trim($this->getRequest()->getQuery('productWithError')));
        }

        $categoriesTable = $this->getTableLocator()->get('Categories');
        $categoriesForSelect = $categoriesTable->getForSelect(null, false, false, false);

        $catalogService = new CatalogService();
        $allProductsCount = $catalogService->getProducts(Configure::read('app.categoryAllProducts'), false, '', 0, true, Configure::read('app.selfServiceModeShowOnlyStockProducts'));
        $categoriesForSelect = [
            Configure::read('app.categoryAllProducts') => __('All_products') . ' (' . $allProductsCount . ')',
        ] + $categoriesForSelect;
        $this->set('categoriesForSelect', $categoriesForSelect);

        $categoryIdForSearch = $categoryId;
        if ($categoryId == 0 && $keyword != '') {
            $categoryIdForSearch = Configure::read('app.categoryAllProducts');
        }
        $products = $catalogService->getProducts($categoryIdForSearch, false, $keyword, 0, false, Configure::read('app.selfServiceModeShowOnlyStockProducts'), page: $page);
        $totalProductCount = $catalogService->getProducts($categoryIdForSearch, false, $keyword, 0, true, Configure::read('app.selfServiceModeShowOnlyStockProducts'));
        $pagesCount = $catalogService->getPagesCount($totalProductCount);
        $products = $catalogService->prepareProducts($products);

        $this->set('totalProductCount', $totalProductCount);
        $this->set('products', $products);
        $this->set('pagesCount', $pagesCount);
        $this->set('page', $page);

        $this->set('products', $products);

        $this->viewBuilder()->setLayout('self_service');
        $this->set('title_for_layout', __('Self_service_mode'));

        if (!empty($this->getRequest()->getQuery('keyword')) && count($products) == 1) {

            $hashedProductId = strtolower(substr($keyword, 0, 4));
            $attributeId = (int) substr($keyword, 4, 4);

            $customBarcodeFound = false;
            $customBarcodeWithWeightFound = false;
            if (!empty($products[0]->barcode_product) && $keyword == $products[0]->barcode_product->barcode) {
                $customBarcodeFound = true;
                $attributeId = 0;
            } else {
                if (!empty($products[0]->barcode_product)) {
                    if ($catalogService->hasABarcodeWeightPrefix($keyword)){
                        if ($catalogService->getBarcodeWeightFilledWithNull($keyword) == $products[0]->barcode_product->barcode) {
                            $customBarcodeWithWeightFound = true;
                            $attributeId = 0;
                        }
                    }
                }
            }

            if (!empty($products[0]->product_attributes)) {
                foreach($products[0]->product_attributes as $productAttribute) {
                    if ($productAttribute->barcode_product_attribute) {
                        if ($keyword == $productAttribute->barcode_product_attribute->barcode) {
                            $customBarcodeFound = true;
                            $attributeId = $productAttribute->id_product_attribute;
                            break;
                        } else {
                            if ($catalogService->hasABarcodeWeightPrefix($keyword)){
                                if ($catalogService->getBarcodeWeightFilledWithNull($keyword) == $productAttribute->barcode_product_attribute->barcode) {
                                    $customBarcodeWithWeightFound = true;
                                    $attributeId = $productAttribute->id_product_attribute;
                                    break;
                                }
                            }
                        }
                    }
                }
            }

            if ($hashedProductId == $products[0]->system_bar_code || $customBarcodeFound || $customBarcodeWithWeightFound) {
                $cartProductsTable = $this->getTableLocator()->get('CartProducts');
                $quantityInUnits = -1;
                if ($customBarcodeWithWeightFound){
                    $quantityInUnits = $catalogService->getBarcodeWeight($keyword);
                }
                $result = $cartProductsTable->add($products[0]->id_product, $attributeId, 1, $quantityInUnits);

                if (!empty($result['msg'])) {
                    $this->Flash->error($result['msg']);
                    $this->request->getSession()->write('highlightedProductId', $products[0]->id_product); // sic! no attributeId needed!
                    $redirectUrl = Configure::read('app.slugHelper')->getSelfService('', $keyword);
                } else {
                    $imgString = '';
                    $imageId = !empty($products[0]->Image) ? $products[0]->Image->id_image : 0;
                    $imgSrc = Configure::read('app.htmlHelper')->getProductImageSrc($imageId, 'home');
                    if ($imgSrc != '') {
                        $imgString .= '<br /><img src="'.$imgSrc.'" />';
                    }
                    $this->Flash->success(__('The_product_{0}_was_added_to_your_cart.', [
                        '<b>' . $products[0]->name . '</b>'
                    ]) . $imgString);
                    $redirectUrl = Configure::read('app.slugHelper')->getSelfService();
                }
                return $this->redirect($redirectUrl);
            }
        }

        if ($this->getRequest()->getEnv('ORIGINAL_REQUEST_METHOD') == 'GET') {
            $cart = $this->identity->getCart();
            $this->set('cart', $cart['Cart']);
        }

        if ($this->getRequest()->getEnv('ORIGINAL_REQUEST_METHOD') == 'POST') {

            if ($this->identity->isCartEmpty()) {
                $this->Flash->error(__('Your_shopping_bag_was_empty.'));
                return $this->redirect(Configure::read('app.slugHelper')->getSelfService());
            }

            $selectedPaymentType = (int) $this->getRequest()->getData('self_service_payment_type', 0);
            $submittedSumUpTxId = trim((string) $this->getRequest()->getData('sumup_transaction_id', ''));
            $isSumUpCheckout = $this->getSumUpService()->isEnabled()
                && ($selectedPaymentType === Cart::SELF_SERVICE_PAYMENT_TYPE_CARD || $submittedSumUpTxId !== '');

            if ($isSumUpCheckout) {
                $verified = $this->request->getSession()->read('sumup_verified_payment');
                $expectedAmountCents = (int) round($this->identity->getProductAndDepositSum() * 100);
                $isValidVerification = is_array($verified)
                    && $submittedSumUpTxId !== ''
                    && ($verified['client_transaction_id'] ?? '') === $submittedSumUpTxId
                    && (int) ($verified['cart_id'] ?? 0) === $this->identity->getCartId()
                    && abs((int) ($verified['amount_cents'] ?? 0) - $expectedAmountCents) <= 1;

                if (!$isValidVerification) {
                    $this->Flash->error(__('Card_payment_not_confirmed_please_try_again.'));
                    return $this->redirect(Configure::read('app.slugHelper')->getSelfService());
                }
            }

            $cart = $this->cartService->finish();

            if (empty($this->viewBuilder()->getVars()['cartErrors']) && empty($this->viewBuilder()->getVars()['formErrors'])) {

                $this->request->getSession()->delete('sumup_pending_payment');
                $this->request->getSession()->delete('sumup_verified_payment');

                $redirectUrl = Configure::read('app.slugHelper')->getSelfService();

                if (isset($cart['invoice_id'])) {
                    $invoiceId = $cart['invoice_id'];
                    if (Configure::read('appDb.FCS_HELLO_CASH_API_ENABLED')) {
                        $invoiceRoute = Configure::read('app.slugHelper')->getHelloCashReceipt($invoiceId);
                    } else {
                        $invoicesTable = $this->getTableLocator()->get('Invoices');
                        $invoice = $invoicesTable->find('all', conditions: [
                            'Invoices.id' => $invoiceId,
                        ])->first();
                        if (!empty($invoice)) {
                            $invoiceRoute = Configure::read('app.slugHelper')->getInvoiceDownloadRoute($invoice->filename);
                        }
                    }

                    if (!$this->identity->invoices_per_email_enabled && isset($invoiceRoute)) {
                        $this->request->getSession()->write('invoiceRouteForAutoPrint', $invoiceRoute);
                    }
                }

                $this->resetOriginalLoggedCustomer();
                $this->destroyOrderCustomer();

                return $this->redirect($redirectUrl);

            }

        }

        return null;

    }

    public function checkBarcodeType(): void
    {
        $this->request->allowMethod(['get']);
        $barcode = (string)$this->getRequest()->getQuery('barcode', '');
        $type = 'product';

        if ($barcode !== '') {
            $customersTable = $this->getTableLocator()->get('Customers');

            try {
                if (method_exists($customersTable, 'getBarcodeFieldString')) {
                    $barcodeFieldExpr = $customersTable->getBarcodeFieldString();

                    $customer = $customersTable->find()
                        ->select(['id_customer'])
                        ->where([
                            $barcodeFieldExpr . ' =' => $barcode,
                            $customersTable->aliasField('active') => APP_ON,
                        ])
                        ->limit(1)
                        ->first();

                    if ($customer !== null) {
                        $type = 'customer';
                    }
                }
            } catch (\Throwable $e) {
                // defensive: default to product
            }
        }

        $this->set(compact('type'));
        $this->viewBuilder()->setClassName('Json');
        $this->viewBuilder()->setOption('serialize', ['type']);
    }

    public function sumUpStartCheckout(): void
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $sumUpService = $this->getSumUpService();
        if (!$sumUpService->isEnabled()) {
            $this->set([
                'status' => 0,
                'msg' => __('SumUp_integration_is_not_enabled.'),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
            return;
        }

        if ($this->identity === null) {
            $this->set([
                'status' => 0,
                'msg' => __('Your_shopping_bag_was_empty.'),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
            return;
        }

        $cartsTable = $this->getTableLocator()->get('Carts');
        $selfServiceCart = $cartsTable->getCart($this->identity, Cart::TYPE_SELF_SERVICE);
        if (empty($selfServiceCart['CartProducts'])) {
            $this->set([
                'status' => 0,
                'msg' => __('Your_shopping_bag_was_empty.'),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
            return;
        }

        $totalSum = (float) ($selfServiceCart['CartProductSum'] ?? 0) + (float) ($selfServiceCart['CartDepositSum'] ?? 0);
        $amountInCents = (int) round($totalSum * 100);
        if ($amountInCents <= 0) {
            $this->set([
                'status' => 0,
                'msg' => __('Payment_amount_must_be_greater_than_zero.'),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
            return;
        }

        $cartId = (int) $selfServiceCart['Cart']->id_cart;
        $foreignTransactionId = 'fcs-' . $cartId . '-' . bin2hex(random_bytes(6));
        $description = Configure::read('appDb.FCS_APP_NAME') . ' #' . $cartId;

        $returnUrl = null;
        if ((bool) $sumUpService->getConfig('webhookEnabled', false)) {
            $baseUrl = rtrim((string) Configure::read('App.fullBaseUrl'), '/');
            $secret = trim((string) $sumUpService->getConfig('webhookSecret', ''));
            $returnUrl = $baseUrl . '/' . __('route_self_service') . '/sumup/webhook';
            if ($secret !== '') {
                $returnUrl .= '?secret=' . rawurlencode($secret);
            }
        }

        try {
            $result = $sumUpService->createReaderCheckout(
                $amountInCents,
                $foreignTransactionId,
                $description,
                $returnUrl,
            );

            $clientTransactionId = $result['client_transaction_id'];
            $session = $this->request->getSession();
            $session->delete('sumup_verified_payment');
            $session->write('sumup_pending_payment', [
                'client_transaction_id' => $clientTransactionId,
                'foreign_transaction_id' => $foreignTransactionId,
                'cart_id' => $cartId,
                'amount_cents' => $amountInCents,
                'started_at' => time(),
            ]);

            $this->set([
                'status' => 1,
                'transactionId' => $clientTransactionId,
                'amountCents' => $amountInCents,
                'amountFormatted' => Configure::read('app.numberHelper')->formatAsCurrency($totalSum),
                'pollIntervalMs' => max(1000, (int) $sumUpService->getConfig('pollIntervalMs', 2000)),
                'timeoutSeconds' => max(30, (int) $sumUpService->getConfig('timeoutSeconds', 120)),
            ]);
            $this->viewBuilder()->setOption('serialize', [
                'status',
                'transactionId',
                'amountCents',
                'amountFormatted',
                'pollIntervalMs',
                'timeoutSeconds',
            ]);
        } catch (SumUpApiException $e) {
            $this->set([
                'status' => 0,
                'msg' => $e->getMessage(),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
        }
    }

    /**
     * Initiates a standalone free-amount SumUp Solo payment (e.g. cash-bag / Barsäckchen)
     * without creating a FoodCoopShop order or invoice.
     */
    public function sumUpStartFreeAmount(): void
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $sumUpService = $this->getSumUpService();
        if (!$sumUpService->isEnabled()) {
            $this->set([
                'status' => 0,
                'msg' => __('SumUp_integration_is_not_enabled.'),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
            return;
        }

        $rawAmount = trim((string) $this->getRequest()->getData('amount', ''));
        $normalizedAmount = str_replace(['€', ' ', "\u{00A0}"], '', $rawAmount);
        // Handle German decimal comma (e.g. "12,50" -> "12.50")
        if (str_contains($normalizedAmount, ',') && str_contains($normalizedAmount, '.')) {
            $normalizedAmount = str_replace('.', '', $normalizedAmount);
        }
        $normalizedAmount = str_replace(',', '.', $normalizedAmount);

        if ($normalizedAmount === '' || !is_numeric($normalizedAmount)) {
            $this->set([
                'status' => 0,
                'msg' => __('SumUp_free_amount_invalid.'),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
            return;
        }

        $amountFloat = (float) $normalizedAmount;
        $amountInCents = (int) round($amountFloat * 100);
        if ($amountInCents < 100) {
            $this->set([
                'status' => 0,
                'msg' => __('SumUp_free_amount_min_error.'),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
            return;
        }

        if ($amountInCents > 50000) {
            $this->set([
                'status' => 0,
                'msg' => __('SumUp_free_amount_max_error.'),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
            return;
        }

        $foreignTransactionId = 'fcs-free-' . bin2hex(random_bytes(6));
        $description = Configure::read('appDb.FCS_APP_NAME') . ' - ' . __('SumUp_free_amount_description');

        $returnUrl = null;
        if ((bool) $sumUpService->getConfig('webhookEnabled', false)) {
            $baseUrl = rtrim((string) Configure::read('App.fullBaseUrl'), '/');
            $secret = trim((string) $sumUpService->getConfig('webhookSecret', ''));
            $returnUrl = $baseUrl . '/' . __('route_self_service') . '/sumup/webhook';
            if ($secret !== '') {
                $returnUrl .= '?secret=' . rawurlencode($secret);
            }
        }

        try {
            $result = $sumUpService->createReaderCheckout(
                $amountInCents,
                $foreignTransactionId,
                $description,
                $returnUrl,
            );

            $clientTransactionId = $result['client_transaction_id'];
            $session = $this->request->getSession();
            $session->delete('sumup_verified_payment');
            $session->write('sumup_pending_payment', [
                'client_transaction_id' => $clientTransactionId,
                'foreign_transaction_id' => $foreignTransactionId,
                'cart_id' => 0,
                'is_free_amount' => true,
                'amount_cents' => $amountInCents,
                'started_at' => time(),
            ]);

            $this->set([
                'status' => 1,
                'transactionId' => $clientTransactionId,
                'amountCents' => $amountInCents,
                'amountFormatted' => Configure::read('app.numberHelper')->formatAsCurrency($amountInCents / 100),
                'pollIntervalMs' => max(1000, (int) $sumUpService->getConfig('pollIntervalMs', 2000)),
                'timeoutSeconds' => max(30, (int) $sumUpService->getConfig('timeoutSeconds', 120)),
            ]);
            $this->viewBuilder()->setOption('serialize', [
                'status',
                'transactionId',
                'amountCents',
                'amountFormatted',
                'pollIntervalMs',
                'timeoutSeconds',
            ]);
        } catch (SumUpApiException $e) {
            $this->set([
                'status' => 0,
                'msg' => $e->getMessage(),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
        }
    }

    public function sumUpCheckStatus(): void
    {
        $this->request->allowMethod(['get']);
        $this->viewBuilder()->setClassName('Json');

        $session = $this->request->getSession();
        $pending = $session->read('sumup_pending_payment');
        $requestedTxId = trim((string) $this->getRequest()->getQuery('transactionId', ''));
        $txId = $requestedTxId !== '' ? $requestedTxId : (string) ($pending['client_transaction_id'] ?? '');

        if (!is_array($pending) || $txId === '' || ($pending['client_transaction_id'] ?? '') !== $txId) {
            $this->set([
                'status' => 0,
                'msg' => __('No_active_card_payment_found.'),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
            return;
        }

        $sumUpService = $this->getSumUpService();

        try {
            $webhookHint = Cache::read('sumup_webhook_' . md5($txId));
            $result = $sumUpService->getTransactionStatus($txId, true);

            // If API transaction is still pending/not yet indexed, check if webhook already reported terminal failure/cancellation
            if (
                $result['status'] === SumUpService::STATUS_PENDING
                && is_array($webhookHint)
                && in_array($webhookHint['status'] ?? '', [SumUpService::STATUS_FAILED, SumUpService::STATUS_CANCELLED], true)
            ) {
                $result['status'] = $webhookHint['status'];
                $result['message'] = $webhookHint['status'] === SumUpService::STATUS_CANCELLED
                    ? __('SumUp_payment_cancelled.')
                    : __('SumUp_payment_failed.');
            }

            if ($result['status'] === SumUpService::STATUS_SUCCESSFUL) {
                if ($result['amount'] !== null) {
                    $paidCents = (int) round($result['amount'] * 100);
                    if (abs($paidCents - (int) $pending['amount_cents']) > 1) {
                        $session->delete('sumup_pending_payment');
                        $session->delete('sumup_verified_payment');
                        $this->set([
                            'status' => 0,
                            'paymentStatus' => SumUpService::STATUS_FAILED,
                            'msg' => __('SumUp_amount_mismatch.'),
                        ]);
                        $this->viewBuilder()->setOption('serialize', ['status', 'paymentStatus', 'msg']);
                        return;
                    }
                }

                if (empty($pending['is_free_amount'])) {
                    $session->write('sumup_verified_payment', [
                        'client_transaction_id' => $txId,
                        'cart_id' => (int) $pending['cart_id'],
                        'amount_cents' => (int) $pending['amount_cents'],
                        'transaction_code' => $result['transactionCode'],
                        'verified_at' => time(),
                    ]);
                }
                $session->delete('sumup_pending_payment');
                Cache::delete('sumup_webhook_' . md5($txId));
            } elseif (in_array($result['status'], [SumUpService::STATUS_FAILED, SumUpService::STATUS_CANCELLED], true)) {
                $session->delete('sumup_pending_payment');
                $session->delete('sumup_verified_payment');
                Cache::delete('sumup_webhook_' . md5($txId));
            }

            $this->set([
                'status' => 1,
                'paymentStatus' => $result['status'],
                'readerStatus' => $result['readerStatus'],
                'readerState' => $result['readerState'],
                'message' => $result['message'],
            ]);
            $this->viewBuilder()->setOption('serialize', [
                'status',
                'paymentStatus',
                'readerStatus',
                'readerState',
                'message',
            ]);
        } catch (SumUpApiException $e) {
            $this->set([
                'status' => 0,
                'msg' => $e->getMessage(),
            ]);
            $this->viewBuilder()->setOption('serialize', ['status', 'msg']);
        }
    }

    public function sumUpCancelCheckout(): void
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $session = $this->request->getSession();
        $pending = $session->read('sumup_pending_payment');
        $txId = is_array($pending) ? (string) ($pending['client_transaction_id'] ?? '') : '';

        $terminated = false;
        try {
            $sumUpService = $this->getSumUpService();
            if ($sumUpService->isEnabled()) {
                $terminated = $sumUpService->terminateCheckout();
            }
        } catch (SumUpApiException $e) {
            // Still clear local pending session so customer is never trapped in the modal
        }

        $session->delete('sumup_pending_payment');
        $session->delete('sumup_verified_payment');
        if ($txId !== '') {
            Cache::delete('sumup_webhook_' . md5($txId));
        }

        $this->set([
            'status' => 1,
            'terminated' => $terminated,
        ]);
        $this->viewBuilder()->setOption('serialize', ['status', 'terminated']);
    }

    public function sumUpWebhook(): ?Response
    {
        $this->request->allowMethod(['post']);
        $this->viewBuilder()->setClassName('Json');

        $sumUpService = $this->getSumUpService();
        if (!(bool) $sumUpService->getConfig('webhookEnabled', false)) {
            return $this->response->withStatus(404);
        }

        $expectedSecret = trim((string) $sumUpService->getConfig('webhookSecret', ''));
        if ($expectedSecret !== '') {
            $providedSecret = (string) ($this->getRequest()->getQuery('secret')
                ?? $this->getRequest()->getHeaderLine('X-SumUp-Webhook-Secret'));
            if (!hash_equals($expectedSecret, $providedSecret)) {
                return $this->response->withStatus(403);
            }
        }

        $data = $this->getRequest()->getData();
        if (empty($data)) {
            $raw = (string) $this->getRequest()->getBody();
            $decoded = json_decode($raw, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : $data;
        $txId = trim((string) ($payload['client_transaction_id'] ?? ''));
        $rawStatus = (string) ($payload['status'] ?? '');

        if ($txId !== '' && $rawStatus !== '') {
            Cache::write('sumup_webhook_' . md5($txId), [
                'status' => $sumUpService->normalizeTransactionStatus($rawStatus),
                'received_at' => time(),
            ]);
        }

        $this->set(['received' => true]);
        $this->viewBuilder()->setOption('serialize', ['received']);
        return null;
    }

}
