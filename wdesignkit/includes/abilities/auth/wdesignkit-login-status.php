<?php
/**
 * Ability: Check WDesignKit login status and account info.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/get-login-status', [
    'label'       => __('Get WDesignKit Login Status', 'wdesignkit'),
    'description' => __(
        'Checks whether the user is logged in to WDesignKit cloud. Returns login status, email, token validity, expiry time, and widget credit limits. Cloud operations like pushing widgets to marketplace require login.',
        'wdesignkit',
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
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'public'       => true,
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
    $email        = null;
    $token_expiry = null;

    // Single shared lookup — see wdesignkit_mcp_find_auth_session() in
    // includes/abilities/class-wdk-ability-main.php. Every cloud ability resolves the
    // session through it, so this status can never contradict what push-widget or any
    // other cloud call sees.
    $session   = wdesignkit_mcp_find_auth_session();
    $logged_in = !empty($session['found']);

    if ($logged_in) {
        $session_state = 'logged_in';
        $email         = $session['data']['user_email'] ?? null;
    } else {
        $session_state = !empty($session['expired']) ? 'session_expired' : 'not_logged_in';
    }

    if (!empty($session['timeout'])) {
        $token_expiry = wp_date('Y-m-d H:i:s', (int) $session['timeout']);
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
