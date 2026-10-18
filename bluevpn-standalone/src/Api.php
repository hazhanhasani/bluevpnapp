<?php
declare(strict_types=1);

namespace BlueVPN\Standalone;

use PDO;
use RuntimeException;
use Throwable;

final class Api
{
    private PDO $pdo;
    public function __construct(private Database $db, private array $config)
    {
        $this->pdo=$db->connection();
    }

    /** All API aliases share one application contract; no WordPress bootstrap. */
    public function handle(string $path, string $method): void
    {
        $path=preg_replace('#^/wp-json/bluevpn/v1#','/api/v1',$path);
        $path=preg_replace('#^/wp-json/bluevpn-system/v1#','/system/v1',$path);
        try {
            if ($path==='/system/v1/app-connection' && $method==='GET') {
                $base=rtrim((string)($this->config['app']['base_url']??''),'/');
                self::json(['status'=>'ok','service'=>'bluevpn-standalone','api_base_url'=>$base,
                    'compat_api_prefix'=>'/api/v1','rest_api_base_url'=>$base.'/wp-json/bluevpn/v1',
                    'health_url'=>$base.'/health','mobile_config_url'=>$base.'/api/v1/mobile/config',
                    'login_url'=>$base.'/api/v1/auth/login','register_url'=>$base.'/api/v1/auth/register',
                    'otp_request_url'=>$base.'/api/v1/auth/otp/request',
                    'otp_verify_url'=>$base.'/api/v1/auth/otp/verify',
                    'cutover_ready'=>false,'app_cutover_enabled'=>true,
                    'features'=>['email_login'=>true,'email_registration'=>true,'plans'=>true,
                        'orders'=>false,'blupal_webhook'=>false,'bind_phone_otp'=>false,
                        'provider_sync'=>false,'live_support'=>false,'free_access'=>false]]);
                return;
            }
            if ($path==='/api/v1/mobile/config' && $method==='GET') {
                $this->mobileConfig(); return;
            }
            if ($path==='/api/v1/windows/update' && $method==='GET') {
                $this->windowsUpdate(); return;
            }
            if ($path==='/api/v1/auth/register' && $method==='POST') {
                $this->register(self::body()); return;
            }
            if ($path==='/api/v1/auth/login' && $method==='POST') {
                $this->login(self::body()); return;
            }
            if ($path==='/api/v1/auth/refresh' && $method==='POST') {
                $this->refresh(self::body()); return;
            }
            if ($path==='/api/v1/auth/logout' && $method==='POST') {
                $this->logout(); return;
            }
            if ($path==='/api/v1/plans' && $method==='GET') {
                $this->currentCustomer();
                $stmt=$this->pdo->query('SELECT id,title,description,price_toman,duration_days,data_limit_gb,device_limit FROM '.
                    $this->db->table('plans').' WHERE active=1 ORDER BY sort_order,id');
                self::json(['success'=>true,'plans'=>array_map(static fn($row)=>[
                    'id'=>(int)$row['id'],'title'=>$row['title'],'description'=>$row['description']??'',
                    'price_toman'=>(int)$row['price_toman'],'duration_days'=>(int)$row['duration_days'],
                    'data_limit_gb'=>(int)$row['data_limit_gb'],'device_limit'=>(int)$row['device_limit'],
                ],$stmt->fetchAll())]); return;
            }
            if ($path==='/api/v1/account' && $method==='GET') {
                $customer=$this->currentCustomer();
                self::json(['success'=>true,'source'=>'standalone_mysql','account'=>$this->accountPayload($customer)]);
                return;
            }
            if ($path==='/api/v1/account/sync' && $method==='POST') {
                $customer=$this->currentCustomer();
                self::json(['success'=>true,'sync'=>['queued'=>false,'mode'=>'no_provider_configured',
                    'message'=>'No provider has been configured yet'],
                    'account'=>$this->accountPayload($customer)]); return;
            }
            if ($path==='/api/v1/orders' && $method==='POST') {
                $this->currentCustomer();
                self::error('PAYMENTS_NOT_CONFIGURED','پرداخت هنوز پیکربندی نشده است.',503); return;
            }
            if (str_starts_with($path,'/api/v1/orders/') && $method==='GET') {
                $this->currentCustomer();
                self::error('ORDER_NOT_FOUND','سفارشی یافت نشد.',404); return;
            }
            if (str_starts_with($path,'/api/v1/')) {
                self::error('FEATURE_NOT_CONFIGURED','این قابلیت هنوز راه‌اندازی نشده است.',503); return;
            }
            self::error('NOT_FOUND','مسیر یافت نشد.',404);
        } catch (ApiFailure $e) {
            self::error($e->errorCode,$e->getMessage(),$e->httpStatus);
        } catch (Throwable $e) {
            error_log('BlueVPN API failure: '.get_class($e));
            self::error('INTERNAL_ERROR','خطای داخلی سرور.',500);
        }
    }

