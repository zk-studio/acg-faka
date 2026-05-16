<?php
declare(strict_types=1);

namespace App\Pay\Epay\Impl;

use App\Pay\Signature as SignatureContract;

/**
 * 易支付回调签名校验
 *
 * 入站参数（GET）来自易支付服务端，acg-faka 已经把它们填到 $data。
 * 校验规则与 Pay::sign 一致：
 *   把 sign / sign_type 之外的非空字段按 key 升序拼成 query，再 md5(query.key)。
 */
class Signature implements SignatureContract
{
    public function verification(array $data, array $config): bool
    {
        $remote = (string)($data['sign'] ?? '');
        $key = (string)($config['key'] ?? '');
        if ($remote === '' || $key === '') {
            return false;
        }

        $expected = Pay::sign($data, $key);
        return hash_equals($expected, $remote);
    }
}
