from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]

class SentinelExternalHttpResilience6110(unittest.TestCase):
    def text(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_provider_subscription_urls_use_resilient_fetcher(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("BlueVPN_Subscription_Sources::fetch_url_configs($url)",src)
        self.assertIn("'X-BlueVPN-Sentinel-Ignore'=>'1'",src)
        self.assertNotIn("wp_remote_get($url,['timeout'=>8,'redirection'=>2,'sslverify'=>true,'headers'=>['User-Agent'=>'BlueVPN-WordPress/'.BLUEVPN_MANAGER_VERSION,'Accept'=>'text/plain,*/*']]);",src)

    def test_provider_get_retry_marks_only_nonfinal_attempt_transient(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-providers.php")
        self.assertIn("private const RETRYABLE_HTTP = [408,425,429,500,502,503,504];",src)
        self.assertIn("$attempts=$method==='GET'?2:1",src)
        self.assertIn("'X-BlueVPN-Sentinel-Transient']='1'",src)
        self.assertIn("in_array($code,self::RETRYABLE_HTTP,true)",src)

    def test_sentinel_redacts_token_paths_and_separates_hosts(self):
        src=self.text("bluevpn-manager/includes/class-bluevpn-error-monitor.php")
        self.assertIn("['sub','subscribe','subscription','token','auth','key']",src)
        self.assertIn("$safe[]=$looksSecret?'[REDACTED]':$segment",src)
        self.assertIn("(string)($safeContext['host'] ?? '')",src)
        self.assertIn("preg_replace_callback('~https?://",src)

if __name__=="__main__":
    unittest.main()
