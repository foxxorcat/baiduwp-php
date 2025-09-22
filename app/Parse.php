<?php

namespace app;

use app\Req;
use app\Tool;
use think\facade\Db;

/**
 * PanDownload 网页复刻版，PHP 语言版
 *
 * @author Yuan_Tuo <yuantuo666@gmail.com>
 * @link https://github.com/yuantuo666/baiduwp-php
 */
class Parse
{
	public static function getSign(string $surl = "", string $share_id = "", string $uk = ""): array
	{
		// construct url
		$params = "";
		if (substr($surl, 0, 1) == "2") {
			$surl_2 = substr($surl, 1);
			$surl_2 = base64_decode($surl_2);
			[$uk, $share_id] = explode("&", $surl_2);
			$surl = "";
		}
		if ($surl)
			$params .= "&surl=$surl";
		if ($share_id)
			$params .= "&shareid=$share_id";
		if ($uk)
			$params .= "&uk=$uk";
		$url = "https://pan.baidu.com/share/tplconfig?$params&fields=sign,timestamp&channel=chunlei&web=1&app_id=250528&clienttype=0";
		$header = [
			"User-Agent: netdisk;pan.baidu.com",
			"Cookie: " . config('baiduwp.cookie'),
		];
		$result = Req::GET($url, $header);
		$result = json_decode($result, true, 512, JSON_BIGINT_AS_STRING);
		if (($result["errno"] ?? 1) == 0) {
			$sign = $result["data"]["sign"];
			$timestamp = $result["data"]["timestamp"];
			return [0, $sign, $timestamp];
		} else {
			return [-1, $result["show_msg"] ?? "", ""];
		}
	}

	public static function decodeSceKey($seckey)
	{
		$seckey = str_replace("-", "+", $seckey);
		$seckey = str_replace("~", "=", $seckey);
		return str_replace("_", "/", $seckey);
	}

	public static function decryptMd5($md5)
	{
		if (preg_match('/^.{9}[a-f0-9]/', $md5) && ctype_xdigit(substr($md5, 9, 1))) {
			return $md5;
		}
		$key = dechex(ord(substr($md5, 9, 1)) - ord('g'));
		$key2 = substr($md5, 0, 9) . $key . substr($md5, 10, strlen($md5));
		$key3 = "";
		for ($a = 0; $a < strlen($key2); $a++) {
			$key3 .= dechex(hexdec($key2[$a]) ^ (15 & $a));
		}
		return substr($key3, 8, 8) . substr($key3, 0, 8) . substr($key3, 24, 8) . substr($key3, 16, 8);
	}

