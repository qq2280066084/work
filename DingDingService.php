<?php

declare(strict_types=1);

namespace app\admin\service;

use Actioncard;
use DingTalkClient;
use DingTalkConstant;
use OapiChatCreateRequest;
use OapiGettokenRequest;
use think\facade\Cache;
use OapiUserGetbyunionidRequest;
use OapiSnsGetuserinfoBycodeRequest;
use app\common\traits\JumpTrait;
use BtnJson;
use File;
use Image;
use Link;
use Markdown;
use Msg;
use OapiChatSendRequest;
use OapiChatUpdateRequest;
use Text;
use OapiMessageSendToConversationRequest;
use Voice;
use OA;
use Head;
use Body;
use Rich;
use Form;
class DingDingService  extends \think\Service
{

    use JumpTrait;

    /** @var DingTalkClient $service */
    protected $service;
    /** @var string 应用id */
    protected $appId;
    /** @var string 应用密钥 */
    protected $appSecret;
    /** @var string 移动第三方登录appid */
    protected $login_appId;
    /** @var string 移动第三方登录应用密钥 */
    protected $login_appSecret;
    /** @var string 令牌 */
    protected $AccessToken;
    /** @var string 企业id */
    protected $corpId;
    /** @var string 应用id */
    protected $agentid;

    public function __construct()
    {
        /**
        * 初始化配置
        */
        $this->service = new DingTalkClient(DingTalkConstant::$CALL_TYPE_OAPI, DingTalkConstant::$METHOD_POST, DingTalkConstant::$FORMAT_JSON);
        $this->appId = env('dingding.appkey');
        $this->appSecret = env('dingding.appsecret');
        $this->login_appId = env('dingding.login_appid');
        $this->login_appSecret = env('dingding.login_appsecret');
        $this->corpId = env('dingding.corpid');
        $this->agentid = env('dingding.agentid');
        $this->AccessToken = $this->getToken();
        if (empty($this->appId) || empty($this->appSecret) || empty($this->login_appId) || empty($this->login_appSecret) || empty($this->corpId) ||empty($this->corpId)) {
            $this->error('配置错误');
        }
    }

    /**
     * 获取用户基础信息
     * @param string $code 临时授权码
     * @return string 返回用户唯一码
     */
    public function getUserInfoByCode(string $code)
    {
        $req = new OapiSnsGetuserinfoBycodeRequest();
        $req->setTmpAuthCode($code);
        $resp = $this->service->executeWithAccessKey($req,'https://oapi.dingtalk.com/sns/getuserinfo_bycode',$this->login_appId,$this->login_appSecret);
        $this->checkRequest($resp);
        return $resp->user_info->unionid;
    }

    /**
     * 根据unionid获取用户id
     *
     * @param string $unionid
     * @return string 用户id
     * @throws \think\Exception
     */
    public function getByUnionid($unionid)
    {
        $req = new OapiUserGetbyunionidRequest;
        $req->setUnionid($unionid);
        $resp = $this->service->execute($req, $this->AccessToken, "https://oapi.dingtalk.com/topapi/user/getbyunionid");
        $this->checkRequest($resp);
        if ($resp->result->contact_type != 0) {
            $this->error('当前登录人不是内部员工');
        }
        return $resp->result->userid;
    }

    /**
     * 创建钉钉群
     *
     * @param string $name 群名
     * @param string $user_id 群主钉钉id
     * @param string $user_list 群员钉钉id
     * @return object
     */
    public function createChat($name,$user_id,$user_list)
    {
        $req = new OapiChatCreateRequest;
        $req->setName($name);
        $req->setOwner($user_id);
        $req->setUseridlist($user_list);
        $resp = $this->service->execute($req,$this->AccessToken,'https://oapi.dingtalk.com/chat/create');
        $this->checkRequest($resp);
        return $resp;
    }

    /**
     * 修改钉钉群
     *
     * @param string $chat_id 群id
     * @param string $name 群名
     * @return bool
     */
    public function updateChat($chat_id,$name)
    {
        $req = new OapiChatUpdateRequest;
        $req->setName($name);
        $req->setChatid($chat_id);
        $resp = $this->service->execute($req,$this->AccessToken,'https://oapi.dingtalk.com/chat/update');
        $this->checkRequest($resp);
        return true;
    }

