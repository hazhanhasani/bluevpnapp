<?php
declare(strict_types=1);

namespace BlueVPN\Standalone;

use PDO;

final class AdminPanel
{
    private PDO $pdo;
    public function __construct(private Database $db) { $this->pdo=$db->connection(); }

    public function handle(string $path,string $method): void
    {
        if (!Admin::authorized()) {
            header('Location: /login',true,303); return;
        }
        $section=trim(substr($path,strlen('/admin')),'/');
        if ($section==='') $section='overview';
        $allowed=['overview','users','plans','locations','orders','releases','support','settings'];
        if (!in_array($section,$allowed,true)) {
            http_response_code(404); $this->page('پیدا نشد','<div class="panel">صفحه یافت نشد.</div>','overview');return;
        }
        if ($method==='POST') {
            Admin::requireCsrf();
            $this->save($section);
            header('Location: /admin/'.$section,true,303);
            return;
        }
        if ($method!=='GET') { http_response_code(405);return; }
        $this->page($this->labels()[$section],$this->section($section),$section);
    }

    private function save(string $section): void
    {
        if ($section==='plans') {
            $title=mb_substr(trim((string)($_POST['title']??'')),0,150);
            $price=filter_var($_POST['price_toman']??null,FILTER_VALIDATE_INT);
            $days=filter_var($_POST['duration_days']??null,FILTER_VALIDATE_INT);
            $data=filter_var($_POST['data_limit_gb']??null,FILTER_VALIDATE_INT);
            $devices=filter_var($_POST['device_limit']??null,FILTER_VALIDATE_INT);
            if ($title===''||$price===false||$price<0||$days===false||$days<1||$days>3650||
                $data===false||$data<0||$devices===false||$devices<1||$devices>50) {
                throw new \RuntimeException('Invalid plan');
            }
            $q=$this->pdo->prepare('INSERT INTO '.$this->db->table('plans').
                ' (title,description,price_toman,duration_days,data_limit_gb,device_limit,active) VALUES (?,?,?,?,?,?,0)');
            $q->execute([$title,mb_substr((string)($_POST['description']??''),0,500),$price,$days,$data,$devices]);
            $this->audit('create_plan',(int)$this->pdo->lastInsertId());
        } elseif ($section==='locations') {
            $code=strtoupper(trim((string)($_POST['country_code']??'')));
            $country=mb_substr(trim((string)($_POST['country_name']??'')),0,120);
            $name=mb_substr(trim((string)($_POST['display_name']??'')),0,150);
            if (!preg_match('/^[A-Z]{2}$/',$code)||$country===''||$name==='') throw new \RuntimeException('Invalid location');
            $q=$this->pdo->prepare('INSERT INTO '.$this->db->table('server_locations').
                ' (country_code,country_name,display_name) VALUES (?,?,?)');
            $q->execute([$code,$country,$name]);
            $this->audit('create_location',(int)$this->pdo->lastInsertId());
        } elseif ($section==='releases') {
            $platform=(string)($_POST['platform']??'');
            $version=trim((string)($_POST['version']??''));
            $url=trim((string)($_POST['file_url']??''));
            $hash=strtolower(trim((string)($_POST['sha256']??'')));
            if (!in_array($platform,['android','windows','ios'],true) ||
                !preg_match('/^\d+\.\d+\.\d+$/D',$version) ||
                ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL)||!str_starts_with($url,'https://'))) ||
                ($hash!=='' && !preg_match('/^[a-f0-9]{64}$/D',$hash))) {
                throw new \RuntimeException('Invalid release metadata');
            }
            $code=(int)($_POST['version_code']??0);
            if ($code<0) throw new \RuntimeException('Invalid version code');
            $this->pdo->beginTransaction();
            try {
                $q=$this->pdo->prepare('INSERT INTO '.$this->db->table('releases').
                    ' (platform,version,version_code,channel,file_url,sha256,active) VALUES (?,?,?,?,?,?,0)');
                $q->execute([$platform,$version,$code,'stable',$url,$hash?:null]);
                $this->pdo->commit();
            } catch (\Throwable $e) { $this->pdo->rollBack();throw $e; }
            $this->audit('create_inactive_release',(int)$this->pdo->lastInsertId());
        } elseif ($section==='settings') {
            $key=(string)($_POST['key']??'');
            if (!in_array($key,['site_title','support_email','announcement'],true)) throw new \RuntimeException('Unknown setting');
            $value=mb_substr(trim((string)($_POST['value']??'')),0,1000);
            $this->db->setSetting($key,$value);
            $this->audit('update_setting',0);
        } else {
            throw new \RuntimeException('Unsupported admin mutation');
        }
    }

    private function audit(string $action,int $entity): void
    {
        $q=$this->pdo->prepare('INSERT INTO '.$this->db->table('audit_events').
            ' (actor_type,actor_id,action,context_json) VALUES (?,?,?,?)');
        $q->execute(['admin',(int)$_SESSION['admin_id'],$action,json_encode(['entity'=>$entity],JSON_THROW_ON_ERROR)]);
    }

    private function section(string $section): string
    {
        if ($section==='overview') {
            $metrics=[
                'کاربران'=>$this->db->count('customers'),
                'پلن‌ها'=>$this->db->count('plans'),
                'اشتراک‌ها'=>$this->db->count('subscriptions'),
                'سفارش‌ها'=>$this->db->count('orders'),
                'لوکیشن‌ها'=>$this->db->count('server_locations'),
                'تیکت‌ها'=>$this->db->count('support_tickets'),
            ];
            $cards='<div class="metrics">';
            foreach ($metrics as $label=>$value) {
                $cards.='<article class="metric"><span>'.self::e($label).'</span><strong>'.self::e((string)$value).'</strong></article>';
            }
            $cards.='</div>';
            return $cards.'<div class="panels"><section class="panel"><h2>وضعیت سیستم</h2>'.
                '<p>هسته جدید با PHP و MySQL بدون WordPress نصب شده است.</p>'.
                '<p>فروش و اتصال VPN تا زمان تنظیم ارائه‌دهندگان و درگاه پرداخت غیرفعال است.</p>'.
                '</section><section class="panel"><h2>لینک‌های مدیریت</h2><p><a href="/admin/plans">افزودن پلن</a></p>'.
                '<p><a href="/admin/locations">مدیریت لوکیشن‌ها</a></p><p><a href="/health">سلامت سرویس</a></p></section></div>';
        }
        if ($section==='users') return $this->table(['شناسه','ایمیل','فعال','تاریخ ثبت‌نام'],
            'SELECT id,email,active,created_at FROM '.$this->db->table('customers').' ORDER BY id DESC LIMIT 100');
        if ($section==='orders') return $this->table(['شناسه','کاربر','پلن','مبلغ','وضعیت'],
            'SELECT id,customer_id,plan_id,price_toman,status FROM '.$this->db->table('orders').' ORDER BY created_at DESC LIMIT 100');
        if ($section==='support') return $this->table(['شناسه','کاربر','موضوع','وضعیت'],
            'SELECT id,customer_id,subject,status FROM '.$this->db->table('support_tickets').' ORDER BY created_at DESC LIMIT 100');
        if ($section==='plans') {
            return $this->table(['شناسه','عنوان','قیمت (تومان)','مدت (روز)','فعال'],
                'SELECT id,title,price_toman,duration_days,active FROM '.$this->db->table('plans').' ORDER BY id DESC LIMIT 100').
                '<section class="panel"><h2>ثبت پلن جدید</h2><p>پلن جدید غیرفعال ساخته می‌شود تا ارائه‌دهنده و پرداخت آماده شود.</p>'.
                '<form method="post" action="/admin/plans">'.Admin::csrfField().
                self::field('title','نام پلن','text',true).self::field('description','توضیحات').
                self::field('price_toman','قیمت (تومان)','number',true,'0').
                self::field('duration_days','مدت (روز)','number',true,'30').
                self::field('data_limit_gb','حجم (گیگابایت)','number',true,'0').
                self::field('device_limit','تعداد دستگاه','number',true,'1').
                '<button type="submit">ثبت پلن</button></form></section>';
        }
        if ($section==='locations') {
            return $this->table(['شناسه','کد کشور','کشور','لوکیشن','فعال'],
                'SELECT id,country_code,country_name,display_name,enabled FROM '.
                $this->db->table('server_locations').' ORDER BY id DESC LIMIT 100').
                '<section class="panel"><h2>ثبت لوکیشن</h2><form method="post" action="/admin/locations">'.Admin::csrfField().
                self::field('country_code','کد کشور (DE)','text',true).
                self::field('country_name','کشور','text',true).
                self::field('display_name','عنوان لوکیشن','text',true).
                '<button type="submit">افزودن</button></form></section>';
        }
        if ($section==='releases') {
            return $this->table(['شناسه','پلتفرم','نسخه','کانال','فعال'],
                'SELECT id,platform,version,channel,active FROM '.$this->db->table('releases').' ORDER BY id DESC LIMIT 100').
                '<section class="panel"><h2>ثبت اطلاعات انتشار غیرفعال</h2><form method="post" action="/admin/releases">'.Admin::csrfField().
                '<label>پلتفرم<select name="platform"><option>android</option><option>windows</option><option>ios</option></select></label>'.
                self::field('version','نسخه','text',true,'1.0.0').
                self::field('version_code','کد نسخه','number',true,'100').
                self::field('file_url','لینک HTTPS فایل','url').
                self::field('sha256','SHA-256 فایل').
                '<button type="submit">ثبت آزمایشی</button></form></section>';
        }
        if ($section==='settings') {
            $out='<section class="panel"><h2>تنظیمات عمومی سایت</h2>';
            foreach (['site_title'=>'عنوان سایت','support_email'=>'ایمیل پشتیبانی','announcement'=>'اطلاعیه'] as $key=>$label) {
                $value=$this->db->setting($key);
                $out.='<form method="post" action="/admin/settings">'.Admin::csrfField().
                    '<input type="hidden" name="key" value="'.self::e($key).'">'.
                    '<label>'.self::e($label).'<input name="value" value="'.self::e($value).'" maxlength="1000"></label>'.
                    '<button type="submit">ذخیره</button></form>';
            }
            return $out.'</section>';
        }
        return '';
    }

    private static function field(string $name,string $label,string $type='text',bool $required=false,string $value=''): string
    {
        return '<label>'.self::e($label).'<input type="'.self::e($type).'" name="'.self::e($name).
            '" value="'.self::e($value).'" '.($required?'required ':'').' maxlength="500"></label>';
    }

    private function table(array $headers,string $sql): string
    {
        $rows=$this->pdo->query($sql)->fetchAll();
        $html='<section class="panel"><div class="table-scroll"><table><thead><tr>';
        foreach ($headers as $head) $html.='<th>'.self::e($head).'</th>';
        $html.='</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html.='<tr>';
            foreach ($row as $item) $html.='<td>'.self::e((string)($item??'')).'</td>';
            $html.='</tr>';
        }
        if (!$rows) $html.='<tr><td colspan="'.count($headers).'">هنوز داده‌ای وجود ندارد.</td></tr>';
        return $html.'</tbody></table></div></section>';
    }

    private function labels(): array
    {
        return ['overview'=>'داشبورد','users'=>'کاربران','plans'=>'پلن‌ها','locations'=>'لوکیشن‌ها',
            'orders'=>'سفارش‌ها','releases'=>'انتشار اپ','support'=>'پشتیبانی','settings'=>'تنظیمات'];
    }

    private function page(string $title,string $body,string $active): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        $nav='';
        foreach ($this->labels() as $key=>$label) {
            $href=$key==='overview'?'/admin':'/admin/'.$key;
            $nav.='<a class="'.($key===$active?'active':'').'" href="'.$href.'">'.self::e($label).'</a>';
        }
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'.
            '<meta name="viewport" content="width=device-width,initial-scale=1">'.
            '<meta name="robots" content="noindex,nofollow"><title>'.self::e($title).' | BlueVPN</title>'.
            '<link rel="stylesheet" href="/assets/app.css"></head><body><div class="layout">'.
            '<aside class="sidebar"><div class="logo"><b>◈</b> BlueVPN</div>'.
            '<div class="section-label">CONTROL CENTER</div><nav>'.$nav.'</nav>'.
            '<div class="env">Standalone / cPanel</div></aside><main class="content">'.
            '<header class="topbar"><div><span class="eyebrow">BLUEVPN CONTROL CENTER</span>'.
            '<h1>'.self::e($title).'</h1></div><form action="/logout" method="post">'.
            Admin::csrfField().'<button class="outline">خروج</button></form></header>'.
            $body.'</main></div></body></html>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
