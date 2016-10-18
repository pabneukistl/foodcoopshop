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
	<?php echo '<img id="installation-logo" src="/files/images/logo.jpg" />'; ?>
<?php } else { ?>
	<h2><?php echo $message; ?></h2>
<?php } ?>
<h2><?php echo __d('cake', 'An Internal Error Has Occurred.'); ?></h2>
<?php
if (Configure::read('debug') > 0):
	echo $this->element('exception_stack_trace');
endif;
?>
