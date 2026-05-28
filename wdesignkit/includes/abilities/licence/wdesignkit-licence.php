<?php
/**
 * Abilities: Activate, delete, sync, and overview WDesignKit licence keys.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// ──────────────────────────────────────────────────────────────────────────────
// Activate Licence
// ──────────────────────────────────────────────────────────────────────────────

wp_register_ability('wdesignkit/activate-licence', [
    'label'       => __('Activate WDesignKit Licence', 'sprout-mcp'),
    'description' => __(
        'Activates a licence key against the WDesignKit cloud API. On success the returned licence record is stored locally in the wdkit_licence_data option so licence-gated features are immediately available. Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'licencekey' => [
                'type'        => 'string',
                'description' => 'The licence key to activate.',
            ],
            'licencename' => [
                'type'        => 'string',
                'description' => 'Product identifier for the key being activated.',
                'enum'        => ['wdkit', 'tpae', 'tpgb', 'uichemy'],
            ],
            'uichemyid' => [
                'type'        => 'string',
                'description' => 'UIChemy-specific product ID. Only required when licencename is "uichemy".',
            ],
        ],
        'required' => ['licencekey', 'licencename'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'message'       => ['type' => 'string'],
            'licence_saved' => ['type' => 'boolean'],
            'response'      => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_activate_licence',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Activates a WDesignKit licence key. Requires cloud login.',
                'licencename values: "wdkit" (WDesignKit), "tpae" (The Plus Elementor), "tpgb" (The Plus Gutenberg), "uichemy" (UIChemy).',
                'On success for licencename "wdkit", the licence data is stored locally in wdkit_licence_data option.',
                'Use wdesignkit/licence-overview to verify the activation status.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => false,
        ],
    ],
]);

// ──────────────────────────────────────────────────────────────────────────────
// Delete Licence
// ──────────────────────────────────────────────────────────────────────────────

wp_register_ability('wdesignkit/delete-licence', [
    'label'       => __('Delete WDesignKit Licence', 'sprout-mcp'),
    'description' => __(
        'Deactivates and removes a licence key from the WDesignKit cloud. For the "wdkit" licence, the local wdkit_licence_data option is also deleted. Requires cloud login and confirm: true.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'licencename' => [
                'type'        => 'string',
                'description' => 'Product identifier of the licence to delete.',
                'enum'        => ['wdkit', 'tpae', 'tpgb', 'uichemy'],
            ],
            'apikey' => [
                'type'        => 'string',
                'description' => 'The API / licence key to deactivate. For "wdkit", if omitted the locally stored key is used automatically. If provided it must match the locally stored key — mismatches are rejected before any cloud call is made.',
            ],
            'confirm' => [
                'type'        => 'boolean',
                'description' => 'Must be true to execute the deletion.',
            ],
        ],
        'required' => ['licencename'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'         => ['type' => 'boolean'],
            'message'         => ['type' => 'string'],
            'local_data_deleted' => ['type' => 'boolean'],
            'response'        => ['type' => ['object', 'array']],
            'dry_run'         => ['type' => 'boolean'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_delete_licence',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Deletes a WDesignKit licence key. Requires cloud login and confirm: true.',
                'For licencename "wdkit", the local wdkit_licence_data WordPress option is also removed.',
                'Without confirm: true the call returns a dry-run preview only.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

// ──────────────────────────────────────────────────────────────────────────────
// Sync Licence
// ──────────────────────────────────────────────────────────────────────────────

wp_register_ability('wdesignkit/sync-licence', [
    'label'       => __('Sync WDesignKit Licence', 'sprout-mcp'),
    'description' => __(
        'Refreshes a licence record by re-fetching its current status from the WDesignKit cloud API. Useful after a plan upgrade or after renewing an expired licence. Requires cloud login.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'licencename' => [
                'type'        => 'string',
                'description' => 'Product identifier of the licence to sync.',
                'enum'        => ['wdkit', 'tpae', 'tpgb', 'uichemy'],
            ],
        ],
        'required' => ['licencename'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'message'  => ['type' => 'string'],
            'response' => ['type' => ['object', 'array']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_sync_licence',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Syncs a licence record with the WDesignKit cloud. Requires cloud login.',
                'Use after upgrading a plan or renewing an expired licence to pull the latest status.',
                'After syncing "wdkit", call wdesignkit/licence-overview to see the updated status.',
            ]),
            'readonly'    => false,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

// ──────────────────────────────────────────────────────────────────────────────
// Licence Credit & Storage Overview
// ──────────────────────────────────────────────────────────────────────────────

wp_register_ability('wdesignkit/licence-overview', [
    'label'       => __('WDesignKit Licence Credit & Storage Overview', 'sprout-mcp'),
    'description' => __(
        'Returns a combined overview of the locally stored WDesignKit licence record (plan, expiry, status) plus current AI credit balance fetched from the cloud. Set refresh: true to fetch fresh credit data; omit it to use locally cached licence data only.',
        'sprout-mcp',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'refresh' => [
                'type'        => 'boolean',
                'description' => 'When true, fetches fresh AI credit data from the cloud (requires login). Defaults to false.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'message'       => ['type' => 'string'],
            'licence'       => ['type' => ['object', 'null']],
            'credits'       => ['type' => ['object', 'null']],
            'credits_fresh' => ['type' => 'boolean'],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_licence_overview',
    'permission_callback' => 'sprout_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Returns the stored licence record and optionally live AI credit balance.',
                'licence is read from the wdkit_licence_data WordPress option (cached after activate/sync).',
                'Set refresh: true to fetch fresh credit data from the cloud — requires login.',
                'Use wdesignkit/sync-licence to refresh the licence record itself.',
            ]),
            'readonly'    => true,
            'destructive' => false,
            'idempotent'  => true,
        ],
    ],
]);

// ──────────────────────────────────────────────────────────────────────────────
// Callbacks
// ──────────────────────────────────────────────────────────────────────────────

function wdesignkit_mcp_activate_licence(array $input): array {
    // Timeout guard: cloud activation call uses the default HTTP timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $licencekey  = sanitize_text_field((string) ($input['licencekey'] ?? ''));
    $licencename = sanitize_text_field((string) ($input['licencename'] ?? ''));
    $uichemyid   = sanitize_text_field((string) ($input['uichemyid'] ?? ''));

    if ($licencekey === '' || $licencename === '') {
        return ['success' => false, 'message' => 'licencekey and licencename are required.'];
    }

    $args = [
        'token'       => $auth['token'],
        'licencekey'  => $licencekey,
        'licencename' => $licencename,
        'uichemyid'   => $uichemyid,
    ];

    $cloud = wdesignkit_mcp_template_cloud_call('wkit_activate_key', $args, 'form');

    if (empty($cloud['success'])) {
        $message = (string) ($cloud['massage'] ?? $cloud['message'] ?? 'Activation failed.');

        // Normalize cloud-side debug strings before surfacing to the caller.
        // "ProductType Not Found Going to else" is an internal PHP branch trace from
        // the tpgb activation handler — replace with a clean user-facing message.
        if (stripos($message, 'Going to else') !== false) {
            $message = 'Invalid License Key.';
        }

        // "key already exists" is returned by the wdkit cloud endpoint when a licence
        // is already registered on the account — regardless of whether the new key is
        // valid. Rewrite it to make the actual situation clear to the caller.
        if (stripos($message, 'key already exists') !== false) {
            $message = "A {$licencename} licence is already active on this site. Use wdesignkit/delete-licence to remove it before activating a new key.";
        }

        return [
            'success'  => false,
            'message'  => $message,
            'response' => $cloud,
        ];
    }

    // Persist wdkit licence data locally when available
    $licence_saved = false;
    $wdkit_licence = $cloud['data']['wdkit_licence'] ?? null;

    if ($wdkit_licence !== null) {
        if (is_string($wdkit_licence) && is_serialized($wdkit_licence)) {
            $wdkit_licence = @unserialize($wdkit_licence);
        }
        if (is_array($wdkit_licence) && !empty($wdkit_licence)) {
            update_option('wdkit_licence_data', $wdkit_licence);
            $licence_saved = true;
        }
    }

    return [
        'success'       => true,
        'message'       => "Licence '{$licencename}' activated successfully." . ($licence_saved ? ' Local licence data updated.' : ''),
        'licence_saved' => $licence_saved,
        'response'      => $cloud,
    ];
}

function wdesignkit_mcp_delete_licence(array $input): array {
    // Timeout guard: cloud delete call uses the default HTTP timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $licencename = sanitize_text_field((string) ($input['licencename'] ?? ''));
    $apikey      = sanitize_text_field((string) ($input['apikey'] ?? ''));
    $confirm     = (bool) ($input['confirm'] ?? false);

    if ($licencename === '') {
        return ['success' => false, 'message' => 'licencename is required.'];
    }

    // ── Security guard for wdkit: validate apikey against the locally stored key ──
    // Without this check, any string in apikey permanently deactivates the licence
    // on the cloud (reduces site_count) regardless of whether it matches the real key.
    if ($licencename === 'wdkit') {
        $stored_licence = get_option('wdkit_licence_data', []);

        // Pre-flight: bail early if no local record exists (cloud returns vacuous success
        // with raw debug strings for empty registrations — avoid that idempotency violation).
        if (empty($stored_licence)) {
            return [
                'success'            => false,
                'message'            => 'No active wdkit licence is registered on this site.',
                'local_data_deleted' => false,
                'dry_run'            => false,
            ];
        }

        // Extract the stored API key — try all known field names returned by the cloud.
        $stored_key = '';
        if (is_array($stored_licence)) {
            foreach (['ApiKey', 'api_key', 'licencekey', 'licence_key', 'key', 'item_api_key'] as $field) {
                if (!empty($stored_licence[$field]) && is_string($stored_licence[$field])) {
                    $stored_key = $stored_licence[$field];
                    break;
                }
            }
        }

        if ($stored_key !== '') {
            if ($apikey === '') {
                // Caller omitted apikey — use the stored key automatically (safest UX).
                $apikey = $stored_key;
            } elseif ($apikey !== $stored_key) {
                // Caller provided a key that doesn't match — reject before any cloud call.
                return [
                    'success'            => false,
                    'message'            => 'Provided API key does not match the stored licence key. Deletion rejected. Omit apikey to use the stored key automatically.',
                    'local_data_deleted' => false,
                    'dry_run'            => false,
                ];
            }
        } elseif ($apikey === '') {
            return ['success' => false, 'message' => 'apikey is required — could not resolve it from local licence data.', 'local_data_deleted' => false, 'dry_run' => false];
        }
    } elseif ($apikey === '') {
        return ['success' => false, 'message' => 'apikey is required for licencename "' . $licencename . '".', 'local_data_deleted' => false, 'dry_run' => false];
    }

    if (!$confirm) {
        return [
            'success' => false,
            'message' => "Dry run: would delete licence '{$licencename}' (key: " . substr($apikey, 0, 4) . '…' . substr($apikey, -4) . ').' . ($licencename === 'wdkit' ? ' Local wdkit_licence_data option would also be removed.' : '') . ' Pass confirm: true to execute.',
            'dry_run' => true,
        ];
    }

    $args = [
        'token'       => $auth['token'],
        'licencename' => $licencename,
        'apikey'      => $apikey,
    ];

    $cloud = wdesignkit_mcp_template_cloud_call('licence_delete', $args, 'form');

    $local_deleted = false;
    if ($licencename === 'wdkit') {
        delete_option('wdkit_licence_data');
        $local_deleted = true;
    }

    if (empty($cloud['success'])) {
        return [
            'success'            => false,
            'message'            => $cloud['massage'] ?? $cloud['message'] ?? 'Cloud deletion failed.',
            'local_data_deleted' => $local_deleted,
            'response'           => $cloud,
            'dry_run'            => false,
        ];
    }

    return [
        'success'            => true,
        'message'            => "Licence '{$licencename}' deleted." . ($local_deleted ? ' Local wdkit_licence_data removed.' : ''),
        'local_data_deleted' => $local_deleted,
        'response'           => $cloud,
        'dry_run'            => false,
    ];
}

function wdesignkit_mcp_sync_licence(array $input): array {
    // Timeout guard: cloud sync call uses the default HTTP timeout.
    set_time_limit(90);

    if (!defined('WDKIT_SERVER_API_URL')) {
        return ['success' => false, 'message' => 'WDesignKit plugin is not active.'];
    }

    $auth = wdesignkit_mcp_template_get_auth();
    if (empty($auth['logged_in'])) {
        return ['success' => false, 'message' => $auth['message'] ?? 'Not logged in to WDesignKit cloud.'];
    }

    $licencename = sanitize_text_field((string) ($input['licencename'] ?? ''));

    if ($licencename === '') {
        return ['success' => false, 'message' => 'licencename is required.'];
    }

    $args = [
        'token'       => $auth['token'],
        'licencename' => $licencename,
    ];

    $cloud = wdesignkit_mcp_template_cloud_call('licence_sync', $args, 'form');

    if (empty($cloud['success'])) {
        $message = $cloud['massage'] ?? $cloud['message'] ?? 'Sync failed.';

        // Normalize cloud-side inconsistency: tpgb returns "Licence Name Not Found"
        // for the same condition (no licence registered for that product) that
        // tpae/uichemy return "Record Not Found" for. Standardise to "Record Not Found"
        // so callers see a consistent error message across all licence types.
        if (stripos($message, 'Licence Name Not Found') !== false) {
            $message = 'Record Not Found';
        }

        return [
            'success'  => false,
            'message'  => $message,
            'response' => $cloud,
        ];
    }

    return [
        'success'  => true,
        'message'  => "Licence '{$licencename}' synced successfully.",
        'response' => $cloud,
    ];
}

function wdesignkit_mcp_licence_overview(array $input): array {
    $refresh = (bool) ($input['refresh'] ?? false);

    // Always read locally cached licence data
    $licence = get_option('wdkit_licence_data', null);
    $licence = is_array($licence) ? $licence : null;

    $credits       = null;
    $credits_fresh = false;

    if ($refresh) {
        // Timeout guard: cloud credit fetch uses the default HTTP timeout.
        set_time_limit(60);

        if (!defined('WDKIT_SERVER_API_URL')) {
            return [
                'success'       => false,
                'message'       => 'WDesignKit plugin is not active.',
                'licence'       => $licence,
                'credits'       => null,
                'credits_fresh' => false,
            ];
        }

        $auth = wdesignkit_mcp_template_get_auth();
        if (empty($auth['logged_in'])) {
            return [
                'success'       => false,
                'message'       => $auth['message'] ?? 'Not logged in — cannot fetch fresh credit data. Set refresh: false to view cached licence only.',
                'licence'       => $licence,
                'credits'       => null,
                'credits_fresh' => false,
            ];
        }

        $cloud = wdesignkit_mcp_template_cloud_call('ai/credits/get', ['token' => $auth['token']], 'form');

        if (!empty($cloud['success'])) {
            $credits       = $cloud['data'] ?? $cloud;
            $credits_fresh = true;
        }
    }

    $has_data = $licence !== null || $credits !== null;

    return [
        'success'       => true,
        'message'       => $has_data
            ? 'Licence overview loaded.' . ($credits_fresh ? ' Credits fetched fresh from cloud.' : ' Use refresh: true to fetch live credit data.')
            : 'No local licence data found. Activate a licence with wdesignkit/activate-licence first.',
        'licence'       => $licence,
        'credits'       => $credits,
        'credits_fresh' => $credits_fresh,
    ];
}