	public static function getList($surl, $pwd, $dir, $sign = "", $timestamp = ""): array
	{
		$message = [];
		if (!$sign || !$timestamp) {
			list($status, $sign, $timestamp) = self::getSign($surl);
			if ($status !== 0) {
				$sign = '';
				$timestamp = '1';
				$message[] = "无传入，自动获取sign和timestamp失败, $sign";
			} else {
				$message[] = "无传入，自动获取sign和timestamp成功: $sign, $timestamp";
			}
		}

		$IsRoot = $dir == "";
		$file_list = [];
		$Page = 1;
		// 获取所有文件 fix #86
		while (true) {
			$Filejson = self::getListApi($surl, $dir, $IsRoot, $pwd, $Page);
			if (config('app.debug'))
				$message[] = json_encode($Filejson);
			if ($Filejson["errno"] ?? 999 !== 0) {
				return self::listError($Filejson, $message);
			}
			foreach ($Filejson['data']['list'] as $v) {
				$file_list[] = $v;
			}
			if (count($Filejson['data']["list"]) < 1000)
				break;
			$Page++;
		}
		$randSk = urlencode(self::decodeSceKey($Filejson["data"]["seckey"]));
		$shareid = $Filejson["data"]["shareid"];
		$uk = $Filejson["data"]["uk"];

		// breadcrumb
		$DirSrc = [];
		if (!$IsRoot) {
			$Dir_list = explode("/", $dir);

			for ($i = 1; $i <= count($Dir_list) - 2; $i++) {
				if ($i == 1 and strstr($Dir_list[$i], "sharelink"))
					continue;
				$fullsrc = strstr($dir, $Dir_list[$i], true) . $Dir_list[$i];
				$DirSrc[] = array("isactive" => 0, "fullsrc" => $fullsrc, "dirname" => $Dir_list[$i]);
			}
			$DirSrc[] = array("isactive" => 1, "fullsrc" => $dir, "dirname" => $Dir_list[$i]);
		}
		$Filenum = count($file_list);
		$FileData = [];
		$RootData = array(
			"src" => $DirSrc,
			"timestamp" => $timestamp,
			"sign" => $sign,
			"randsk" => $randSk,
			"shareid" => $shareid,
			"surl" => $surl,
			"pwd" => $pwd,
			"uk" => $uk,
		);

		foreach ($file_list as $file) {
			if ($file["isdir"] == 0) { // 根目录返回的居然是字符串 #255
				//文件
				$FileData[] = array(
					"isdir" => 0,
					"name" => $file["server_filename"],
					"fs_id" => number_format($file["fs_id"], 0, '', ''),
					"size" => $file["size"],
					"uploadtime" => $file["server_ctime"],
					"md5" => $file["md5"],
					"dlink" => $file["dlink"]
				);
			} else {
				//文件夹
				$FileData[] = array(
					"isdir" => 1,
					"name" => $file["server_filename"],
					"path" => $file["path"],
					"size" => $file["size"],
					"uploadtime" => $file["server_ctime"]
				);
			}
		}

		return array(
			"error" => 0,
			"isroot" => $IsRoot,
			"dirdata" => $RootData,
			"filenum" => $Filenum,
			"filedata" => $FileData,
			"message" => $message
		);
	}
	public static function download($fs_id, $timestamp, $sign, $randsk, $share_id, $uk)
	{
		$message = [];
		$ip = Tool::getIP();
		$isipwhite = FALSE;

		// 1. IP 黑白名单检查
		if (config('baiduwp.db')) {
			$data = Db::connect()->table("ip")->where('ip', $ip)->find();
			if ($data) {
				if ($data->type === -1) {
					return array("error" => -1, "msg" => "当前ip已被加入黑名单，请联系站长解封", "ip" => $ip);
				} elseif ($data->type === 0) {
					$message[] = "当前ip为白名单~ $ip";
					$isipwhite = TRUE;
				}
			}
		}

		// 2. 参数基础验证
		if (!$fs_id || !$randsk || !$share_id || !$uk) {
			return ['error' => -1, 'msg' => '核心参数缺失，无法继续解析。'];
		}

		// 3. 检查并自动续期 sign 和 timestamp
		if (time() - $timestamp > 300) {
			list($_status, $sign, $timestamp) = self::getSign("", $share_id, $uk);
			if ($_status !== 0) {
				$message[] = "链接超时，自动续期 sign 和 timestamp 失败, $sign";
			} else {
				$message[] = "链接超时，自动续期 sign 和 timestamp 成功: $sign, $timestamp";
			}
		}

		// 4. 获取解析所需的 BDUSS, UID 和 SK
		$cookie = config('baiduwp.svip_cookie') ?: config('baiduwp.cookie');
		$bduss = Tool::getSubstr($cookie, 'BDUSS=', ';');
		if (empty($bduss)) {
			return ['error' => -1, 'msg' => '未在配置中找到 SVIP BDUSS。'];
		}
		$uid = self::_getUid($bduss);
		$sk = $uid ? self::_getSk($uid, $bduss) : null;
		if (!$uid || !$sk) {
			return ['error' => -1, 'msg' => '获取用户信息(UID/SK)失败，请检查 BDUSS 是否有效或过期。'];
		}

		// 5. 调用核心接口获取下载信息 
		$json4 = self::getDlink($fs_id, $randsk, $share_id, $uk, $bduss, $uid, $sk);
		$errno = $json4["errno"] ?? 999;

		if ($errno !== 0) {
			$errMsg = $json4["errmsg"] ?? ($json4["error_msg"] ?? "未知错误");
			$message[] = config('app.debug') ? "获取下载链接 API 返回错误: " . json_encode($json4) : "获取下载链接 API 返回错误: " . $errMsg;
			return self::downloadError($json4, $message);
		}

		// 6. 解析 API 返回的文件信息 (恢复原始逻辑)
		$dlink = $json4["list"][0]["dlink"] ?? "";
		$md5 = self::decryptMd5($json4["list"][0]["md5"]);
		$filename = $json4["list"][0]["server_filename"] ?? "";
		$size = $json4["list"][0]["size"] ?? "0";
		$path = $json4["list"][0]["path"] ?? "";
		$server_ctime = (int) ($json4["list"][0]["server_ctime"] ?? 28800);

		$FileData = ["filename" => $filename, "size" => $size, "path" => $path, "uploadtime" => $server_ctime, "md5" => $md5];

		// 7. 小文件直接返回
		if ($size <= 5242880) { // 5MB
			return ["error" => 0, "filedata" => $FileData, "directlink" => $dlink, "user_agent" => "LogStatistic", "message" => $message];
		}

		// 8. 大文件处理流程
		list($ID, $svip_cookie_db) = ["-1", config('baiduwp.svip_cookie') ? config('baiduwp.svip_cookie') : config('baiduwp.cookie')];

		if (config('baiduwp.db')) {
			$link_expired_time = config('baiduwp.link_expired_time') ?? 8;
			$record = Db::connect()->table("records")->where('md5', $md5)->where('time', '>', date('Y-m-d H:i:s', time() - ($link_expired_time * 3600)))->find();
			if ($record) {
				return ["error" => 0, "usingcache" => true, "filedata" => $FileData, "directlink" => $record['link'], "user_agent" => "LogStatistic", "message" => $message];
			}

			if (!$isipwhite) {
				$count = Db::connect()->table('records')->where('ip', $ip)->where('time', '>', date('Y-m-d 00:00:00'))->count();
				if ($count > config('baiduwp.times')) {
					return ["error" => 1, "msg" => "今日解析次数已达上限，请明天再试", "ip" => $ip];
				}
				$flow = Db::connect()->table('records')->where('ip', $ip)->where('time', '>', date('Y-m-d 00:00:00'))->sum('size');
				if ($flow > config('baiduwp.flow') * 1024 * 1024 * 1024) {
					return ["error" => 1, "msg" => "今日解析流量已达上限，请明天再试", "ip" => $ip];
				}
			}

			$query = Db::connect()->table("account")->where('status', 0);
			if (config('baiduwp.random_account')) {
				$query = $query->order('last_used_at', 'asc');
			} else {
				$query = $query->order('created_at', 'asc');
			}
			$account = $query->find();
			if ($account) {
				list($ID, $svip_cookie_db) = [$account['id'], $account['cookie']];
				Db::connect()->table('account')->where('id', $ID)->update(['last_used_at' => date('Y-m-d H:i:s')]);
			}
		}

		$SVIP_BDUSS = Tool::getSubstr($svip_cookie_db, 'BDUSS=', ';');
		$headerArray = ['User-Agent: LogStatistic', 'Cookie: BDUSS=' . $SVIP_BDUSS . ';'];
		$head_response = Req::HEAD($dlink, $headerArray);

		if (!strstr($head_response, "Location")) {
			$message[] = "获取真实链接失败 $head_response";
		}
		$real_link = Tool::getSubstr(str_replace("https://", "http://", $head_response), "Location: ", "\r\n");

		if (!$real_link || strlen($real_link) < 20) {
			$body = json_decode(Req::GET($dlink, $headerArray), true);
			$message[] = "获取真实下载链接失败：" . json_encode($body);

			if (config('baiduwp.db') && config('baiduwp.check_speed_limit') && $ID != "-1") {
				if (Db::connect()->table('account')->where('id', $ID)->update(['status' => -1])) {
					return ["error" => -1, "msg" => "SVIP账号自动切换成功，请重新请求获取下载地址", "message" => $message];
				} else {
					return ["error" => -1, "msg" => "SVIP账号自动切换失败", "message" => $message];
				}
			}
			return self::realLinkError($body, $message);
		}
		if (str_contains($real_link, "qdall01") || !str_contains($real_link, 'tsl=0')) {
			$message[] = "SVIP账号限速";
			if (config('baiduwp.db') && config('baiduwp.check_speed_limit') && $ID != "-1") {
				if (Db::connect()->table('account')->where('id', $ID)->update(['status' => -1])) {
					return ["error" => -1, "msg" => "SVIP账号自动切换成功，请重新请求获取下载地址", "message" => $message];
				} else {
					return ["error" => -1, "msg" => "SVIP账号自动切换失败", "message" => $message];
				}
			}
		}

		if (config('baiduwp.db')) {
			if (!Db::connect()->table("records")->insert(['ip' => $ip, 'ua' => request()->header('User-Agent'), 'name' => $filename, 'size' => $size, 'md5' => $md5, 'link' => $real_link, 'time' => date('Y-m-d H:i:s'), 'account' => $ID])) {
				return ["error" => -1, "msg" => "数据库保存错误", "message" => $message];
			}
		}

		return ["error" => 0, "filedata" => $FileData, "directlink" => $real_link, "user_agent" => "LogStatistic", "message" => $message];
	}

