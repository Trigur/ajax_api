<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

/*
 * Image CMS
 *
 * Forms Module
 *
 */
class Ajax_api extends MY_Controller {

    private $config;
    private $spamConfig;

    public function __construct()
    {
        parent::__construct();
        $lang = new MY_Lang();
        $lang->load('ajax');
        $this->lang->load('ajax_api');

        $this->load->model('ajax_m');
        $this->load->library('Form_validation');

        $this->defaultConfig = [
            'mark'                  => false,
            'sendEmail'             => true,
            'emailTemplate'         => false,
            'fieldsArrays'          => false,

            'errorType'             => 'fields',
            'errorHtmlWrapClass'    => false,
            'defaultSuccessMessage' => lang('Ваша заявка успешно отправлена!', 'ajax_api'),
            'defaultErrorMessage'   => lang('Техническая ошибка! Попробуйте позднее.', 'ajax_api')
        ];

        $this->spamConfig = [
            'max'     => 10, // максимальное количество отправляемых сообщений.
            'time'    => 3600, // за промежуток времени (в часах)
            'message' => lang('Превышен лимит обращений! Попробуйте позднее.', 'ajax_api'),
        ];
    }

    private function _spamCheck()
    {
        if ($this->dx_auth->is_admin()){
            return;
        }

        if ($ajaxCalls = $this->session->userdata('ajaxCalls')){
            if ($ajaxCalls['time'] < time() - $this->spamConfig['time']) {
                $ajaxCalls = [
                    'time' => time(),
                    'num'  => 1,
                ];
            } else {
                if ($ajaxCalls['num'] <= $this->spamConfig['max']) {
                    $ajaxCalls = [
                        'time' => time(),
                        'num'  => $ajaxCalls['num']++,
                    ];
                } else {
                    $this->_error($this->spamConfig['message'], 'html');
                }
            }
        }else{
            $ajaxCalls = [
                'time' => time(),
                'num'  => 1,
            ];
        }

        $this->session->set_userdata([
            'ajaxCalls' => $lastCalls
        ]);
    }

    /*
        Возвращаем сообщение об успехе
    */
    private function _success($message = null)
    {
        if (empty($message)) {
            $message = $this->defaultConfig['defaultSuccessMessage'];
        }

        return json_encode([
            'type' => 'success',
            'message' => $message,
        ]);
    }

    /*
        Возвращаем ошибку
    */
    private function _error($errorData = null, $responseType = 'text')
    {
        $response = ['type' => 'error'];

        switch ($responseType) {
            case 'fields':
                $response['fields'] = $errorData;
                break;

            case 'text':
            case 'html':
            default:
                if (empty($errorData)) {
                    $errorData = $this->defaultConfig['defaultErrorMessage'];
                }

                $response['message'] = $errorData;
                break;
        }

        return json_encode($response);
    }

    private function _ajax_controller( $config, $fields )
    {
        if (empty($_POST) || empty($fields)) {
            $this->_error();
        }

        $config = $this->_initConfig($config);

        if (! $config) {
            return $this->_error();
        }

        $this->_spamCheck();

        $val = $this->form_validation;
        $val->set_rules($fields);

        if (! $val->run($this)) {
            $errorsArray = $val->getErrorsArray();

            switch ($config['errorType']) {
                case 'html':
                    if ($config['errorHtmlWrapClass']) {
                        $p = '<p class="' . $config['errorHtmlWrapClass'] . '">';
                    } else {
                        $p = '<p>';
                    }

                    $errorData = validation_errors($p, '</p>');
                    break;

                case 'fields':
                    $errorData = $errorsArray;
                    break;

                case 'text':
                default:
                    $errorData = implode(' ', $errorsArray);
                    break;
            }

            return $this->_error($errorData, $config['errorType']);
        }


        if ($config['fieldsArrays']) {

            $fieldsArray = [];

            foreach ($config['fieldsArrays'] as $arrayName) {
                $fieldsArray = array_merge($fieldsArray, $this->input->post($arrayName));
            }

            $data = $fields_array;

        } else {

            $data = [];

            foreach ($fields as $value) {
                $data[$value['field']] = $this->input->post($value['field']);
            }
        }

        $data['site'] = site_url();

        if ($config['beforeSend']) {
            if (is_callable($config['beforeSend'])) {
                $config['beforeSend']($data);
            } elseif (is_string($config['beforeSend'])) {
                $methodArray = explode(':', $config['beforeSend']);
                if (count($methodArray) > 1) {
                    $className  = $methodArray[0];
                    $methodName = $methodArray[1];
                } else {
                    $className  = static::class;
                    $methodName = $methodArray[0];
                }

                if (method_exists($className, $methodName)) {
                    $parametr = array(&$data);

                    call_user_func_array([$className, $methodName], $parametr);
                }
            }
        }
        
        $this->ajax_m->log($config['mark'], serialize($data));

        if (! $config['sendEmail']) {
            return $this->_success($config['successMessage']);
        }

        $email = isset($data['email']) ? $data['email'] : null;
        $emailSendResult = \cmsemail\email::getInstance()->sendEmail($email, $config['emailTemplate'], $data, true);

        if ($emailSendResult === true) {
            return $this->_success($config['successMessage']);
        }else{
            return $this->_error($config['errorMessage']);
        }
    }

    private function _initConfig($nConfig)
    {
        $config = $this->defaultConfig;

        foreach ( $nConfig as $key => $value ) {
            $config[$key] = $value;
        }

        if ( $config['adminEmail'] === false ) {
            $config['adminEmail'] = siteinfo('adminemail');
        }

        if ( ! $config['emailTemplate'] ) {
            $config['emailTemplate'] = $config['mark'];
        }

        if ($config['sendEmail']) {
            if (! $config['emailTemplate']) {
                return false;
            }

            $templateName = $this->ajax_m->getPattern($config['emailTemplate']);

            if (! $templateName) {
                return false;
            }
        }

        return $config;
    }

    /******************************************************/

    public function reception()
    {
        if (! $this->input->is_ajax_request()) {
            $this->core->error_404();
        }

        $fields = [
            [
                'field' => 'reception[name]',
                'label' => lang('Имя', 'ajax'),
                'rules' => 'strip_tags|trim|required|xss_clean|max_length[50]',
            ],
            [
                'field' => 'reception[address]',
                'label' => lang('Адрес', 'ajax'),
                'rules' => 'strip_tags|trim|max_length[255]',
            ],
            [
                'field' => 'reception[email]',
                'label' => lang('Электронная почта', 'ajax'),
                'rules' => 'strip_tags|trim|required|xss_clean|min_length[6]|max_length[50]||valid_email',
            ],
            [
                'field' => 'reception[text]',
                'label' => lang('Текст сообщения', 'ajax'),
                'rules' => 'strip_tags|trim|required|max_length[500]',
            ],
        ];

        $config = [
            'mark' => 'review',
            'errorType' => 'fields',
            'fieldsArrays' => ['review'],
        ];

        return $this->_ajax_controller($config, $fields);
    }

    /******************************************************/

    public function _install() {
        if ($this->dx_auth->is_admin() == FALSE) {
            $this->core->error_404();
        }

        $this->load->model('ajax_m')->install();
    }

    public function _deinstall() {
        if ($this->dx_auth->is_admin() == FALSE) {
            $this->core->error_404();
        }

        $this->load->model('ajax_m')->deinstall();
    }
}
