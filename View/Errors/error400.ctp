<?php
/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 1.0.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, http://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */
?>

<?php if (Configure::read('debug') == 0) { ?>
    <?php echo '<a href="/"><img id="installation-logo" src="/files/images/logo.jpg" /></a>'; ?>
<?php } else { ?>
    <h2><?php echo $message; ?></h2>
<?php } ?>

<h2><?php printf(
    __d('cake', 'The requested address %s was not found on this server.'),
    "<strong>'{$url}'</strong>"
); ?></h2>
</h2>	

<?php
if (Configure::read('debug') > 0) :
    echo $this->element('exception_stack_trace');
endif;
?>
