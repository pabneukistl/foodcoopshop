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

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\Log\Log;

class SumUpService
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SUCCESSFUL = 'SUCCESSFUL';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /**
     * @var array<string, mixed>
     */
    private array $config;

    private ?Client $httpClient;

    private ?string $resolvedMerchantCode = null;

    /**
     * @param array<string, mixed>|null $config
     */
    public function __construct(?array $config = null, ?Client $httpClient = null)
    {
        $configured = $config ?? (array) Configure::read('app.sumUp', []);
        $this->config = array_merge([
            'enabled' => false,
            'apiBaseUrl' => 'https://api.sumup.com',
            'apiKey' => '',
            'merchantCode' => '',
            'affiliateKey' => '',
            'affiliateAppId' => '',
            'readerId' => '',
            'currency' => 'EUR',
            'pollIntervalMs' => 2000,
            'timeoutSeconds' => 120,
            'webhookEnabled' => false,
            'webhookSecret' => '',
        ], $configured);

        $this->httpClient = $httpClient;
    }

    public function isEnabled(): bool
    {
        return !empty($this->config['enabled'])
            && trim((string) $this->config['apiKey']) !== ''
            && trim((string) $this->config['readerId']) !== '';
    }

    public function isConfiguredForPairing(): bool
    {
        return trim((string) $this->config['apiKey']) !== '';
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function getHttpClient(): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'timeout' => 15,
            ]);
        }

        return $this->httpClient;
    }

    /**
     * Resolves the SumUp merchant code from config or via GET /v0.1/me.
     */
    public function getMerchantCode(): string
    {
        $configuredCode = trim((string) $this->config['merchantCode']);
        if ($configuredCode !== '') {
            return $configuredCode;
        }

        if ($this->resolvedMerchantCode !== null) {
            return $this->resolvedMerchantCode;
        }

        $data = $this->requestJson('GET', '/v0.1/me');
        $merchantCode = $data['merchant_profile']['merchant_code']
            ?? $data['merchant_code']
            ?? '';

        if (!is_string($merchantCode) || trim($merchantCode) === '') {
            $this->logError('Could not resolve merchant_code from /v0.1/me response');
            throw new SumUpApiException('SumUp Merchant Code konnte nicht ermittelt werden. Bitte in der Konfiguration hinterlegen.');
        }

        $this->resolvedMerchantCode = trim($merchantCode);

        return $this->resolvedMerchantCode;
    }

    /**
     * Pairs a physical SumUp Solo reader using the pairing code shown on the device.
     *
     * @return array<string, mixed>
     */
    public function pairReader(string $pairingCode, string $readerName = 'FoodCoopShop Kassa'): array
    {
        $pairingCode = strtoupper(trim($pairingCode));
        if ($pairingCode === '') {
            throw new SumUpApiException('Pairing-Code darf nicht leer sein.');
        }

        $merchantCode = $this->getMerchantCode();
        $payload = [
            'pairing_code' => $pairingCode,
            'name' => trim($readerName) !== '' ? trim($readerName) : 'FoodCoopShop Kassa',
        ];

        return $this->requestJson(
            'POST',
            '/v0.1/merchants/' . rawurlencode($merchantCode) . '/readers',
            $payload,
        );
    }

    /**
     * Lists all paired SumUp readers for the configured merchant.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listReaders(): array
    {
        $merchantCode = $this->getMerchantCode();
        $data = $this->requestJson(
            'GET',
            '/v0.1/merchants/' . rawurlencode($merchantCode) . '/readers',
        );

        if (isset($data['items']) && is_array($data['items'])) {
            return $data['items'];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Fetches metadata for a single paired reader.
     *
     * @return array<string, mixed>
     */
    public function getReader(?string $readerId = null): array
    {
        $merchantCode = $this->getMerchantCode();
        $targetReaderId = trim((string) ($readerId ?? $this->config['readerId']));
        if ($targetReaderId === '') {
            throw new SumUpApiException('SumUp Reader ID ist nicht konfiguriert.');
        }

        return $this->requestJson(
            'GET',
            '/v0.1/merchants/' . rawurlencode($merchantCode) . '/readers/' . rawurlencode($targetReaderId),
        );
    }

    /**
     * Unpairs/deletes a reader from the SumUp merchant account.
     */
    public function deleteReader(string $readerId): bool
    {
        $merchantCode = $this->getMerchantCode();
        $this->requestJson(
            'DELETE',
            '/v0.1/merchants/' . rawurlencode($merchantCode) . '/readers/' . rawurlencode(trim($readerId)),
            null,
            true,
        );

        return true;
    }

    /**
     * Returns live hardware/screen state of the Solo reader (e.g. IDLE, WAITING_FOR_CARD, WAITING_FOR_PIN, OFFLINE).
     *
     * @return array{status: string, state: string, raw: array<string, mixed>}
     */
    public function getReaderStatus(?string $readerId = null): array
    {
        $merchantCode = $this->getMerchantCode();
        $targetReaderId = trim((string) ($readerId ?? $this->config['readerId']));
        if ($targetReaderId === '') {
            return ['status' => 'UNKNOWN', 'state' => 'UNKNOWN', 'raw' => []];
        }

        try {
            $data = $this->requestJson(
                'GET',
                '/v0.1/merchants/' . rawurlencode($merchantCode) . '/readers/' . rawurlencode($targetReaderId) . '/status',
            );
            $inner = is_array($data['data'] ?? null) ? $data['data'] : $data;

            return [
                'status' => strtoupper((string) ($inner['status'] ?? 'UNKNOWN')),
                'state' => strtoupper((string) ($inner['state'] ?? 'UNKNOWN')),
                'raw' => $data,
            ];
        } catch (SumUpApiException $e) {
            $this->logWarning('Failed to query reader live status', [
                'httpStatus' => $e->getHttpStatusCode(),
                'errorCode' => $e->getApiErrorCode(),
            ]);

            return ['status' => 'UNKNOWN', 'state' => 'UNKNOWN', 'raw' => []];
        }
    }

    /**
     * Sends a payment request to the configured SumUp Solo terminal.
     *
     * @return array{client_transaction_id: string, raw: array<string, mixed>}
     */
    public function createReaderCheckout(
        int $amountInCents,
        string $foreignTransactionId,
        string $description = 'Self-Service Einkauf',
        ?string $returnUrl = null,
    ): array {
        if ($amountInCents <= 0) {
            throw new SumUpApiException('Der Zahlungsbetrag muss größer als 0 sein.');
        }

        $merchantCode = $this->getMerchantCode();
        $readerId = trim((string) $this->config['readerId']);
        if ($readerId === '') {
            throw new SumUpApiException('SumUp Reader ID ist nicht konfiguriert.');
        }

        $currency = strtoupper(trim((string) ($this->config['currency'] ?: 'EUR')));

        $payload = [
            'total_amount' => [
                'value' => $amountInCents,
                'currency' => $currency,
                'minor_unit' => 2,
            ],
            'description' => mb_substr($description, 0, 140),
        ];

        $affiliateKey = trim((string) $this->config['affiliateKey']);
        $affiliateAppId = trim((string) $this->config['affiliateAppId']);
        if ($affiliateKey !== '') {
            $affiliate = [
                'key' => $affiliateKey,
                'foreign_transaction_id' => $foreignTransactionId,
            ];
            if ($affiliateAppId !== '') {
                $affiliate['app_id'] = $affiliateAppId;
            }
            $payload['affiliate'] = $affiliate;
        }

        if ($returnUrl !== null && trim($returnUrl) !== '') {
            $payload['return_url'] = trim($returnUrl);
        }

        $data = $this->requestJson(
            'POST',
            '/v0.1/merchants/' . rawurlencode($merchantCode) . '/readers/' . rawurlencode($readerId) . '/checkout',
            $payload,
        );

        $clientTransactionId = (string) (
            $data['data']['client_transaction_id']
            ?? $data['client_transaction_id']
            ?? ''
        );

        if ($clientTransactionId === '') {
            $this->logError('SumUp reader checkout response did not contain client_transaction_id');
            throw new SumUpApiException('SumUp hat keine Transaktions-ID zurückgegeben.');
        }

        return [
            'client_transaction_id' => $clientTransactionId,
            'raw' => $data,
        ];
    }

    /**
     * Checks the authoritative financial transaction status via SumUp Transactions API,
     * optionally enriched with live reader screen state while PENDING.
     *
     * @return array{
     *   status: string,
     *   readerStatus: string,
     *   readerState: string,
     *   amount: float|null,
     *   currency: string|null,
     *   transactionCode: string|null,
     *   message: string
     * }
     */
    public function getTransactionStatus(string $clientTransactionId, bool $includeReaderStatus = true): array
    {
        $clientTransactionId = trim($clientTransactionId);
        if ($clientTransactionId === '') {
            throw new SumUpApiException('Fehlende Transaktions-ID.');
        }

        $merchantCode = $this->getMerchantCode();
        $transactionData = null;

        try {
            $response = $this->requestJson(
                'GET',
                '/v2.1/merchants/' . rawurlencode($merchantCode) . '/transactions?client_transaction_id=' . rawurlencode($clientTransactionId),
            );
            $transactionData = $this->extractTransactionRecord($response);
        } catch (SumUpApiException $e) {
            // 404 is expected while the customer has not yet presented a card on the Solo reader
            if ($e->getHttpStatusCode() !== 404) {
                throw $e;
            }
        }

        if ($transactionData !== null) {
            $normalizedStatus = $this->normalizeTransactionStatus((string) ($transactionData['status'] ?? ''));
            $amount = isset($transactionData['amount']) ? (float) $transactionData['amount'] : null;
            $currency = isset($transactionData['currency']) ? (string) $transactionData['currency'] : null;
            $transactionCode = isset($transactionData['transaction_code']) ? (string) $transactionData['transaction_code'] : null;

            $readerStatus = 'UNKNOWN';
            $readerState = 'UNKNOWN';
            if ($normalizedStatus === self::STATUS_PENDING && $includeReaderStatus) {
                $live = $this->getReaderStatus();
                $readerStatus = $live['status'];
                $readerState = $live['state'];
            }

            return [
                'status' => $normalizedStatus,
                'readerStatus' => $readerStatus,
                'readerState' => $readerState,
                'amount' => $amount,
                'currency' => $currency,
                'transactionCode' => $transactionCode,
                'message' => $this->getStatusMessage($normalizedStatus, $readerState),
            ];
        }

        $readerStatus = 'UNKNOWN';
        $readerState = 'UNKNOWN';
        if ($includeReaderStatus) {
            $live = $this->getReaderStatus();
            $readerStatus = $live['status'];
            $readerState = $live['state'];
        }

        return [
            'status' => self::STATUS_PENDING,
            'readerStatus' => $readerStatus,
            'readerState' => $readerState,
            'amount' => null,
            'currency' => null,
            'transactionCode' => null,
            'message' => $this->getStatusMessage(self::STATUS_PENDING, $readerState),
        ];
    }

    /**
     * Cancels/terminates an ongoing payment on the Solo terminal.
     */
    public function terminateCheckout(?string $readerId = null): bool
    {
        $merchantCode = $this->getMerchantCode();
        $targetReaderId = trim((string) ($readerId ?? $this->config['readerId']));
        if ($targetReaderId === '') {
            return false;
        }

        try {
            $this->requestJson(
                'POST',
                '/v0.1/merchants/' . rawurlencode($merchantCode) . '/readers/' . rawurlencode($targetReaderId) . '/terminate',
                [],
                true,
            );

            return true;
        } catch (SumUpApiException $e) {
            // 404/422 can occur if the terminal already returned to IDLE
            if (in_array($e->getHttpStatusCode(), [404, 422], true)) {
                $this->logWarning('Terminate called when reader was not in cancellable state', [
                    'httpStatus' => $e->getHttpStatusCode(),
                ]);

                return false;
            }
            throw $e;
        }
    }

    /**
     * Normalizes raw SumUp status values to the 4 canonical states.
     */
    public function normalizeTransactionStatus(string $rawStatus): string
    {
        return match (strtoupper(trim($rawStatus))) {
            'SUCCESSFUL', 'PAID' => self::STATUS_SUCCESSFUL,
            'CANCELLED', 'CANCELED' => self::STATUS_CANCELLED,
            'FAILED', 'DECLINED', 'EXPIRED' => self::STATUS_FAILED,
            default => self::STATUS_PENDING,
        };
    }

    /**
     * Redacts sensitive credentials before writing any log message.
     */
    public function sanitizeForLog(string $text): string
    {
        $secrets = [
            (string) ($this->config['apiKey'] ?? ''),
            (string) ($this->config['affiliateKey'] ?? ''),
            (string) ($this->config['webhookSecret'] ?? ''),
        ];

        $sanitized = $text;
        foreach ($secrets as $secret) {
            $trimmed = trim($secret);
            if ($trimmed !== '') {
                $sanitized = str_replace($trimmed, '[REDACTED]', $sanitized);
            }
        }

        $sanitized = (string) preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [REDACTED]', $sanitized);

        return $sanitized;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function requestJson(
        string $method,
        string $path,
        ?array $payload = null,
        bool $allowEmptyBody = false,
    ): array {
        $apiKey = trim((string) $this->config['apiKey']);
        if ($apiKey === '') {
            throw new SumUpApiException('SumUp API Key ist nicht konfiguriert.');
        }

        $baseUrl = rtrim((string) $this->config['apiBaseUrl'], '/');
        $url = $baseUrl . $path;

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ],
        ];

        $body = null;
        if ($payload !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                throw new SumUpApiException('SumUp Request-Daten konnten nicht serialisiert werden.');
            }
        }

        try {
            $client = $this->getHttpClient();
            $response = match (strtoupper($method)) {
                'GET' => $client->get($url, [], $options),
                'POST' => $client->post($url, $body ?? '{}', $options),
                'DELETE' => $client->delete($url, [], $options),
                default => throw new SumUpApiException('Nicht unterstützte HTTP-Methode: ' . $method),
            };
        } catch (SumUpApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logError('HTTP communication error with SumUp API', [
                'method' => $method,
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);
            throw new SumUpApiException(
                'Verbindung zum SumUp-Kartenterminal fehlgeschlagen. Bitte Netzwerkverbindung prüfen.',
                0,
                'NETWORK_ERROR',
                $e,
            );
        }

        return $this->parseResponse($response, $method, $path, $allowEmptyBody);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResponse(
        Response $response,
        string $method,
        string $path,
        bool $allowEmptyBody,
    ): array {
        $statusCode = $response->getStatusCode();
        $rawBody = $response->getStringBody();
        $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;

        if ($statusCode < 200 || $statusCode >= 300) {
            $apiErrorCode = '';
            $apiMessage = '';
            if (is_array($decoded)) {
                if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                    $apiErrorCode = (string) ($decoded['errors']['type'] ?? '');
                    $apiMessage = (string) ($decoded['errors']['detail'] ?? $decoded['errors']['title'] ?? '');
                } else {
                    $apiErrorCode = (string) ($decoded['error_code'] ?? $decoded['type'] ?? '');
                    $apiMessage = (string) ($decoded['message'] ?? $decoded['detail'] ?? $decoded['error_message'] ?? '');
                }
            }

            if ($statusCode !== 404) {
                $this->logError('SumUp API returned error status', [
                    'method' => $method,
                    'path' => $path,
                    'httpStatus' => $statusCode,
                    'errorCode' => $apiErrorCode,
                    'detail' => mb_substr($apiMessage !== '' ? $apiMessage : $rawBody, 0, 300),
                ]);
            }

            $friendlyMessage = $this->mapErrorToFriendlyMessage($statusCode, $apiErrorCode, $apiMessage);
            throw new SumUpApiException($friendlyMessage, $statusCode, $apiErrorCode);
        }

        if ($rawBody === '' && $allowEmptyBody) {
            return [];
        }

        if (!is_array($decoded)) {
            if ($allowEmptyBody) {
                return [];
            }
            $this->logError('SumUp API returned invalid JSON', [
                'method' => $method,
                'path' => $path,
                'httpStatus' => $statusCode,
            ]);
            throw new SumUpApiException('Ungültige Antwort von der SumUp API erhalten.', $statusCode, 'INVALID_JSON');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>|null
     */
    private function extractTransactionRecord(array $response): ?array
    {
        if (isset($response['status'])) {
            return $response;
        }
        if (isset($response['items']) && is_array($response['items']) && !empty($response['items'])) {
            $first = $response['items'][0];
            if (is_array($first)) {
                return $first;
            }
        }

        return null;
    }

    private function getStatusMessage(string $status, string $readerState): string
    {
        if ($status === self::STATUS_SUCCESSFUL) {
            return 'Zahlung erfolgreich abgeschlossen.';
        }
        if ($status === self::STATUS_CANCELLED) {
            return 'Die Kartenzahlung wurde am Terminal abgebrochen.';
        }
        if ($status === self::STATUS_FAILED) {
            return 'Die Kartenzahlung wurde abgelehnt oder ist fehlgeschlagen.';
        }

        return match ($readerState) {
            'WAITING_FOR_CARD' => 'Bitte Karte jetzt an das Kartenterminal halten oder einstecken…',
            'WAITING_FOR_PIN' => 'Bitte PIN am Kartenterminal eingeben…',
            'WAITING_FOR_SIGNATURE' => 'Bitte Unterschrift am Kartenterminal bestätigen…',
            'SELECTING_TIP' => 'Bitte Auswahl am Kartenterminal bestätigen…',
            'OFFLINE' => 'Kartenterminal ist derzeit offline. Bitte Verbindung prüfen…',
            default => 'Bitte jetzt am Kartenterminal bezahlen…',
        };
    }

    private function mapErrorToFriendlyMessage(int $statusCode, string $errorCode, string $detail): string
    {
        $upperCode = strtoupper($errorCode);
        if ($statusCode === 401 || $statusCode === 403) {
            return 'SumUp-Authentifizierung fehlgeschlagen. Bitte API-Key bzw. Affiliate-Key prüfen.';
        }
        if ($statusCode === 422 || str_contains($upperCode, 'OFFLINE') || str_contains(strtolower($detail), 'offline')) {
            return 'Das SumUp-Kartenterminal ist derzeit nicht bereit oder offline. Bitte prüfen und erneut versuchen.';
        }
        if ($statusCode === 429) {
            return 'Zu viele Anfragen an SumUp. Bitte kurz warten und erneut versuchen.';
        }
        if ($detail !== '') {
            return 'SumUp-Fehler: ' . $this->sanitizeForLog($detail);
        }

        return 'Fehler bei der Kommunikation mit dem SumUp-Kartenterminal (HTTP ' . $statusCode . ').';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logError(string $message, array $context = []): void
    {
        $serializedContext = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Log::error('[SumUp] ' . $this->sanitizeForLog($message . $serializedContext));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logWarning(string $message, array $context = []): void
    {
        $serializedContext = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Log::warning('[SumUp] ' . $this->sanitizeForLog($message . $serializedContext));
    }
}
