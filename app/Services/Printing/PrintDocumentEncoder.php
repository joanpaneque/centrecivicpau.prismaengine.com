<?php

namespace App\Services\Printing;

use App\Models\Printer;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Turns a print document (the lines the TPV builds) into what a printer understands:
 * ESC/POS bytes for thermal printers (accents in the printer's code page, native QR,
 * the logo as a raster image), or the same lines ready to draw through a Windows driver
 * (with the QR as a module matrix and the logo as PNG).
 */
class PrintDocumentEncoder
{
    /** Characters 0x80–0xFF of each code page, in order ('?' = not printable). */
    private const CODEPAGES = [
        'cp858' => 'ÇüéâäàåçêëèïîìÄÅÉæÆôöòûùÿÖÜø£Ø×ƒáíóúñÑªº¿®¬½¼¡«»░▒▓│┤ÁÂÀ©╣║╗╝¢¥┐└┴┬├─┼ãÃ╚╔╩╦╠═╬¤ðÐÊËÈ€ÍÎÏ┘┌█▄¦Ì▀ÓßÔÒõÕµþÞÚÛÙýÝ¯´?±‗¾¶§÷¸°¨·¹³²■ ',
        'cp1252' => '€?‚ƒ„…†‡ˆ‰Š‹Œ?Ž??‘’“”•–—˜™š›œ?žŸ ¡¢£¤¥¦§¨©ª«¬?®¯°±²³´µ¶·¸¹º»¼½¾¿ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖ×ØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõö÷øùúûüýþÿ',
    ];

    /** ESC t n for each code page (Epson numbering, followed by most ESC/POS printers). */
    private const CODEPAGE_COMMANDS = ['cp858' => 19, 'cp1252' => 16];

    /** @var array<string, array<string, string>> */
    private array $maps = [];

    public function __construct(private readonly DocumentPdfRenderer $renderer) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function escpos(array $document, ?int $width = null, string $codepage = 'cp858'): string
    {
        $esc = "\x1B";
        $gs = "\x1D";
        $width = $width ?? (int) ($document['width'] ?? 48);
        $out = $esc.'@';

        if (isset(self::CODEPAGE_COMMANDS[$codepage])) {
            $out .= $esc.'t'.chr(self::CODEPAGE_COMMANDS[$codepage]);
        }

        foreach ($this->lines($document) as $line) {
            $type = (string) ($line['type'] ?? 'text');
            $big = (int) ($line['size'] ?? 1) > 1;
            $columns = $big ? intdiv($width, 2) : $width;
            $align = match ($line['align'] ?? 'left') {
                'center' => "\x01",
                'right' => "\x02",
                default => "\x00",
            };

            if (in_array($type, ['qr', 'image'], true)) {
                $align = "\x01";
            }

            $out .= $esc.'a'.$align;
            $out .= $esc.'E'.(! empty($line['bold']) ? "\x01" : "\x00");
            $out .= $gs.'!'.($big ? "\x11" : "\x00");
            $out .= $gs.'B'.(! empty($line['invert']) ? "\x01" : "\x00");

            $out .= match ($type) {
                'divider' => $this->text(str_repeat(mb_substr((string) ($line['char'] ?? '-'), 0, 1) ?: '-', $width), $codepage)."\n",
                'row' => $this->text($this->row((string) ($line['left'] ?? ''), (string) ($line['right'] ?? ''), $columns), $codepage)."\n",
                'feed' => $esc.'d'.chr(max(1, min(Printer::MAX_MARGIN, (int) ($line['lines'] ?? 1)))),
                'cut' => $gs.'V'."\x42\x03",
                'qr' => $this->qr((string) ($line['data'] ?? '')).(isset($line['caption']) ? $this->text((string) $line['caption'], $codepage)."\n" : ''),
                'image' => $this->raster($width),
                default => $this->text((string) ($line['text'] ?? ''), $codepage)."\n",
            };
        }

        // Leave the printer in its normal state for whoever prints next.
        return $out.$esc.'a'."\x00".$gs.'!'."\x00".$gs.'B'."\x00".$esc.'E'."\x00";
    }

