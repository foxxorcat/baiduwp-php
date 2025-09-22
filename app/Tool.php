<?php

namespace app;

class Tool
{
    public const DEVICE_ID = "BB91C9B818963851F99A99261A70E3TUE|VUFQKX5JL";
    public const APP_SIGNATURE_CERT_MD5 = "ae5821440fab5e1a61a025f014bd8972";
    public const APP_VERSION = "11.10.4";
    public const DYNAMIC_KEY = "B8ec24caf34ef7227c66767d29ffd3fb";

    public static function getSubstr($str, string $leftStr, string $rightStr): string
    {
        if (empty($str))
            return "";
        $left = strpos($str, $leftStr);
        if ($left === false)
            return "";
        $left += strlen($leftStr);
        $right = strpos($str, $rightStr, $left);
        if ($right === false)
            return "";
        return substr($str, $left, $right - $left);
    }

    public static function getIP(): string
    {
        if (getenv("HTTP_CLIENT_IP") && strcasecmp(getenv("HTTP_CLIENT_IP"), "unknown")) {
            $ip = getenv("HTTP_CLIENT_IP");
        } else if (getenv("HTTP_X_FORWARDED_FOR") && strcasecmp(getenv("HTTP_X_FORWARDED_FOR"), "unknown")) {
            $ip = getenv("HTTP_X_FORWARDED_FOR");
        } else if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], "unknown")) {
            $ip = $_SERVER['REMOTE_ADDR'];
        } else {
            $ip = "unknown";
        }
        return htmlspecialchars($ip, ENT_QUOTES); // 防注入 #193
    }

    /**
     * RC4 解密 SK
     *
     * @param string $encrypted_sk
     * @param string $uid
     * @return string|null
     */
    public static function getTrueSk(string $encrypted_sk, string $uid): ?string
    {
        $decoded_sk_bytes = base64_decode($encrypted_sk);
        if ($decoded_sk_bytes === false)
            return null;

        $key = $uid;
        $s = range(0, 255);
        $j = 0;
        $key_len = strlen($key);
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $key_len])) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $i = $j = 0;
        $res = '';
        $str_len = strlen($decoded_sk_bytes);
        for ($y = 0; $y < $str_len; $y++) {
            $i = ($i + 1) % 256;
            $j = ($j + $s[$i]) % 256;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $res .= $decoded_sk_bytes[$y] ^ chr($s[($s[$i] + $s[$j]) % 256]);
        }
        return $res;
    }

    /**
     * 计算下载 API 所需的 rand 参数
     *
     * @param integer $timestamp_ms
     * @param string $bduss
     * @param string $uid
     * @param string $sk
     * @return string
     */
    public static function calculateRand(int $timestamp_ms, string $bduss, string $uid, string $sk): string
    {
        $true_sk = self::getTrueSk($sk, $uid);
        if ($true_sk === null)
            return "cannot_calculate";

        $sha1_of_bduss = sha1($bduss);
        $base_string = $sha1_of_bduss . $uid . $true_sk . $timestamp_ms . self::DEVICE_ID . self::APP_VERSION . self::APP_SIGNATURE_CERT_MD5;

        return sha1($base_string);
    }

    /**
     * 计算下载 API 所需的 sign 参数
     *
     * @param string $post_params_str
     * @param integer $timestamp
     * @param string $dynamic_key
     * @param string $device_id
     * @return string
     */
    public static function generateShareDownloadSign(string $post_params_str, int $timestamp): string
    {
        $base_string = $post_params_str . '_' . self::DEVICE_ID . '_' . $timestamp;
        return hash_hmac('sha1', $base_string, self::DYNAMIC_KEY);
    }

    /**
     * @description: 仿百度云SHA-1算法
     * @param string $data
     * @return string
     */
    public static function BCShafReg(string $data): string
    {
        $g = str_split("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!~");
        $sha1 = sha1($data, true);
        $e = "";
        for ($b = 0; $b < 8; $b++) {
            $c = ord(substr($sha1, $b, 1));
            $e .= $g[$c >> 2];
            $c = (3 & $c) << 4 | ord(substr($sha1, ++$b, 1)) >> 4;
            $e .= $g[$c];
            $c = (15 & ord(substr($sha1, $b, 1))) << 2 | ord(substr($sha1, ++$b, 1)) >> 6;
            $e .= $g[$c];
            $e .= $g[63 & ord(substr($sha1, $b, 1))];
        }
        $c = ord(substr($sha1, $b, 1));
        $e .= $g[$c >> 2];
        $c = (3 & $c) << 4 | ord(substr($sha1, ++$b, 1)) >> 4;
        $e .= $g[$c];
        $c = (15 & ord(substr($sha1, $b, 1))) << 2;
        $e .= $g[$c];
        $e .= "=";
        return $e;
    }


    // 贴吧客户端签名算法
    public static function tiebaClientSignature(array $post_data)
    {
        // Ported from Python script
        $BDUSS = $post_data['BDUSS'];
        $model = Tool::getPhoneModel($BDUSS);
        $imei = Tool::sumImei($model . '_' . $BDUSS);

        $post_data['_client_type'] = '2';
        $post_data['_client_version'] = '7.0.0.0';
        $post_data['_phone_imei'] = $imei;
        $post_data['from'] = 'mini_ad_wandoujia';
        $post_data['model'] = $model;

        $cuid_base = $BDUSS . '_' . $post_data['_client_version'] . '_' . $imei . '_' . $post_data['from'];
        $post_data['cuid'] = strtoupper(md5($cuid_base)) . '|' . strrev($imei);

        unset($post_data['sign']);
        ksort($post_data);

        $sign_str = '';
        foreach ($post_data as $key => $value) {
            $sign_str .= $key . '=' . $value;
        }
        $sign_str .= 'tiebaclient!!!';

        $post_data['sign'] = strtoupper(md5($sign_str));
        return $post_data;
    }

    /**
     * 手机型号生成
     * 使用更小的数据集和标准的 crc32 哈希算法，功能不变，性能更高。
     */
    public static function getPhoneModel($key)
    {
        // 使用一个更小、更有代表性的设备列表
        static $device_db = [
        // Apple
        "iPhone 16 Pro Max",
        "iPhone 16 Pro",
        "iPhone 15 Pro Max",
        "iPhone 15 Pro",

        // Samsung
        "Samsung Galaxy S25 Ultra",
        "Samsung Galaxy S24 Ultra",
        "Samsung Galaxy Z Fold7",
        "Samsung Galaxy Z Flip7",
        "Samsung Galaxy A56",

        // Google
        "Google Pixel 9 Pro",
        "Google Pixel 10 Pro XL",
        "Google Pixel 8a",

        // Xiaomi
        "Xiaomi 15 Ultra",
        "Xiaomi 14 Pro",
        "Redmi K70 Pro",

        // Huawei
        "Huawei Pura 80 Ultra",
        "Huawei Mate 60 Pro+",

        // OnePlus
        "OnePlus 13",
        "OnePlus 12",

        // Vivo & OPPO
        "Vivo X200 Ultra",
        "Oppo Find X8 Ultra",

        // Honor
        "Honor Magic6 Pro",

        // Others
        "Asus ROG Phone 9",
        "Motorola Razr Ultra"
        ];

        // 使用 crc32 生成一个确定性的哈希值，然后取模得到数组索引
        $hash = crc32($key);
        $index = abs($hash) % count($device_db); // 使用 abs() 确保索引为正数

        return $device_db[$index];
    }

    /**
     * IMEI生成
     * 使用 md5 哈希和数学运算生成确定性的15位数字，避免处理超大整数。
     */
    public static function sumImei($key)
    {
        // 1. 使用 md5 生成一个稳定的32位十六进制哈希
        $hash = md5($key);

        // 2. 截取一部分哈希值（例如前12位）并转换为十进制
        // 这足以提供巨大的随机性，同时避免超出 PHP 整数处理的范围
        $dec_val = hexdec(substr($hash, 0, 12));

        // 3. 将其限制在14位数以内
        $imei_base = $dec_val % 100000000000000; // 10^14

        // 4. 加上一个基数，确保结果总是15位数，这与原始代码的逻辑功能完全相同
        $imei = $imei_base + 100000000000000; // 10^14

        return (string) $imei;
    }
}
