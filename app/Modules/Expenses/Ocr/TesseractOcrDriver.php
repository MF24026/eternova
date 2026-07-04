<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Ocr;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * OCR driver that shells out to the system Tesseract binary.
 *
 * Pipeline for each file:
 *   1. If PDF  → convert first page to a PNG via pdftoppm (poppler-utils).
 *   2. Preprocess image → grayscale + high-contrast threshold (improves
 *      Tesseract accuracy on scanned/photographed receipts significantly).
 *   3. Run:  tesseract <img> stdout -l <languages>
 *      Stdout is the raw UTF-8 text; stderr is discarded unless the
 *      process exits with a non-zero code.
 *   4. Pass raw text to ReceiptTextParser → return OcrResult.
 *
 * All shell calls have explicit timeouts so a corrupted file can never
 * stall a queue worker permanently.
 *
 * Requirements (installed in docker/8.4/Dockerfile):
 *   - tesseract-ocr + tesseract-ocr-spa
 *   - poppler-utils (provides pdftoppm)
 *   - php8.4-imagick OR GD (intervention/image handles the fallback)
 */
final class TesseractOcrDriver implements OcrDriverInterface
{
    public function __construct(
        private readonly ReceiptTextParser $parser,
    ) {}

    public function extract(string $absolutePath): OcrResult
    {
        if (! file_exists($absolutePath)) {
            throw new OcrException("OCR input file not found: {$absolutePath}");
        }

        $imagePath = $this->resolveImagePath($absolutePath);

        try {
            $preprocessed = $this->preprocessImage($imagePath);
            $rawText = $this->runTesseract($preprocessed);

            $parsed = $this->parser->parse($rawText);

            return new OcrResult(
                rawText: $rawText,
                vendor: $parsed['vendor'],
                amountCents: $parsed['amount_cents'],
                date: $parsed['date'],
                confidence: null, // Tesseract CLI does not expose per-document confidence easily
                lineItems: [],
            );
        } finally {
            // Clean up any temporary files we created during this extraction.
            if ($imagePath !== $absolutePath && file_exists($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PDF → image conversion
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return an absolute path to an image suitable for Tesseract.
     *
     * For PDFs: convert the first page to a 300-dpi PNG via pdftoppm and
     * return the path to that PNG. The caller is responsible for deleting it.
     * For images: return the original path unchanged.
     */
    private function resolveImagePath(string $absolutePath): string
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if ($extension !== 'pdf') {
            return $absolutePath;
        }

        return $this->convertPdfToImage($absolutePath);
    }

    /**
     * Convert the first PDF page to a 300-dpi PNG and return the temp path.
     *
     * pdftoppm flags:
     *   -r 300   → 300 DPI, standard for OCR
     *   -png     → PNG output (lossless, better for Tesseract than JPEG)
     *   -f 1 -l 1 → only the first page
     *
     * pdftoppm appends a zero-padded page number to the output prefix, so
     * the actual file ends up as {prefix}-1.png (or -01.png depending on
     * page count). We check both conventions.
     */
    private function convertPdfToImage(string $pdfPath): string
    {
        $outputPrefix = sys_get_temp_dir().'/ocr_'.uniqid('', true);

        $process = new Process([
            'pdftoppm',
            '-r', '300',
            '-png',
            '-f', '1',
            '-l', '1',
            $pdfPath,
            $outputPrefix,
        ]);

        $process->setTimeout((int) config('ocr.tesseract.timeout', 60));

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new OcrException(
                "PDF→image conversion timed out for: {$pdfPath}",
                previous: $e,
            );
        }

        if (! $process->isSuccessful()) {
            throw new OcrException(
                "pdftoppm failed (exit {$process->getExitCode()}) for: {$pdfPath}. ".
                'Stderr: '.$process->getErrorOutput(),
            );
        }

        // pdftoppm pads page numbers based on total pages: -1.png or -01.png.
        foreach (['-1.png', '-01.png', '-001.png'] as $suffix) {
            $candidate = $outputPrefix.$suffix;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        throw new OcrException(
            "pdftoppm ran successfully but produced no output file for: {$pdfPath}",
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Image preprocessing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert the image to grayscale and increase contrast to improve OCR.
     *
     * Writes to a temp file so Tesseract always gets a fresh preprocessed
     * image regardless of the original format. Returns the temp path.
     *
     * Intervention Image 4.x API: make() → greyscale() → contrast().
     * The GD driver is used because php8.4-gd is always available in the
     * image. Imagick (php8.4-imagick) is also installed and Intervention
     * will use it if the Imagick driver is configured, but GD is sufficient
     * for preprocessing.
     */
    private function preprocessImage(string $imagePath): string
    {
        $outputPath = sys_get_temp_dir().'/ocr_pre_'.uniqid('', true).'.png';

        try {
            $manager = new ImageManager(new GdDriver);
            $image = $manager->read($imagePath);

            $image->greyscale();

            // Contrast: Intervention Image 4.x accepts -100..100.
            // +40 sharpens low-contrast receipts without destroying fine text.
            $image->contrast(40);

            $image->save($outputPath);
        } catch (\Throwable $e) {
            // Preprocessing is best-effort. If it fails, fall back to the
            // original image — Tesseract may still produce usable output.
            return $imagePath;
        }

        return $outputPath;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tesseract execution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Shell out to Tesseract and return the raw stdout text.
     *
     * Command: tesseract <image> stdout -l <languages>
     *   - "stdout" as output basename tells Tesseract to write to stdout
     *     rather than a file (no .txt file created on disk).
     *
     * @throws OcrException On timeout or non-zero exit code.
     */
    private function runTesseract(string $imagePath): string
    {
        $binary = config('ocr.tesseract.binary', 'tesseract');
        $languages = config('ocr.tesseract.languages', 'spa+eng');
        $timeout = (int) config('ocr.tesseract.timeout', 60);

        $process = new Process([$binary, $imagePath, 'stdout', '-l', $languages]);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new OcrException(
                "Tesseract timed out after {$timeout}s for image: {$imagePath}",
                previous: $e,
            );
        }

        if (! $process->isSuccessful()) {
            throw new OcrException(
                "Tesseract failed (exit {$process->getExitCode()}) for: {$imagePath}. ".
                'Stderr: '.$process->getErrorOutput(),
            );
        }

        return $process->getOutput();
    }
}
