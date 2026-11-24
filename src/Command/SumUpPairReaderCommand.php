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

namespace App\Command;

use App\Services\SumUp\SumUpApiException;
use App\Services\SumUp\SumUpService;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;

/**
 * CLI helper to pair, list, or unpair a SumUp Solo reader via the SumUp Cloud API.
 *
 * Usage:
 *   bin/cake sum_up_pair_reader <PAIRING_CODE> --name="Pabneukistl Kassa"
 *   bin/cake sum_up_pair_reader --list
 *   bin/cake sum_up_pair_reader --unpair=<READER_ID>
 */
class SumUpPairReaderCommand extends AppCommand
{
    public static function defaultName(): string
    {
        return 'sum_up_pair_reader';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);
        $parser->setDescription('Koppelt, listet oder entkoppelt ein SumUp Solo Kartenterminal über die SumUp Cloud API.');
        $parser->addArgument('pairing_code', [
            'help' => 'Pairing-Code vom Display des SumUp Solo Terminals (z. B. ABCD1234).',
            'required' => false,
        ]);
        $parser->addOption('name', [
            'short' => 'n',
            'help' => 'Anzeigename des Terminals in SumUp.',
            'default' => 'FoodCoopShop Kassa',
        ]);
        $parser->addOption('list', [
            'short' => 'l',
            'help' => 'Listet alle gekoppelten SumUp Reader inkl. Status auf.',
            'boolean' => true,
        ]);
        $parser->addOption('unpair', [
            'short' => 'u',
            'help' => 'Entkoppelt den Reader mit der angegebenen Reader-ID.',
            'default' => '',
        ]);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $sumUpService = new SumUpService();

        if (!$sumUpService->isConfiguredForPairing()) {
            $io->err('SumUp API Key fehlt. Bitte app.sumUp.apiKey in config/custom_config.php oder ENV SUMUP_API_KEY setzen.');

            return static::CODE_ERROR;
        }

        try {
            $merchantCode = $sumUpService->getMerchantCode();
            $io->out('Merchant Code: ' . $merchantCode);

            if ($args->getOption('list')) {
                $readers = $sumUpService->listReaders();
                if (empty($readers)) {
                    $io->out('Keine gekoppelten SumUp Reader gefunden.');

                    return static::CODE_SUCCESS;
                }
                foreach ($readers as $reader) {
                    $id = (string) ($reader['id'] ?? '-');
                    $name = (string) ($reader['name'] ?? '-');
                    $status = (string) ($reader['status'] ?? 'unknown');
                    $io->out(sprintf(' - ID: %s | Name: %s | Status: %s', $id, $name, $status));
                }

                return static::CODE_SUCCESS;
            }

            $unpairId = trim((string) $args->getOption('unpair'));
            if ($unpairId !== '') {
                $sumUpService->deleteReader($unpairId);
                $io->success('SumUp Reader ' . $unpairId . ' wurde erfolgreich entkoppelt.');

                return static::CODE_SUCCESS;
            }

            $pairingCode = trim((string) ($args->getArgument('pairing_code') ?? ''));
            if ($pairingCode === '') {
                $io->err('Bitte einen Pairing-Code angeben oder --list / --unpair=<READER_ID> verwenden.');

                return static::CODE_ERROR;
            }

            $readerName = (string) $args->getOption('name');
            $result = $sumUpService->pairReader($pairingCode, $readerName);
            $readerId = (string) ($result['id'] ?? '');
            $status = (string) ($result['status'] ?? 'processing');

            $io->success('SumUp Solo erfolgreich gekoppelt!');
            $io->out('Reader ID: ' . $readerId);
            $io->out('Status:    ' . $status);
            $io->out('');
            $io->out('Bitte in config/custom_config.php unter app.sumUp eintragen:');
            $io->out("  'merchantCode' => '" . $merchantCode . "',");
            $io->out("  'readerId' => '" . $readerId . "',");
            $io->out("  'enabled' => true,");

            return static::CODE_SUCCESS;
        } catch (SumUpApiException $e) {
            $io->err('SumUp API Fehler: ' . $e->getMessage());

            return static::CODE_ERROR;
        }
    }
}
