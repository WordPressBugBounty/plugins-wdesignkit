<?php
/**
 * Abilities: Login, logout, sign up, and password reset for WDesignKit cloud.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/login', [
    'label'       => __('Login to WDesignKit Cloud', 'sprout-mcp'),
    'description' => __(
        'Authenticates the user against the WDesignKit cloud using email and password, and stores the session token locally. Use remember_me: true for a 90-day session; false (default) for a 1-day session.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'email' => [
                'type'        => 'string',
                'description' => 'WDesignKit cloud account email address.',
            ],
            'password' => [
                'type'        => 'string',
                'description' => 'WDesignKit cloud account password.',
            ],
            'remember_me' => [
                'type'        => 'boolean',
                'description' => 'true = 90-day session (Remember Me). false (default) = 1-day session.',
            ],
        ],
        'required' => ['email', 'password'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'email'   => ['type' => ['string', 'null']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_login',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Logs in with email and password and stores the session locally.',
                'remember_me: true stores for 90 days; false (default) stores for 1 day.',
                'After logging in, use wdesignkit/get-login-status to confirm the session.',
                'All cloud operations (workspace, marketplace, upload) require a valid session.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

wp_register_ability('wdesignkit/login-api-key', [
    'label'       => __('Login to WDesignKit Cloud with API Key', 'sprout-mcp'),
    'description' => __(
        'Authenticates the user against the WDesignKit cloud using a personal API token (instead of email and password), and stores the session locally. Useful for automation and CI workflows.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'api_key' => [
                'type'        => 'string',
                'description' => 'WDesignKit personal API token / key.',
            ],
        ],
        'required' => ['api_key'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'email'   => ['type' => ['string', 'null']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_login_api_key',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Logs in using a WDesignKit API token rather than email/password.',
                'Obtain the API key from wdesignkit.com account settings.',
                'Session is stored for 90 days on success.',
                'Use wdesignkit/get-login-status to confirm the session afterwards.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

wp_register_ability('wdesignkit/social-login', [
    'label'       => __('Login / Sign Up to WDesignKit Cloud via Google or Facebook', 'sprout-mcp'),
    'description' => __(
        'Completes a WDesignKit Google or Facebook OAuth login/signup using the state code produced after the user authorises in the OAuth browser popup. Covers Login with Google, Login with Facebook, Sign Up with Google, and Sign Up with Facebook — all share the same backend endpoint.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'state' => [
                'type'        => 'string',
                'description' => 'The unique state ID generated before opening the OAuth popup. The server exchanges this for a token after the user authorises.',
            ],
        ],
        'required' => ['state'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'email'    => ['type' => ['string', 'null']],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_social_login',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Step 2 of the Google/Facebook OAuth login or signup flow.',
                'First call wdesignkit/get-social-login-url(provider) to get the auth_url and state (Step 1).',
                'Open auth_url in a browser popup for the user to authorise, then call this ability with the state.',
                'Covers: Login with Google, Login with Facebook, Sign Up with Google, Sign Up with Facebook.',
                'On success the session is stored for 90 days.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

wp_register_ability('wdesignkit/signup', [
    'label'       => __('Sign Up for WDesignKit Cloud', 'sprout-mcp'),
    'description' => __(
        'Creates a new WDesignKit cloud account with a full name, email address, and password, then stores the session token locally so cloud features are immediately available.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'fullname' => [
                'type'        => 'string',
                'description' => 'Full name for the new account.',
            ],
            'email' => [
                'type'        => 'string',
                'description' => 'Email address for the new account.',
            ],
            'password' => [
                'type'        => 'string',
                'description' => 'Password for the new account.',
            ],
        ],
        'required' => ['fullname', 'email', 'password'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
            'email'   => ['type' => ['string', 'null']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_signup',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Creates a new WDesignKit cloud account and stores the session.',
                'If successful, the user is immediately logged in (1-day session stored).',
                'Use wdesignkit/get-login-status to confirm the session afterwards.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

wp_register_ability('wdesignkit/forgot-password', [
    'label'       => __('WDesignKit Cloud Forgot Password', 'sprout-mcp'),
    'description' => __(
        'Sends a password reset email to the specified WDesignKit cloud account address. This is a read-only operation — no local session is modified.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'email' => [
                'type'        => 'string',
                'description' => 'Email address of the WDesignKit cloud account to reset.',
            ],
        ],
        'required' => ['email'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_forgot_password',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Sends a password reset email to the given address.',
                'No local session is created or modified.',
                'After resetting, use wdesignkit/login to start a new session.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/logout', [
    'label'       => __('Logout from WDesignKit Cloud', 'sprout-mcp'),
    'description' => __(
        'Logs the currently authenticated user out of WDesignKit cloud. Deletes the local session transient, calls the cloud logout endpoint to invalidate the token, and clears the cached licence data.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => (object) [],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'message' => ['type' => 'string'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_logout',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Logs out the current WDesignKit cloud session.',
                'Deletes the local auth transient and calls the cloud logout endpoint.',
                'Also clears cached licence data (wdkit_licence_data option).',
                'Use wdesignkit/get-login-status to confirm logout.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

wp_register_ability('wdesignkit/get-social-login-url', [
    'label'       => __('Get WDesignKit Social Login URL', 'sprout-mcp'),
    'description' => __(
        'Generates a Google or Facebook OAuth authorisation URL and a unique state token. Step 1 of social login: open the returned auth_url in a browser popup for the user to authorise, then pass the state to wdesignkit/social-login (Step 2) once the popup closes.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'provider' => [
                'type'        => 'string',
                'description' => 'OAuth provider: "google" or "facebook".',
                'enum'        => ['google', 'facebook'],
            ],
        ],
        'required' => ['provider'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'    => ['type' => 'boolean'],
            'message'    => ['type' => 'string'],
            'provider'   => ['type' => 'string'],
            'auth_url'   => ['type' => 'string'],
            'state'      => ['type' => 'string'],
            'expires_in' => ['type' => 'integer'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_get_social_login_url',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Step 1 of the 2-step Google/Facebook OAuth login or signup flow.',
                'Returns auth_url and state. Open auth_url in a browser popup for the user to authorise.',
                'After the user authorises and the popup closes, pass state to wdesignkit/social-login.',
                'The state token is valid for approximately 10 minutes (expires_in seconds).',
                'Covers: Login with Google, Login with Facebook, Sign Up with Google, Sign Up with Facebook.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

// === Callback implementations ===

function wdesignkit_mcp_login(array $input): array {
    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $email       = strtolower(sanitize_email((string) ($input['email'] ?? '')));
    $password    = sanitize_text_field((string) ($input['password'] ?? ''));
    $remember_me = (bool) ($input['remember_me'] ?? false);

    if ($email === '' || $password === '') {
        return ['success' => false, 'message' => 'email and password are required.'];
    }

    $user_key = strstr($email, '@', true);

    // Clear any existing session for this user
    delete_transient('wdkit_auth_' . $user_key);

    $response = wdesignkit_mcp_template_cloud_call('login', [
        'user_email' => $email,
        'password'   => $password,
        'site_url'   => home_url(),
    ], 'json');

    if (empty($response['success'])) {
        return [
            'success' => false,
            'message' => $response['message'] ?? $response['massage'] ?? 'Login failed. Check email and password.',
            'email'   => null,
        ];
    }

    $token = sanitize_text_field((string) ($response['token'] ?? ''));

    if ($token === '') {
        return ['success' => false, 'message' => 'Login succeeded but no token returned.', 'email' => null];
    }

    // Resolve cloud user_id — needed for download-widget u_id auto-resolution.
    // The login response may expose it under different keys depending on the endpoint.
    $user_data_login = $response['user'] ?? [];
    $cloud_user_id   = (string) ($user_data_login['id'] ?? $user_data_login['user_id'] ?? $response['user_id'] ?? $response['id'] ?? '');

    // Store session — 90 days for "remember me", 1 day for session-only
    $expiry = $remember_me ? 7776000 : 86400;
    set_transient('wdkit_auth_' . $user_key, [
        'user_email' => $email,
        'token'      => $token,
        'user_id'    => $cloud_user_id,
    ], $expiry);

    return [
        'success' => true,
        'message' => $response['message'] ?? 'Logged in successfully.',
        'email'   => $email,
    ];
}

function wdesignkit_mcp_login_api_key(array $input): array {
    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $api_key = sanitize_text_field((string) ($input['api_key'] ?? ''));

    if ($api_key === '') {
        return ['success' => false, 'message' => 'api_key is required.'];
    }

    $response = wdesignkit_mcp_template_cloud_call('login/api', [
        'token'    => $api_key,
        'site_url' => home_url(),
    ], 'form');

    if (empty($response['success'])) {
        $message = $response['message'] ?? $response['massage'] ?? 'API key login failed.';
        // Normalize raw HTTP-status messages ("WDesignKit cloud returned status 4xx")
        // that leak from the shared cloud-call helper on authentication failures.
        if (preg_match('/returned status\s+4\d\d/i', $message)) {
            $message = 'Invalid API key. Please check your WDesignKit API token.';
        }
        return ['success' => false, 'message' => $message, 'email' => null];
    }

    // Response data: user->user_email
    $user_data  = $response['user'] ?? [];
    $user_email = strtolower(sanitize_email((string) ($user_data['user_email'] ?? '')));
    $user_key   = $user_email !== '' ? strstr($user_email, '@', true) : '';

    if ($user_key !== '') {
        // $user_data already extracted above: $response['user'] ?? []
        $cloud_user_id_api = (string) ($user_data['id'] ?? $user_data['user_id'] ?? $response['user_id'] ?? $response['id'] ?? '');
        set_transient('wdkit_auth_' . $user_key, [
            'user_email' => $user_email,
            'token'      => $api_key,
            'user_id'    => $cloud_user_id_api,
        ], 7776000); // 90 days
    }

    return [
        'success' => true,
        'message' => 'Logged in with API key successfully.',
        'email'   => $user_email ?: null,
    ];
}

function wdesignkit_mcp_social_login(array $input): array {
    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $state = sanitize_text_field((string) ($input['state'] ?? ''));

    if ($state === '') {
        return ['success' => false, 'message' => 'state is required.'];
    }

    $response = wdesignkit_mcp_template_cloud_call('login/ip', [
        'state'    => $state,
        'site_url' => home_url(),
    ], 'form');

    if (empty($response['success'])) {
        return [
            'success'  => false,
            'message'  => $response['message'] ?? $response['massage'] ?? 'Social login failed. Ensure OAuth was completed in the browser popup first.',
            'email'    => null,
            'response' => $response,
        ];
    }

    // Response contains user->user_email and token at top level
    $user_data  = $response['user'] ?? [];
    $user_email = strtolower(sanitize_email((string) ($user_data['user_email'] ?? '')));
    $token      = sanitize_text_field((string) ($response['token'] ?? ''));
    $user_key   = $user_email !== '' ? strstr($user_email, '@', true) : '';

    if ($user_key !== '' && $token !== '') {
        $cloud_user_id_social = (string) ($user_data['id'] ?? $user_data['user_id'] ?? $response['user_id'] ?? $response['id'] ?? '');
        set_transient('wdkit_auth_' . $user_key, [
            'user_email' => $user_email,
            'token'      => $token,
            'user_id'    => $cloud_user_id_social,
        ], 7776000); // 90 days
    }

    return [
        'success'  => true,
        'message'  => $response['message'] ?? 'Logged in via social account successfully.',
        'email'    => $user_email ?: null,
        'response' => $response,
    ];
}

function wdesignkit_mcp_signup(array $input): array {
    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $fullname  = sanitize_text_field((string) ($input['fullname'] ?? ''));
    $raw_email = trim((string) ($input['email'] ?? ''));
    $password  = sanitize_text_field((string) ($input['password'] ?? ''));

    if ($fullname === '' || $raw_email === '' || $password === '') {
        return ['success' => false, 'message' => 'fullname, email, and password are required.', 'email' => null];
    }

    // Validate email format before sanitizing — sanitize_email() returns '' for
    // malformed addresses, which would incorrectly trigger the "required" error above.
    if (!filter_var($raw_email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address.', 'email' => null];
    }

    $email    = strtolower(sanitize_email($raw_email));
    $user_key = strstr($email, '@', true);

    // Clear any existing session before signup
    delete_transient('wdkit_auth_' . $user_key);

    $response = wdesignkit_mcp_template_cloud_call('signup', [
        'fullname'   => $fullname,
        'password'   => $password,
        'user_email' => $email,
    ], 'json');

    if (empty($response['success'])) {
        return [
            'success' => false,
            'message' => $response['message'] ?? $response['massage'] ?? 'Sign up failed.',
            'email'   => null,
        ];
    }

    $token = sanitize_text_field((string) ($response['token'] ?? ''));

    if ($token !== '') {
        $signup_user_data  = $response['user'] ?? [];
        $cloud_user_id_sig = (string) ($signup_user_data['id'] ?? $signup_user_data['user_id'] ?? $response['user_id'] ?? $response['id'] ?? '');
        set_transient('wdkit_auth_' . $user_key, [
            'user_email' => $email,
            'token'      => $token,
            'user_id'    => $cloud_user_id_sig,
        ], 86400); // 1-day session after signup
    }

    return [
        'success' => true,
        'message' => $response['message'] ?? 'Account created and logged in successfully.',
        'email'   => $email,
    ];
}

function wdesignkit_mcp_forgot_password(array $input): array {
    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $email = strtolower(sanitize_email((string) ($input['email'] ?? '')));

    if ($email === '') {
        return ['success' => false, 'message' => 'email is required.'];
    }

    $response = wdesignkit_mcp_template_cloud_call('password/forgot', [
        'email'    => $email,
        'site_url' => home_url(),
    ], 'form');

    $success = !empty($response['success']);
    $message = $response['message'] ?? $response['massage'] ?? ($success ? 'Password reset email sent.' : 'Failed to send reset email.');

    return [
        'success' => $success,
        'message' => $message,
    ];
}

function wdesignkit_mcp_get_social_login_url(array $input): array {
    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $provider = sanitize_text_field((string) ($input['provider'] ?? ''));

    if (!in_array($provider, ['google', 'facebook'], true)) {
        return ['success' => false, 'message' => 'provider must be "google" or "facebook".'];
    }

    // Generate a state token matching the JS keyUniqueID() format:
    // 6 base-36 alphanumeric chars + 2-digit year suffix (e.g. "ab3f2x26").
    $year  = substr(date('Y'), -2);
    $state = substr(base_convert(bin2hex(random_bytes(5)), 16, 36), 0, 6) . $year;

    $api_base   = rtrim(WDKIT_SERVER_API_URL, '/');
    $expires_in = 600; // OAuth state tokens are valid for ~10 minutes

    if ($provider === 'google') {
        $auth_url = add_query_arg([
            'client_id'     => '428406150181-7rui8lmg2m9nkqqahreida3j02apfnim.apps.googleusercontent.com',
            'redirect_uri'  => $api_base . '/api/auth/google/callback-plugin',
            'response_type' => 'code',
            'scope'         => 'email profile',
            'state'         => $state,
        ], 'https://accounts.google.com/o/oauth2/auth');
    } else {
        $auth_url = add_query_arg([
            'client_id'     => '590712039607331',
            'redirect_uri'  => $api_base . '/api/auth/facebook/callback-plugin',
            'response_type' => 'code',
            'scope'         => 'email',
            'state'         => $state,
        ], 'https://www.facebook.com/v12.0/dialog/oauth');
    }

    return [
        'success'    => true,
        'message'    => "Open auth_url in a browser popup for the user to authorise with {$provider}, then pass state to wdesignkit/social-login.",
        'provider'   => $provider,
        'auth_url'   => $auth_url,
        'state'      => $state,
        'expires_in' => $expires_in,
    ];
}

function wdesignkit_mcp_logout(array $input): array {
    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();

    if (empty($auth['logged_in'])) {
        return ['success' => true, 'message' => 'Already logged out — no active session found.'];
    }

    $token = $auth['token'] ?? '';
    $email = $auth['email'] ?? '';

    // Delete local session transient
    if ($email !== '') {
        $user_key = strstr($email, '@', true);
        delete_transient('wdkit_auth_' . $user_key);
    }

    // Clear cached licence data
    delete_option('wdkit_licence_data');

    // Notify cloud
    if ($token !== '') {
        wdesignkit_mcp_template_cloud_call('logout', ['token' => $token], 'json');
    }

    return ['success' => true, 'message' => 'Logged out successfully.'];
}