	private static function getListApi(string $Shorturl, string $Dir, bool $IsRoot, string $Password, int $Page = 1)
	{
		$Url = 'https://pan.baidu.com/share/wxlist?channel=weixin&version=2.2.2&clienttype=25&web=1';
		$Root = ($IsRoot) ? "1" : "0";
		$Dir = urlencode($Dir);
		if (substr($Shorturl, 0, 1) == "2") {
			$Shorturl = substr($Shorturl, 1);
			$Shorturl = base64_decode($Shorturl);
			[$uk, $share_id] = explode("&", $Shorturl);
			$params = "&uk=$uk&shareid=$share_id";
		} else {
			$params = "&shorturl=$Shorturl";
		}
		$Data = "$params&dir=$Dir&root=$Root&pwd=$Password&page=$Page&num=1000&order=time";
		$BDUSS = Tool::getSubstr(config('baiduwp.cookie'), 'BDUSS=', ';');
		$header = ["User-Agent: netdisk", "Cookie: BDUSS=$BDUSS", "Referer: https://pan.baidu.com/disk/home"];
		return json_decode(Req::POST($Url, $Data, $header), true);
	}

	private static function getDlink(string $fs_id, string $randsk, string $share_id, string $uk, string $bduss, string $uid, string $sk)
	{
		$timestamp = time();
		$timestamp_ms = (int) (microtime(true) * 1000);

		$rand = Tool::calculateRand($timestamp_ms, $bduss, $uid, $sk);
		if ($rand === "cannot_calculate") {
			return ["errno" => -1, "errmsg" => "无法解密 SK，请检查 UID 和 SK 是否正确。"];
		}

		$post_body = "encrypt=0&uk=$uk&product=share&primaryid=$share_id&fid_list=[$fs_id]&extra=" . urlencode('{"sekey":"' . urldecode($randsk) . '"}');
		$sign = Tool::generateShareDownloadSign($post_body, $timestamp);

		$params = [
			'sign' => $sign,
			'timestamp' => $timestamp,
			'rand' => $rand,
			'time' => $timestamp_ms,
			'devuid' => Tool::DEVICE_ID,
			'channel' => 'android',
			'clienttype' => '1',
			'version' => Tool::APP_VERSION
		];
		$url = 'https://pan.baidu.com/api/sharedownload?' . http_build_query($params);

		$header = [
			"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/107.0.0.0 Safari/537.36",
			"Cookie: BDUSS=" . $bduss,
			"Referer: https://pan.baidu.com/disk/home"
		];

		return json_decode(Req::POST($url, $post_body, $header), true);
	}

