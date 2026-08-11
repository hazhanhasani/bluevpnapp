<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_AI {
    private const LIVE_TTL_SECONDS = 180;
    private const LIVE_PROBE_MAX_AGE_MS = 130000;

    public static function init(): void {
        add_action('admin_post_bluevpn_blueai_save', [self::class, 'save_settings']);
    }

    private static function clean($value, int $limit = 120, string $fallback = 'unknown'): string {
        $text = preg_replace('/[\x00-\x1f]+/u', ' ', (string)($value ?? '')) ?: '';
        $text = trim($text);
        return mb_substr($text !== '' ? $text : $fallback, 0, $limit);
    }

    private static function clamp($value, int $min, int $max, int $default = 0): int {
        if (!is_numeric($value)) return $default;
        return max($min, min($max, (int)$value));
    }

    public static function canonical_operator($value): string {
        $raw = mb_strtolower(str_replace('‌', '', self::clean($value, 100, '')));
        foreach ([
            'ایرانسل' => ['irancell', 'mtn', 'ایرانسل'],
            'همراه اول' => ['hamrah', 'mci', 'همراه اول', 'همراه‌اول'],
            'رایتل' => ['rightel', 'رایتل'],
            'شاتل موبایل' => ['shatel', 'شاتل'],
            'سامانتل' => ['samantel', 'saman tel', 'سامانتل'],
            'آپتل' => ['aptel', 'آپتل'],
            'تالیا' => ['taliya', 'تالیا'],
        ] as $name => $needles) {
            foreach ($needles as $needle) if (str_contains($raw, $needle)) return $name;
        }
        if (in_array($raw, ['wi-fi', 'wifi'], true)) return 'Wi-Fi';
        return $raw !== '' ? $raw : 'ناشناخته';
    }

    private static function hour_bucket($value = null): int {
        return $value === null || $value === '' ? (int)gmdate('G') : self::clamp($value, 0, 23, (int)gmdate('G'));
    }

    private static function heartbeat_proof(array $p): array {
        $session = self::clean($p['session_id'] ?? '', 80, '');
        $age = self::clamp($p['probe_age_ms'] ?? 86400000, 0, 86400000, 86400000);
        $source = self::clean($p['verification_source'] ?? '', 80, '');
        $checks = [
            'session' => strlen($session) >= 8,
            'connected' => BlueVPN_Utils::boolish($p['connected'] ?? false),
            'tunnel_running' => BlueVPN_Utils::boolish($p['tunnel_running'] ?? false),
            'vpn_transport' => BlueVPN_Utils::boolish($p['vpn_transport'] ?? false),
            'internet_verified' => BlueVPN_Utils::boolish($p['internet_verified'] ?? false),
            'fresh_probe' => $age <= self::LIVE_PROBE_MAX_AGE_MS,
            'source' => in_array($source, ['bluevpn-health', 'cloudflare-204', 'google-204', 'cloudflare-trace', 'xray-http-probe'], true),
        ];
        $failed = [];
        foreach ($checks as $key => $ok) if (!$ok) $failed[] = $key;
        return [!$failed, implode(',', $failed)];
    }

    private static function epoch_to_mysql($value): ?string {
        if (!is_numeric($value) || (float)$value <= 0) return null;
        $raw = (float)$value;
        if ($raw > 10000000000) $raw /= 1000;
        return gmdate('Y-m-d H:i:s', (int)$raw);
    }

    private static function update_live(int $customerId, array $p, string $operator, string $network, string $mode, bool $proofOk, string $proofError): array {
        global $wpdb;
        $t = BlueVPN_DB::table('ai_live_connections');
        $device = self::clean($p['device_id'] ?? '', 80, '');
        $session = self::clean($p['session_id'] ?? '', 80, '');
        if ($device === '' || $session === '') return [null, false];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE customer_id=%d AND device_id=%s LIMIT 1", $customerId, $device), ARRAY_A);
        $same = $row && (string)$row['session_id'] === $session;
        $seq = self::clamp($p['heartbeat_seq'] ?? 0, 0, PHP_INT_MAX, 0);
        if ($same && $seq > 0 && $seq < (int)($row['heartbeat_seq'] ?? 0)) return [$row, false];
        $now = BlueVPN_Utils::now_mysql();
        if (!$proofOk) {
            if ($same) $wpdb->update($t, [
                'connected' => 0, 'verified' => 0,
                'tunnel_running' => BlueVPN_Utils::boolish($p['tunnel_running'] ?? false) ? 1 : 0,
                'vpn_transport' => BlueVPN_Utils::boolish($p['vpn_transport'] ?? false) ? 1 : 0,
                'last_seen_at' => $now, 'expires_at' => $now, 'disconnected_at' => $now,
                'disconnect_reason' => mb_substr('unverified:' . $proofError, 0, 500),
            ], ['id' => (int)$row['id']]);
            return [$row, false];
        }
        $incomingDown = self::clamp($p['download_bytes'] ?? 0, 0, PHP_INT_MAX, 0);
        $incomingUp = self::clamp($p['upload_bytes'] ?? 0, 0, PHP_INT_MAX, 0);
        $traffic = BlueVPN_Utils::boolish($p['traffic_active'] ?? false);
        $data = [
            'customer_id' => $customerId, 'device_id' => $device, 'session_id' => $session,
            'config_key' => self::clean($p['config_key'] ?? '', 80, ''),
            'location_key' => self::clean($p['location_key'] ?? '', 24, 'unknown'),
            'location_title' => self::clean($p['location_title'] ?? '', 100, 'نامشخص'),
            'operator' => $operator, 'network_type' => $network, 'mode' => $mode,
            'connected' => 1, 'verified' => 1, 'tunnel_running' => 1, 'vpn_transport' => 1,
            'verification_source' => self::clean($p['verification_source'] ?? '', 80, ''),
            'ping_ms' => self::clamp($p['ping_ms'] ?? 0, 0, 10000),
            'health_score' => self::clamp($p['health_score'] ?? 0, 0, 100),
            'download_bytes' => $same ? max((int)($row['download_bytes'] ?? 0), $incomingDown) : $incomingDown,
            'upload_bytes' => $same ? max((int)($row['upload_bytes'] ?? 0), $incomingUp) : $incomingUp,
            'traffic_active' => $traffic ? 1 : 0,
            'last_traffic_at' => $traffic ? $now : ($same ? ($row['last_traffic_at'] ?? null) : null),
            'heartbeat_seq' => $seq,
            'started_at' => self::epoch_to_mysql($p['started_at'] ?? null) ?: ($same ? ($row['started_at'] ?? $now) : $now),
            'last_verified_at' => $now, 'last_seen_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::LIVE_TTL_SECONDS),
            'disconnected_at' => null, 'disconnect_reason' => '',
            'app_version' => self::clean($p['app_version'] ?? '', 40, ''),
            'android_version' => self::clean($p['android_version'] ?? '', 40, ''),
            'device_model' => self::clean($p['device_model'] ?? '', 160, ''),
        ];
        if ($row) $wpdb->update($t, $data, ['id' => (int)$row['id']]); else $wpdb->insert($t, $data);
        $data['id'] = $row['id'] ?? $wpdb->insert_id;
        return [$data, true];
    }

    private static function disconnect_live(int $customerId, array $p, string $reason): void {
        global $wpdb;
        $device = self::clean($p['device_id'] ?? '', 80, ''); $session = self::clean($p['session_id'] ?? '', 80, '');
        if ($device === '' || $session === '') return;
        $t = BlueVPN_DB::table('ai_live_connections');
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,session_id FROM {$t} WHERE customer_id=%d AND device_id=%s LIMIT 1", $customerId, $device), ARRAY_A);
        if (!$row || !hash_equals((string)$row['session_id'], $session)) return;
        $now = BlueVPN_Utils::now_mysql();
        $wpdb->update($t, ['connected'=>0,'verified'=>0,'last_seen_at'=>$now,'expires_at'=>$now,'disconnected_at'=>$now,'disconnect_reason'=>self::clean($reason,500,'disconnected')], ['id'=>(int)$row['id']]);
    }

    private static function recent_stats(string $config, string $operator, string $network, string $mode): array {
        global $wpdb;
        $t = BlueVPN_DB::table('ai_connection_events');
        $since = gmdate('Y-m-d H:i:s', time() - 48 * HOUR_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT success,ping_ms,jitter_ms,packet_loss_x100,duration_seconds,created_at FROM {$t} WHERE event_type<>'heartbeat' AND config_key=%s AND operator=%s AND network_type=%s AND mode=%s AND created_at>=%s ORDER BY created_at DESC LIMIT 600",
            $config, $operator, $network, $mode, $since
        ), ARRAY_A);
        $samples=$successes=$failures=$ping=$pingW=$jitter=$jitterW=$loss=$dur=$durW=0.0;
        $now = time();
        foreach ($rows as $row) {
            $created = strtotime((string)$row['created_at'] . ' UTC') ?: $now;
            $ageHours = max(0.0, ($now - $created) / 3600.0);
            $w = pow(0.5, $ageHours / 2.0);
            if ($w < 0.000001) continue;
            $samples += $w;
            if ((int)$row['success']) $successes += $w; else $failures += $w;
            if ((int)$row['ping_ms'] > 0) {$ping += (int)$row['ping_ms'] * $w; $pingW += $w;}
            if ((int)$row['jitter_ms'] > 0) {$jitter += (int)$row['jitter_ms'] * $w; $jitterW += $w;}
            $loss += ((int)$row['packet_loss_x100'] / 100.0) * $w;
            if ((int)$row['success'] && (int)$row['duration_seconds'] > 0) {$dur += (int)$row['duration_seconds'] * $w; $durW += $w;}
        }
        return [
            'weighted_samples'=>$samples,'weighted_successes'=>$successes,'weighted_failures'=>$failures,
            'success_rate'=>$successes/max(0.000001,$samples),'failure_rate'=>$failures/max(0.000001,$samples),
            'average_ping_ms'=>$ping/max(0.000001,$pingW),'average_jitter_ms'=>$jitter/max(0.000001,$jitterW),
            'average_packet_loss'=>$loss/max(0.000001,$samples),'average_duration_seconds'=>$dur/max(0.000001,$durW),
        ];
    }

    private static function wilson(float $successes, float $samples, float $z=1.645): float {
        if ($samples <= 0) return 0.0;
        $p = max(0.0, min(1.0, $successes / $samples));
        $den = 1.0 + ($z*$z/$samples);
        $centre = $p + ($z*$z/(2.0*$samples));
        $margin = $z * sqrt(max(0.0, ($p*(1.0-$p)/$samples) + ($z*$z/(4.0*$samples*$samples))));
        return max(0.0, min(1.0, ($centre-$margin)/$den));
    }

    private static function score_details(array $agg, array $recent): array {
        $rawSamples=max(0,(int)$agg['sample_count']); $rawSuccess=max(0,(int)$agg['success_count']);
        $rawRate=$rawSuccess/max(1,$rawSamples);
        $ws=max(0.0,(float)$recent['weighted_samples']); $wSucc=max(0.0,(float)$recent['weighted_successes']);
        $recentRate=max(0.0,min(1.0,(float)$recent['success_rate'])); $failureRate=max(0.0,min(1.0,(float)$recent['failure_rate']));
        $avgPing=max(0.0,(float)$recent['average_ping_ms']); $avgJitter=max(0.0,(float)$recent['average_jitter_ms']); $avgLoss=max(0.0,(float)$recent['average_packet_loss']); $avgDur=max(0.0,(float)$recent['average_duration_seconds']);
        $rawConf=1.0-exp(-$rawSamples/24.0); $recentConf=1.0-exp(-$ws/5.0); $confidence=max(0.0,min(1.0,$rawConf*.65+$recentConf*.35));
        $recentLower=self::wilson($wSucc,max(.01,$ws)); $longLower=self::wilson((float)$rawSuccess,(float)max(1,$rawSamples));
        $mix=min(.88,.48+$recentConf*.40); $reliability=($recentLower*$mix+$longLower*(1-$mix))*.68+($recentRate*.78+$rawRate*.22)*.32;
        $successComp=$reliability*66.0;
        if($avgPing<=0)$pingComp=7.0;elseif($avgPing<=70)$pingComp=16.0;elseif($avgPing<=180)$pingComp=16.0-(($avgPing-70)/110)*7.0;elseif($avgPing<=450)$pingComp=9.0-(($avgPing-180)/270)*9.0;else$pingComp=0.0;
        if($avgJitter<=12)$jitterPenalty=0.0;elseif($avgJitter<=30)$jitterPenalty=(($avgJitter-12)/18)*7.0;elseif($avgJitter<=65)$jitterPenalty=7.0+(($avgJitter-30)/35)*18.0;else$jitterPenalty=min(44.0,25.0+(($avgJitter-65)/85)*19.0);
        $lossPenalty=min(24.0,$avgLoss*2.4); $durationComp=$avgDur>0?min(5.0,log(1+$avgDur)/log(1801.0)*5.0):0.0; $confComp=$confidence*9.0;
        $op=self::canonical_operator($agg['operator']??'unknown'); $specific=!in_array($op,['unknown','ناشناخته','Wi-Fi',''],true);
        $blocked=$specific&&$ws>=5.0&&$rawSamples>=10&&$failureRate>=.72;
        $score=$blocked?0.0:$successComp+$pingComp+$durationComp+$confComp-$jitterPenalty-$lossPenalty;
        return ['score'=>(int)round(max(0,min(100,$score))),'confidence'=>round($confidence,4),'recent_success_rate'=>round($recentRate,4),'recent_effective_samples'=>round($ws,3),'average_jitter_ms'=>round($avgJitter,2),'jitter_penalty'=>round($jitterPenalty,2),'blocked_for_operator'=>$blocked,'blocked_operator'=>$blocked?$op:'','block_reason'=>$blocked?'operator_recent_failure_rate':''];
    }

    public static function submit_event(array $customer, array $p): array {
        global $wpdb;
        $config=self::clean($p['config_key']??'',80,''); if(strlen($config)<8) throw new InvalidArgumentException('config_key نامعتبر است');
        $operator=self::canonical_operator($p['operator']??''); $network=mb_strtolower(self::clean($p['network_type']??'',30,'unknown')); $mode=mb_strtolower(self::clean($p['mode']??'',30,'balanced')); $type=mb_strtolower(self::clean($p['event_type']??'',30,'session')); $bucket=self::hour_bucket($p['hour_bucket']??null);
        $proofOk=false;$proofError=''; if($type==='heartbeat')[$proofOk,$proofError]=self::heartbeat_proof($p); $success=$type==='heartbeat'?$proofOk:BlueVPN_Utils::boolish($p['success']??false);
        $event=[
            'customer_id'=>(int)$customer['id'],'device_id'=>self::clean($p['device_id']??'',80,''),'config_key'=>$config,'location_key'=>self::clean($p['location_key']??'',24,'unknown'),'location_title'=>self::clean($p['location_title']??'',100,'نامشخص'),'operator'=>$operator,'network_type'=>$network,'mode'=>$mode,'event_type'=>$type,'success'=>$success?1:0,
            'ping_ms'=>self::clamp($p['ping_ms']??0,0,10000),'jitter_ms'=>self::clamp($p['jitter_ms']??0,0,10000),'packet_loss_x100'=>self::clamp($p['packet_loss_x100']??0,0,10000),'duration_seconds'=>self::clamp($p['duration_seconds']??0,0,31536000),'health_score'=>self::clamp($p['health_score']??0,0,100),'download_bytes'=>self::clamp($p['download_bytes']??0,0,PHP_INT_MAX),'upload_bytes'=>self::clamp($p['upload_bytes']??0,0,PHP_INT_MAX),'failure_reason'=>self::clean($type==='heartbeat'?$proofError:($p['failure_reason']??''),500,''),'app_version'=>self::clean($p['app_version']??'',40,''),'android_version'=>self::clean($p['android_version']??'',40,''),'device_model'=>self::clean($p['device_model']??'',160,''),'hour_bucket'=>$bucket,'created_at'=>BlueVPN_Utils::now_mysql(),
        ];
        $wpdb->insert(BlueVPN_DB::table('ai_connection_events'),$event);
        if($type==='heartbeat'){
            [$live,$accepted]=self::update_live((int)$customer['id'],$p,$operator,$network,$mode,$proofOk,$proofError);
            return ['accepted'=>true,'live'=>$accepted,'verified'=>$proofOk,'proof_error'=>$proofError,'operator'=>$operator,'network_type'=>$network,'session_id'=>$live['session_id']??'','expires_in_seconds'=>$accepted?self::LIVE_TTL_SECONDS:0];
        }
        if(in_array($type,['session','disconnect'],true)||mb_strtolower(self::clean($p['live_state']??'',30,''))==='disconnected'||!BlueVPN_Utils::boolish($p['connected']??true)) self::disconnect_live((int)$customer['id'],$p,(string)($p['failure_reason']??$type));
        $at=BlueVPN_DB::table('ai_route_aggregates');
        $agg=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$at} WHERE config_key=%s AND operator=%s AND network_type=%s AND mode=%s AND hour_bucket=%d LIMIT 1",$config,$operator,$network,$mode,$bucket),ARRAY_A);
        $now=BlueVPN_Utils::now_mysql();
        if(!$agg){
            $wpdb->insert($at,['config_key'=>$config,'location_key'=>$event['location_key'],'location_title'=>$event['location_title'],'operator'=>$operator,'network_type'=>$network,'mode'=>$mode,'hour_bucket'=>$bucket,'sample_count'=>0,'success_count'=>0,'failure_count'=>0,'score'=>50,'recent_score'=>50,'updated_at'=>$now]);
            $agg=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$at} WHERE id=%d",(int)$wpdb->insert_id),ARRAY_A);
        }
        $sample=(int)$agg['sample_count']+1;$succ=(int)$agg['success_count']+($success?1:0);$fail=(int)$agg['failure_count']+($success?0:1);$dur=(int)$agg['total_duration_seconds']+$event['duration_seconds'];$ping=(int)$agg['total_ping_ms']+($event['ping_ms']>0?$event['ping_ms']:0);$pingSamples=(int)$agg['ping_samples']+($event['ping_ms']>0?1:0);$jitter=(int)$agg['total_jitter_ms']+($event['jitter_ms']>0?$event['jitter_ms']:0);$jitterSamples=(int)$agg['jitter_samples']+($event['jitter_ms']>0?1:0);$loss=(int)$agg['total_packet_loss_x100']+$event['packet_loss_x100'];
        $update=['location_key'=>$event['location_key'],'location_title'=>$event['location_title'],'sample_count'=>$sample,'success_count'=>$succ,'failure_count'=>$fail,'total_duration_seconds'=>$dur,'total_ping_ms'=>$ping,'ping_samples'=>$pingSamples,'total_jitter_ms'=>$jitter,'jitter_samples'=>$jitterSamples,'total_packet_loss_x100'=>$loss,'success_rate'=>$succ/max(1,$sample),'average_ping_ms'=>$ping/max(1,$pingSamples),'average_duration_seconds'=>$dur/max(1,$succ),'consecutive_failures'=>$success?0:(int)$agg['consecutive_failures']+1,'updated_at'=>$now];
        $update[$success?'last_success_at':'last_failure_at']=$now;
        $merged=array_merge($agg,$update);$details=self::score_details($merged,self::recent_stats($config,$operator,$network,$mode));
        $update['score']=$details['score'];$update['recent_score']=$details['score'];$update['confidence_score']=$details['confidence'];$update['recent_success_rate']=$details['recent_success_rate'];$update['adaptive_sample_weight']=$details['recent_effective_samples'];
        $wpdb->update($at,$update,['id'=>(int)$agg['id']]);
        return ['accepted'=>true,'route_score'=>$details['score'],'samples'=>$sample,'confidence'=>$details['confidence'],'recent_effective_samples'=>$details['recent_effective_samples'],'recent_success_rate'=>round($details['recent_success_rate']*100,1),'average_jitter_ms'=>$details['average_jitter_ms'],'jitter_penalty'=>$details['jitter_penalty'],'blocked_for_operator'=>$details['blocked_for_operator'],'blocked_operator'=>$details['blocked_operator'],'block_reason'=>$details['block_reason']];
    }

    public static function recommendations(string $operator,string $network,string $mode,$bucket=null,int $limit=30): array {
        global $wpdb;$operator=self::canonical_operator($operator);$network=mb_strtolower(self::clean($network,30,'unknown'));$mode=mb_strtolower(self::clean($mode,30,'balanced'));$bucket=self::hour_bucket($bucket);$limit=max(1,min(50,$limit));$t=BlueVPN_DB::table('ai_route_aggregates');
        $rows=$wpdb->get_results("SELECT * FROM {$t} WHERE sample_count>0 ORDER BY updated_at DESC LIMIT 1000",ARRAY_A);$latest=[];foreach($rows as $r)if($r['operator']===$operator&&!isset($latest[$r['config_key']]))$latest[$r['config_key']]=$r;$cutoff=time()-6*HOUR_IN_SECONDS;$blocked=[];foreach($latest as $k=>$r)if((int)$r['score']<=0&&(strtotime($r['updated_at'].' UTC')?:0)>=$cutoff)$blocked[$k]=true;
        $combined=[];foreach($rows as $r){if(isset($blocked[$r['config_key']]))continue;$w=0;if($r['operator']===$operator)$w+=14;elseif(!in_array($r['operator'],['unknown',''],true))$w-=3;if($r['network_type']===$network)$w+=8;if($r['mode']===$mode)$w+=7;$distance=min(((int)$r['hour_bucket']-$bucket+24)%24,($bucket-(int)$r['hour_bucket']+24)%24);$w+=max(0,6-$distance*2);$sampleConf=min(8,(int)floor(log(max(1,(int)$r['sample_count']),2))*2);$adaptive=min(8,(int)round((float)$r['confidence_score']*8));$failure=min(24,(int)$r['consecutive_failures']*6);$base=(int)($r['recent_score']??$r['score']);$score=max(0,min(100,$base+$w+$sampleConf+$adaptive-$failure));$item=['config_key'=>$r['config_key'],'location_key'=>$r['location_key'],'location_title'=>$r['location_title'],'score'=>$score,'base_score'=>(int)$r['score'],'recent_score'=>(int)($r['recent_score']??$r['score']),'confidence'=>round((float)$r['confidence_score'],4),'recent_success_rate'=>round((float)$r['recent_success_rate']*100,1),'consecutive_failures'=>(int)$r['consecutive_failures'],'samples'=>(int)$r['sample_count'],'success_rate'=>round((float)$r['success_rate']*100,1),'average_ping_ms'=>round((float)$r['average_ping_ms'],1),'average_duration_seconds'=>round((float)$r['average_duration_seconds'],1),'operator'=>$r['operator'],'network_type'=>$r['network_type'],'mode'=>$r['mode']];if(!isset($combined[$r['config_key']])||$score>$combined[$r['config_key']]['score'])$combined[$r['config_key']]=$item;}
        $out=array_values($combined);usort($out,static fn($a,$b)=>[$b['score'],$b['samples']]<=>[$a['score'],$a['samples']]);return array_slice($out,0,$limit);
    }

    public static function dashboard(int $customerId): array {
        global $wpdb;$t=BlueVPN_DB::table('ai_connection_events');$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE customer_id=%d ORDER BY created_at DESC LIMIT 500",$customerId),ARRAY_A);$successes=array_values(array_filter($rows,static fn($x)=>(int)$x['success']===1));$duration=0;$ping=0;$pingN=0;$routes=[];foreach($successes as $x){$duration+=(int)$x['duration_seconds'];if((int)$x['ping_ms']>0){$ping+=(int)$x['ping_ms'];$pingN++;}$k=(string)$x['config_key'];if(!isset($routes[$k]))$routes[$k]=['duration'=>0,'count'=>0,'title'=>$x['location_title'],'key'=>$x['location_key']];$routes[$k]['duration']+=(int)$x['duration_seconds'];$routes[$k]['count']++;} $best=[];foreach($routes as $x)if(!$best||[$x['duration'],$x['count']]>[$best['duration'],$best['count']])$best=$x;return ['learning_events'=>count($rows),'successful_sessions'=>count($successes),'total_duration_seconds'=>$duration,'average_ping_ms'=>round($ping/max(1,$pingN),1),'success_rate'=>round(count($successes)*100/max(1,count($rows)),1),'best_location'=>$best,'privacy'=>['content_collected'=>false,'destination_ips_collected'=>false,'technical_metrics_only'=>true]];
    }

    public static function feedback(int $customerId,array $p): array {
        global $wpdb;$t=BlueVPN_DB::table('ai_feedback');$wpdb->insert($t,['customer_id'=>$customerId,'rating'=>self::clamp($p['rating']??5,1,5,5),'category'=>self::clean($p['category']??'',50,'general'),'message'=>self::clean($p['message']??'',2000,''),'diagnostics_json'=>mb_substr(BlueVPN_Utils::json_encode(is_array($p['diagnostics']??null)?$p['diagnostics']:[]),0,8000),'app_version'=>self::clean($p['app_version']??'',40,''),'created_at'=>BlueVPN_Utils::now_mysql()]);return ['accepted'=>true,'id'=>(int)$wpdb->insert_id];
    }

    public static function stats(): array {
        global $wpdb;$e=BlueVPN_DB::table('ai_connection_events');$a=BlueVPN_DB::table('ai_route_aggregates');$l=BlueVPN_DB::table('ai_live_connections');$now=BlueVPN_Utils::now_mysql();$wpdb->query($wpdb->prepare("UPDATE {$l} SET connected=0,verified=0,disconnect_reason=IF(disconnect_reason='', 'heartbeat_expired', disconnect_reason) WHERE connected=1 AND expires_at IS NOT NULL AND expires_at<%s",$now));
        return ['events'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$e} WHERE event_type<>'heartbeat'"),'heartbeats'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$e} WHERE event_type='heartbeat'"),'successes'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$e} WHERE event_type<>'heartbeat' AND success=1"),'routes'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$a}"),'avg_score'=>round((float)$wpdb->get_var("SELECT AVG(score) FROM {$a}"),1),'active_24h'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$e} WHERE event_type<>'heartbeat' AND created_at>=%s",gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS))),'live'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$l} WHERE connected=1 AND verified=1 AND expires_at>UTC_TIMESTAMP()")];
    }

    public static function save_settings(): void {
        if(!current_user_can('manage_options'))wp_die('دسترسی ندارید.');check_admin_referer('bluevpn_blueai_save');$s=BlueVPN_DB::settings();$s['blueai_enabled']=isset($_POST['blueai_enabled']);$s['blueai_collective']=isset($_POST['blueai_collective']);$s['blueai_auto_heal']=isset($_POST['blueai_auto_heal']);$s['blueai_min_samples']=max(1,min(100,(int)($_POST['blueai_min_samples']??3)));$s['blueai_privacy_message']=mb_substr(sanitize_text_field(wp_unslash($_POST['blueai_privacy_message']??'')),0,500);BlueVPN_DB::save_settings($s);wp_safe_redirect(add_query_arg(['page'=>'bluevpn-blueai','bluevpn_notice'=>'تنظیمات BlueAI ذخیره شد.'],admin_url('admin.php')));exit;
    }

    public static function render_admin(): void {
        global $wpdb;$s=BlueVPN_DB::settings();$st=self::stats();if(!empty($_GET['bluevpn_notice']))echo '<div class="notice notice-success"><p>'.esc_html(wp_unslash($_GET['bluevpn_notice'])).'</p></div>';
        echo '<div class="bvc-grid">';foreach(['داده‌های یادگیری'=>'events','Heartbeat'=>'heartbeats','اتصال زنده'=>'live','Routeها'=>'routes','میانگین Score'=>'avg_score','رویداد ۲۴ ساعت'=>'active_24h'] as $label=>$key)echo '<div class="bvc-card bvc-kpi"><span>'.esc_html($label).'</span><strong>'.esc_html((string)$st[$key]).'</strong></div>';echo '</div>';
        echo '<div class="bvc-card"><h2>تنظیمات BlueAI</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_blueai_save');echo '<input type="hidden" name="action" value="bluevpn_blueai_save"><div class="bvc-form-grid"><label><input type="checkbox" name="blueai_enabled" value="1" '.checked(!empty($s['blueai_enabled']),true,false).'> BlueAI فعال</label><label><input type="checkbox" name="blueai_collective" value="1" '.checked(!empty($s['blueai_collective']),true,false).'> یادگیری جمعی</label><label><input type="checkbox" name="blueai_auto_heal" value="1" '.checked(!empty($s['blueai_auto_heal']),true,false).'> Auto Heal</label><label>حداقل نمونه<input type="number" min="1" max="100" name="blueai_min_samples" value="'.(int)$s['blueai_min_samples'].'"></label><label style="grid-column:1/-1">پیام حریم خصوصی<input type="text" name="blueai_privacy_message" value="'.esc_attr((string)$s['blueai_privacy_message']).'"></label></div>';submit_button('ذخیره BlueAI','primary','submit',false);echo '</form></div>';
        $a=BlueVPN_DB::table('ai_route_aggregates');$rows=$wpdb->get_results("SELECT location_title,operator,network_type,mode,score,recent_score,sample_count,success_rate,average_ping_ms,updated_at FROM {$a} ORDER BY score DESC,sample_count DESC LIMIT 30",ARRAY_A);echo '<div class="bvc-card"><h2>بهترین Routeهای یادگرفته‌شده</h2><table class="widefat striped bvc-table"><tr><th>لوکیشن</th><th>اپراتور</th><th>شبکه</th><th>Mode</th><th>Score</th><th>نمونه</th><th>Success</th><th>Ping</th><th>آخرین داده</th></tr>';foreach($rows as $r)echo '<tr><td>'.esc_html($r['location_title']).'</td><td>'.esc_html($r['operator']).'</td><td>'.esc_html($r['network_type']).'</td><td>'.esc_html($r['mode']).'</td><td><strong>'.(int)$r['score'].'</strong></td><td>'.(int)$r['sample_count'].'</td><td>'.esc_html((string)round((float)$r['success_rate']*100,1)).'%</td><td>'.esc_html((string)round((float)$r['average_ping_ms'],1)).' ms</td><td>'.esc_html($r['updated_at']).'</td></tr>';echo '</table></div>';
    }
}
