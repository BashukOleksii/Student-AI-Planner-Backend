<?php

namespace Tests\Concerns;

/** Compare sparse/dense source families rather than historical legacy-only query totals. */
trait AssertsBatchedReads
{
    private function assertSameReadShape(array $sparse, array $dense): void
    {
        $families = function (array $queries): array {
            $counts = [];
            foreach ($queries as $query) {
                $sql = strtolower($query['query']);
                $this->assertStringStartsWith('select ', $sql);
                $this->assertStringNotContainsString('for update', $sql);
                preg_match('/\bfrom\s+`?([a-z_]+)`?/', $sql, $match);
                $this->assertNotEmpty($match, 'Every read must identify an explicit source family.');
                $counts[$match[1]] = ($counts[$match[1]] ?? 0) + 1;
            }
            ksort($counts);

            return $counts;
        };
        $this->assertNotEmpty($sparse);
        $this->assertSame($families($sparse), $families($dense), 'Source growth must not add per-record queries or change read families.');
    }
}
