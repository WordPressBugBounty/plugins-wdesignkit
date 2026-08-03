<?php
/**
 * Ability: Atomically delete a code snippet from both local site storage and WDesignKit cloud.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('wdesignkit/delete-snippet-everywhere', [
    'label'       => __('Delete WDesignKit Snippet Everywhere', 'wdesignkit'),
    'description' => __(
        'Atomically deletes a code snippet from both local site storage (file-based or post-based) AND the WDesignKit cloud marketplace in a single call. Requires confirm: true and cloud login for the cloud side. Supports dry_run preview.',
        'wdesignkit',
    ),
    'category'    => 'wdesignkit',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'snippet_id' => [
                'type'        => 'string',
                'description' => 'Cloud snippet ID to delete from WDesignKit marketplace. Resolved automatically from local snippet if omitted.',
            ],
            'file_id' => [
                'type'        => 'string',
                'description' => 'Local file-based snippet key (from wdesignkit/list-local-snippets). Use for Nexter Pro file-based storage.',
            ],
            'post_id' => [
                'type'        => 'integer',
                'description' => 'Local WordPress post ID of the nxt-code-snippet post. Use for post-based storage.',
            ],
            'name' => [
                'type'        => 'string',
                'description' => 'Snippet title/name. Used to look up local and cloud IDs if omitted.',
            ],
            'confirm' => [
                'type'        => 'boolean',
                'description' => 'Must be true to execute deletion. Omit or false for a dry-run preview.',
            ],
            'dry_run' => [
                'type'        => 'boolean',
                'description' => 'When true, returns preview of target local and cloud snippets to be deleted without modifying files/records.',
            ],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'       => ['type' => 'boolean'],
            'message'       => ['type' => 'string'],
            'dry_run'       => ['type' => 'boolean'],
            'local_deleted' => ['type' => ['boolean', 'null']],
            'cloud_deleted' => ['type' => ['boolean', 'null']],
            'local_target'  => ['type' => ['object', 'null']],
            'cloud_target'  => ['type' => ['object', 'null']],
        ],
    ],
    'execute_callback'    => 'wdesignkit_mcp_delete_snippet_everywhere',
    'permission_callback' => 'wdesignkit_mcp_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true],
        'annotations'  => [
            'instructions' => implode("\n", [
                'Deletes a code snippet from both local site storage AND the WDesignKit cloud marketplace in one call.',
                'confirm: true is required to execute. Without it the call returns a dry-run preview.',
                'Identify by snippet_id (cloud), file_id / post_id (local), or snippet name.',
                'Reports exact execution results for both local and cloud targets.',
            ]),
            'readonly'    => false,
            'destructive' => true,
            'idempotent'  => false,
        ],
    ],
]);

function wdesignkit_mcp_delete_snippet_everywhere(array $input): array {
    set_time_limit(90);

    $snippet_id = sanitize_text_field((string) ($input['snippet_id'] ?? ''));
    $file_id    = sanitize_text_field((string) ($input['file_id'] ?? ''));
    $post_id    = (int) ($input['post_id'] ?? 0);
    $name       = sanitize_text_field((string) ($input['name'] ?? ''));
    $confirm    = !empty($input['confirm']);
    $dry_run    = !empty($input['dry_run']);

    $auth = function_exists('wdesignkit_mcp_template_get_auth') ? wdesignkit_mcp_template_get_auth() : [];

    $local_target = null;
    $cloud_target = null;

    // ── 1. Resolve Local Target ───────────────────────────────────────────────
    if ($file_id !== '' || $post_id > 0) {
        if (function_exists('wdesignkit_mcp_get_snippet_info')) {
            $info = wdesignkit_mcp_get_snippet_info(['file_id' => $file_id, 'post_id' => $post_id]);
            if (!empty($info['success']) && !empty($info['data'])) {
                $d = $info['data'];
                $local_target = [
                    'file_id' => (string) ($d['file_id'] ?? $file_id ?: ''),
                    'post_id' => (int) ($d['post_id'] ?? $post_id ?: 0),
                    'name'    => (string) ($d['title'] ?? $name),
                    'storage' => (string) ($info['source'] ?? ($file_id !== '' ? 'file' : 'post')),
                ];
            }
        }
    }

    if (!$local_target && function_exists('wdesignkit_mcp_list_local_snippets')) {
        $local_list = wdesignkit_mcp_list_local_snippets(['search' => $name]);
        if (!empty($local_list['success']) && !empty($local_list['snippets'])) {
            foreach ($local_list['snippets'] as $item) {
                $item_name = (string) ($item['name'] ?? '');
                if ($name !== '' && strtolower($item_name) === strtolower($name)) {
                    $local_target = [
                        'file_id' => (string) ($item['file_id'] ?? ''),
                        'post_id' => (int) ($item['post_id'] ?? 0),
                        'name'    => $item_name,
                        'storage' => (string) ($item['storage'] ?? ''),
                    ];
                    break;
                }
            }
        }
    }

    // ── 2. Resolve Cloud Target ───────────────────────────────────────────────
    if ($snippet_id !== '') {
        $cloud_target = [
            'snippet_id' => $snippet_id,
            'title'      => $name ?: ($local_target['name'] ?? $snippet_id),
        ];
    } elseif (!empty($auth['logged_in'])) {
        $search_term = $name ?: ($local_target['name'] ?? '');
        if ($search_term !== '' && function_exists('wdesignkit_mcp_template_cloud_call')) {
            $cloud_list = wdesignkit_mcp_template_cloud_call('snippet/save/get_exist', [
                'token'  => $auth['token'],
                'search' => $search_term,
            ], 'form');

            $rows = $cloud_list['data']['snippets'] ?? $cloud_list['data'] ?? [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $row_title = (string) ($row['title'] ?? $row['name'] ?? '');
                    if (strtolower($row_title) === strtolower($search_term)) {
                        $cloud_target = [
                            'snippet_id' => (string) ($row['id'] ?? $row['snippet_id'] ?? ''),
                            'title'      => $row_title,
                        ];
                        break;
                    }
                }
            }
        }
    }

    if (!$local_target && !$cloud_target) {
        return [
            'success'      => false,
            'message'      => 'No matching local or cloud snippet found to delete. Specify snippet_id, file_id, post_id, or snippet name.',
            'dry_run'      => $dry_run,
            'local_target' => null,
            'cloud_target' => null,
        ];
    }

    // ── 3. Dry Run / Confirmation Check ───────────────────────────────────────
    if ($dry_run) {
        return [
            'success'       => true,
            'message'       => 'Dry run: Preview of snippet deletion everywhere.',
            'dry_run'       => true,
            'local_deleted' => null,
            'cloud_deleted' => null,
            'local_target'  => $local_target,
            'cloud_target'  => $cloud_target,
        ];
    }

    if (!$confirm) {
        $target_desc = [];
        if ($local_target) {
            $target_desc[] = "local snippet '{$local_target['name']}'";
        }
        if ($cloud_target) {
            $target_desc[] = "cloud snippet ID '{$cloud_target['snippet_id']}'";
        }
        return [
            'success'       => false,
            'message'       => 'Deleting snippet everywhere will permanently remove ' . implode(' and ', $target_desc) . '. Re-send request with confirm: true to proceed, or use dry_run: true to preview.',
            'dry_run'       => true,
            'local_deleted' => null,
            'cloud_deleted' => null,
            'local_target'  => $local_target,
            'cloud_target'  => $cloud_target,
        ];
    }

    // ── 4. Execute Deletion ───────────────────────────────────────────────────
    $local_deleted = null;
    $cloud_deleted = null;
    $errors        = [];

    // Local deletion
    if ($local_target) {
        $storage = $local_target['storage'] ?? '';
        $fid     = $local_target['file_id'] ?? '';
        $pid     = (int) ($local_target['post_id'] ?? 0);

        if ($storage === 'file' || ($fid !== '' && $fid !== '0')) {
            if (class_exists('Nexter_Code_Snippets_File_Based')) {
                $file_based   = new \Nexter_Code_Snippets_File_Based();
                $snippet_data = $file_based->get_all_snippets([], $fid, true);
                if (!empty($snippet_data['file']) && file_exists($snippet_data['file'])) {
                    if (@unlink($snippet_data['file'])) {
                        if (method_exists($file_based, 'snippetIndexData')) {
                            $file_based->snippetIndexData();
                        }
                        $local_deleted = true;
                    } else {
                        $local_deleted = false;
                        $errors[]      = "Failed to delete local snippet file '{$fid}'.";
                    }
                } else {
                    $local_deleted = false;
                    $errors[]      = "Local snippet file '{$fid}' not found on disk.";
                }
            } else {
                $local_deleted = false;
                $errors[]      = 'Nexter_Code_Snippets_File_Based class not available.';
            }
        } elseif ($pid > 0) {
            if (get_post($pid)) {
                $del_res = wp_delete_post($pid, true);
                $local_deleted = (false !== $del_res && null !== $del_res);
                if (!$local_deleted) {
                    $errors[] = "Failed to delete local snippet post ID {$pid}.";
                }
            } else {
                $local_deleted = false;
                $errors[]      = "Local snippet post ID {$pid} not found.";
            }
        }
    }

    // Cloud deletion
    if ($cloud_target && !empty($cloud_target['snippet_id'])) {
        $cid = $cloud_target['snippet_id'];
        if (empty($auth['logged_in'])) {
            $cloud_deleted = false;
            $errors[]      = 'Cloud login required to delete cloud snippet record.';
        } elseif (function_exists('wdesignkit_mcp_template_cloud_call')) {
            $cloud_res = wdesignkit_mcp_template_cloud_call('snippet/delete', [
                'token' => $auth['token'],
                'id'    => $cid,
            ], 'form');

            $cloud_deleted = !empty($cloud_res['success']);
            if (!$cloud_deleted) {
                $errors[] = 'Cloud error: ' . ($cloud_res['message'] ?? $cloud_res['massage'] ?? 'Failed to delete cloud snippet.');
            }
        }
    }

    // Determine overall success
    $all_ok = ($local_deleted !== false) && ($cloud_deleted !== false);

    if (!$all_ok) {
        $msg = 'Partial snippet deletion. ' . implode(' ', $errors);
    } elseif ($local_deleted === true && $cloud_deleted === true) {
        $msg = 'Snippet successfully deleted everywhere (local and cloud).';
    } elseif ($local_deleted === true && $cloud_deleted === null) {
        $msg = 'Snippet deleted locally. No linked cloud copy was found.';
    } elseif ($local_deleted === null && $cloud_deleted === true) {
        $msg = 'Cloud snippet deleted. No linked local copy was found.';
    } else {
        $msg = 'Nothing to delete — no linked local or cloud copy was found.';
    }

    return [
        'success'       => $all_ok,
        'message'       => $msg,
        'dry_run'       => false,
        'local_deleted' => $local_deleted,
        'cloud_deleted' => $cloud_deleted,
        'local_target'  => $local_target,
        'cloud_target'  => $cloud_target,
    ];
}
