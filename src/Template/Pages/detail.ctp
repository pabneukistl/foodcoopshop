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

$this->element('addScript', ['script' =>
    Configure::read('AppConfig.jsNamespace').".Helper.init();"
]);

if ($page['Page']['full_width']) {
    echo $this->Html->css('page-full-width');
}

echo '<h1>'.$page['Page']['title'].'</h1>';

if (!empty($page['children'])) {
    foreach ($page['children'] as $childPage) {
        echo '<p>'.$this->Html->link(
            $childPage['Page']['title'],
            $this->Slug->getPageDetail($childPage['Page']['id_page'], $childPage['Page']['title']),
            [
                'class' => 'btn btn-success'
            ]
        ).'</p>';
    }
}

if ($appAuth->isSuperadmin() || $appAuth->isAdmin()) {
    echo $this->Html->getJqueryUiIcon(
        $this->Html->image($this->Html->getFamFamFamPath('page_edit.png')),
        [
            'title' => 'Bearbeiten'
        ],
        $this->Slug->getPageEdit($page['Page']['id_page'])
    );
}

echo $page['Page']['content'];