	/**
	 * 获取用户 UID
	 *
	 * @param string $bduss
	 * @return string|null
	 */
    /**
	 * 获取用户 UID，优先使用 sync 接口，失败则回退到贴吧客户端登录
	 */
	private static function _getUid(string $bduss): ?string
	{
		// 1. 优先从数据库读取
		$account = Db::connect()->table("account")->where('cookie', 'like', '%' . $bduss . '%')->find();
		if ($account && !empty($account['uid'])) {
			return $account['uid'];
		}
		$uid = null;

		// 2. 数据库中不存在，发起网络请求 (主方案)
		$url = "https://tieba.baidu.com/mo/q/sync";
		$header = ["Cookie: BDUSS=" . $bduss, "User-Agent: Mozilla/5.0"];
		$result = Req::GET($url, $header);

		if (!empty($result)) {
			$data = json_decode($result, true);
			if (isset($data['no']) && $data['no'] == 0 && isset($data['data']['user_id'])) {
				$uid = (string)$data['data']['user_id'];
			}
		}

		// 3. 如果主方案失败，则执行备用方案 (Fallback)
		if ($uid === null) {
			$clientLoginResult = Account::checkByClientLogin($bduss);
			if (isset($clientLoginResult['errno']) && $clientLoginResult['errno'] === 0 && isset($clientLoginResult['uid'])) {
				$uid = $clientLoginResult['uid'];
			}
		}

		// 4. 如果任一方案成功，则将结果写回数据库
		if ($uid !== null && $account) {
			Db::connect()->table("account")->where('id', $account['id'])->update(['uid' => $uid]);
		}

		return $uid;
	}