    private static function body(): array
    {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>32768) throw new ApiFailure(413,'PAYLOAD_TOO_LARGE','درخواست بیش از حد بزرگ است.');
        $raw=file_get_contents('php://input',false,null,0,32769);
        if (!is_string($raw) || strlen($raw)>32768) throw new ApiFailure(413,'PAYLOAD_TOO_LARGE','درخواست بیش از حد بزرگ است.');
        $data=json_decode($raw,true);
        if (!is_array($data) || array_is_list($data)) {
            throw new ApiFailure(400,'INVALID_JSON','بدنه درخواست باید JSON معتبر باشد.');
        }
        return $data;
    }

    private function rateLimit(string $scope, string $identity, int $limit=10): void
    {
        $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
        $key=hash('sha256',$scope.'|'.$ip.'|'.strtolower($identity));
        $table=$this->db->table('rate_limits');
        $q=$this->pdo->prepare("INSERT INTO {$table} (limiter_key,attempts,reset_at)
            VALUES (?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))
            ON DUPLICATE KEY UPDATE
              attempts=IF(reset_at <= UTC_TIMESTAMP(),1,attempts+1),
              reset_at=IF(reset_at <= UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),reset_at)");
        $q->execute([$key]);
        $q=$this->pdo->prepare("SELECT attempts FROM {$table} WHERE limiter_key=?");
        $q->execute([$key]);
        if ((int)$q->fetchColumn()>$limit) throw new ApiFailure(429,'RATE_LIMITED','درخواست‌های زیادی ارسال شده است.');
    }

    private function email(string $email): string
    {
        $email=strtolower(trim($email));
        if (strlen($email)>255 || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
            throw new ApiFailure(422,'EMAIL_INVALID','ایمیل معتبر نیست.');
        }
        return $email;
    }

    private function device(array $body): string
    {
        $id=trim((string)($body['device_id']??''));
        if ($id==='' || strlen($id)>180 || preg_match('/[\x00-\x1F]/',$id)) {
            throw new ApiFailure(422,'DEVICE_ID_REQUIRED','شناسه دستگاه معتبر نیست.');
        }
        return $id;
    }

    private function register(array $body): void
    {
        if (empty($this->config['app']['registration_enabled'])) throw new ApiFailure(403,'REGISTRATION_DISABLED','ثبت‌نام بسته است.');
        $email=$this->email((string)($body['email']??''));
        $device=$this->device($body);
        $password=(string)($body['password']??'');
        if (strlen($password)<8 || strlen($password)>128) throw new ApiFailure(422,'WEAK_PASSWORD','گذرواژه باید بین ۸ تا ۱۲۸ نویسه باشد.');
        $this->rateLimit('register',$email,5);
        try {
            $q=$this->pdo->prepare('INSERT INTO '.$this->db->table('customers').' (email,password_hash) VALUES (?,?)');
            $q->execute([$email,password_hash($password,PASSWORD_DEFAULT)]);
        } catch (\PDOException $e) {
            if ((string)$e->getCode()==='23000') throw new ApiFailure(409,'EMAIL_ALREADY_USED','این ایمیل قبلاً ثبت شده است.');
            throw $e;
        }
        $customerId=(int)$this->pdo->lastInsertId();
        $customer=$this->customerById($customerId);
        $tokens=$this->issueTokens($customerId,$device,(string)($body['device_name']??''));
        self::json(['success'=>true,'is_new_account'=>true]+$tokens+['account'=>$this->accountPayload($customer)],201);
    }

    private function login(array $body): void
    {
        $email=$this->email((string)($body['email']??''));
        $device=$this->device($body);
        $this->rateLimit('login',$email,10);
        $q=$this->pdo->prepare('SELECT * FROM '.$this->db->table('customers').' WHERE email=? LIMIT 1');
        $q->execute([$email]);
        $customer=$q->fetch();
        if (!$customer || !password_verify((string)($body['password']??''),(string)$customer['password_hash'])) {
            throw new ApiFailure(401,'INVALID_CREDENTIALS','ایمیل یا گذرواژه نادرست است.');
        }
        if (!(int)$customer['active']) throw new ApiFailure(403,'ACCOUNT_DISABLED','حساب غیرفعال است.');
        $tokens=$this->issueTokens((int)$customer['id'],$device,(string)($body['device_name']??''));
        self::json(['success'=>true,'is_new_account'=>false]+$tokens+['account'=>$this->accountPayload($customer)]);
    }

    private function issueTokens(int $id,string $device,string $name): array
    {
        $access=bin2hex(random_bytes(32));$refresh=bin2hex(random_bytes(48));
        $t=$this->db->table('customer_sessions');
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare("UPDATE {$t} SET revoked_at=UTC_TIMESTAMP()
                WHERE customer_id=? AND device_id=? AND revoked_at IS NULL");
            $q->execute([$id,$device]);
            $q=$this->pdo->prepare("INSERT INTO {$t}
                (customer_id,device_id,device_name,token_hash,refresh_hash,expires_at,refresh_expires_at)
                VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 DAY))");
            $q->execute([$id,$device,mb_substr(trim($name),0,180),hash('sha256',$access),hash('sha256',$refresh)]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();throw $e;
        }
        return ['token'=>$access,'refresh_token'=>$refresh];
    }

    private function refresh(array $body): void
    {
        $device=$this->device($body);
        $raw=(string)($body['refresh_token']??'');
        if (strlen($raw)<32 || strlen($raw)>160) throw new ApiFailure(401,'INVALID_REFRESH','نشست معتبر نیست.');
        $identity=strtolower(trim((string)($body['identity']??$body['email']??$body['phone']??'')));
        $key=hash('sha256',$raw);
        $table=$this->db->table('customer_sessions');
        $this->rateLimit('refresh',$device,40);
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare("SELECT s.*,c.email,c.active FROM {$table} s
                JOIN ".$this->db->table('customers')." c ON c.id=s.customer_id
                WHERE s.refresh_hash=? AND s.device_id=? FOR UPDATE");
            $q->execute([$key,$device]); $record=$q->fetch();
            if (!$record || !(int)$record['active'] || $record['revoked_at']!==null
                || strtotime($record['refresh_expires_at'].' UTC')<=time()
                || ($identity!=='' && !hash_equals(strtolower((string)$record['email']),$identity))) {
                throw new ApiFailure(401,'INVALID_REFRESH','توکن تمدید معتبر نیست.');
            }
            $access=bin2hex(random_bytes(32));$refresh=bin2hex(random_bytes(48));
            $q=$this->pdo->prepare("UPDATE {$table} SET token_hash=?,refresh_hash=?,
                expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY),
                refresh_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 DAY),device_name=?
                WHERE id=?");
            $q->execute([hash('sha256',$access),hash('sha256',$refresh),
                mb_substr((string)($body['device_name']??''),0,180),(int)$record['id']]);
            $this->pdo->commit();
            $customer=$this->customerById((int)$record['customer_id']);
            self::json(['success'=>true,'token'=>$access,'refresh_token'=>$refresh,'account'=>$this->accountPayload($customer)]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function currentCustomer(): array
    {
        $header=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/i',trim($header),$match)) {
            throw new ApiFailure(401,'AUTH_REQUIRED','وارد حساب شوید.');
        }
        $token=hash('sha256',$match[1]);
        $q=$this->pdo->prepare('SELECT c.* FROM '.$this->db->table('customer_sessions').' s
            JOIN '.$this->db->table('customers').' c ON c.id=s.customer_id
            WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP() AND c.active=1 LIMIT 1');
        $q->execute([$token]);$customer=$q->fetch();
        if (!$customer) throw new ApiFailure(401,'INVALID_SESSION','نشست معتبر نیست.');
        return $customer;
    }

    private function logout(): void
    {
        $customer=$this->currentCustomer();
        $header=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        preg_match('/^Bearer ([a-f0-9]{64})$/i',trim($header),$match);
        $q=$this->pdo->prepare('UPDATE '.$this->db->table('customer_sessions').
            ' SET revoked_at=UTC_TIMESTAMP() WHERE customer_id=? AND token_hash=?');
        $q->execute([(int)$customer['id'],hash('sha256',$match[1])]);
        self::json(['success'=>true]);
    }

    private function customerById(int $id): array
    {
        $q=$this->pdo->prepare('SELECT * FROM '.$this->db->table('customers').' WHERE id=? LIMIT 1');
        $q->execute([$id]);$row=$q->fetch();
        if (!$row) throw new ApiFailure(404,'ACCOUNT_NOT_FOUND','حساب یافت نشد.');
        return $row;
    }

    private function accountPayload(array $c): array
    {
        $q=$this->pdo->prepare('SELECT s.*,p.title AS plan_title,p.device_limit FROM '.$this->db->table('subscriptions').
            ' s LEFT JOIN '.$this->db->table('plans').' p ON p.id=s.plan_id WHERE s.customer_id=? LIMIT 1');
        $q->execute([(int)$c['id']]);$sub=$q->fetch()?:[];
        $expiry=(string)($sub['expires_at']??'');
        $seconds=$expiry!==''?max(0,strtotime($expiry.' UTC')-time()):null;
        $limit=(int)($sub['data_limit_bytes']??0);$used=(int)($sub['used_traffic_bytes']??0);
        $url=(string)($sub['subscription_url']??'');
        $active=($sub['status']??'')==='active' && $url!=='' && ($seconds===null||$seconds>0)
            && ($limit===0||$used<$limit);
        $status=$active?'active':((string)($sub['status']??'inactive'));
        if ($status==='active'&&!$active) $status='expired';
        $identity=(string)($c['phone']?:$c['email']);
        return [
            'id'=>(int)$c['id'],'email'=>(string)$c['email'],'phone'=>(string)($c['phone']??''),
            'phone_display'=>(string)($c['phone']??''),'phone_verified'=>false,
            'display_identity'=>$identity,'auth_method'=>'email_password','active'=>(bool)$c['active'],
            'plan_id'=>isset($sub['plan_id'])?(int)$sub['plan_id']:null,
            'plan_title'=>(string)($sub['plan_title']??''),'device_limit'=>(int)($sub['device_limit']??1),
            'expires_at_fa'=>'',
            'subscription'=>[
                'active'=>$active,'status'=>$status,'url'=>$active?$url:'',
                'expire'=>$expiry!==''?gmdate('Y-m-d\TH:i:s\Z',strtotime($expiry.' UTC')):'',
                'expire_fa'=>'','remaining_seconds'=>$seconds,'device_limit'=>(int)($sub['device_limit']??1),
                'data_limit_bytes'=>$limit,'used_traffic_bytes'=>$used,
                'remaining_bytes'=>$limit>0?max(0,$limit-$used):0,
                'pool_identity'=>hash('sha256',(string)$c['id'].'|'.(string)($sub['plan_id']??0).'|'.$url),
                'sync_error'=>'',
            ],
        ];
    }

    private function mobileConfig(): void
    {
        $base=rtrim((string)($this->config['app']['base_url']??''),'/');
        $q=$this->pdo->query("SELECT version,version_code,file_url,channel FROM ".
            $this->db->table('releases')." WHERE platform='android' AND active=1 ORDER BY id DESC LIMIT 1");
        $release=$q->fetch()?:[];
        self::json([
            'app_name'=>'BlueVPN','maintenance'=>false,'support_url'=>$base.'/support',
            'account_required'=>true,'latest_version'=>(string)($release['version']??''),
            'latest_version_code'=>(int)($release['version_code']??0),'minimum_version'=>'0.0.0',
            'force_update'=>false,'auto_update'=>false,'auto_update_stable'=>false,
            'auto_update_beta'=>false,'apk_url'=>(string)($release['file_url']??''),
            'apk_assets'=>[],'apk_asset_meta'=>[],'update_title'=>'','update_message'=>'',
            'release_channel'=>(string)($release['channel']??'stable'),
            'release_auth_state'=>'guest','update_source'=>'standalone_mysql',
            'auth'=>['mode'=>'email_password','email_login'=>true,'password_login'=>true,
                'email_registration'=>!empty($this->config['app']['registration_enabled']),
                'phone_otp_enabled'=>false,
                'login_url'=>$base.'/api/v1/auth/login','register_url'=>$base.'/api/v1/auth/register'],
            'advertising'=>['enabled'=>false,'items'=>[]],'ads'=>['enabled'=>false,'items'=>[]],
            'tapsell'=>['enabled'=>false], 'free_story_ads'=>['enabled'=>false],
            'blueai'=>['enabled'=>false], 'announcement'=>['enabled'=>false],
        ]);
    }

    private function windowsUpdate(): void
    {
        $q=$this->pdo->query("SELECT version,version_code,file_url,sha256,channel FROM ".
            $this->db->table('releases')." WHERE platform='windows' AND active=1 ORDER BY id DESC LIMIT 1");
        $release=$q->fetch()?:[];
        self::json(['success'=>true,'platform'=>'windows','available'=>false,
            'current_version'=>'','latest_version'=>(string)($release['version']??''),
            'latest_version_code'=>(int)($release['version_code']??0),
            'update_available'=>false,'minimum_version'=>'0.0.0','below_minimum'=>false,
            'force_update'=>false,'auto_update'=>false,'release_channel'=>'stable',
            'beta_tester'=>false,'title'=>'','message'=>'','release_url'=>'',
            'architecture'=>'win-x64','download_url'=>(string)($release['file_url']??''),
            'filename'=>'','sha256'=>(string)($release['sha256']??''),'size'=>0]);
    }

    public static function json(array $data,int $status=200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Language: fa-IR');
        header('Cache-Control: no-store');
        echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public static function error(string $code,string $message,int $status): void
    {
        self::json(['detail'=>['code'=>$code,'message'=>$message]],$status);
    }
}

final class ApiFailure extends RuntimeException
{
    public function __construct(public int $httpStatus,public string $errorCode,string $message)
    {
        parent::__construct($message,$httpStatus);
    }
}
