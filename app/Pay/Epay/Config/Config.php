<?php
declare(strict_types=1);

/**
 * 易支付商户配置
 *
 * 在「后台 → 支付 → 支付插件 → 易支付 → 设置」里维护：
 *  - gateway 易支付站点根地址（不要带末尾斜杠），例如 https://www.ezfpy.cn
 *  - pid     商户 ID
 *  - key     商户密钥（用于下单与回调验签的 md5 加密）
 *  - device  下单时声明的终端：默认 pc
 *  - submit_type  redirect=用 submit.php 跳转, mapi=用 mapi.php 直连
 */
return [
    "gateway" => "",
    "pid" => "",
    "key" => "",
    "device" => "pc",
    "submit_type" => "redirect",
];
