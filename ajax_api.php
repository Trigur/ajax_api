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
        $this->ajax_m->log($config['mark'], serialize($data));

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

    public function _checkCaptcha($captchaValue)
    {
        $secret = '6Lf2jSIUAAAAAOxoH6gRWPR9VP-ti9ZcZ8VdZ22X';

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://www.google.com/recaptcha/api/siteverify');
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $postData = [
            'secret'   => $secret,
            'response' => $captchaValue,
        ];

        $postDataBuilded = http_build_query($postData);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $postDataBuilded);

        $requestResult = curl_exec($curl);
        curl_close($curl);

        $requestResult = json_decode($requestResult, true);

        return ($requestResult['success'] == true);
    }

    public function _checkCommentPageId($str)
    {
        $commentPageId = 1236;
        $this->form_validation->set_message('_checkCommentPageId', lang('Техническая ошибка. Пожалуйста обновить страницу.', 'ajax_api'));

        return ($commentPageId === (int)$str) ? true : false;
    }

    public function comment()
    {
        if (! $this->input->is_ajax_request()) {
            $this->core->error_404();
        }

        $fields = [
            [
                'field' => 'comment_item_id',
                'label' => lang('', 'ajax'),
                'rules' => 'callback__checkCommentPageId',
            ],
            [
                'field' => 'comment_author',
                'label' => lang('Имя', 'ajax'),
                'rules' => 'strip_tags|trim|required|xss_clean|min_length[2]|max_length[50]',
            ],
            [
                'field' => 'comment_email',
                'label' => lang('Электронная почта', 'ajax'),
                'rules' => 'strip_tags|trim|required|xss_clean|min_length[6]|max_length[50]||valid_email',
            ],
            [
                'field' => 'comment_text',
                'label' => lang('Текст сообщения', 'ajax'),
                'rules' => 'strip_tags|trim|required|max_length[500]',
            ],
            [
                'field' => 'g-recaptcha-response',
                'label' => lang('Капча', 'ajax'),
                'rules' => 'callback__checkCaptcha',
            ]
        ];


        $message = lang('Ваше сообщение будет опубликовано после модерации администратором.', 'ajax_api');
        $message .= '<script>setTimeout(function(){document.location.href=document.location.pathname},2000)</script>';

        $config = [
            'sendEmail'      => false,
            'errorType'      => 'fields',
            'successMessage' => $message,
            'beforeSend'     => function($data){
                $this->load->module('comments')->addPost();
            }
        ];

        return $this->_ajax_controller($config, $fields);
    }

    public function commentsList()
    {
        $commentPageId = 1236;
        $page = $this->input->get('p');
        $page = $page - 1;
        $page = max(0, $page);
        return $this->load->module('comments')->show($commentPageId, $page);
    }


    private function _uploadReceptionImage(&$data)
    {
        $fileUploadResult = $this->load->module('ajax_api/files_uploader')->_upload(
            ['reception', 'image'],
            [
                'allowedFileTypes' => [
                    'image/jpeg',
                    'image/png',
                ],
                'image' => [
                    'minWidth'  => 260,
                    'maxWidth'  => 1920,
                    'minHeight' => 260,
                    'maxHeight' => 1920,
                    'quality'   => 100,
                ],
            ]
        );
        if ($fileUploadResult) {
            if ($fileUploadResult['status'] == 'error') {
                exit($this->_error(['reception[image]' => $fileUploadResult['message']], 'fields'));
            } else {
                $data['image'] = $fileUploadResult['path'];
            }
        } else {
            $data['image'] = 'нет';
        }
    }

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
            [
                'field' => 'g-recaptcha-response',
                'label' => lang('Капча', 'ajax'),
                'rules' => 'callback__checkCaptcha',
            ]
        ];

        $config = [
            'mark' => 'review',
            'errorType' => 'fields',
            'fieldsArrays' => ['review'],
            'beforeSend' => '_uploadReceptionImage',
        ];

        return $this->_ajax_controller($config, $fields);
    }

    public function moreNews($categoryId)
    {
        return $this->load->module('project/news')->_more($categoryId);
    }

    public function moreEvents()
    {
        return $this->load->module('project/events')->_more();
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