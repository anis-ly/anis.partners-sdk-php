<?php

declare(strict_types=1);

namespace Anis\Partners\Tests\Sample;

use Anis\Partners\AnisPartnersClient;
use Anis\Partners\ClientOptions;
use Anis\Partners\Tests\Support\ProductPayloads as P;
use Anis\Partners\Tests\Support\SignedFakeWire;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductJournalTest extends TestCase
{
    #[Test]
    public function it_persists_private_complete_bytes_and_never_overwrites_an_operation(): void
    {
        require_once dirname(__DIR__, 2) . '/samples/console/products-sample.php';
        $folder = sys_get_temp_dir() . '/anis-journal-' . bin2hex(random_bytes(8));
        $path = $folder . '/' . P::METER_OPERATION . '.json';
        try {
            $record = ['walletId' => P::WALLET, 'request' => ['meterNumber' => '0268000710262', 'note' => 'مرحبا']];
            \journalWrite($folder, P::METER_OPERATION, $record);
            self::assertSame(0600, fileperms($path) & 0777);
            self::assertSame($record, \journalRead($folder, P::METER_OPERATION));
            $bytes = file_get_contents($path);
            self::assertSame(json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $bytes);
            try {
                \journalWrite($folder, P::METER_OPERATION, ['different' => true]);
                self::fail('An existing operation must never be replaced.');
            } catch (\RuntimeException) {
                self::assertSame($bytes, file_get_contents($path));
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($folder)) {
                rmdir($folder);
            }
        }
    }

    #[Test]
    public function it_sends_no_purchase_when_persistence_fails_for_either_product(): void
    {
        require_once dirname(__DIR__, 2) . '/samples/console/products-sample.php';
        $blockedFolder = tempnam(sys_get_temp_dir(), 'anis-journal-blocked-');
        self::assertNotFalse($blockedFolder);
        $previous = getenv('SAMPLE_PRODUCTS_FOLDER');
        putenv('SAMPLE_PRODUCTS_FOLDER=' . $blockedFolder);
        try {
            foreach ([['meter-charge', P::WALLET, P::PACKAGE, '0268000710262', '97.500'],
                ['recharge', P::WALLET, P::PRODUCT, '1500.000', '237.500', 'recipient_number=01012345678']] as $args) {
                $wire = new SignedFakeWire();
                $factory = new HttpFactory();
                $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
                try {
                    \productsMain(['products-sample.php', ...$args], $client);
                    self::fail('Persistence failure must abort the sample.');
                } catch (\RuntimeException) {
                    self::assertSame([], $wire->requests);
                }
            }
        } finally {
            putenv($previous === false ? 'SAMPLE_PRODUCTS_FOLDER' : 'SAMPLE_PRODUCTS_FOLDER=' . $previous);
            unlink($blockedFolder);
        }
    }

    #[Test]
    public function it_prints_recipient_acceptance_and_location_without_claiming_a_projected_exchange(): void
    {
        require_once dirname(__DIR__, 2) . '/samples/console/products-sample.php';
        $wire = new SignedFakeWire();
        $wire->status = 202;
        $wire->body = P::accepted('directRecharge', P::RECHARGE_OPERATION);
        $location = '/v1/direct-recharges/' . P::RECHARGE_OPERATION;
        $wire->responseHeaders['Location'] = $location;
        $factory = new HttpFactory();
        $client = AnisPartnersClient::create(new ClientOptions('https://partners.example'), $wire->requestSigner(), $wire, $factory, $factory);
        ob_start();
        try {
            $code = \productsMain(['products-sample.php', 'recharge-fix', P::RECHARGE_OPERATION, 'recipient_number=01098765432'], $client);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $code);
        self::assertIsString($output);
        self::assertStringContainsString('Correction accepted for ' . P::RECHARGE_OPERATION, $output);
        self::assertStringContainsString('Read ' . $location, $output);
        self::assertStringContainsString('after 5s', $output);
        self::assertStringContainsString('read may still show the old state', $output);
        self::assertStringNotContainsString('01098765432', $output);
        self::assertCount(1, array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'PATCH'));
        self::assertSame([], array_values(array_filter($wire->requests, static fn($request): bool => $request->getMethod() === 'GET' && $request->getUri()->getPath() === $location)));
    }
}
