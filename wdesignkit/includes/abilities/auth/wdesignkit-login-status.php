<?php
/**
 * Ability: Check WDesignKit login status and account info.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-login-status', [
    'label'       => __('Get WDesignKit Login Status', 'sprout-mcp'),
    'description' => __(
        'Checks whether the user is logged in to WDesignKit cloud. Returns login status, email, token validity, expiry time, and widget credit limits. Cloud operations like pushing widgets to marketplace require login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type' => 'object',
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'             => ['type' => 'boolean'],
            'logged_in'           => ['type' => 'boolean'],
            'session_state'       => ['type' => 'string'],
            'email'               => ['type' => ['string', 'null']],
            'token_expiry'        => ['type' => ['string', 'null']],
            'deactivated_widgets' => ['type' => 'integer'],
            'login_url'           => ['type' => 'string'],
            'message'             => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_login_status',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Checks if the user is logged in to WDesignKit cloud (wdesignkit.com).',
                'Login is required for cloud operations: pushing widgets to marketplace, downloading from marketplace, workspace sharing.',
                'Local widget CRUD (create, edit, delete, list) does NOT require login.',
                'session_state values:',
                '- "logged_in": active valid session found',
                '- "session_expired": a session existed but the token has expired — user must log in again',
                '- "not_logged_in": no session found at all',
                'If not logged in or session expired, direct the user to WP Admin → WDesignKit → Login.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

function wdesignkit_mcp_get_login_status(array $input): array {
    $logged_in     = false;
    $email         = null;
    $token_expiry  = null;
    $session_state = 'not_logged_in';

    // Normalise a raw transient value into an associative array.
    // Handles three possible shapes from different storage backends:
    //   1. PHP serialized string  → maybe_unserialize returns an array  ✓
    //   2. JSON-encoded string    → json_decode($v, true) returns an array
    //   3. stdClass object        → (array) cast converts public props to keys
    $normalise_auth = static function ($raw): array {
        if (is_array($raw)) {
            return $raw;
        }
        if ($raw instanceof \stdClass) {
            return (array) $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    };

    // First try: use current WP user email to construct the transient key
    $current_user = wp_get_current_user();
    if ($current_user && $current_user->user_email) {
        $user_key = strstr($current_user->user_email, '@', true);
        $timeout  = get_option('_transient_timeout_wdkit_auth_' . $user_key);

        // Explicit expiry guard: external object caches may return stale transient
        // data after the timeout has passed. Compare the raw timestamp first.
        if ($timeout && (int) $timeout < time()) {
            delete_transient('wdkit_auth_' . $user_key);
            $session_state = 'session_expired';
        } else {
            $auth_data = $normalise_auth(get_transient('wdkit_auth_' . $user_key));
            if (!empty($auth_data['token'])) {
                $logged_in     = true;
                $email         = $auth_data['user_email'] ?? $current_user->user_email;
                $session_state = 'logged_in';
                if ($timeout) {
                    $token_expiry = wp_date('Y-m-d H:i:s', (int) $timeout);
                }
            }
        }
    }

    // Second try: scan transients table
    if (!$logged_in) {
        global $wpdb;
        $transient_prefix = '_transient_wdkit_auth_';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 5",
                $wpdb->esc_like($transient_prefix) . '%'
            ),
            ARRAY_A
        );

        $found_expired = false;

        foreach (($rows ?: []) as $row) {
            $key     = str_replace('_transient_', '', $row['option_name']);
            $timeout = get_option('_transient_timeout_' . $key);

            if ($timeout && (int) $timeout < time()) {
                // Found a transient but it's expired
                $found_expired = true;
                continue;
            }

            $data = $normalise_auth(@maybe_unserialize($row['option_value']));
            if (!empty($data['token'])) {
                $logged_in     = true;
                $email         = $data['user_email'] ?? null;
                $session_state = 'logged_in';

                if ($timeout) {
                    $token_expiry = wp_date('Y-m-d H:i:s', (int) $timeout);
                }
                break;
            }
        }

        // If not logged in but found expired transient, report session as expired
        if (!$logged_in && $found_expired) {
            $session_state = 'session_expired';
        }
    }

    $login_url        = admin_url('admin.php?page=wdesignkit');
    $deactivated_count = count((array) get_option('wkit_deactivate_widgets', []));

    if (!$logged_in) {
        $message = $session_state === 'session_expired'
            ? 'Your WDesignKit session has expired. Go to WP Admin → WDesignKit and log in again to restore cloud access.'
            : 'Not logged in to WDesignKit. Go to WP Admin → WDesignKit and click Login. Login is needed for cloud features (marketplace, workspace). Local widget creation works without login.';

        return [
            'success'             => true,
            'logged_in'           => false,
            'session_state'       => $session_state,
            'email'               => $email,
            'token_expiry'        => $token_expiry,
            'deactivated_widgets' => $deactivated_count,
            'message'             => $message,
            'login_url'           => $login_url,
        ];
    }

    return [
        'success'             => true,
        'logged_in'           => true,
        'session_state'       => 'logged_in',
        'email'               => $email,
        'token_expiry'        => $token_expiry,
        'deactivated_widgets' => $deactivated_count,
        'message'             => 'Logged in to WDesignKit as ' . ($email ?? '') . ($token_expiry ? '. Session expires: ' . $token_expiry . '.' : '.') . ' Cloud features are available.',
        'login_url'           => $login_url,
    ];
}
