<?php
/**
 * PDNS Console
 * Copyright (c) 2025 Neowyze LLC
 *
 * Licensed under the Business Source License 1.0.
 * You may use this file in compliance with the license terms.
 *
 * License details: https://github.com/andersonit/pdnsconsole/blob/main/LICENSE.md
 */

/**
 * Shared pagination & per-page selector helpers
 */

if (!function_exists('formatCountRange')) {
    function formatCountRange(int $start, int $end, int $total, string $nounPlural): string {
        if ($total === 0) { return 'No ' . $nounPlural; }
        return 'Showing ' . number_format($start) . '–' . number_format($end) . ' of ' . number_format($total) . ' ' . $nounPlural; }
}

if (!function_exists('buildQueryString')) {
    function buildQueryString(array $params): string {
        // Filter null or empty (but allow 0)
        $filtered = array_filter($params, function($v){ return $v !== null && $v !== ''; });
        return http_build_query($filtered);
    }
}

if (!function_exists('renderPaginationNav')) {
    /**
     * Render compact pagination nav (current ±1 window with first/last & ellipses)
     * $cfg keys: current,total_pages,page_param,base_params(limit included),size(optional)
     */
    function renderPaginationNav(array $cfg): void {
        $current = max(1, (int)$cfg['current']);
        $total = max(0, (int)$cfg['total_pages']);
        if ($total <= 1) { return; }
        $pageParam = $cfg['page_param'];
        $base = $cfg['base_params'] ?? [];
        $sizeClass = 'pagination-sm';
        $windowStart = max(1, $current - 1);
        $windowEnd   = min($total, $current + 1);
        echo '<nav aria-label="Pagination"><ul class="pagination '.$sizeClass.' mb-0">';
        // First
        $disabledFirst = $current <= 1 ? ' disabled' : '';
        $firstQuery = buildQueryString(array_merge($base, [$pageParam => 1]));
        echo '<li class="page-item'.$disabledFirst.'"><a class="page-link" aria-label="First page" href="?'.$firstQuery.'"><span aria-hidden="true">«</span></a></li>';
        // Prev
        $prevQuery = buildQueryString(array_merge($base, [$pageParam => max(1, $current-1)]));
        echo '<li class="page-item'.$disabledFirst.'"><a class="page-link" aria-label="Previous page" href="?'.$prevQuery.'"><span aria-hidden="true">‹</span></a></li>';
        // Leading first + ellipsis
        if ($windowStart > 1) {
            $q1 = buildQueryString(array_merge($base, [$pageParam => 1]));
            echo '<li class="page-item"><a class="page-link" href="?'.$q1.'">1</a></li>';
            if ($windowStart > 2) {
                echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
        }
        for ($i=$windowStart;$i<=$windowEnd;$i++) {
            $qi = buildQueryString(array_merge($base, [$pageParam => $i]));
            $active = $i === $current ? ' active' : '';
            echo '<li class="page-item'.$active.'"><a class="page-link" href="?'.$qi.'">'.$i.'</a></li>';
        }
        if ($windowEnd < $total) {
            if ($windowEnd < $total - 1) {
                echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            $qlast = buildQueryString(array_merge($base, [$pageParam => $total]));
            echo '<li class="page-item"><a class="page-link" href="?'.$qlast.'">'.$total.'</a></li>';
        }
        // Next
        $disabledLast = $current >= $total ? ' disabled' : '';
        $nextQuery = buildQueryString(array_merge($base, [$pageParam => min($total, $current+1)]));
        echo '<li class="page-item'.$disabledLast.'"><a class="page-link" aria-label="Next page" href="?'.$nextQuery.'"><span aria-hidden="true">›</span></a></li>';
        // Last
        $lastQuery = buildQueryString(array_merge($base, [$pageParam => $total]));
        echo '<li class="page-item'.$disabledLast.'"><a class="page-link" aria-label="Last page" href="?'.$lastQuery.'"><span aria-hidden="true">»</span></a></li>';
        echo '</ul></nav>';
    }
}

if (!function_exists('renderPerPageForm')) {
    /**
     * Render per-page selector form
     * cfg keys: base_params, page_param, limit, limit_options, label (plural noun)
     */
    function renderPerPageForm(array $cfg): void {
        $base = $cfg['base_params'] ?? [];
        $pageParam = $cfg['page_param'];
        $limit = (int)$cfg['limit'];
        $options = $cfg['limit_options'] ?? [10,25,50,100];
        // Reset page to 1 on change
        echo '<form class="d-flex align-items-center gap-1 mb-0" method="GET" action="">';
        foreach ($base as $k=>$v) {
            if ($k === $pageParam) { continue; }
            echo '<input type="hidden" name="'.htmlspecialchars($k).'" value="'.htmlspecialchars((string)$v).'">';
        }
        echo '<input type="hidden" name="'.htmlspecialchars($pageParam).'" value="1">';
        echo '<label for="limit" class="col-form-label small mb-0 text-nowrap">Per page</label>';
        echo '<select name="limit" id="limit" class="form-select form-select-sm" onchange="this.form.submit()">';
        foreach ($options as $opt) {
            $sel = $opt == $limit ? ' selected' : '';
            echo '<option value="'.$opt.'"'.$sel.'>'.$opt.'</option>';
        }
        echo '</select></form>';
    }
}

if (!function_exists('preparePaginationBaseParams')) {
    function preparePaginationBaseParams(array $params, string $pageParam): array {
        // ensure pageParam present for consistency
        if (!isset($params[$pageParam])) { $params[$pageParam] = 1; }
        return $params;
    }
}
?>
