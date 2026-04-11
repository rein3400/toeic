<?php
/**
 * CSRF Token Helper
 *
 * Provides token generation and validation for AJAX endpoints.
 * Requires session_handler.php to be loaded first (session must be active).
 */

/**
 * Generate a CSRF token and store it in the session.
 * Returns the token string for embedding in forms/JS.
 */
function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token against the session token.
 * Accepts token from POST, JSON body, or custom header.
 *
 * @param string|null $token  Explicit token to validate (optional).
 *                            If null, reads from $_POST['csrf_token'],
 *                            JSON body 'csrf_token', or X-CSRF-Token header.
 * @return bool
 */
function validateCsrfToken(?string $token = null): bool
{
    if ($token === null) {
        // Try POST parameter
        $token = $_POST['csrf_token'] ?? null;

        // Try JSON body
        if ($token === null) {
            $input = file_get_contents('php://input');
            if ($input) {
                $json = json_decode($input, true);
                $token = $json['csrf_token'] ?? null;
            }
        }

        // Try header
        if ($token === null) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        }
    }

    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Output a hidden input field with the CSRF token.
 * For use in HTML forms.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
}

/**
 * Return a meta tag for JS to read the token from.
 * Place in <head> of pages that make AJAX calls.
 */
function csrfMeta(): string
{
    return '<meta name="csrf-token" content="' . htmlspecialchars(generateCsrfToken()) . '">';
}
