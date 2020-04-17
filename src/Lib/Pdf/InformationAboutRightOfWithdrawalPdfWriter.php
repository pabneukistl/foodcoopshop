<?php
/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 3.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 * @author        Mario Rothauer <office@foodcoopshop.com>
 * @copyright     Copyright (c) Mario Rothauer, https://www.rothauer-it.com
 * @link          https://www.foodcoopshop.com
 */
namespace App\Lib\Pdf;

use Cake\Core\Configure;
use Cake\I18n\I18n;

class InformationAboutRightOfWithdrawalPdfWriter extends PdfWriter
{
    
    public function __construct()
    {
        $this->setPdfLibrary(new ListTcpdf());
    }
    
    public function getFilename()
    {
        return __('Filename_Information-about-right-of-withdrawal').'.pdf';
    }
    
    public function getTemplate()
    {
        return 'pdf' . DS . 'generate_right_of_withdrawal_information_and_form';
    }
    
}

