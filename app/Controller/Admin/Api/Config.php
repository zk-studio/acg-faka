<?php
declare(strict_types=1);

namespace App\Controller\Admin\Api;

use App\Controller\Base\API\Manage;
use App\Entity\Query\Get;
use App\Interceptor\ManageSession;
use App\Model\Business;
use App\Model\Config as CFG;
use App\Model\ManageLog;
use App\Service\Email;
use App\Service\Query;
use App\Service\Sms;
use App\Util\Client;
use App\Util\Date;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Exception\RuntimeException;
use Kernel\Waf\Filter;
use PHPMailer\PHPMailer\PHPMailer;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Config extends Manage
{

    #[Inject]
    private Query $query;

    #[Inject]
    private Sms $sms;

    #[Inject]
    private Email $email;

    /**
     * 解析后台保存时传来的 logo 字段，决定真正落库的值。
     *
     * - 远程 http(s) 直接保留
     * - 本地资源：把上传产物搬到 /assets/cache/general/image/site-logo.<ext> 这个稳定路径，
     *   避免下一次部署 git reset 把它冲掉，也避免 favicon.ico 被默认 logo 覆盖。
     * - 已经位于稳定路径或仅文件名不同：原样接受
     * - 不可识别 / 文件不存在：fallback 到 /favicon.ico
     */
    private function resolveLogoPath(string $input): string
    {
        $fallback = '/favicon.ico';
        $input = trim($input);

        if ($input === '') {
            return $fallback;
        }

        // 远程地址直接接受
        if (preg_match('#^https?://#i', $input)) {
            return $input;
        }

        $path = parse_url($input, PHP_URL_PATH) ?: $input;
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/')) {
            return $fallback;
        }

        // 防止越权
        if (str_contains($path, '..')) {
            return $fallback;
        }

        // 用户什么都没改，直接保留默认 favicon
        if ($path === $fallback) {
            return $fallback;
        }

        $sourceFile = BASE_PATH . $path;
        if (!is_file($sourceFile)) {
            return $fallback;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png', 'jpg', 'jpeg', 'ico', 'webp', 'gif'], true)) {
            $extension = 'png';
        }

        $targetPath = "/assets/cache/general/image/site-logo.{$extension}";
        $targetFile = BASE_PATH . $targetPath;

        // 已经是稳定路径，直接复用
        if ($sourceFile === $targetFile) {
            return $targetPath;
        }

        @mkdir(dirname($targetFile), 0775, true);

        // 清掉旧扩展名的同名 logo，防止前端预览拿到不同扩展的旧文件
        foreach (['png', 'jpg', 'jpeg', 'ico', 'webp', 'gif'] as $ext) {
            if ($ext === $extension) {
                continue;
            }
            $stale = BASE_PATH . "/assets/cache/general/image/site-logo.{$ext}";
            if (is_file($stale)) {
                @unlink($stale);
            }
        }

        if (!@copy($sourceFile, $targetFile)) {
            // 复制失败但源文件确实存在，至少把原始路径保留下来
            return $path;
        }

        // 推进 mtime，让前台 ?v=mtime 这种 cache buster 能发现变更
        @touch($targetFile);

        return $targetPath;
    }

    /**
     * @param Request $request
     * @return array
     * @throws JSONException
     * @throws \Throwable
     */
    public function setting(Request $request): array
    {
        $post = $request->post(flags: Filter::NORMAL);
        $keys = ["closed_message", "background_mobile_url", "closed", "username_len", "user_theme", "user_mobile_theme", "user_center_theme", "background_url", "shop_name", "title", "description", "keywords", "registered_state", "registered_type", "registered_verification", "registered_phone_verification", "registered_email_verification", "login_verification", "forget_type", "notice", "trade_verification", "session_expire", "request_log"]; //全部字段
        $inits = ["closed", "registered_state", "registered_type", "registered_verification", "registered_phone_verification", "registered_email_verification", "login_verification", "forget_type", "trade_verification", "session_expire", "request_log"]; //需要初始化的字段

        $keys[] = "logo";
        $logoInput = (string)($post['logo'] ?? '');
        $post['logo'] = $this->resolveLogoPath($logoInput);
        try {
            if (isset($post['ip_get_mode'])) {
                Client::setClientMode((int)$post['ip_get_mode']);
            }

            foreach ($keys as $index => $key) {
                if (in_array($key, $inits)) {
                    if (!isset($post[$key])) {
                        $post[$key] = 0;
                    }
                }
                CFG::put($key, $post[$key]);
            }
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        _plugin_start($post['user_theme'], true);
        ManageLog::log($this->getManage(), "修改了网站设置");
        return $this->json(200, '保存成功');
    }

    /**
     * @return array
     * @throws JSONException
     */
    public function other(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        $keys = ["recharge_min", "commodity_recommend", "commodity_name", "recharge_max", "cname", "default_category", "callback_domain", "recharge_welfare_config", "recharge_welfare", "substation_display", "domain", "service_url", "service_qq", "cash_type_alipay", "cash_type_wechat", "cash_type_balance", "cash_cost", "cash_min", "cash_type_usdt"]; //全部字段
        $inits = ["recharge_min", "commodity_recommend", "recharge_max", "recharge_welfare", "substation_display", "cash_type_alipay", "cash_type_wechat", "cash_type_balance", "cash_cost", "cash_min", "default_category", "cash_type_usdt"]; //需要初始化的字段

        if (!empty($map['recharge_welfare_config'])) {
            $explode = explode(PHP_EOL, trim($map['recharge_welfare_config'], PHP_EOL));
            foreach ($explode as $item) {
                $def = explode("-", $item);
                if (count($def) != 2) {
                    throw new JSONException("充值赠送配置规则表达式错误");
                }
            }
        }

        try {
            foreach ($keys as $index => $key) {
                if (in_array($key, $inits)) {
                    if (!isset($map[$key])) {
                        $map[$key] = 0;
                    }
                }
                CFG::put($key, $map[$key]);
            }
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        ManageLog::log($this->getManage(), "修改了其他设置");
        return $this->json(200, '保存成功');
    }


    /**
     * @return array
     * @throws RuntimeException
     */
    public function setSubstationDisplayList(): array
    {
        $userId = (int)$_POST['id'];
        $type = (int)$_POST['type'];
        $list = json_decode(CFG::get("substation_display_list"), true);
        if ($type == 0) {
            //添加过滤
            if (!in_array($userId, $list)) {
                $list[] = $userId;
            }
        } else {
            //解除过滤
            if (($key = array_search($userId, $list)) !== false) {
                unset($list[$key]);
                $list = array_values($list);
            }
        }

        ManageLog::log($this->getManage(), "修改了子站显示列表");
        CFG::put("substation_display_list", json_encode($list));
        return $this->json(200, "成功", $list);
    }

    /**
     * @throws JSONException
     */
    public function sms(): array
    {
        try {
            CFG::put("sms_config", json_encode($_POST));
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        ManageLog::log($this->getManage(), "修改了短信配置");
        return $this->json(200, '保存成功');
    }

    /**
     * @throws JSONException
     */
    public function email(): array
    {
        try {
            CFG::put("email_config", json_encode($_POST));
        } catch (\Exception $e) {
            throw new JSONException("保存失败，请检查原因");
        }

        ManageLog::log($this->getManage(), "修改了邮件配置");
        return $this->json(200, '保存成功');
    }


    public function smsTest(): array
    {
        $this->sms->sendCaptcha($_POST['phone'], Sms::CAPTCHA_REGISTER);

        ManageLog::log($this->getManage(), "测试了短信发送");
        return $this->json(200, "短信发送成功");
    }

    /**
     * @return array
     * @throws JSONException
     * @throws RuntimeException
     */
    public function emailTest(): array
    {
        $shopName = CFG::get("shop_name");
        $result = $this->email->send($_POST['email'], $shopName . "-手动测试邮件", '测试邮件，发送时间：' . Date::current());
        if (!$result) {
            throw new JSONException("发送失败");
        }
        ManageLog::log($this->getManage(), "测试了邮件发送");
        return $this->json(200, "成功!");
    }

    /**
     * @return array
     */
    public function getBusiness(): array
    {
        $get = new Get(Business::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with(['user' => function (Relation $relation) {
                $relation->with(['businessLevel'])->select(["id", "business_level", "username", "avatar"]);
            }]);
        });
        return $this->json(data: $data);
    }
}
