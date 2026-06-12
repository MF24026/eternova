<?php

declare(strict_types=1);

namespace Tests\Feature\Expenses;

use App\Modules\Expenses\Ocr\FakeOcrDriver;
use App\Modules\Expenses\Ocr\OcrDriverInterface;
use App\Modules\Expenses\Ocr\OcrException;
use App\Modules\Expenses\Ocr\OcrResult;
use App\Modules\Expenses\Ocr\TesseractOcrDriver;
use Tests\TestCase;

/**
 * Verifies the OCR driver container binding and FakeOcrDriver behaviour.
 *
 * These tests do NOT run Tesseract. phpunit.xml sets OCR_DRIVER=fake so
 * the container always binds FakeOcrDriver in the test environment.
 *
 * The TesseractOcrDriver binary-skip test is here to document the pattern
 * and to make future devs aware that a Tesseract test exists and IS
 * intentionally skipped in CI.
 */
final class OcrDriverBindingTest extends TestCase
{
    public function test_container_resolves_fake_driver_when_ocr_driver_is_fake(): void
    {
        // phpunit.xml sets OCR_DRIVER=fake; this test confirms the ServiceProvider
        // reads that config and binds FakeOcrDriver correctly.
        $driver = $this->app->make(OcrDriverInterface::class);

        $this->assertInstanceOf(FakeOcrDriver::class, $driver);
    }

    public function test_fake_driver_returns_canonical_ocr_result(): void
    {
        /** @var OcrDriverInterface $driver */
        $driver = $this->app->make(OcrDriverInterface::class);

        // Pass any string — FakeOcrDriver ignores the path.
        $result = $driver->extract('/dev/null');

        $this->assertInstanceOf(OcrResult::class, $result);
        $this->assertSame(FakeOcrDriver::VENDOR, $result->vendor);
        $this->assertSame(FakeOcrDriver::AMOUNT_CENTS, $result->amountCents);
        $this->assertSame(FakeOcrDriver::DATE, $result->date);
        $this->assertNotEmpty($result->rawText);
        $this->assertIsArray($result->lineItems);
    }

    public function test_ocr_result_to_array_matches_sprint_6_erd_schema(): void
    {
        /** @var OcrDriverInterface $driver */
        $driver = $this->app->make(OcrDriverInterface::class);
        $result = $driver->extract('/dev/null');

        $array = $result->toArray();

        // Verify every key that expenses.ocr_data must contain (ERD spec).
        $this->assertArrayHasKey('vendor', $array);
        $this->assertArrayHasKey('amount_cents', $array);
        $this->assertArrayHasKey('date', $array);
        $this->assertArrayHasKey('raw_text', $array);
        $this->assertArrayHasKey('confidence', $array);
        $this->assertArrayHasKey('line_items', $array);

        $this->assertSame(FakeOcrDriver::VENDOR, $array['vendor']);
        $this->assertSame(FakeOcrDriver::AMOUNT_CENTS, $array['amount_cents']);
        $this->assertSame(FakeOcrDriver::DATE, $array['date']);
        $this->assertIsArray($array['line_items']);
    }

    public function test_service_provider_throws_ocr_exception_for_unknown_driver(): void
    {
        // Temporarily override config to an invalid driver name.
        config(['ocr.driver' => 'nonexistent_driver']);

        // Flush the binding so it re-evaluates with the new config on next resolve.
        $this->app->forgetInstance(OcrDriverInterface::class);

        $this->expectException(OcrException::class);
        $this->expectExceptionMessageMatches("/Unknown OCR driver 'nonexistent_driver'/");

        $this->app->make(OcrDriverInterface::class);
    }

    public function test_container_resolves_tesseract_driver_when_config_is_tesseract(): void
    {
        // Verify the class map in config/ocr.php correctly maps 'tesseract'.
        // We do NOT actually invoke extract() here — just check the class resolves.
        config(['ocr.driver' => 'tesseract']);
        $this->app->forgetInstance(OcrDriverInterface::class);

        $driver = $this->app->make(OcrDriverInterface::class);

        $this->assertInstanceOf(TesseractOcrDriver::class, $driver);
    }

    /**
     * Verify that TesseractOcrDriver raises OcrException for a missing file.
     * Skipped automatically when the tesseract binary is not installed — this
     * is the expected behaviour in CI (no Tesseract, uses FakeOcrDriver).
     */
    public function test_tesseract_driver_throws_ocr_exception_for_missing_file(): void
    {
        exec('which tesseract 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            $this->markTestSkipped('tesseract binary not installed; skipped in CI.');
        }

        /** @var TesseractOcrDriver $driver */
        $driver = $this->app->make(TesseractOcrDriver::class);

        $this->expectException(OcrException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $driver->extract('/tmp/this_file_absolutely_does_not_exist.jpg');
    }
}
