<?php
/**
 * Typecho S3 Upload Plugin
 * 
 * @package AxS3Upload
 * @version 1.0.0
 * @link https://blog.yemaster.cn/
 * @date 2024-09-18
 */

// 确保类文件被正确加载
require_once __DIR__ . '/aws.phar';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class AxS3Upload_Plugin implements Typecho_Plugin_Interface {
    
    private static $connection;

    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array('AxS3Upload_Plugin', 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array('AxS3Upload_Plugin', 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array('AxS3Upload_Plugin', 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array('AxS3Upload_Plugin', 'attachmentHandle');
        return _t('S3 Upload 插件已激活，请配置 S3 服务器信息。');
    }

    public static function deactivate()
    {
        return _t('S3 Upload 插件已禁用。');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $endpoint = new Typecho_Widget_Helper_Form_Element_Text('endpoint', null, null, _t('API 端点：'));
        $form->addInput($endpoint->addRule('required', _t('“API 端点”不能为空！')));
        
        $region = new Typecho_Widget_Helper_Form_Element_Text('region', null, null, _t('地域：'));
        $form->addInput($region->addRule('required', _t('“地域”不能为空！')));
        
        $bucket = new Typecho_Widget_Helper_Form_Element_Text('bucket', null, null, _t('存储桶：'));
        $form->addInput($bucket->addRule('required', _t('“存储桶”不能为空！')));
        
        $bucketDomain = new Typecho_Widget_Helper_Form_Element_Text('bucketDomain', null, null, _t('Bucket 域名：'), _t('如果设置了 Bucket 域名，则返回的 URL 将使用该域名，格式为：bucketDomain/xxx'));
        $form->addInput($bucketDomain);
        
        $accesskey = new Typecho_Widget_Helper_Form_Element_Text('accesskey', null, null, _t('AccessKey：'));
        $form->addInput($accesskey->addRule('required', _t('AccessKey 不能为空！')));
        
        $sercetkey = new Typecho_Widget_Helper_Form_Element_Text('sercetkey', null, null, _t('SecretKey：'));
        $form->addInput($sercetkey->addRule('required', _t('SecretKey 不能为空！')));

        $savepath = new Typecho_Widget_Helper_Form_Element_Text('savepath', null, '{year}/{month}/', _t('保存路径格式：'), _t('附件保存路径格式，默认为 {year}/{month}/ 格式。'));
        $form->addInput($savepath->addRule('required', _t('请填写保存路径格式！')));
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function getConfig()
    {
        return Typecho_Widget::widget('Widget_Options')->plugin('AxS3Upload');
    }

    // 初始化 S3 客户端连接
    public static function initConnection()
    {
        if (!self::$connection) {
            $option = self::getConfig();
            self::$connection = new S3Client([
                'version' => 'latest',
                'region'  => $option->region,
                'endpoint' => $option->endpoint,
                'credentials' => [
                    'key'    => $option->accesskey,
                    'secret' => $option->sercetkey,
                ],
                'use_path_style_endpoint' => true,
                'use_aws_shared_config_files' => false,
            ]);
        }
        return self::$connection;
    }

    public static function formatSavePath($pattern)
    {
        $year = date('Y');
        $month = date('m');
        
        // 替换 {year} 和 {month} 占位符
        $path = str_replace(['{year}', '{month}'], [$year, $month], $pattern);
        
        return $path;
    }

    // 上传文件处理
    public static function uploadHandle($file)
    {
        $option = self::getConfig();
        $s3 = self::initConnection();
        
        $pathPattern = $option->savepath;    
        $path = self::formatSavePath($pathPattern);
        
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        $hash = md5($file['name']); // 使用 md5 哈希算法
        $newFileName = $hash . '.' . $fileExtension; // 只保留哈希后的文件名
        $fullPath = $path . $newFileName;

        try {
            $result = $s3->putObject([
                'Bucket' => $option->bucket,
                'Key'    => $fullPath,
                'Body'   => fopen($file['tmp_name'], 'rb'),
                'ACL'    => 'public-read',
            ]);
            
            $bucketDomain = $option->bucketDomain;
            $url = $bucketDomain ? rtrim($option->bucketDomain, '/') . '/' . ltrim($fullPath, '/') : $result['ObjectURL'];
            error_log('Bucket Domain: ' . $bucketDomain);
            error_log('Full Path: ' . $fullPath);
            error_log('Url: ' . $url);
            
            return [
                'name' => $file['name'],
                'path' => $fullPath,
                'size' => $file['size'],
                'type' => $file['type'],
                'url'  => $url,  // 返回文件的 URL
                'mime'  =>  Typecho_Common::mimeContentType($fullPath)
            ];
        } catch (AwsException $e) {
            throw new Typecho_Widget_Exception(_t('文件上传失败：%s', $e->getMessage()));
            return false;
        }
    }

    // 修改文件处理（可以和上传类似）
    public static function modifyHandle($content, $file)
    {
        return self::uploadHandle($file);
    }

    // 删除文件处理
    public static function deleteHandle(array $content)
    {
        $option = self::getConfig();
        $s3 = self::initConnection();
        
        try {
            $s3->deleteObject([
                'Bucket' => $option->bucket,
                'Key'    => $content['attachment']->path,
            ]);
            return true;
        } catch (AwsException $e) {
            throw new Typecho_Widget_Exception(_t('文件删除失败：%s', $e->getMessage()));
            return false;
        }
    }

    // 获取附件信息（S3上）
    public static function attachmentHandle(array $content)
    {
        $option = self::getConfig();
    
        // 使用自定义域名生成 URL
        if ($option->bucketDomain) {
            $bucketDomain = rtrim($option->bucketDomain, '/');
            $s3ObjectUrl = $bucketDomain . '/' . ltrim($content['attachment']->path, '/');
        } else {
            // 使用 S3 默认的 getObjectUrl 生成 URL
            $s3 = self::initConnection();
            $s3ObjectUrl = $s3->getObjectUrl($option->bucket, $content['attachment']->path);
        }
    
        // 返回生成的 URL
        return $s3ObjectUrl;
    }
}
