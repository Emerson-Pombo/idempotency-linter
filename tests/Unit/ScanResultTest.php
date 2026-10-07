<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Unit;

use IdempotencyLinter\Report\Finding;
use IdempotencyLinter\Report\RiskLevel;
use IdempotencyLinter\Report\ScanResult;
use PHPUnit\Framework\TestCase;

final class ScanResultTest extends TestCase
{
    private function finding(string $job, RiskLevel $risk, string $file = 'a.php', int $line = 1): Finding
    {
        return new Finding($file, $line, $job, 'sink', $risk, 'msg');
    }

    public function test_findings_are_sorted_by_risk_then_file_then_line(): void
    {
        $result = new ScanResult();
        $low = $this->finding('A', RiskLevel::Low);
        $highB = $this->finding('B', RiskLevel::High, 'b.php', 3);
        $highA2 = $this->finding('C', RiskLevel::High, 'a.php', 9);
        $highA1 = $this->finding('D', RiskLevel::High, 'a.php', 2);

        foreach ([$low, $highB, $highA2, $highA1] as $f) {
            $result->addFinding($f);
        }

        $this->assertSame([$highA1, $highA2, $highB, $low], $result->findings());
    }

    public function test_has_findings_at_or_above_threshold(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding('A', RiskLevel::Medium));

        $this->assertTrue($result->hasFindingsAtOrAbove(RiskLevel::Low));
        $this->assertTrue($result->hasFindingsAtOrAbove(RiskLevel::Medium));
        $this->assertFalse($result->hasFindingsAtOrAbove(RiskLevel::High));
    }

    public function test_risky_jobs_are_counted_once_per_job(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding('A', RiskLevel::High));
        $result->addFinding($this->finding('A', RiskLevel::Low));
        $result->addFinding($this->finding('B', RiskLevel::Low));

        $this->assertSame(2, $result->riskyJobCount());
    }

    public function test_records_errors(): void
    {
        $result = new ScanResult();
        $result->addError('x.php', 'boom');

        $this->assertSame([['file' => 'x.php', 'error' => 'boom']], $result->errors());
    }
}
