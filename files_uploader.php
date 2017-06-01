    <?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

/*
 * Image CMS
 *
 * Forms Module
 *
 */

class Files_uploader extends MY_Controller
{
    private $defaultConfig = [
        'maxFileSize' => 1000000,
        'uploadBasePath' => '/uploads/outerfiles/',
        'allowedFileTypes' => [
            'application/msword',
            'text/plain',
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg',
            'image/png',
        ],
        'image' => [
            'minWidth'  => 320,
            'maxWidth'  => 1920,
            'minHeight' => 240,
            'maxHeight' => 1080,
            'quality'   => 100,
        ]
    ];

    private $imageTypes = [
        'image/jpeg',
        'image/png',
    ];

    private $errorCodes;

    private $config = [];

    private $file;
    private $files;
    private $fullUploadPath;
    private $mediaPath;

    public function __construct()
    {
        parent::__construct();
        $lang = new MY_Lang();
        $lang->load('ajax');
        $this->lang->load('ajax');

        $this->errorCodes = [
            // move_uploaded_file почему-то не отработала
            'moveError' => lang('Техническая ошибка при загрузке файла. Попробуйте позднее. (afu1)', 'ajax'),
            // Неполучилось создать директорию для загрузки файла. Посмотреть $mkdirErrorArray['message']
            'mkdirError' => lang('Техническая ошибка при загрузке файла. Попробуйте позднее. (afu2)', 'ajax'),
            // Возникла ошибка при конвертации изображения (испорченная картинка, хакеры?)
            'imageConvert' => lang('Техническая ошибка при загрузке файла. Попробуйте позднее. (afu3)', 'ajax'),
            // Путь указанный при загрузке - не найден.
            'incorrectFileArrayPath' => lang('Техническая ошибка при загрузке файла. Попробуйте позднее. (afu4)', 'ajax'),


            'allowedFileTypes' => lang('Неподдерживаемый тип файла.', 'ajax'),
            'maxFileSize'      => lang('Превышен максимально допустимый размер файла.', 'ajax'),
            'imageMinWidth'    => lang('Изображение должно быть не менее %0%px в ширину.', 'ajax'),
            'imageMinHeight'   => lang('Изображение должно быть не менее %0%px в высоту.', 'ajax'),
        ];
    }

    public function _upload($filename, $config)
    {
        try {
            $this->_prepareFilesArray();
            $this->_initConfig($config);

            if (    $this->_findFile($filename) &&
                    $this->_fileIsValid() &&
                    $this->_prepareFilePath()
                ) {

                if (move_uploaded_file($this->file['tmp_name'], $this->fullUploadPath)) {
                    if (in_array($this->file['type'], $this->imageTypes)){
                        $this->_checkImage();
                    }

                    return [
                        'status' => 'success',
                        'path' => $this->mediaPath,
                    ];
                } else {
                    $this->_error('moveError');
                }
            }
        } catch (Exception $e) {

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }

        return false;
    }

    private function _fileIsValid()
    {
        if (!in_array($this->file['type'], $this->config['allowedFileTypes'])) {
            $this->_error('allowedFileTypes');
        }

        if ($this->file['size'] > $this->config['maxFileSize']) {
            $this->_error('maxFileSize');
        }

        return true;
    }

    private function _initConfig($config)
    {
        $this->config = $this->defaultConfig;
        if (is_array($config)) {
            foreach ($config as $key => $value) {
                $this->config[$key] = $value;
            }
        }
    }

    private function _prepareFilePath()
    {
        $pathArray = [
            $this->config['uploadBasePath'],
            date('d.m.Y', strtotime('today midnight')),
        ];

        foreach ($pathArray as $value) {
            $path .= trim($value, '/') . '/';
        }

        $documentPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $path;

        if (! file_exists($path)) {
            if (! @mkdir($path, 0777, $recursive = true)){
                // $mkdirErrorArray = error_get_last();

                $this->_error('mkdirError');
            }
        }
        $name = $this->_makeNewFileName();
        $this->fullUploadPath = $documentPath . $name;
        $this->mediaPath = media_url($path . $name);
        return true;
    }