	/**
	 * 获取加密的 SK
	 *
	 * @param string $uid
	 * @param string $bduss
	 * @return string|null
	 */
	private static function _getSk(string $uid, string $bduss): ?string
	{
		// 1. 优先从数据库读取
		$account = Db::connect()->table("account")->where('uid', $uid)->find();
		if ($account && !empty($account['sk'])) {
			return $account['sk'];
		}

		// 2. 数据库中不存在，发起网络请求
		$params = [
			'action' => 'ANDROID_ACTIVE_BACKGROUND_UPLOAD_AND_DOWNLOAD', 'clienttype' => '1', 'needrookie' => '1',
			'timestamp' => time(), 'bind_uid' => $uid, 'channel' => 'android'
		];
		$url = "https://pan.baidu.com/api/report/user?" . http_build_query($params);
		$header = ["Cookie: BDUSS=" . $bduss, "User-Agent: Mozilla/5.0"];
		$result = Req::GET($url, $header);
		if (empty($result)) return null;

		$data = json_decode($result, true);
		if (isset($data['errno']) && $data['errno'] == 0 && isset($data['uinfo'])) {
			$sk = $data['uinfo'];
			// 3. 将新获取的 SK 写回数据库
			if ($account) {
				Db::connect()->table("account")->where('id', $account['id'])->update(['sk' => $sk]);
			}
			return $sk;
		}
		
		return null;
	}

