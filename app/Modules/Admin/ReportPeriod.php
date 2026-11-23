<?php
declare(strict_types=1);
namespace Furoeducare\Modules\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Furoeducare\Core\HttpException;

/** Inclusive calendar dates in Africa/Lagos; database timestamps are UTC. */
final class ReportPeriod
{
    private function __construct(public readonly ?string $from, public readonly ?string $to) {}

    public static function all(): self { return new self(null, null); }

    public static function between(string $from, string $to): self
    {
        foreach ([$from, $to] as $value) {
            $date = preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) === 1
                ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Africa/Lagos')) : false;
            if ($date === false || $date->format('Y-m-d') !== $value || $value < '1000-01-01' || $value > '9998-12-31') {
                throw new HttpException(422, 'Enter valid report dates (YYYY-MM-DD), from year 1000 through 9998.');
            }
        }
        if ($from > $to) { throw new HttpException(422, 'The report end date must follow the start date.'); }
        return new self($from, $to);
    }

    /** @return ?array{string,string} UTC SQL timestamps, inclusive start and exclusive end. */
    public function bounds(): ?array
    {
        if ($this->from === null) { return null; }
        $wat = new DateTimeZone('Africa/Lagos');
        $utc = new DateTimeZone('UTC');
        return [(new DateTimeImmutable($this->from, $wat))->setTimezone($utc)->format('Y-m-d H:i:s'),
            (new DateTimeImmutable($this->to, $wat))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    public function query(): array
    {
        return $this->from === null ? ['period' => 'all'] : ['from' => $this->from, 'to' => $this->to];
    }
}
