<?php
declare(strict_types=1);

namespace BlueVPN\Standalone;

final class Site
{
    public function __construct(private Database $db,private array $config) {}

    public function handle(string $path): void
    {
        $base=rtrim((string)($this->config['app']['base_url']??''),'/');
        $title=$this->db->setting('site_title','BlueVPN');
        $supportEmail=$this->db->setting('support_email');
        $out='';
        if ($path==='/' || $path==='/home') {
            $out='<section class="hero"><div><div class="eyebrow">BLUEVPN • SECURE CONNECTION</div>'.
                '<h1>اتصال امن، تجربه ساده</h1><p>بلو وی‌پی‌ان برای مدیریت ساده اشتراک، اتصال به موقعیت دلخواه و تجربه‌ای روان طراحی شده است.</p>'.
                '<div class="hero-actions"><a class="main-btn" href="/download">دریافت اپلیکیشن</a><a class="outline-btn" href="/plans">مشاهده پلن‌ها</a></div></div>'.
                '<div class="hero-visual"><div class="signal">◈</div><strong>BlueVPN</strong><p>Private by design</p></div></section>'.
                '<section class="benefits"><article><h2>حساب یکپارچه</h2><p>دسترسی به حساب و اشتراک در اپلیکیشن‌های سازگار.</p></article>'.
                '<article><h2>انتخاب کشور</h2><p>موقعیت موردنظر را با رابط کاربری ساده انتخاب کنید.</p></article>'.
                '<article><h2>پنل مستقل</h2><p>زیرساخت API روی PHP و MySQL بدون وابستگی به وردپرس.</p></article></section>';
        } elseif ($path==='/plans') {
            $plans=$this->db->connection()->query('SELECT title,description,price_toman,duration_days,data_limit_gb,device_limit FROM '.
                $this->db->table('plans').' WHERE active=1 ORDER BY sort_order,id LIMIT 30')->fetchAll();
            $out='<h1>پلن‌های اشتراک</h1><p class="lead">پلن‌های آماده خرید در این بخش نمایش داده می‌شوند.</p><section class="benefits">';
            foreach ($plans as $p) {
                $out.='<article><h2>'.self::e((string)$p['title']).'</h2><p>'.self::e((string)($p['description']??'')).'</p>'.
                    '<h3>'.number_format((int)$p['price_toman']).' تومان</h3><p>'.(int)$p['duration_days'].' روز · '.
                    (int)$p['data_limit_gb'].' گیگابایت · '.(int)$p['device_limit'].' دستگاه</p><p>خرید از طریق برنامه</p></article>';
            }
            if (!$plans) $out.='<article><h2>در حال آماده‌سازی</h2><p>پلن فعالی تعریف نشده است.</p></article>';
            $out.='</section>';
        } elseif ($path==='/download') {
            $rows=$this->db->connection()->query('SELECT platform,version,file_url FROM '.
                $this->db->table('releases').' WHERE active=1 AND channel="stable" ORDER BY id DESC LIMIT 12')->fetchAll();
            $out='<h1>دانلود BlueVPN</h1><p class="lead">نسخه‌های رسمی و تأییدشده در این صفحه قرار می‌گیرند.</p><section class="benefits">';
            $seen=[];
            foreach ($rows as $row) {
                $platform=(string)$row['platform'];
                $url=(string)$row['file_url'];
                if (isset($seen[$platform])||!str_starts_with($url,'https://'))continue;
                $seen[$platform]=true;
                $out.='<article><h2>'.self::e(ucfirst($platform)).'</h2><p>نسخه '.self::e((string)$row['version']).
                    '</p><a class="main-btn" rel="noopener noreferrer" href="'.self::e($url).'">دریافت نسخه</a></article>';
            }
            if (!$seen) $out.='<article><h2>نسخه جدید</h2><p>هنوز فایل رسمی برای دانلود منتشر نشده است.</p></article>';
            $out.='</section>';
        } elseif ($path==='/account') {
            $out='<h1>حساب کاربری</h1><p class="lead">برای ورود، ثبت‌نام و مشاهده اشتراک خود از اپلیکیشن BlueVPN استفاده کنید.</p>'.
                '<a class="main-btn" href="/download">دریافت اپ</a>';
        } elseif ($path==='/support') {
            $out='<h1>پشتیبانی BlueVPN</h1><p class="lead">برای دریافت پشتیبانی از روش‌های رسمی اعلام‌شده استفاده کنید.</p>'.
                ($supportEmail!==''?'<p>ایمیل: <a href="mailto:'.self::e($supportEmail).'">'.self::e($supportEmail).'</a></p>':
                    '<p>اطلاعات تماس هنوز توسط مدیر تنظیم نشده است.</p>');
        } else { http_response_code(404);$out='<h1>صفحه پیدا نشد</h1>'; }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'.
            '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark">'.
            '<title>'.self::e($title).'</title><meta name="description" content="BlueVPN - اپلیکیشن و مدیریت اشتراک">'.
            '<link rel="stylesheet" href="/assets/app.css"></head><body class="public-site">'.
            '<header class="site-header"><a class="logo" href="/"><b>◈</b> BlueVPN</a>'.
            '<nav><a href="/">خانه</a><a href="/plans">اشتراک‌ها</a><a href="/download">دانلود</a>'.
            '<a href="/support">پشتیبانی</a><a href="/account">حساب</a></nav></header>'.
            '<main class="site-main">'.$out.'</main><footer class="site-footer">© '.date('Y').
            ' BlueVPN · PHP / MySQL</footer></body></html>';
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
