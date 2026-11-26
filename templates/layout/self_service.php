<?php
declare(strict_types=1);

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

use Cake\Core\Configure;
use App\Services\OrderCustomerService;
use App\Services\SumUp\SumUpService;

$sumUpFreeAmountEnabled = (new SumUpService())->isEnabled();

echo $this->element('layout/header');
?>

<div id="content" class="self-service">
    <?php
        // avoid "access denied" message on login page if protected /self-service is requested
        if ($this->request->is('get') && $this->request->getParam('action') == 'login') {
            $this->request->getSession()->delete('Flash');
        }
        echo $this->Flash->render();
        echo $this->Flash->render('auth');
    ?>
    <?php echo $this->fetch('content'); ?>

    <?php if (!OrderCustomerService::isOrderForDifferentCustomerMode()) { ?>
        <div class="footer">
            <div class="left-wrapper">
                <?php
                    $logoutButton = $this->Menu->getAuthMenuElement($identity);
                    if ($identity !== null) { ?>
                        <a class="btn btn-success <?php echo join(' ', $logoutButton['options']['class']); ?>" href="<?php echo $logoutButton['slug']; ?>"><i class="fas fa-fw fa-sign-out-alt"></i><?php echo $logoutButton['name']; ?></a> <span class="user-name-wrapper"><?php echo $identity->name; ?>
                        <?php if (Configure::read('app.selfServiceModeAutoLogoutDesktopEnabled')) { ?>
                             - </span><?php echo str_replace('X', '<span class="auto-logout-timer"></span>', __('Auto_logout_in_X_sec')); ?>
                        <?php } ?>
                <?php } ?>
            </div>
            <?php if ($sumUpFreeAmountEnabled) { ?>
                <div class="center-wrapper">
                    <a class="btn btn-outline-light btn-sm btn-sumup-free-amount" href="javascript:void(0);">
                        <i class="fas fa-fw fa-credit-card"></i> <?php echo __('SumUp_free_amount_button'); ?>
                    </a>
                </div>
            <?php } ?>
            <div class="right-wrapper">
                <?php
                if (!Configure::read('app.selfServiceEasyModeEnabled')){
                ?>
                    <a class="btn btn-success" href="<?php echo $this->Slug->getHome(); ?>">
                        <i class="fas fa-home"></i> <?php echo __('Home'); ?>
                    </a>
                <?php } ?>
                <?php echo $this->element('selfService/addDeposit'); ?>
                <?php
                    echo $this->element('logo', [
                        'isLogoLinked' => false,
                    ]);
                ?>
            </div>
        </div>
    <?php } ?>

</div>

<?php
    if ($sumUpFreeAmountEnabled && !OrderCustomerService::isOrderForDifferentCustomerMode()) {
        $this->element('addScript', [
            'script' => Configure::read('app.jsNamespace') . '.ModalSelfServiceSumUpFreeAmount.init();',
        ]);
    }
    echo $this->element('layout/footer', [
        'mobileInitFunction' => Configure::read('app.jsNamespace').".Mobile.initMenusSelfService();"
    ]);
?>