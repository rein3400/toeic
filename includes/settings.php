<?php
// Settings helper functions

// In-memory cache: loaded once per request, serves all getSiteSetting() calls
$_settingsCache = null;
$_settingsCacheLoaded = false;

/**
 * Bulk-load all settings into memory (single query per request).
 */
function _loadAllSettings() {
    global $conn, $_settingsCache, $_settingsCacheLoaded;

    if ($_settingsCacheLoaded) return;
    $_settingsCacheLoaded = true;
    $_settingsCache = [];

    if (!isset($conn)) {
        require_once __DIR__ . '/config.php';
    }

    if (!($conn instanceof mysqli)) {
        return;
    }

    try {
        $result = $conn->query("SELECT setting_key, setting_value FROM site_settings");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $_settingsCache[$row['setting_key']] = $row['setting_value'];
            }
            $result->free();
        }
    } catch (\Throwable $e) {
        // Table may not exist yet
    }
}

// Function to get setting value
function getSiteSetting($key, $default = '') {
    global $_settingsCache, $_settingsCacheLoaded;

    if (!$_settingsCacheLoaded) {
        _loadAllSettings();
    }

    return $_settingsCache[$key] ?? $default;
}

// Function to get website title
function getWebsiteTitle() {
    return getSiteSetting('website_title', 'FOEM UPY');
}

// Function to get website logo
function getWebsiteLogo() {
    return getSiteSetting('website_logo', '');
}

// Function to get website favicon
function getWebsiteFavicon() {
    return getSiteSetting('website_favicon', '');
}

// Function to get logo HTML
function getLogoHTML($class = '', $alt = 'Logo') {
    $logo = getWebsiteLogo();
    if (!empty($logo) && file_exists(__DIR__ . '/../' . $logo)) {
        return '<img src="' . htmlspecialchars($logo) . '" alt="' . htmlspecialchars($alt) . '" class="' . htmlspecialchars($class) . '">';
    }
    return '';
}

// Function to get favicon HTML
function getFaviconHTML() {
    $favicon = getWebsiteFavicon();
    if (!empty($favicon) && file_exists(__DIR__ . '/../' . $favicon)) {
        $ext = pathinfo($favicon, PATHINFO_EXTENSION);
        $type = 'image/x-icon';
        
        if ($ext == 'png') $type = 'image/png';
        elseif ($ext == 'jpg' || $ext == 'jpeg') $type = 'image/jpeg';
        elseif ($ext == 'gif') $type = 'image/gif';
        
        return '<link rel="icon" type="' . $type . '" href="' . htmlspecialchars($favicon) . '">';
    }
    return '';
}

// Append a filemtime-based cache-busting query string to public assets.
function getVersionedAssetUrl($assetPath, $href = null) {
    $publicPath = ltrim((string)$assetPath, '/');
    $hrefPath = $href ?? $publicPath;
    $absolutePath = __DIR__ . '/../' . $publicPath;

    if (file_exists($absolutePath)) {
        return $hrefPath . '?v=' . filemtime($absolutePath);
    }

    return $hrefPath;
}
?>
