<?php
declare(strict_types=1);

/**
 * 易支付（彩虹/V1 标准协议）插件元信息
 *
 * 协议参考：易支付 V1 标准
 *  - submit.php       表单跳转下单
 *  - mapi.php         API 形式下单（返回 JSON）
 *  - 异步回调 GET 参数携带签名 sign
 *
 * 回调示例字段：
 *   pid           商户 ID
 *   trade_no      易支付订单号
 *   out_trade_no  商户订单号  ←→ 我们这里就是 acg-faka 的 trade_no
 *   type          支付方式（alipay/wxpay/qqpay/...）
 *   name          商品名称
 *   money         金额
 *   trade_status  TRADE_SUCCESS
 *   sign          签名
 *   sign_type     MD5
 */
return [
    "name" => "易支付",
    "code" => "Epay",
    "version" => "1.0.0",
    "description" => "兼容标准易支付 V1 协议（彩虹易支付、ezfpy 等）",
    "author" => "self",

    /**
     * 回调字段映射，按 App\Consts\Pay 的整型 key 定义
     */
    "callback" => [
        \App\Consts\Pay::IS_SIGN => true,                 // 启用签名校验
        \App\Consts\Pay::IS_STATUS => true,               // 启用状态校验
        \App\Consts\Pay::FIELD_STATUS_KEY => "trade_status",
        \App\Consts\Pay::FIELD_STATUS_VALUE => "TRADE_SUCCESS",
        \App\Consts\Pay::FIELD_ORDER_KEY => "out_trade_no",
        \App\Consts\Pay::FIELD_AMOUNT_KEY => "money",
        \App\Consts\Pay::FIELD_RESPONSE => "success",     // 通知响应体
    ],
];
