<?php
declare(strict_types=1);

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Logo\Logo;

function generarQrConLogo(string $data, string $destinoPng, string $logoPath): void
{
    $result = Builder::create()
        ->writer(new PngWriter())
        ->data($data)
        ->encoding(new Encoding('UTF-8'))
        ->errorCorrectionLevel(ErrorCorrectionLevel::High) // <- cambio importante
        ->size(700)
        ->margin(20)
        ->logoPath($logoPath)
        ->logoResizeToWidth(160)
        ->logoPunchoutBackground(true)
        ->build();

    $result->saveToFile($destinoPng);
}