<?php
if (!defined('ABSPATH')) exit;

/**
 * Windows Web Publisher guard.
 *
 * The Windows WebView render detector intentionally treats only visible media as
 * a successful impression. Some Tapsell failure/loading shells contain large
 * visible text/buttons and were previously mistaken for a rendered ad. This
 * bridge keeps the publisher root hidden until a real creative surface exists.
 */
final class BlueVPN_Tapsell_Web_Guard {
    private const ROUTE = '/bluevpn/v1/mobile/config';

    public static function init(): void {
        // Run before BlueVPN_Ads::serve_windows_tapsell() (default priority 10).
        add_action('admin_post_bluevpn_windows_tapsell', [self::class, 'serve'], 1);
        add_action('admin_post_nopriv_bluevpn_windows_tapsell', [self::class, 'serve'], 1);

        // When a real HTTPS bridge is available, Windows must not fall back to a
        // synthetic/local document. Tapsell publisher validation is origin-aware
        // and local fallback can reproduce the same provider error shell.
        add_filter('rest_post_dispatch', [self::class, 'prefer_https_bridge'], 20, 3);
    }

    public static function prefer_https_bridge($response, $server, $request) {
        if (!($request instanceof WP_REST_Request) || $request->get_route() !== self::ROUTE) return $response;
        if (!($response instanceof WP_REST_Response)) return $response;

        $data = $response->get_data();
        if (!is_array($data)) return $response;

        $web = $data['tapsell']['windows_web'] ?? null;
        if (!is_array($web)) return $response;

        $bridge = trim((string)($web['bridge_url'] ?? ''));
        if (!self::is_https_url($bridge)) return $response;

        // Bridge is authoritative for Windows Web. Clearing only the local
        // ScriptHtml compatibility field does not affect Android placements.
        $data['tapsell']['windows_web']['script_html'] = '';
        $response->set_data($data);
        return $response;
    }

    private static function is_https_url(string $url): bool {
        if ($url === '' || !wp_http_validate_url($url)) return false;
        return strtolower((string)wp_parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    public static function serve(): void {
        $settings = BlueVPN_DB::settings();
        $enabled = !empty($settings['tapsell_windows_web_enabled']);
        $script = trim((string)($settings['tapsell_windows_web_script_html'] ?? ''));

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'self' https: data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' https: data: blob:; frame-src https:; connect-src https: wss:;");
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Content-Type-Options: nosniff');

        if (!$enabled || $script === '') {
            status_header(404);
            echo '<!doctype html><html><body></body></html>';
            exit;
        }

        echo '<!doctype html><html dir="rtl"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<style>';
        echo 'html,body{margin:0;width:100%;height:100%;overflow:hidden;background:transparent}';
        echo 'body{display:flex;align-items:center;justify-content:center}';
        // visibility:hidden preserves layout dimensions for the publisher while
        // preventing the Windows detector from accepting loading/error text.
        echo '#bluevpn-tapsell-root{width:100%;height:100%;visibility:hidden}';
        echo 'iframe,img,video,canvas,object,embed{max-width:100%;max-height:100%;border:0}';
        echo '</style></head><body>';
        echo '<div id="bluevpn-tapsell-root">';
        echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';

        // The provider may render asynchronously. Reveal only a creative surface:
        // media, a CSS background creative, or a real outbound CTA. Known provider
        // loading/error shells stay hidden so Windows can continue its failover.
        echo <<<'HTML'
<script>
(function(){
  const root=document.getElementById('bluevpn-tapsell-root');
  if(!root)return;

  const badMarkers=[
    'در حال دریافت تبلیغ',
    'دریافت تبلیغ',
    'مشکلی پیش آمده',
    'دوباره تلاش کنید',
    'تلاش مجدد',
    'نمایش نسخه مرورگر',
    'loading',
    'try again',
    'something went wrong',
    'browser version'
  ];

  const norm=value=>String(value||'').replace(/\s+/g,' ').trim().toLowerCase();
  const hasBadText=()=>{
    const text=norm(root.innerText);
    return badMarkers.some(marker=>text.includes(norm(marker)));
  };
  const sized=node=>{
    if(!node||!node.getBoundingClientRect)return false;
    const box=node.getBoundingClientRect();
    return box.width>32&&box.height>24;
  };
  const realMedia=()=>[...root.querySelectorAll('iframe,img,video,canvas,object,embed')].some(node=>{
    if(!sized(node))return false;
    const tag=node.tagName;
    if(tag==='IMG'&&(!node.complete||Number(node.naturalWidth||0)<2))return false;
    if(tag==='VIDEO'&&Number(node.readyState||0)<2&&!node.poster)return false;
    return true;
  });
  const visualCreative=()=>[...root.querySelectorAll('a,button,[role="button"],[style]')].some(node=>{
    if(!sized(node))return false;
    const text=norm(node.innerText);
    if(badMarkers.some(marker=>text.includes(norm(marker))))return false;
    const style=getComputedStyle(node);
    const background=style.backgroundImage||'none';
    const anchor=node.closest?node.closest('a[href]'):null;
    const href=anchor&&anchor.href?anchor.href:'';
    return background!=='none'||(href!==''&&text.length>=4);
  });
  const reveal=()=>{
    if(hasBadText())return false;
    if(!realMedia()&&!visualCreative())return false;
    root.style.visibility='visible';
    root.setAttribute('data-bluevpn-rendered','1');
    document.documentElement.dataset.bluevpnTapsell='ready';
    return true;
  };

  let ticks=0;
  const observer=new MutationObserver(reveal);
  observer.observe(root,{childList:true,subtree:true,attributes:true,characterData:true});

  if(reveal())return;
  const timer=setInterval(()=>{
    ticks++;
    if(reveal()||ticks>=64){
      clearInterval(timer);
      observer.disconnect();
      if(root.getAttribute('data-bluevpn-rendered')!=='1'){
        document.documentElement.dataset.bluevpnTapsell='empty';
      }
    }
  },150);
})();
</script>
HTML;
        echo '</body></html>';
        exit;
    }
}
