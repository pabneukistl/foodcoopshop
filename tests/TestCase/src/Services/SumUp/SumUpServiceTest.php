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

use App\Services\SumUp\SumUpApiException;
use App\Services\SumUp\SumUpService;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use PHPUnit\Framework\TestCase;

class SumUpServiceTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function getDefaultConfig(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'apiBaseUrl' => 'https://api.sumup.com',
            'apiKey' => 'sup_sk_secret_api_key_12345',
            'merchantCode' => 'M123456',
            'affiliateKey' => 'sup_afk_secret_aff_98765',
            'affiliateAppId' => 'at.pabneukistl.foodcoopshop',
            'readerId' => 'rdr_solo_001',
            'currency' => 'EUR',
            'pollIntervalMs' => 2000,
            'timeoutSeconds' => 120,
            'webhookEnabled' => false,
            'webhookSecret' => 'whsec_secret_555',
        ], $overrides);
    }

    private function createJsonResponse(int $statusCode, array|string $body): Response
    {
        $json = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

        return new Response([
            'HTTP/1.1 ' . $statusCode . ' OK',
            'Content-Type: application/json',
        ], $json);
    }

    public function testIsEnabledRequiresAllMandatoryFields(): void
    {
        $this->assertTrue((new SumUpService($this->getDefaultConfig()))->isEnabled());
        $this->assertFalse((new SumUpService($this->getDefaultConfig(['enabled' => false])))->isEnabled());
        $this->assertFalse((new SumUpService($this->getDefaultConfig(['apiKey' => ''])))->isEnabled());
        $this->assertFalse((new SumUpService($this->getDefaultConfig(['readerId' => ''])))->isEnabled());
    }

    public function testCreateReaderCheckoutSendsCentsAndAffiliatePayload(): void
    {
        $capturedUrl = '';
        $capturedPayload = [];
        $capturedOptions = [];

        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $url, mixed $data, array $options) use (&$capturedUrl, &$capturedPayload, &$capturedOptions) {
                $capturedUrl = $url;
                $capturedPayload = json_decode((string) $data, true, 512, JSON_THROW_ON_ERROR);
                $capturedOptions = $options;

                return $this->createJsonResponse(201, [
                    'data' => [
                        'client_transaction_id' => 'tx-uuid-12345',
                    ],
                ]);
            });

        $service = new SumUpService($this->getDefaultConfig(), $mockClient);
        $result = $service->createReaderCheckout(1495, 'fcs-42-abc', 'Pabneukistl #42');

        $this->assertSame('tx-uuid-12345', $result['client_transaction_id']);
        $this->assertSame('https://api.sumup.com/v0.1/merchants/M123456/readers/rdr_solo_001/checkout', $capturedUrl);
        $this->assertSame('Bearer sup_sk_secret_api_key_12345', $capturedOptions['headers']['Authorization']);
        $this->assertSame([
            'value' => 1495,
            'currency' => 'EUR',
            'minor_unit' => 2,
        ], $capturedPayload['total_amount']);
        $this->assertSame([
            'key' => 'sup_afk_secret_aff_98765',
            'foreign_transaction_id' => 'fcs-42-abc',
            'app_id' => 'at.pabneukistl.foodcoopshop',
        ], $capturedPayload['affiliate']);
    }

    public function testCreateReaderCheckoutRejectsZeroAmount(): void
    {
        $service = new SumUpService($this->getDefaultConfig(), $this->createStub(Client::class));
        $this->expectException(SumUpApiException::class);
        $service->createReaderCheckout(0, 'fcs-1');
    }

    public function testGetTransactionStatusReturnsSuccessful(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('get')
            ->with(
                'https://api.sumup.com/v2.1/merchants/M123456/transactions?client_transaction_id=tx-ok-1',
                [],
                $this->isArray(),
            )
            ->willReturn($this->createJsonResponse(200, [
                'status' => 'SUCCESSFUL',
                'amount' => 14.95,
                'currency' => 'EUR',
                'transaction_code' => 'T123456',
            ]));

        $service = new SumUpService($this->getDefaultConfig(), $mockClient);
        $status = $service->getTransactionStatus('tx-ok-1');

        $this->assertSame(SumUpService::STATUS_SUCCESSFUL, $status['status']);
        $this->assertSame(14.95, $status['amount']);
        $this->assertSame('T123456', $status['transactionCode']);
    }

    public function testGetTransactionStatusReturnsPendingWithLiveReaderStateWhen404(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponse(404, ['message' => 'Not found']),
                $this->createJsonResponse(200, [
                    'data' => [
                        'status' => 'ONLINE',
                        'state' => 'WAITING_FOR_CARD',
                    ],
                ]),
            );

        $service = new SumUpService($this->getDefaultConfig(), $mockClient);
        $status = $service->getTransactionStatus('tx-pending-1');

        $this->assertSame(SumUpService::STATUS_PENDING, $status['status']);
        $this->assertSame('ONLINE', $status['readerStatus']);
        $this->assertSame('WAITING_FOR_CARD', $status['readerState']);
        $this->assertStringContainsString('Karte', $status['message']);
    }

    public function testGetTransactionStatusMapsFailedAndCancelled(): void
    {
        $service = new SumUpService($this->getDefaultConfig(), $this->createStub(Client::class));
        $this->assertSame(SumUpService::STATUS_SUCCESSFUL, $service->normalizeTransactionStatus('SUCCESSFUL'));
        $this->assertSame(SumUpService::STATUS_FAILED, $service->normalizeTransactionStatus('FAILED'));
        $this->assertSame(SumUpService::STATUS_FAILED, $service->normalizeTransactionStatus('DECLINED'));
        $this->assertSame(SumUpService::STATUS_CANCELLED, $service->normalizeTransactionStatus('CANCELLED'));
        $this->assertSame(SumUpService::STATUS_PENDING, $service->normalizeTransactionStatus('PENDING'));
    }

    public function testTerminateCheckoutSendsPostAndHandlesAlreadyIdle(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->exactly(2))
            ->method('post')
            ->with(
                'https://api.sumup.com/v0.1/merchants/M123456/readers/rdr_solo_001/terminate',
                $this->isString(),
                $this->isArray(),
            )
            ->willReturnOnConsecutiveCalls(
                $this->createJsonResponse(202, ''),
                $this->createJsonResponse(422, ['message' => 'Reader is idle']),
            );

        $service = new SumUpService($this->getDefaultConfig(), $mockClient);
        $this->assertTrue($service->terminateCheckout());
        $this->assertFalse($service->terminateCheckout());
    }

    public function testPairReaderSendsPairingCodeAndName(): void
    {
        $capturedPayload = [];
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('post')
            ->willReturnCallback(function (string $url, mixed $data) use (&$capturedPayload) {
                $this->assertSame('https://api.sumup.com/v0.1/merchants/M123456/readers', $url);
                $capturedPayload = json_decode((string) $data, true, 512, JSON_THROW_ON_ERROR);

                return $this->createJsonResponse(201, [
                    'id' => 'rdr_new_999',
                    'status' => 'processing',
                ]);
            });

        $service = new SumUpService($this->getDefaultConfig(), $mockClient);
        $result = $service->pairReader('abcd1234', 'Pabneukistl Kassa');

        $this->assertSame('rdr_new_999', $result['id']);
        $this->assertSame('ABCD1234', $capturedPayload['pairing_code']);
        $this->assertSame('Pabneukistl Kassa', $capturedPayload['name']);
    }

    public function testSanitizeForLogRedactsSensitiveCredentials(): void
    {
        $service = new SumUpService($this->getDefaultConfig(), $this->createStub(Client::class));
        $raw = 'Authorization: Bearer sup_sk_secret_api_key_12345 affiliate=sup_afk_secret_aff_98765 secret=whsec_secret_555';
        $sanitized = $service->sanitizeForLog($raw);

        $this->assertStringNotContainsString('sup_sk_secret_api_key_12345', $sanitized);
        $this->assertStringNotContainsString('sup_afk_secret_aff_98765', $sanitized);
        $this->assertStringNotContainsString('whsec_secret_555', $sanitized);
        $this->assertStringContainsString('[REDACTED]', $sanitized);
    }
}
