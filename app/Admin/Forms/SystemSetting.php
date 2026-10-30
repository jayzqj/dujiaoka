<?php

namespace App\Admin\Forms;

use App\Models\BaseModel;
use Dcat\Admin\Widgets\Form;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Form
{
    /**
     * Handle the form request.
     *
     * @param array $input
     *
     * @return mixed
     */
    public function handle(array $input)
    {
        $existing = Cache::get('system-setting') ?: [];
        if (array_key_exists('procurement_failure_wecom_url', $input)) {
            $input['procurement_failure_wecom_url'] = is_string($input['procurement_failure_wecom_url'])
                ? trim($input['procurement_failure_wecom_url']) : '';
            if ($input['procurement_failure_wecom_url'] !== ''
                && !\App\Jobs\ProcurementFailedPush::validWebhook($input['procurement_failure_wecom_url'])) {
                return $this->response()->error('请填写企业微信群机器人完整 HTTPS Hook URL，或留空关闭失败通知');
            }
        } elseif (isset($existing['procurement_failure_wecom_url'])) {
            $input['procurement_failure_wecom_url'] = $existing['procurement_failure_wecom_url'];
        }
        if (!empty($input['ifaka_app_id']) && !preg_match('/^[A-Za-z0-9]{2,4}$/D', $input['ifaka_request_prefix'] ?? '')) {
            return $this->response()->error('请配置平台分配的 2–4 位字母数字请求号前缀');
        }
        if (!empty($input['ifaka_app_key'])) {
            $input['ifaka_app_key_encrypted'] = encrypt($input['ifaka_app_key']);
        } elseif (!empty($existing['ifaka_app_key_encrypted'])) {
            $input['ifaka_app_key_encrypted'] = $existing['ifaka_app_key_encrypted'];
        }
        unset($input['ifaka_app_key']);
        Cache::put('system-setting', $input);
        return $this
				->response()
				->success(admin_trans('system-setting.rule_messages.save_system_setting_success'));
    }

    /**
     * Build a form here.
     */
    public function form()
    {
        $this->tab('第三方自动购卡', function () {
            $this->text('ifaka_app_id', 'ifaka App ID');
            $this->password('ifaka_app_key', 'ifaka App Key')->help('留空保留原密钥；保存时加密');
            $this->text('ifaka_request_prefix', '请求号专属前缀')->help('向平台确认独占前缀，2–4 位字母数字；启用后不要更换或复用其他站点前缀');
            $this->text('customer_service_wechat', '客服微信')->help('在出卡失败提示旁展示，客户主动联系');
            $this->url('procurement_failure_wecom_url', '采购与出卡企业微信通知 Hook URL')
                ->help('填写企业微信群机器人的完整 Webhook 地址；采购成功或支付后的采购/出卡异常时通知，并附 ifaka 账户余额。留空不发送，与普通订单推送独立。');
        });
        $this->tab(admin_trans('system-setting.labels.base_setting'), function () {
            $this->text('title', admin_trans('system-setting.fields.title'))->required();
            $this->image('img_logo', admin_trans('system-setting.fields.img_logo'));
            $this->text('text_logo', admin_trans('system-setting.fields.text_logo'));
            $this->text('keywords', admin_trans('system-setting.fields.keywords'));
            $this->textarea('description', admin_trans('system-setting.fields.description'));
            $this->select('template', admin_trans('system-setting.fields.template'))
                ->options(config('dujiaoka.templates'))
                ->required();
            $this->select('language', admin_trans('system-setting.fields.language'))
                ->options(config('dujiaoka.language'))
                ->required();
            $this->text('manage_email', admin_trans('system-setting.fields.manage_email'));
            $this->number('order_expire_time', admin_trans('system-setting.fields.order_expire_time'))
                ->default(5)
                ->required();
            $this->switch('is_open_anti_red', admin_trans('system-setting.fields.is_open_anti_red'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->switch('is_open_img_code', admin_trans('system-setting.fields.is_open_img_code'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->switch('is_open_search_pwd', admin_trans('system-setting.fields.is_open_search_pwd'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->switch('is_open_google_translate', admin_trans('system-setting.fields.is_open_google_translate'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->editor('notice', admin_trans('system-setting.fields.notice'));
            $this->textarea('footer', admin_trans('system-setting.fields.footer'));
        });
        $this->tab(admin_trans('system-setting.labels.order_push_setting'), function () {
            $this->switch('is_open_server_jiang', admin_trans('system-setting.fields.is_open_server_jiang'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->text('server_jiang_token', admin_trans('system-setting.fields.server_jiang_token'));
            $this->switch('is_open_telegram_push', admin_trans('system-setting.fields.is_open_telegram_push'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->text('telegram_bot_token', admin_trans('system-setting.fields.telegram_bot_token'));
            $this->text('telegram_userid', admin_trans('system-setting.fields.telegram_userid'));
            $this->switch('is_open_bark_push', admin_trans('system-setting.fields.is_open_bark_push'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->switch('is_open_bark_push_url', admin_trans('system-setting.fields.is_open_bark_push_url'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->text('bark_server', admin_trans('system-setting.fields.bark_server'));
            $this->text('bark_token', admin_trans('system-setting.fields.bark_token'));
            $this->switch('is_open_qywxbot_push', admin_trans('system-setting.fields.is_open_qywxbot_push'))
                ->default(BaseModel::STATUS_CLOSE);
            $this->text('qywxbot_key', admin_trans('system-setting.fields.qywxbot_key'));
        });
        $this->tab(admin_trans('system-setting.labels.mail_setting'), function () {
            $this->text('driver', admin_trans('system-setting.fields.driver'))->default('smtp')->required();
            $this->text('host', admin_trans('system-setting.fields.host'));
            $this->text('port', admin_trans('system-setting.fields.port'))->default(587);
            $this->text('username', admin_trans('system-setting.fields.username'));
            $this->text('password', admin_trans('system-setting.fields.password'));
            $this->text('encryption', admin_trans('system-setting.fields.encryption'));
            $this->text('from_address', admin_trans('system-setting.fields.from_address'));
            $this->text('from_name', admin_trans('system-setting.fields.from_name'));
        });
        $this->tab(admin_trans('system-setting.labels.geetest'), function () {
            $this->text('geetest_id', admin_trans('system-setting.fields.geetest_id'));
            $this->text('geetest_key', admin_trans('system-setting.fields.geetest_key'));
            $this->switch('is_open_geetest', admin_trans('system-setting.fields.is_open_geetest'))->default(BaseModel::STATUS_CLOSE);
        });
        $this->confirm(
            admin_trans('dujiaoka.warning_title'),
            admin_trans('system-setting.rule_messages.change_reboot_php_worker')
        );
    }

    public function default()
    {
        $settings = Cache::get('system-setting') ?: [];
        unset($settings['ifaka_app_key_encrypted']);
        $settings['ifaka_app_key'] = '';
        return $settings;
    }

}