    /**
     * 发送消息
     *
     * @param string $chat_id 群id
     * @param string $msg 消息体
     * @return bool
     */
    public function chatSendTextMsg($chat_id,$msg)
    {
        $req = new OapiChatSendRequest; // 请求体类
        $req->setChatid($chat_id); // 发送群id
        $text = new Text; // 普通的文字消息体类
        $text->content=$msg; // 消息内容
        $req->setText($text); // 封装进请求体
        $req->setMsgtype('text'); // 设置消息类型
        // 发送请求
        $resp = $this->service->execute($req, $this->AccessToken, "https://oapi.dingtalk.com/chat/send");
        $this->checkRequest($resp);
        
        return true;
    }


    
    /**
     * 单发信息
     * @param string $userid 发送者userid
     * @param string $cid 个人会话id
     * @param object $msg 消息体
     * @return bool
     */
    public function chatSingleSendMsg($userid, $cid, $msg) {
        $req = new OapiMessageSendToConversationRequest();
        $req->setSender($userid); //发送者id
        $req->setCid($cid);       //群会话或者个人会话id
        //获取消息类型
        $msg_type = $msg['msgtype'] ?? "text";
        if(!isset($msg['msgtype']) || !isset($msg[$msg_type])) {
            //参数不合法
            return false;
        }
        switch ($msg['msgtype'])
            {
            case "text":
                $text = new Text(); 
                isset($msg[$msg_type]['content']) &&  $text->content = $msg[$msg_type]['content'];   //消息内容
             
                $req->setText($text);
                
                break; 
            case "image":
                //图片消息
                $image = new Image(); 
                isset($msg[$msg_type]['media_id']) && $image->media_id = $msg[$msg_type]['media_id']; //媒体文件mediaid
                $req->setImage($image);
                break;
            case "voice":
                //语音消息
                $voice = new Voice(); 
                isset($msg[$msg_type]['duration']) && $voice->duration = $msg[$msg_type]['duration'];   //音频时长
                isset($msg[$msg_type]['media_id']) && $voice->media_id = $msg[$msg_type]['media_id'];  //媒体文件mediaid
                $req->setVoice($voice);
                break;
            case "file":
                //文件消息
                $file = new File(); 
                isset($msg[$msg_type]['media_id']) && $file->media_id = $msg[$msg_type]['media_id'];  //媒体文件mediaid
                $req->setFile($file);
                break;
            case "link":
                 //链接消息
                $link = new Link();
                isset($msg[$msg_type]['messageUrl']) && $link->message_url = $msg[$msg_type]['messageUrl']; //消息点击链接地址
                isset($msg[$msg_type]['title']) && $link->title = $msg[$msg_type]['title'];   //消息标题
                isset($msg[$msg_type]['picUrl']) && $link->pic_url = $msg[$msg_type]['picUrl']; //图片地址
                isset($msg[$msg_type]['text']) && $link->text = $msg[$msg_type]['text'];     //消息描述
                $req->setLink($link);
                break;
            case "markdown":
                //markdown
                $markdown = new Markdown();
                isset($msg[$msg_type]['title']) && $markdown->title = $msg[$msg_type]['title'];  //首屏会话透出的展示内容
                isset($msg[$msg_type]['text']) && $markdown->text = $msg[$msg_type]['text']; //markdown格式的消息
                $req->setMarkdown($markdown);
                break;
            case "action_card":
                //卡片消息
                //卡片类型  1 整体跳转  2 独立跳转
                $card_type = isset($msg[$msg_type]['single_title']) && isset($msg[$msg_type]['single_url']) ? 1 : 2;
                $action_card = new Actioncard();
                isset($msg[$msg_type]['title']) && $action_card->title = $msg[$msg_type]['title'];  //透出到会话列表和通知的文案
                isset($msg[$msg_type]['markdown']) && $action_card->markdown = $msg[$msg_type]['markdown']; //消息内容
                if($card_type == 1) {
                    isset($msg[$msg_type]['single_url']) && $action_card->single_url= $msg[$msg_type]['single_url'];  //消息点击链接地址
                    isset($msg[$msg_type]['single_title']) && $action_card->single_title= $msg[$msg_type]['single_title']; //使用整体跳转ActionCard样式时的标题
                } else {
                    isset($msg[$msg_type]['btn_orientation']) && $action_card->btn_orientation = $msg[$msg_type]['btn_orientation']; //使用独立跳转ActionCard样式时的按钮排列方式  0：竖直排列  1：横向排列
                  
                    $btn_json_list = [];
                    foreach ($msg[$msg_type]['btn_json_list'] as $key => $value) {
                        $btn_json_list_item = new BtnJson();
                        $btn_json_list_item->title = $value['title'];
                        $btn_json_list_item->action_url = $value['action_url'];
                        $btn_json_list[] = $btn_json_list_item;
                    }
                    $action_card->btn_json_list = $btn_json_list;
                    
                }
                $req->setActionCard($action_card);
                break;
            case "oa":
                //oa消息类型
                $oa = new OA;
                isset($msg[$msg_type]['message_url']) && $oa->message_url = $msg[$msg_type]['message_url']; //消息点击链接地址
                isset($msg[$msg_type]['pc_message_url']) && $oa->message_url = $msg[$msg_type]['pc_message_url']; //PC端点击消息时跳转到的地址
                //消息头部内容
                $head = new Head;
                $head->bgcolor= $msg[$msg_type]['head']['bgcolor'] ?? "";  //消息头部的背景颜色
                $head->text= $msg[$msg_type]['head']['text'] ?? "";  //消息的头部标题
                $oa->head = $head;

                $body = new Body();
                $body->title= $msg[$msg_type]['body']['title'] ?? "";    //消息体的标题
                $body->content = $msg[$msg_type]['body']['content'] ?? ""; //消息体的内容，最多显示3行
                $body->image = $msg[$msg_type]['body']['image'] ?? "";    //消息体中的图片，支持图片资源@mediaId
                $body->file_count = $msg[$msg_type]['body']['file_count'] ?? "";  //自定义的附件数目    
                $body->author = $msg[$msg_type]['body']['author'] ?? "";  //自定义的作者名字
                //单行富文本信息
                $rich = new Rich;
                $rich->num= $msg[$msg_type]['body']['rich']['num'] ?? "";  //单行富文本信息的数目
                $rich->unit= $msg[$msg_type]['body']['rich']['unit'] ?? "";  //单行富文本信息的单位
                $body->rich = $rich;

                $forms = [];   //消息体的表单，最多显示6个，超过会被隐藏。
                if(isset($msg[$msg_type]['body']['form']) && is_array($msg[$msg_type]['body']['form'])) {
                    foreach ($msg[$msg_type]['body']['form'] as $key => $value) {
                        $form = new Form;
                        $form->value = $value['value'];   //消息体的关键字
                        $form->key = $value['key'];   //消息体的关键字对应的值
                        $forms[] = $form;
                    }
                }
                $body->form = $forms;

                $oa->body = $body;
                $req->setOa($oa);
                break;
            
            }
            
        $req->setMsgtype($msg_type); //设置消息类型
        $resp = $this->service->execute($req, $this->access_token, "https://oapi.dingtalk.com/message/send_to_conversation");
        $this->checkRequest($resp);
        return true;
        
        
    }

