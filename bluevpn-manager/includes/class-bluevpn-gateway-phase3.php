<?php
if (!defined('ABSPATH')) exit;

/**
 * BlueVPN Gateway Phase 3 groundwork.
 *
 * This layer is deliberately observational first: it derives a deterministic
 * health/capacity score from the heartbeat fields already written by Phase 2
 * and stores a fleet snapshot. It does not automatically drain nodes or change
 * live customer routing yet; that keeps the first Phase 3 step rollback-safe.
 */
final class BlueVPN_Gateway_Phase3 {
    private const SNAPSHOT_OPTION='bluevpn_gateway_phase3_snapshot';
    private const STALE_AFTER_SECONDS=180;

    public static function init(): void {
        add_action('bluevpn_manager_cleanup',[self::class,'refresh_snapshot'],30);
    }

    public static function score_node(array $node): int {
        if((int)($node['active']??0)!==1||(int)($node['draining']??0)===1)return 0;

        $score=100;
        $seen=trim((string)($node['last_seen_at']??''));
        if($seen==='')$score-=35;
        else{
            $ts=strtotime($seen.' UTC');
            if($ts===false)$score-=35;
            else{
                $age=max(0,time()-$ts);
                if($age>self::STALE_AFTER_SECONDS)$score-=min(60,20+(int)floor(($age-self::STALE_AFTER_SECONDS)/30)*5);
            }
        }

        $load=max(0.0,(float)($node['last_load1']??0));
        $score-=min(25,(int)round($load*4));

        $pending=max(0,(int)($node['last_pending_events']??0));
        $score-=min(20,(int)floor($pending/25)*2);

        $active=max(0,(int)($node['last_active_sessions']??0));
        $max=max(0,(int)($node['max_sessions']??0));
        if($max>0){
            $ratio=min(1.5,$active/$max);
            $score-=min(30,(int)round($ratio*25));
        }

        if(trim((string)($node['last_error']??''))!=='')$score-=10;
        return max(0,min(100,$score));
    }

    /** @return array<int,array<string,mixed>> */
    public static function ranked_nodes(): array {
        global $wpdb;
        $table=BlueVPN_DB::table('gateway_nodes');
        $rows=$wpdb->get_results(
            "SELECT id,name,active,draining,priority,max_sessions,last_seen_at,last_active_sessions,last_pending_events,last_load1,last_error
             FROM {$table} WHERE active=1 ORDER BY priority ASC,id ASC",
            ARRAY_A
        )?:[];

        foreach($rows as &$row)$row['phase3_health_score']=self::score_node($row);
        unset($row);

        usort($rows,static function(array $a,array $b): int {
            $pa=(int)($a['priority']??100);$pb=(int)($b['priority']??100);
            if($pa!==$pb)return $pa<=>$pb;
            $sa=(int)($a['phase3_health_score']??0);$sb=(int)($b['phase3_health_score']??0);
            if($sa!==$sb)return $sb<=>$sa;
            $aa=(int)($a['last_active_sessions']??0);$ab=(int)($b['last_active_sessions']??0);
            if($aa!==$ab)return $aa<=>$ab;
            return ((int)$a['id'])<=>((int)$b['id']);
        });
        return $rows;
    }

    public static function refresh_snapshot(): void {
        $nodes=self::ranked_nodes();
        $snapshot=[
            'schema'=>1,
            'generated_at'=>BlueVPN_Utils::iso_now(),
            'phase'=>'3-groundwork',
            'nodes'=>array_map(static function(array $node): array {
                return [
                    'id'=>(int)($node['id']??0),
                    'name'=>(string)($node['name']??''),
                    'priority'=>(int)($node['priority']??100),
                    'health_score'=>(int)($node['phase3_health_score']??0),
                    'active_sessions'=>(int)($node['last_active_sessions']??0),
                    'pending_events'=>(int)($node['last_pending_events']??0),
                    'load1'=>(float)($node['last_load1']??0),
                    'last_seen_at'=>(string)($node['last_seen_at']??''),
                ];
            },$nodes),
        ];
        update_option(self::SNAPSHOT_OPTION,$snapshot,false);
    }

    public static function snapshot(): array {
        $value=get_option(self::SNAPSHOT_OPTION,[]);
        return is_array($value)?$value:[];
    }
}
