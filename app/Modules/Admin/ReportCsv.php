<?php
declare(strict_types=1);
namespace Furoeducare\Modules\Admin;

/** Aggregate-only serialization. No account or business record fields are exported. */
final class ReportCsv
{
    public static function encode(array $snapshot): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new \RuntimeException('Unable to prepare report export.');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            self::row($stream, ['Metric', 'Count', 'From (WAT)', 'To (WAT)', 'Date basis', 'Definition', 'Generated at (UTC)']);
            foreach ($snapshot['metrics'] as $metric) {
                if (!is_int($metric['value']) || $metric['value'] < 0) throw new \RuntimeException('Invalid aggregate report count.');
                self::row($stream, [$metric['label'], (string) $metric['value'], $snapshot['period']['from'] ?? 'All time',
                    $snapshot['period']['to'] ?? 'All time', $metric['date_basis'], $metric['definition'], $snapshot['generated_at']]);
            }
            rewind($stream);
            $result = stream_get_contents($stream);
            if ($result === false) throw new \RuntimeException('Unable to read report export.');
            return $result;
        } finally { fclose($stream); }
    }

    private static function row($stream, array $values): void
    {
        $cells = array_map(static function (string $value): string {
            return preg_match('/^[\x00-\x20]*[=+@-]|^[\t\r\n]/', $value) === 1 ? "'" . $value : $value;
        }, $values);
        if (fputcsv($stream, $cells, ',', '"', '', "\r\n") === false) throw new \RuntimeException('Unable to write report export.');
    }
}