    private function _checkImage()
    {
        $imageinfo = getimagesize($this->fullUploadPath);

        // Проверка на ширину изображения
        if ($this->config['image']['maxWidth'] && $imageinfo[0] > $this->config['image']['maxWidth']) {
            $resize['width'] = $this->config['image']['maxWidth'];
        } elseif ($this->config['image']['minWidth'] && $imageinfo[0] < $this->config['image']['minWidth']){

            $this->_error('imageMinWidth', $this->config['image']['minWidth']);
        }

        // Проверка на высоту изображения
        if ($this->config['image']['maxHeight'] && $imageinfo[1] > $this->config['image']['maxHeight']) {
            $resize['height'] = $this->config['image']['maxHeight'];
        } elseif ($this->config['image']['minHeight'] && $imageinfo[1] < $this->config['image']['minHeight']){

            $this->_error('imageMinHeight', $this->config['image']['minHeight']);
        }

        if ($resize) {
            // Ресайз стандартными функциями - изображение должно быть безопасно
            imageResize($this->fullUploadPath, $resize['width'], $resize['height'], 'scale', true);

            return true;
        } else {
            // Конвертируем изображение вручную
            return $this->_compressImage($imageinfo);
        }
    }

    private function _compressImage($imageinfo)
    {

        if ($imageinfo['mime'] == 'image/jpeg') {

            $image = imagecreatefromjpeg($this->fullUploadPath);
            imagejpeg($image, $this->fullUploadPath, $this->config['image']['quality']);
            imagedestroy($image);

        } elseif ($imageinfo['mime'] == 'image/png') {

            $image = imagecreatefrompng($this->fullUploadPath);

            imageAlphaBlending($image, true);
            imageSaveAlpha($image, true);

            $png_quality = 9 - (($this->config['image']['quality'] * 9 ) / 100 );
            imagePng($image, $this->fullUploadPath, $png_quality);

        }

        if ($image) {
            imagedestroy($image);
            return true;
        } else {
            $this->_error('imageConvert');
        }
    }

    private function _makeNewFileName()
    {
        $nameArray = explode('.', $this->file['name']);
        return md5(uniqid(rand(), 1)) . '.' . $nameArray[count($nameArray) - 1];
    }

    private function _prepareFilesArray()
    {
        $this->files = [];
        foreach($_FILES as $name => $dataArray) {
            foreach ($dataArray as $key => $data) {
                $this->_expandFileDataArray($data, $key, $this->files[$name]);
            }
        }
    }

    private function _expandFileDataArray($data, $name, &$result)
    {
        if (is_array($data)) {
            foreach ($data as $key => $item) {
                $this->_expandFileDataArray($item, $name, $result[$key]);
            }
        } else {
            $result[$name] = $data;
        }
    }

    private function _findFile($filename)
    {
        if (! is_array($filename)) {
            $filename = [$filename];
        }

        $tempFilePath = $this->files;

        foreach ($filename as $slug) {
            if (! array_key_exists($slug, $tempFilePath)) {
                $this->_error('incorrectFilePath');
            }

            $tempFilePath = $tempFilePath[$slug];
        }

        if (file_exists($tempFilePath['tmp_name'])) {
            $this->file = $tempFilePath;
            return true;
        }

        return false; // Файла нема - поьзователь его не отправил
    }

    private function _error($errorCode, $errorData = null)
    {
        $errorMessage = $this->errorCodes[$errorCode];

        if ($errorData){
            if (! is_array($errorData)) {
                $errorData = [$errorData];
            }

            foreach ($errorData as $key => $item) {
                $errorMessage = str_replace("%$key%", $item, $errorMessage);
            }
        }

        throw new Exception($errorMessage, 1);
    }
}