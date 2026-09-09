<?php
if (!defined('ABSPATH')) exit;

/**
 * Authoritative topology epoch for client pool invalidation.
 *
 * Source/provider removal is not the same thing as a temporary upstream outage.
 * Android intentionally keeps a last-known-good Premium pool during transient
 * refresh failures, so every explicit admin topology mutation advances this
 * epoch. The epoch is folded into account pool_identity responses, making stale
 * Android location/LKG caches ineligible as soon as the client refreshes account
 * metadata.
 */
final class BlueVPN_Topology_Epoch {
    private const OPTION = 'bluevpn_topology_epoch';

    /** @var string[] */
    private const MUTATION_HOOKS = [
        'bluevpn_cc_save_plan_routing',
        'bluevpn_cc_save_plan',
        'bluevpn_cc_delete_plan',
        'bluevpn_cc_restore_plan',
        'bluevpn_cc_save_provider',
        'bluevpn_cc_toggle_provider',
        'bluevpn_cc_delete_provider',
        'bluevpn_cc_save_subscription_source',
        'bluevpn_cc_toggle_subscription_source',
        'bluevpn_cc_delete_subscription_source',
        'bluevpn_shahrah_save',
        'bluevpn_shahrah_toggle',
        'bluevpn_shahrah_delete',
        'bluevpn_free_source_save',
        'bluevpn_free_source_toggle',
        'bluevpn_free_source_delete',
    ];

    public static function init(): void {
        // Priority 0 is intentional. Some legacy admin handlers perform the
        // mutation and redirect/exit from an earlier lifecycle callback, so a
        // trailing hook would never get a chance to invalidate connected apps.
        foreach (self::MUTATION_HOOKS as $hook) {
            add_action('admin_post_' . $hook, [self::class, 'admin_topology_mutation'], 0);
        }

        // Stamp every account-bearing REST response (login/register/refresh,
        // /account and /account/sync) with the same server-authored pool epoch.
        add_filter('rest_post_dispatch', [self::class, 'stamp_rest_response'], 8, 3);
    }

    public static function epoch(): int {
        return max(1, (int)get_option(self::OPTION, 1));
    }

    public static function bump(): int {
        $next = self::epoch() + 1;
        update_option(self::OPTION, (string)$next, false);
        return $next;
    }

    public static function admin_topology_mutation(): void {
        // This does not authorize or execute the requested mutation; the owning
        // admin handler still performs its normal nonce/capability checks. A
        // harmless extra epoch increment is preferable to ever retaining routes
        // after an authoritative delete/disable operation.
        if (!current_user_can('manage_options')) return;
        self::bump();
    }

    public static function stamp_rest_response($response, $server, $request) {
        if (!($request instanceof WP_REST_Request) || !($response instanceof WP_REST_Response)) {
            return $response;
        }

        $route = (string)$request->get_route();
        if (!str_starts_with($route, '/bluevpn/v1/')) return $response;

        $data = $response->get_data();
        if (!is_array($data)) return $response;

        $epoch = self::epoch();
        $changed = false;

        if (isset($data['account']) && is_array($data['account'])) {
            $changed = self::stamp_account_payload($data['account'], $epoch) || $changed;
        }

        // Free clients derive their cache identity from the configured source
        // inventory. Expose the same epoch for diagnostics while the canonical
        // subscription list remains authoritative (including an explicit []).
        if (isset($data['free_access']) && is_array($data['free_access'])) {
            $data['free_access']['topology_epoch'] = $epoch;
            $changed = true;
        }

        if ($changed) $response->set_data($data);
        $response->header('X-BlueVPN-Topology-Epoch', (string)$epoch);
        return $response;
    }

    private static function stamp_account_payload(array &$account, int $epoch): bool {
        if (!isset($account['subscription']) || !is_array($account['subscription'])) return false;

        $subscription =& $account['subscription'];
        $base = trim((string)($subscription['pool_identity'] ?? ''));
        if ($base === '') {
            $base = implode('|', [
                (string)($account['id'] ?? 0),
                (string)($subscription['entitlement_plan_id'] ?? $subscription['plan_id'] ?? 0),
                trim((string)($subscription['url'] ?? '')),
            ]);
        }

        $subscription['pool_identity'] = hash(
            'sha256',
            $base . '|topology-epoch:' . $epoch
        );
        $subscription['topology_epoch'] = $epoch;
        return true;
    }
}
