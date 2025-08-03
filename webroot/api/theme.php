<?php
/**
 * PDNS Console - Theme Switcher API
 */

// Include configuration first
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/config.php';

// Start session and include required classes
session_start();
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Settings.php';

// For now, skip authentication check since it's not implemented yet
// TODO: Add authentication check when auth system is complete

// Set JSON response header
header('Content-Type: application/json');

// Add CORS headers for local development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // Initialize settings (Database is initialized as singleton inside Settings)
    $settings = new Settings();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get available themes and current theme with dark mode info
        $themeInfo = $settings->getThemeInfo();
        
        $response = [
            'success' => true,
            'current_theme' => $themeInfo['theme'],
            'theme_name' => $themeInfo['theme_name'],
            'dark_mode' => $themeInfo['dark_mode'],
            'naturally_dark' => $themeInfo['naturally_dark'],
            'effective_dark' => $themeInfo['effective_dark'],
            'available_themes' => AVAILABLE_THEMES,
            'theme_categories' => THEME_CATEGORIES,
            'naturally_dark_themes' => NATURALLY_DARK_THEMES,
            'theme_url' => $themeInfo['theme_url']
        ];
        
        echo json_encode($response);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle theme change or dark mode toggle
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (isset($input['theme'])) {
            // Change theme
            $theme = $input['theme'];
            
            if (!$settings->isValidTheme($theme)) {
                throw new Exception('Invalid theme name');
            }
            
            $success = $settings->setTheme($theme);
            
            if ($success) {
                $themeInfo = $settings->getThemeInfo();
                $response = [
                    'success' => true,
                    'message' => 'Theme updated successfully',
                    'theme' => $themeInfo['theme'],
                    'theme_name' => $themeInfo['theme_name'],
                    'dark_mode' => $themeInfo['dark_mode'],
                    'naturally_dark' => $themeInfo['naturally_dark'],
                    'effective_dark' => $themeInfo['effective_dark'],
                    'theme_url' => $themeInfo['theme_url']
                ];
            } else {
                throw new Exception('Failed to update theme');
            }
            
        } elseif (isset($input['dark_mode'])) {
            // Toggle dark mode
            $darkMode = (bool)$input['dark_mode'];
            $success = $settings->setDarkMode($darkMode);
            
            if ($success) {
                $themeInfo = $settings->getThemeInfo();
                $response = [
                    'success' => true,
                    'message' => 'Dark mode ' . ($darkMode ? 'enabled' : 'disabled'),
                    'theme' => $themeInfo['theme'],
                    'theme_name' => $themeInfo['theme_name'], 
                    'dark_mode' => $themeInfo['dark_mode'],
                    'naturally_dark' => $themeInfo['naturally_dark'],
                    'effective_dark' => $themeInfo['effective_dark'],
                    'theme_url' => $themeInfo['theme_url']
                ];
            } else {
                throw new Exception('Failed to update dark mode setting');
            }
            
        } else {
            throw new Exception('Either theme or dark_mode parameter required');
        }
        
        echo json_encode($response);
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Theme API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}
?>