    /**
     * The document for printing through a Windows driver: the QR as rows of 0/1 modules
     * and the logo as a PNG, so the program only has to draw.
     *
     * @param  array<string, mixed>  $document
     * @return array{width: int, lines: list<array<string, mixed>>}
     */
    public function forDriver(array $document, ?int $width = null): array
    {
        $lines = [];

        foreach ($this->lines($document) as $line) {
            if (($line['type'] ?? '') === 'qr') {
                $line['matrix'] = $this->qrMatrix((string) ($line['data'] ?? ''));
            }

            if (($line['type'] ?? '') === 'image') {
                $png = $this->logoPng();

                if ($png === null) {
                    continue;
                }

                $line = ['type' => 'image', 'png' => base64_encode($png)];
            }

            $lines[] = $line;
        }

        return ['width' => $width ?? (int) ($document['width'] ?? 48), 'lines' => $lines];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    private function lines(array $document): array
    {
        return array_values(array_filter((array) ($document['lines'] ?? []), 'is_array'));
    }

    /**
     * UTF-8 text in the printer's code page; what it lacks loses its accent or becomes '?'.
     */
    public function text(string $text, string $codepage): string
    {
        $map = $this->map($codepage);
        $out = '';

        foreach (mb_str_split($text) as $char) {
            if (strlen($char) === 1) {
                $out .= $char;
            } elseif (isset($map[$char])) {
                $out .= $map[$char];
            } else {
                $plain = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $char);
                $out .= is_string($plain) && $plain !== '' && $plain !== '?' ? $plain : '?';
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function map(string $codepage): array
    {
        if (! isset(self::CODEPAGES[$codepage])) {
            return [];
        }

        if (! isset($this->maps[$codepage])) {
            $this->maps[$codepage] = [];

            foreach (mb_str_split(self::CODEPAGES[$codepage]) as $i => $char) {
                if ($char !== '?') {
                    $this->maps[$codepage][$char] = chr((0x80 + $i) & 0xFF);
                }
            }
        }

        return $this->maps[$codepage];
    }

    private function row(string $left, string $right, int $width): string
    {
        $space = $width - mb_strlen($left) - mb_strlen($right);

        return $space >= 1
            ? $left.str_repeat(' ', $space).$right
            : mb_substr($left, 0, max(0, $width - mb_strlen($right) - 1)).' '.$right;
    }

    /**
     * Native ESC/POS QR code (model 2, error level M).
     */
    private function qr(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $fn = fn (string $body) => "\x1D(k".pack('v', strlen($body)).$body;

        return $fn("\x31\x41\x32\x00")
            .$fn("\x31\x43\x06")
            .$fn("\x31\x45\x31")
            .$fn("\x31\x50\x30".$data)
            .$fn("\x31\x51\x30")
            ."\n";
    }

    /**
     * @return list<string>
     */
    private function qrMatrix(string $data): array
    {
        if ($data === '') {
            return [];
        }

        $matrix = Encoder::encode($data, ErrorCorrectionLevel::M())->getMatrix();
        $rows = [];

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            $row = '';

            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $row .= $matrix->get($x, $y) === 1 ? '1' : '0';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The logo as an ESC/POS raster image (GS v 0), about half the paper wide.
     */
    private function raster(int $columns): string
    {
        $image = $this->logoImage();

        if ($image === null) {
            return '';
        }

        $maxWidth = $columns <= 32 ? 256 : 320;
        $scale = min(1, $maxWidth / imagesx($image), 160 / imagesy($image));
        $w = max(8, (int) round(imagesx($image) * $scale));
        $h = max(1, (int) round(imagesy($image) * $scale));
        $resized = imagecreatetruecolor($w, $h);
        imagefill($resized, 0, 0, (int) imagecolorallocate($resized, 255, 255, 255));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $w, $h, imagesx($image), imagesy($image));

        $bytesPerRow = (int) ceil($w / 8);
        $data = '';

        for ($y = 0; $y < $h; $y++) {
            for ($bx = 0; $bx < $bytesPerRow; $bx++) {
                $byte = 0;

                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $bx * 8 + $bit;

                    if ($x < $w) {
                        $rgb = imagecolorat($resized, $x, $y);
                        $luma = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);

                        if ($luma < 128) {
                            $byte |= 0x80 >> $bit;
                        }
                    }
                }

                $data .= chr($byte & 0xFF);
            }
        }

        return "\x1Dv0\x00".pack('v', $bytesPerRow).pack('v', $h).$data."\n";
    }

    private function logoPng(): ?string
    {
        $image = $this->logoImage();

        if ($image === null) {
            return null;
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * The logo on a white background (transparent areas would otherwise print black).
     */
    private function logoImage(): ?\GdImage
    {
        $path = $this->renderer->logoPath();
        $source = $path !== null && is_file($path) ? @imagecreatefromstring((string) file_get_contents($path)) : false;

        if ($source === false) {
            return null;
        }

        $image = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagecopy($image, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));

        return $image;
    }
}