	private static function listError($Filejson, $message): array
	{
		if (empty($Filejson)) {
			return [
				"error" => -1,
				"title" => "获取到的列表为空",
				"msg" => "请确保已正确设置 Cookie 且服务器网络连接正常",
				"message" => $message
			];
		}
		// 解析异常
		$ErrorCode = $Filejson["errtype"] ?? ($Filejson["errno"] ?? 999);
		$ErrorMessage = [
			"mis_105" => "你所解析的文件不存在~",
			"mispw_9" => "提取码错误",
			"mispwd-9" => "提取码错误",
			"mis_2" => "不存在此目录",
			"mis_4" => "不存在此目录",
			5 => "不存在此分享链接或提取码错误",
			3 => "此链接分享内容可能因为涉及侵权、色情、反动、低俗等信息，无法访问！",
			0 => "啊哦，你来晚了，分享的文件已经被删除了，下次要早点哟。",
			10 => "啊哦，来晚了，该分享文件已过期",
			8001 => "普通账号可能被限制，请检查普通账号状态",
			9013 => "普通账号被限制，请检查普通账号状态",
			9019 => "普通账号 Cookie 状态异常，请检查：账号是否被限制、Cookie 是否过期（前往网站设置页面修改）",
			999 => "错误 -> " . json_encode($Filejson)
		];
		return [
			"error" => -1,
			"title" => "获取列表错误 ($ErrorCode)",
			"msg" => $ErrorMessage[$ErrorCode] ?? "未知错误，如多次出现请向提出issue反馈",
			"message" => $message
		];
	}

	private static function downloadError($json4, $message): array
	{
		$errno = $json4["errno"] ?? 999;
		$error = [
			999 => ["请求错误", "请求百度网盘服务器出错，请检查网络连接或重试"],
			-20 => ["触发验证码(-20)", "请等待一段时间，再返回首页重新解析。"],
			-9 => ["文件不存在(-9)", "请返回首页重新解析。"],
			-6 => ["账号未登录(-6)", "请检查普通账号是否正常登录。"],
			-1 => ["文件违规(-1)", "您下载的内容中包含违规信息"],
			2 => ["下载失败(2)", "下载失败，请稍候重试"],
			112 => ["链接超时(112)", "获取链接超时，每次解析列表后只有5min有效时间，请返回首页重新解析。"],
			113 => ["传参错误(113)", "获取失败，请检查参数是否正确。"],
			116 => ["链接错误(116)", "该分享不存在"],
			118 => ["没有下载权限(118)", "没有下载权限，请求百度服务器时，未传入sekey参数或参数错误。"],
			110 => ["服务器错误(110)", "服务器错误，可能服务器IP被百度封禁，请切换 IP 或更换服务器重试。"],
			121 => ["服务器错误(121)", "你选择操作的文件过多，减点试试吧"],
			8001 => ["普通账号错误(8001)", "普通账号可能被限制，请检查普通账号状态"],
			9013 => ["普通账号错误(9013)", "普通账号被限制，请检查普通账号状态"],
			9019 => ["普通账号错误(9019)", "普通账号 Cookie 状态异常，请检查：账号是否被限制、Cookie 是否过期（前往网站设置页面修改）"],
		];

		if (isset($error[$errno]))
			return [
				"error" => -1,
				"title" => $error[$errno][0],
				"msg" => $error[$errno][1],
				"message" => $message
			];
		else
			return [
				"error" => -1,
				"title" => "获取下载链接失败 ($errno)",
				"msg" => "未知错误！错误：" . json_encode($json4),
				"message" => $message
			];
	}

	private static function realLinkError($body_decode, $message): array
	{
		$ErrorCode = $body_decode["errno"] ?? ($body_decode["error_code"] ?? 999);
		$ErrorMessage = [
			8001 => "SVIP 账号可能被限制，请检查 SVIP 的 Cookie 是否设置正确且有效",
			9013 => "SVIP 账号被限制，请检查更换 SVIP 账号",
			9019 => "SVIP 账号可能被限制，请检查 SVIP 的 Cookie 是否设置正确且有效",
			31360 => "下载链接超时，请刷新页面重试。若重试后仍报错，请检查普通帐号 Cookie 是否过期",
			31362 => "下载链接签名错误，请检查 UA 是否正确",
			999 => "错误 -> " . json_encode($body_decode)
		];

		return [
			"error" => -1,
			"title" => "获取下载链接失败 ($ErrorCode)",
			"msg" => $ErrorMessage[$ErrorCode] ?? "未知错误！错误：" . json_encode($body_decode),
			"message" => $message
		];
	}
}
