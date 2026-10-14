<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class SqlCompatibilityTest extends TestCase
{
    /**
     * Scans the src/ directory for any raw SQL statements that compare boolean columns
     * (like is_public) with numeric literals (0 or 1), which causes PostgreSQL to fail.
     */
    public function testNoBooleanNumericComparisonsInRawSql(): void
    {
        $srcDir = dirname(__DIR__) . '/src';
        $directory = new \RecursiveDirectoryIterator($srcDir);
        $iterator = new \RecursiveIteratorIterator($directory);

        $violations = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getPathname();
                $content = file_get_contents($filePath);
                if ($content === false) {
                    continue;
                }

                // Find occurrences of is_public = 1, is_public = 0, is_public != 1,
                // is_public != 0, is_public <> 1, is_public <> 0, etc.
                if (preg_match_all('/is_public\s*(?:=|\!=|<>)\s*(?:0|1)/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $matchedText = $match[0];
                        $offset = $match[1];
                        // Convert offset to line number
                        $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;
                        $violations[] = "$filePath:$lineNumber -> Found numeric boolean comparison: '$matchedText'";
                    }
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "SQL Compatibility violation: PostgreSQL does not allow comparing boolean columns with " .
            "numeric values (0 or 1). Use TRUE/FALSE literals instead.\n" .
            implode("\n", $violations)
        );
    }
}