    /**
     * 单发信息--消息内容数据格式验证
     * @param object $msg 消息内容
     * @return  bool
     */
    public function msgFormatVerify($msg) {
        //消息类型
        $msg_type = $msg['msgtype'] ?? "text" ;
        $paramInfo= [
            "text" => [
                "content" => ["required" => 1] //消息内容
            ],
            "image" => [
                "media_id" => ['required' => 1] //媒体文件mediaid
            ],
            "voice" => [
                "media_id" => ["required" => 1],//媒体文件mediaid
                "duration" => ["required" => 1] //音频时长，小于60
            ],
            "file" => [
                "media_id" => ["required" => 1] //媒体文件mediaid
            ],
            "link" => [
                "messageUrl" => ["required" => 1], //消息点击链接地址
                "picUrl" => ['required' => 1], //图片地址
                "title" => ["required" => 1],  //消息标题
                "text" => ["required" => 1]    //消息描述
            ],
            "markdown" => [
                "title" => ['required' => 1], //首屏会话透出的展示内容
                "text" => ["required" => 1]   //markdown格式的消息
            ],
            "action_card" => [
                "markdown" => ['required' => 1], //消息内容
                "title" => ['required' => 0], //透出到会话列表和通知的文案
                "single_title" => ['required' => 0],
                "single_url" => ['required' => 0],
                "btn_orientation" => ['required' => 0],
                "btn_json_list" => ['required' => 0]
            ]

        ];

    }

    /**
     * 获取token
     *
     * @return string
     */
    protected function getToken()
    {
        $token = Cache::get('dingding_token');
        if (empty($token)) {
            $req = new OapiGettokenRequest;
            $req->setAppkey($this->appId);  
            $req->setAppsecret($this->appSecret);
            // 这里需要换get请求方式
            $get_service = new DingTalkClient(DingTalkConstant::$CALL_TYPE_OAPI, DingTalkConstant::$METHOD_GET, DingTalkConstant::$FORMAT_JSON);
            $resp = $get_service->execute($req,'', "https://oapi.dingtalk.com/gettoken");
            $this->checkRequest($resp);
            Cache::set('dingding_token', $resp->access_token, 7100);
            return $this->getToken();
        } else {
            return $token;
        }
    }

    
    /**
     * 检查返回结果并处理(不想去改动sdk了)
     *
     * @param object $resp
     * @return void
     * @throws \think\Exception
     */
    protected function checkRequest($resp)
    {
        if ($resp->errcode != 0) {
            $this->error($resp->errmsg);
        }
    }
}
