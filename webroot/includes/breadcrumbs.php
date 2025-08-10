<?php
/**
 * Global breadcrumb helper
 * Usage:
 *   include_once $_SERVER['DOCUMENT_ROOT'] . '/includes/breadcrumbs.php';
 *   renderBreadcrumb([
 *      ['label' => 'Zones', 'url' => '?page=zone_manage'],
 *      ['label' => 'Records: example.com'],
 *   ], $isSuperAdmin);
 */
if (!function_exists('renderBreadcrumb')) {
    function renderBreadcrumb(array $items, bool $isSuperAdmin = false, array $options = []): void {
        if (empty($items)) { return; }
        $defaults = [
            'class' => 'mb-3',
            'prependSystemAdmin' => true,
        ];
        $opts = array_merge($defaults, $options);
        echo '<nav aria-label="breadcrumb" class="' . htmlspecialchars($opts['class']) . '"><ol class="breadcrumb">';
        if ($isSuperAdmin && $opts['prependSystemAdmin']) {
            echo '<li class="breadcrumb-item"><a href="?page=admin_dashboard">System Administration</a></li>';
        }
        $last = count($items) - 1;
        foreach ($items as $i => $item) {
            $label = htmlspecialchars($item['label'] ?? '');
            $url = $item['url'] ?? '';
            if ($i === $last || !$url) {
                echo '<li class="breadcrumb-item active" aria-current="page">' . $label . '</li>';
            } else {
                echo '<li class="breadcrumb-item"><a href="' . htmlspecialchars($url) . '">' . $label . '</a></li>';
            }
        }
        echo '</ol></nav>';
    }
}
