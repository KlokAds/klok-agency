<?php

namespace App\Payments;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use InvalidArgumentException;

/**
 * The agency's Bangla QR, made per payment.
 *
 * A Bangla QR is an EMVCo merchant QR: a string of tag–length–value fields
 * (merchant account, name, city, currency…) ending in a CRC. The bank gives
 * a "static" one, where the payer types the amount. This keeps every field
 * of that QR and only marks it "dynamic" (tag 01 = 12) with the amount in
 * tag 54, so the payer's bKash / Nagad / bank app fills the amount in and
 * locks it. The money still goes to the same merchant account.
 *
 * It is then drawn in the brand's colours with the mark in the middle; the
 * highest error correction (H, ~30%) keeps it readable with the mark there.
 */
class BanglaQr
{
    /** @var array<int|string, string> tag => value ("01" stays a string, "59" becomes 59) */
    private array $fields;

    public function __construct(string $payload)
    {
        $this->fields = self::parse(trim($payload));
    }

    /** @return array<int|string, string> */
    public static function parse(string $payload): array
    {
        if (strlen($payload) < 20 || ! str_starts_with($payload, '000201')) {
            throw new InvalidArgumentException('Not an EMV QR (it must start with 000201).');
        }

        $crc = substr($payload, -4);
        if (substr($payload, -8, 4) !== '6304' || strtoupper($crc) !== self::crc(substr($payload, 0, -4))) {
            throw new InvalidArgumentException('The QR text is incomplete or changed (its check code does not match).');
        }

        $fields = [];
        $at = 0;
        $end = strlen($payload) - 8;
        while ($at < $end) {
            $tag = substr($payload, $at, 2);
            $length = substr($payload, $at + 2, 2);
            if (! ctype_digit($tag) || ! ctype_digit($length)) {
                throw new InvalidArgumentException('The QR text is not in EMV form.');
            }
            $fields[$tag] = substr($payload, $at + 4, (int) $length);
            $at += 4 + (int) $length;
        }

        if ($at !== $end || ($fields['53'] ?? '') !== '050') {
            throw new InvalidArgumentException('This is not a taka (BDT) merchant QR.');
        }

        return $fields;
    }

    public function merchant(): string
    {
        return trim($this->fields['59'] ?? '');
    }

    public function city(): string
    {
        return trim($this->fields['60'] ?? '');
    }

    /** The QR's text for one payment: the amount filled in and locked. $amount in poisha. */
    public function forAmount(int $amount): string
    {
        $fields = $this->fields;
        $fields['01'] = '12';
        $fields['54'] = number_format($amount / 100, 2, '.', '');
        // The check code (63) is not among the fields; it is worked out again below.
        ksort($fields, SORT_STRING);

        $text = '';
        foreach ($fields as $tag => $value) {
            $text .= str_pad((string) $tag, 2, '0', STR_PAD_LEFT).str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
        }
        $text .= '6304';

        return $text.self::crc($text);
    }

    /** CRC-16/CCITT-FALSE, as EMVCo asks: four upper-case hex digits. */
    public static function crc(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /**
     * The QR drawn as an SVG: round dots, the brand's teal eyes, and the
     * Klok mark in a clear square in the middle.
     */
    public static function svg(string $text, int $size = 300): string
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::H(), 'UTF-8')->getMatrix();
        $count = $matrix->getWidth();
        $quiet = 3;
        $cell = $size / ($count + 2 * $quiet);
        $ink = '#0F1F1C';
        $brand = '#0D9488';

        // The middle square kept clear for the mark: under a fifth of the code, well within H.
        $hole = (int) floor($count * 0.2);
        $hole += ($count - $hole) % 2;
        $from = intdiv($count - $hole, 2);
        $to = $from + $hole;

        $isEye = fn (int $x, int $y): bool => ($x < 7 && $y < 7) || ($x >= $count - 7 && $y < 7) || ($x < 7 && $y >= $count - 7);
        $dots = '';
        for ($y = 0; $y < $count; $y++) {
            for ($x = 0; $x < $count; $x++) {
                if ($matrix->get($x, $y) !== 1 || $isEye($x, $y) || ($x >= $from && $x < $to && $y >= $from && $y < $to)) {
                    continue;
                }
                $cx = round(($x + $quiet + 0.5) * $cell, 2);
                $cy = round(($y + $quiet + 0.5) * $cell, 2);
                $dots .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.round($cell * 0.46, 2).'"/>';
            }
        }

        $eyes = '';
        foreach ([[0, 0], [$count - 7, 0], [0, $count - 7]] as [$ex, $ey]) {
            $ox = ($ex + $quiet) * $cell;
            $oy = ($ey + $quiet) * $cell;
            $eyes .= '<rect x="'.round($ox + $cell / 2, 2).'" y="'.round($oy + $cell / 2, 2).'" width="'.round($cell * 6, 2).'" height="'.round($cell * 6, 2).'" rx="'.round($cell * 1.8, 2).'" fill="none" stroke="'.$brand.'" stroke-width="'.round($cell, 2).'"/>'
                .'<rect x="'.round($ox + $cell * 2, 2).'" y="'.round($oy + $cell * 2, 2).'" width="'.round($cell * 3, 2).'" height="'.round($cell * 3, 2).'" rx="'.round($cell, 2).'" fill="'.$ink.'"/>';
        }

        // The Klok mark (as in BrandMark.vue), scaled into the clear square.
        $markSize = ($hole - 1) * $cell;
        $mx = round(($from + $quiet + 0.5) * $cell, 2);
        $my = round(($from + $quiet + 0.5) * $cell, 2);
        $scale = round($markSize / 64, 4);
        $mark = '<g transform="translate('.$mx.' '.$my.') scale('.$scale.')">'
            .'<rect width="64" height="64" rx="16" fill="#11211E"/>'
            .'<path d="M20 16v32M20 34l14-18M26 27l12 21" fill="none" stroke="#2DD4BF" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>'
            .'<path d="M42 40h6M42 32h6M44 24h4" stroke="#D5E2DE" stroke-width="4" stroke-linecap="round"/>'
            .'</g>';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$size.' '.$size.'" width="'.$size.'" height="'.$size.'" role="img" aria-label="Bangla QR">'
            .'<rect width="100%" height="100%" rx="'.round($cell * 2, 2).'" fill="#FFFFFF"/>'
            .'<g fill="'.$ink.'">'.$dots.'</g>'.$eyes.$mark.'</svg>';
    }
}
