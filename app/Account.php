<?php

/**
 * 百度网盘账号管理
 */

namespace app;

class Account
{
    private static $table = 'account';

    // 检查 BDUSS 是否有效
    public static function check($BDUSS)
    {
        // 【修正】优先使用新的、更简单的sync接口获取UID
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/107.0.0.0 Safari/537.36',
            'Cookie' => "BDUSS=$BDUSS;"
        ];
        $resp = Req::get('https://tieba.baidu.com/mo/q/sync', $headers);
        $json = json_decode($resp, true);

        if (isset($json['no']) && $json['no'] === 0 && isset($json['data']['user_id'])) {
            $uid = $json['data']['user_id'];
            // 尝试获取用户名作为补充
            $name_resp = Req::get('https://pan.baidu.com/api/getuname', $headers);
            $name_json = json_decode($name_resp, true);
            $baidu_name = $name_json['uname'] ?? '未知';

            return ['errno' => 0, 'uid' => $uid, 'baidu_name' => $baidu_name];
        } else {
            // 如果新接口失败，则回退到旧的、更复杂的客户端模拟接口
            return self::checkByClientLogin($BDUSS);
        }
    }

    // 获取加密的SK
    public static function getSK($uid, $BDUSS)
    {
        if (empty($uid) || empty($BDUSS))
            return null;

        $url = 'https://pan.baidu.com/api/report/user?action=ANDROID_ACTIVE_BACKGROUND_UPLOAD_AND_DOWNLOAD&clienttype=1&needrookie=1&timestamp=' . time() . '&bind_uid=' . $uid . '&channel=android';
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/107.0.0.0 Safari/537.36',
            'Cookie' => "BDUSS=$BDUSS;"
        ];

        $resp = Req::get($url, $headers);
        $json = json_decode($resp, true);

        if (isset($json['errno']) && $json['errno'] === 0 && isset($json['uinfo'])) {
            return $json['uinfo'];
        }
        return null;
    }


    // 通过模拟客户端登录获取UID（作为备用）
    public static function checkByClientLogin($BDUSS)
    {
        $timestamp = time();
        $post_data = [
            "BDUSS" => $BDUSS,
            "bdusstoken" => $BDUSS . "|null",
            "channel_id" => "",
            "channel_uid" => "",
            "stErrorNums" => "0",
            "subapp_type" => "mini",
            "timestamp" => $timestamp . "922",
        ];

        $signed_post_data = Tool::tiebaClientSignature($post_data);

        $headers = [
            "Content-Type" => "application/x-www-form-urlencoded",
            "Cookie" => "ka=open",
            "net" => "1",
            "User-Agent" => "bdtb for Android 6.9.2.1",
            "client_logid" => $timestamp . "416",
            "Connection" => "Keep-Alive",
        ];

        $resp = Req::post('http://tieba.baidu.com/c/s/login', $signed_post_data, $headers);
        $json = json_decode($resp, true);

        if (isset($json['error_code']) && $json['error_code'] == '0') {
            return ['errno' => 0, 'uid' => $json['user']['id'], 'baidu_name' => $json['user']['name']];
        }
        return ['errno' => 1, 'error' => 'BDUSS已失效'];
    }

    public static function getBDUSS($cookie)
    {
        $BDUSS = "";
        $STOKEN = "";
        preg_match('/BDUSS=([^;]*)/i', $cookie, $matches);
        if (isset($matches[1])) {
            $BDUSS = $matches[1];
        }
        preg_match('/STOKEN=([^;]*)/i', $cookie, $matches);
        if (isset($matches[1])) {
            $STOKEN = $matches[1];
        }
        return [$BDUSS, $STOKEN];
    }

    /**
     * 用于获取账号状态
     *
     * @return array [errno,会员状态,用户名,登录状态,会员剩余时间]
     */
    public static function checkStatus($cookie)
    {
        // list($BDUSS, $STOKEN) = static::getBDUSS($cookie);
        $Url = "https://pan.baidu.com/api/gettemplatevariable?channel=chunlei&web=1&app_id=250528&clienttype=0";
        $Header = [
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.514.1919.810 Safari/537.36",
            "Cookie: $cookie"
        ];
        $Data = "fields=[%22username%22,%22loginstate%22,%22is_vip%22,%22is_svip%22,%22is_evip%22]";
        $Result = Req::POST($Url, $Data, $Header);
        $Result = json_decode($Result, true);
        if ($Result["errno"] == 0) {
            // 正常
            $Username = $Result["result"]["username"];
            $LoginStatus = $Result["result"]["loginstate"];
            if ($Result["result"]["is_vip"] == 1) {
                $SVIP = 1; //会员账号
            } elseif ($Result["result"]["is_svip"] == 1 or $Result["result"]["is_evip"] == 1) {
                $SVIP = 2; //超级会员账号
            } else {
                $SVIP = 0; //普通账号
                return array(0, $SVIP, $Username, $LoginStatus, 0);
            }

            $Url = "https://pan.baidu.com/rest/2.0/membership/user?channel=chunlei&web=1&app_id=250528&clienttype=0";
            $Data = "method=query";
            $Result = Req::POST($Url, $Data, $Header);
            $Result = json_decode($Result, true);
            if (isset($Result["reminder"]["svip"])) {
                //存在会员信息
                $LeftSeconds = $Result["reminder"]["svip"]["leftseconds"];
                return array(0, $SVIP, $Username, $LoginStatus, $LeftSeconds);
            }
            return array(-1);
        } elseif ($Result["errno"] == -6) {
            // 账号失效
            return array(-6);
        } else {
            //未知错误
            return array($Result["errno"]);
        }
    }
}
