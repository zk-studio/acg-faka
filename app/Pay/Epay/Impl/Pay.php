<?php
declare(strict_types=1);

namespace App\Pay\Epay\Impl;

use App\Entity\PayEntity;
use App\Pay\Base;
use App\Pay\Pay as PayContract;
use Kernel\Exception\JSONException;

/**
 * 易支付 V1 协议下单实现。
 *
 * 协议要点：
 *  - 签名规则：把所有非空、非 sign / sign_type 的参数按 key 升序拼成 a=1&b=2 这种串，
 *    在末尾追加 KEY（不带任何分隔符），再做 md5 转小写。
 *  - 下单方式：
 *      1) submit_type=redirect 时直接 302 到 {gateway}/submit.php?... ，由易支付收银台接管
 *      2) submit_type=mapi 时调用 {gateway}/mapi.php，返回 JSON，里面带二维码 / 跳转 URL。
 */
class Pay extends Base implements PayContract
{
    public function trade(): PayEntity
    {
        $gateway = rtrim((string)($this->config['gateway'] ?? ''), '/');
        $pid = (string)($this->config['pid'] ?? '');
        $key = (string)($this->config['key'] ?? '');
        $type = $this->mapPayType((string)$this->code);
        $device = (string)($this->config['device'] ?? 'pc') ?: 'pc';
        $submitType = (string)($this->config['submit_type'] ?? 'redirect');

        if ($gateway === '' || $pid === '' || $key === '') {
            throw new JSONException("易支付未配置 gateway / pid / key");
        }

        $params = [
            "pid" => $pid,
            "type" => $type,
            "out_trade_no" => $this->tradeNo,
            "notify_url" => $this->callbackUrl,
            "return_url" => $this->returnUrl,
            "name" => $this->tradeNo,
            "money" => number_format($this->amount, 2, '.', ''),
            "clientip" => $this->clientIp,
            "device" => $device,
        ];

        $params["sign"] = self::sign($params, $key);
        $params["sign_type"] = "MD5";

        $entity = new PayEntity();

        if ($submitType === 'mapi') {
            $resp = $this->mapi($gateway . '/mapi.php', $params);
            if ((int)($resp['code'] ?? 0) !== 1) {
                $this->log("mapi 下单失败：" . json_encode($resp, JSON_UNESCAPED_UNICODE));
                throw new JSONException("易支付下单失败：" . ($resp['msg'] ?? '未知错误'));
            }

            // mapi 返回里通常有 payurl / qrcode / urlscheme，按优先级取
            $url = (string)($resp['payurl'] ?? $resp['qrcode'] ?? $resp['urlscheme'] ?? '');
            if ($url === '') {
                $this->log("mapi 返回缺少跳转地址：" . json_encode($resp, JSON_UNESCAPED_UNICODE));
                throw new JSONException("易支付下单返回数据异常");
            }

            $entity->setType(PayContract::TYPE_REDIRECT);
            $entity->setUrl($url);
            return $entity;
        }

        // 默认跳转 submit.php
        $entity->setType(PayContract::TYPE_REDIRECT);
        $entity->setUrl($gateway . '/submit.php?' . http_build_query($params));
        return $entity;
    }

    /**
     * 把 acg-faka 配置里的支付通道 code 映射成易支付的 type。
     * 没匹配上就保持原样，方便商户自由扩展。
     */
    private function mapPayType(string $code): string
    {
        $map = [
            'alipay'  => 'alipay',
            'wxpay'   => 'wxpay',
            'wechat'  => 'wxpay',
            'qqpay'   => 'qqpay',
            'qq'      => 'qqpay',
            'unionpay'=> 'unionpay',
            'usdt'    => 'usdt',
            'bank'    => 'bank',
        ];
        $lower = strtolower($code);
        return $map[$lower] ?? ($code !== '' ? $code : 'alipay');
    }

    /**
     * @param array<string,mixed> $params
     */
    public static function sign(array $params, string $key): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || $k === 'sign_type') {
                continue;
            }
            if ($v === '' || $v === null) {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }
        return md5(implode('&', $pairs) . $key);
    }

    private function mapi(string $url, array $params): array
    {
        $client = $this->http();
        try {
            $response = $client->post($url, [
                'form_params' => $params,
                'timeout' => 15,
            ]);
        } catch (\Throwable $e) {
            $this->log("mapi 请求异常：" . $e->getMessage());
            throw new JSONException("易支付下单网络异常");
        }

        $body = (string)$response->getBody()->getContents();
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->log("mapi 返回非 JSON：" . $body);
            throw new JSONException("易支付下单返回异常");
        }
        return $data;
    }
}
