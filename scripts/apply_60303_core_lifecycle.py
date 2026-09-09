from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def load(path): return (ROOT/path).read_text(encoding="utf-8")
def save(path,text): (ROOT/path).write_text(text,encoding="utf-8")
def one(text,old,new,label):
    n=text.count(old)
    if n!=1: raise SystemExit(f"{label}: expected 1 match, got {n}")
    return text.replace(old,new,1)

p="bluevpn-manager/includes/class-bluevpn-free-sources.php"
s=load(p)
s=one(s,
"""    public static function seed(): void {
        global $wpdb;$t=BlueVPN_DB::table('free_config_sources');
        $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE source_key=%s",self::DEFAULT_SOURCE_KEY));
        if(!$exists)$wpdb->insert($t,[
            'source_key'=>self::DEFAULT_SOURCE_KEY,'source_type'=>'telegram_public','title'=>'VPNhub | کانفیگ رایگان',
            'url'=>self::DEFAULT_SOURCE_URL,'enabled'=>1,'priority'=>10,'fetch_interval_seconds'=>300,'max_items'=>400,
            'created_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql(),
        ]);
    }
""",
"""    public static function seed(): void {
        global $wpdb;$t=BlueVPN_DB::table('free_config_sources');$marker='bluevpn_free_sources_initialized';
        if(get_option($marker,'0')==='1')return;
        $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}");
        if($count>0){update_option($marker,'1',false);return;}
        $wpdb->insert($t,[
            'source_key'=>self::DEFAULT_SOURCE_KEY,'source_type'=>'telegram_public','title'=>'VPNhub | کانفیگ رایگان',
            'url'=>self::DEFAULT_SOURCE_URL,'enabled'=>1,'priority'=>10,'fetch_interval_seconds'=>300,'max_items'=>400,
            'created_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql(),
        ]);
        update_option($marker,'1',false);
    }
"","free explicit empty")
save(p,s)

p="bluevpn-manager/includes/class-bluevpn-providers.php"
s=load(p)
s=one(s,
"""        $raw=BlueVPN_Utils::json_decode_array((string)($plan['provider_routes_json']??''),[]);
        $out=['pasarguard'=>[],'marzban'=>[],'shahrah'=>[],'guardcore'=>[],'hiddify'=>[],'threexui'=>[]];""",
"""        $rawText=trim((string)($plan['provider_routes_json']??''));
        $sourceText=trim((string)($plan['source_ids_json']??''));
        $declaredTopology=$rawText!==''||$sourceText!=='';
        $raw=BlueVPN_Utils::json_decode_array($rawText,[]);
        $out=['pasarguard'=>[],'marzban'=>[],'shahrah'=>[],'guardcore'=>[],'hiddify'=>[],'threexui'=>[]];""","topology declaration")

legacy="""        // Backward compatibility: old single-provider columns become one route
        // only when the new route list for that provider is empty.
        if(!$out['pasarguard']&&(int)($plan['panel_id']??0)>0){
            $out['pasarguard'][]=['panel_id'=>(int)$plan['panel_id'],'group_ids'=>BlueVPN_Utils::json_decode_array((string)($plan['group_ids_json']??''),[])];
        }
        if(!$out['marzban']&&(int)($plan['marzban_panel_id']??0)>0){
            $out['marzban'][]=['panel_id'=>(int)$plan['marzban_panel_id'],'inbounds'=>BlueVPN_Utils::json_decode_array((string)($plan['marzban_inbounds_json']??''),[])];
        }
        if(!$out['shahrah']&&(int)($plan['shahrah_panel_id']??0)>0&&trim((string)($plan['shahrah_plan_slug']??''))!==''){
            $out['shahrah'][]=['panel_id'=>(int)$plan['shahrah_panel_id'],'plan_slug'=>trim((string)$plan['shahrah_plan_slug'])];
        }
        if(!$out['guardcore']&&(int)($plan['guardcore_panel_id']??0)>0){
            $out['guardcore'][]=['panel_id'=>(int)$plan['guardcore_panel_id'],'service_ids'=>BlueVPN_Utils::json_decode_array((string)($plan['guardcore_service_ids_json']??''),[])];
        }
"""
legacy_new="""        // Legacy columns are fallback only for plans that never declared
        // modern provider/source topology. Explicit empty topology stays empty.
        if(!$declaredTopology){
            if(!$out['pasarguard']&&(int)($plan['panel_id']??0)>0){
                $out['pasarguard'][]=['panel_id'=>(int)$plan['panel_id'],'group_ids'=>BlueVPN_Utils::json_decode_array((string)($plan['group_ids_json']??''),[])];
            }
            if(!$out['marzban']&&(int)($plan['marzban_panel_id']??0)>0){
                $out['marzban'][]=['panel_id'=>(int)$plan['marzban_panel_id'],'inbounds'=>BlueVPN_Utils::json_decode_array((string)($plan['marzban_inbounds_json']??''),[])];
            }
            if(!$out['shahrah']&&(int)($plan['shahrah_panel_id']??0)>0&&trim((string)($plan['shahrah_plan_slug']??''))!==''){
                $out['shahrah'][]=['panel_id'=>(int)$plan['shahrah_panel_id'],'plan_slug'=>trim((string)$plan['shahrah_plan_slug'])];
            }
            if(!$out['guardcore']&&(int)($plan['guardcore_panel_id']??0)>0){
                $out['guardcore'][]=['panel_id'=>(int)$plan['guardcore_panel_id'],'service_ids'=>BlueVPN_Utils::json_decode_array((string)($plan['guardcore_service_ids_json']??''),[])];
            }
        }
"""
s=one(s,legacy,legacy_new,"legacy fallback")

s=one(s,
"""        $hasExplicit=!empty($manualEntries)||$routeCount>0;

        if(!$hasExplicit){""",
"""        $hasExplicit=!empty($manualEntries)||$routeCount>0;
        $topologyDeclared=trim((string)($plan['provider_routes_json']??''))!==''||trim((string)($plan['source_ids_json']??''))!=='';

        if(!$hasExplicit&&!$topologyDeclared){""","provision fallback")

s=one(s,
"""        $hasExplicitManual=!empty($manualEntries)||$shId>0;

        if(!$hasExplicitManual){""",
"""        $hasExplicitManual=!empty($manualEntries)||$shId>0;
        $topologyDeclared=trim((string)($plan['provider_routes_json']??''))!==''||trim((string)($plan['source_ids_json']??''))!=='';

        if(!$hasExplicitManual&&!$topologyDeclared){""","repair fallback")

s=one(s,
"""    private static function snapshot_store(int $customerId,array $lines,array $errors=[],array $sourceStats=[],array $sourceLines=[]): void {
        if(!$lines)return;
        update_option(self::snapshot_option($customerId),[""",
"""    private static function snapshot_store(int $customerId,array $lines,array $errors=[],array $sourceStats=[],array $sourceLines=[]): void {
        update_option(self::snapshot_option($customerId),[""","empty snapshot")

old="""        $oldSourceLines=is_array($old['source_lines']??null)?$old['source_lines']:[];
        $effectiveSourceLines=$complete?$freshSourceLines:array_merge($oldSourceLines,$freshSourceLines);
        $effective=[];$effectiveSeen=[];
        foreach($effectiveSourceLines as $providerLines)foreach((array)$providerLines as $line){$key=sha1((string)$line);if(isset($effectiveSeen[$key]))continue;$effectiveSeen[$key]=1;$effective[]=(string)$line;}
        // One-time migration from aggregate-only snapshots: preserve the old
        // aggregate during a partial refresh, then naturally retire it after the
        // first complete per-source refresh.
        if(!$complete&&empty($oldSourceLines))foreach((array)($old['lines']??[]) as $line){$key=sha1((string)$line);if(isset($effectiveSeen[$key]))continue;$effectiveSeen[$key]=1;$effective[]=(string)$line;}
        if(!$effective)$effective=$lines;
        $effectiveSources=array_replace((array)($old['sources']??[]),$sourceStats);
        if($effective)self::snapshot_store($customerId,$effective,$errors,$effectiveSources,$effectiveSourceLines);
"""
new="""        $currentSourceKeys=[];foreach($sources as $source){$sourceKey=(string)($source['key']??'source');if($sourceKey!=='')$currentSourceKeys[$sourceKey]=true;}
        $oldSourceLines=is_array($old['source_lines']??null)?$old['source_lines']:[];
        $oldSourceLines=array_intersect_key($oldSourceLines,$currentSourceKeys);
        $effectiveSourceLines=$complete?$freshSourceLines:array_merge($oldSourceLines,$freshSourceLines);
        $effective=[];$effectiveSeen=[];
        foreach($effectiveSourceLines as $providerLines)foreach((array)$providerLines as $line){$key=sha1((string)$line);if(isset($effectiveSeen[$key]))continue;$effectiveSeen[$key]=1;$effective[]=(string)$line;}
        // Aggregate-only LKG is valid only while at least one current source exists.
        if(!$complete&&$currentSourceKeys&&empty($oldSourceLines))foreach((array)($old['lines']??[]) as $line){$key=sha1((string)$line);if(isset($effectiveSeen[$key]))continue;$effectiveSeen[$key]=1;$effective[]=(string)$line;}
        if(!$effective)$effective=$lines;
        $oldSourceStats=is_array($old['sources']??null)?$old['sources']:[];
        $oldSourceStats=array_intersect_key($oldSourceStats,$currentSourceKeys);
        $effectiveSources=array_replace($oldSourceStats,$sourceStats);
        self::snapshot_store($customerId,$effective,$errors,$effectiveSources,$effectiveSourceLines);
"""
s=one(s,old,new,"snapshot lkg pruning")
save(p,s)

p="bluevpn-manager/bluevpn-manager.php"
s=load(p)
s=one(s,"require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-subscription-sources.php';\n",
      "require_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-subscription-sources.php';\nrequire_once BLUEVPN_MANAGER_DIR . 'includes/class-bluevpn-source-lifecycle.php';\n","bootstrap require")
s=one(s,"    BlueVPN_Subscription_Sources::init();\n",
      "    BlueVPN_Subscription_Sources::init();\n    BlueVPN_Source_Lifecycle::init();\n","bootstrap init")
save(p,s)

Path(ROOT/"tests/test_source_lifecycle_60303.py").write_text("""from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class SourceLifecycle60303Tests(unittest.TestCase):
    def text(self,path): return (ROOT/path).read_text(encoding="utf-8")

    def test_explicit_empty_topology_is_authoritative(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        for token in ["$declaredTopology=$rawText!==''||$sourceText!==''","if(!$declaredTopology)","if(!$hasExplicit&&!$topologyDeclared)","if(!$hasExplicitManual&&!$topologyDeclared)"]: self.assertIn(token,src)

    def test_removed_source_lkg_is_pruned(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        for token in ["array_intersect_key($oldSourceLines,$currentSourceKeys)","array_intersect_key($oldSourceStats,$currentSourceKeys)","self::snapshot_store($customerId,$effective"]: self.assertIn(token,src)

    def test_lifecycle_covers_reported_paths(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-source-lifecycle.php")
        for token in ["before_customer_sync","before_plan_save","before_paid_source_mutation","before_shahrah_delete","before_provider_mutation","before_free_toggle","repair_customer_missing_providers($id)","bluevpn_shahrah_panel_","invalidate_all"]: self.assertIn(token,src)

    def test_free_source_can_remain_explicitly_empty(self):
        self.assertIn("bluevpn_free_sources_initialized",self.text("bluevpn-manager/includes/class-bluevpn-free-sources.php"))

if __name__=="__main__": unittest.main()
""",encoding="utf-8")
print("BlueVPN 6.3.3 core lifecycle patch applied")
