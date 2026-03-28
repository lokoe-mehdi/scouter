<?php

/**
 * Tests for crawl comparison logic — URL table comparison column handling
 * and scorecard pre-computation patterns.
 */

describe('Comparison — Column auto-detection', function () {

    /**
     * Simulates the compareColumns resolution logic from url-table.php
     */
    function resolveCompareColumns(?array $explicit, array $defaultColumns): array
    {
        $excludeColumns = ['url', 'category'];

        if ($explicit !== null) {
            return $explicit;
        }

        return array_values(array_filter($defaultColumns, function ($col) use ($excludeColumns) {
            return !in_array($col, $excludeColumns);
        }));
    }

    it('auto-detects all columns except url and category', function () {
        $defaultColumns = ['url', 'category', 'depth', 'code', 'compliant'];
        $result = resolveCompareColumns(null, $defaultColumns);

        expect($result)->toBe(['depth', 'code', 'compliant']);
    });

    it('respects explicit compareColumns when provided', function () {
        $defaultColumns = ['url', 'category', 'depth', 'code', 'compliant'];
        $result = resolveCompareColumns(['depth'], $defaultColumns);

        expect($result)->toBe(['depth']);
    });

    it('returns empty when only url and category in defaults', function () {
        $defaultColumns = ['url', 'category'];
        $result = resolveCompareColumns(null, $defaultColumns);

        expect($result)->toBe([]);
    });

    it('handles single non-excluded column', function () {
        $defaultColumns = ['url', 'inlinks'];
        $result = resolveCompareColumns(null, $defaultColumns);

        expect($result)->toBe(['inlinks']);
    });
});

describe('Comparison — Column expansion', function () {

    /**
     * Simulates the column injection logic from url-table.php
     */
    function expandColumnsWithComparison(array $selectedColumns, array $compareColumns): array
    {
        $expanded = [];
        foreach ($selectedColumns as $col) {
            $expanded[] = $col;
            if (in_array($col, $compareColumns) && !in_array('cmp_' . $col, $selectedColumns)) {
                $expanded[] = 'cmp_' . $col;
            }
        }
        return $expanded;
    }

    it('inserts cmp_ column after each compared column', function () {
        $selected = ['url', 'category', 'depth', 'code'];
        $compare = ['depth', 'code'];

        $result = expandColumnsWithComparison($selected, $compare);

        expect($result)->toBe(['url', 'category', 'depth', 'cmp_depth', 'code', 'cmp_code']);
    });

    it('does not duplicate cmp_ if already present', function () {
        $selected = ['url', 'depth', 'cmp_depth', 'code'];
        $compare = ['depth', 'code'];

        $result = expandColumnsWithComparison($selected, $compare);

        // cmp_depth already in selectedColumns, so not added again
        expect($result)->toBe(['url', 'depth', 'cmp_depth', 'code', 'cmp_code']);
    });

    it('does not add cmp_ for non-compared columns', function () {
        $selected = ['url', 'category', 'depth', 'inlinks'];
        $compare = ['depth'];

        $result = expandColumnsWithComparison($selected, $compare);

        expect($result)->toBe(['url', 'category', 'depth', 'cmp_depth', 'inlinks']);
    });

    it('handles empty compare columns', function () {
        $selected = ['url', 'category', 'depth'];
        $compare = [];

        $result = expandColumnsWithComparison($selected, $compare);

        expect($result)->toBe(['url', 'category', 'depth']);
    });
});

describe('Comparison — renderCol resolution', function () {

    /**
     * Simulates the renderCol/dataField resolution from url-table.php
     */
    function resolveRenderCol(string $col): array
    {
        if (strpos($col, 'cmp_') === 0) {
            return ['renderCol' => substr($col, 4), 'dataField' => $col, 'isCmp' => true];
        }
        return ['renderCol' => $col, 'dataField' => $col, 'isCmp' => false];
    }

    it('resolves regular column', function () {
        $result = resolveRenderCol('depth');
        expect($result['renderCol'])->toBe('depth');
        expect($result['dataField'])->toBe('depth');
        expect($result['isCmp'])->toBeFalse();
    });

    it('resolves cmp_ column correctly', function () {
        $result = resolveRenderCol('cmp_depth');
        expect($result['renderCol'])->toBe('depth');
        expect($result['dataField'])->toBe('cmp_depth');
        expect($result['isCmp'])->toBeTrue();
    });

    it('resolves cmp_code correctly', function () {
        $result = resolveRenderCol('cmp_code');
        expect($result['renderCol'])->toBe('code');
        expect($result['dataField'])->toBe('cmp_code');
        expect($result['isCmp'])->toBeTrue();
    });

    it('does not treat cmp in middle of name as comparison', function () {
        $result = resolveRenderCol('example_cmp_field');
        expect($result['renderCol'])->toBe('example_cmp_field');
        expect($result['isCmp'])->toBeFalse();
    });
});
