<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class Qr
{
    public static function svg(string $data, int $size = 160): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd));

        return $writer->writeString($data);
    }

    public static function dataUri(string $data, int $size = 160): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($data, $size));
    }
}
