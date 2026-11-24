<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @license       https://opensource.org/licenses/AGPL-3.0
 */

namespace App\Services\SumUp;

class SumUpApiException extends \RuntimeException
{
    private int $httpStatusCode;

    private string $apiErrorCode;

    public function __construct(
        string $message,
        int $httpStatusCode = 0,
        string $apiErrorCode = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatusCode, $previous);
        $this->httpStatusCode = $httpStatusCode;
        $this->apiErrorCode = $apiErrorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getApiErrorCode(): string
    {
        return $this->apiErrorCode;
    }
}
