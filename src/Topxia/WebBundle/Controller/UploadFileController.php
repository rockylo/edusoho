<?php
namespace Topxia\WebBundle\Controller;

use Symfony\Component\HttpFoundation\Request;

use Topxia\Service\Util\CloudClientFactory;
use Topxia\Common\StringToolkit;
use Topxia\Common\FileToolkit;
use Topxia\Service\User\CurrentUser;

class UploadFileController extends BaseController
{

    public function uploadAction(Request $request)
    {
        $token = $request->request->get('token');
        $token = $this->getUserService()->getToken('fileupload', $token);
        if (empty($token)) {
            throw $this->createAccessDeniedException('上传TOKEN已过期或不存在。');
        }

        $user = $this->getUserService()->getUser($token['userId']);
        if (empty($user)) {
            throw $this->createAccessDeniedException('上传TOKEN非法。');
        }

        $currentUser = new CurrentUser();
        $this->getServiceKernel()->setCurrentUser($currentUser->fromArray($user));

        $targetType = $request->query->get('targetType');
        $targetId = $request->query->get('targetId');

        $originalFile = $this->get('request')->files->get('file');

        $file = $this->getUploadFileService()->addFile($targetType, $targetId, array(), 'local', $originalFile);
        return $this->createJsonResponse($file);
    }

    // @todo 权限验证
    public function browserAction(Request $request)
    {
        $user = $this->getCurrentUser();

        $conditions = $request->query->all();

        $files = $this->getUploadFileService()->searchFiles($conditions, 'latestUpdated', 0, 1000);
        
        return $this->createFilesJsonResponse($files);
    }

    public function paramsAction(Request $request)
    {
        $user = $this->getCurrentUser();
        if (!$user->isLogin()) {
            throw $this->createAccessDeniedException();
        }

        $params = $request->query->all();

        $params['user'] = $user->id;
        $params['defaultUploadUrl'] = $this->generateUrl('uploadfile_upload', array('targetType' => $params['targetType'], 'targetId' => $params['targetId']));

        if (empty($params['lazyConvert'])) {
            $params['convertCallback'] = $this->generateUrl('uploadfile_cloud_convert_callback2', array(), true);
        } else {
            $params['convertCallback'] = null;
        }

        $setting = $this->getSettingService()->get('storage', array());
        $params['hasVideoWatermark'] = 0;
        
        if ($setting['video_watermark'] == 2 && !empty($setting['video_watermark_image'])) {
            $params['hasVideoWatermark'] = 1;
        }
        
        $params = $this->getUploadFileService()->makeUploadParams($params);

        return $this->createJsonResponse($params);
    }

    public function cloudCallbackAction(Request $request)
    {
        $user = $this->getCurrentUser();
        if (!$user->isLogin()) {
            throw $this->createAccessDeniedException();
        }

        $fileInfo = $request->request->all();
        var_dump($fileInfo);exit();
        $targetType = $request->query->get('targetType');
        $targetId = $request->query->get('targetId');
        $lazyConvert = $request->query->get('lazyConvert') ? true : false;
        $fileInfo['lazyConvert'] = $lazyConvert;

        $file = $this->getUploadFileService()->addFile($targetType, $targetId, $fileInfo, 'cloud');
        if ($lazyConvert) {
            $convertHash = $this->getUploadFileService()->reconvertFile(
                $file['id'],
                $this->generateUrl('uploadfile_cloud_convert_callback2', array(), true)
            );
        }

        return $this->createJsonResponse($file);
    }

    public function cloudConvertCallback2Action(Request $request)
    {
        $result = $request->getContent();
        $result = preg_replace_callback(
          "(\\\\x([0-9a-f]{2}))i",
          function($a) {return chr(hexdec($a[1]));},
          $result
        );

        file_put_contents('/tmp/aaa', serialize($result));
        $this->getLogService()->info('uploadfile', 'cloud_convert_callback', "文件云处理回调", array('result' => $result));
        $result = json_decode($result, true);
        $result = array_merge($request->query->all(), $result);
        if (empty($result['id'])) {
            throw new \RuntimeException('数据中id不能为空');
        }

        if (!empty($result['convertHash'])) {
            $file = $this->getUploadFileService()->getFileByConvertHash($result['convertHash']);
        } else {
            $file = $this->getUploadFileService()->getFileByConvertHash($result['id']);
            if ($file && $file['type'] == 'ppt') {
                $result['nextConvertCallbackUrl'] = $this->generateUrl('uploadfile_cloud_convert_callback2', array('convertHash' => $result['id']), true);
            }
        }

        if (empty($file)) {
            throw new \RuntimeException('文件不存在');
        }

        $file = $this->getUploadFileService()->saveConvertResult($file['id'], $result);

        if (in_array($file['convertStatus'], array('success', 'error'))) {
            $this->getNotificationService()->notify($file['createdUserId'], 'cloud-file-converted', array(
                'file' => $file,
            ));
        }

        return $this->createJsonResponse($file['metas2']);
    }

    public function cloudConvertCallback3Action(Request $request)
    {
        $result = $request->getContent();

        $result = preg_replace_callback(
          "(\\\\x([0-9a-f]{2}))i",
          function($a) {return chr(hexdec($a[1]));},
          $result
        );

        $this->getLogService()->info('uploadfile', 'cloud_convert_callback3', "文件云处理回调", array('result' => $result));
        $result = json_decode($result, true);
        $result = array_merge($request->query->all(), $result);
        if (empty($result['id'])) {
            throw new \RuntimeException('数据中id不能为空');
        }

        if ($result['code'] != 0) {
            $this->getLogService()->error('uploadfile', 'cloud_convert_error', "文件云处理失败", array('result' => $result));
            return $this->createJsonResponse(true);
        }

        $file = $this->getUploadFileService()->getFileByConvertHash($result['id']);
        if (empty($file)) {
            $this->getLogService()->error('uploadfile', 'cloud_convert_error', "文件云处理失败，文件记录不存在", array('result' => $result));
            throw new \RuntimeException('文件不存在');
        }

        $file = $this->getUploadFileService()->saveConvertResult3($file['id'], $result);

        return $this->createJsonResponse($file['metas2']);
    }    

    public function cloudConvertCallbackAction(Request $request)
    {
        $data = $request->getContent();

        $this->getLogService()->info('uploadfile', 'cloud_convert_callback', "文件云处理回调", array('content' => $data));

        $key = $request->query->get('key');
        $fullKey = $request->query->get('fullKey');
        if (empty($key)) {
            throw new \RuntimeException('key不能为空');
        }

        $data = json_decode($data, true);

        if (empty($data['id'])) {
            throw new \RuntimeException('数据中id不能为空');
        }

        if ($fullKey) {
            $hash = $fullKey;
        } else {
            $hash = "{$data['id']}:{$key}";
        }

        $file = $this->getUploadFileService()->getFileByConvertHash($hash);
        if (empty($file)) {
            throw new \RuntimeException('文件不存在');
        }

        if ($data['code'] != 0) {
            $this->getUploadFileService()->convertFile($file['id'], 'error');
            throw new \RuntimeException('转换失败');
        }

        $items = (empty($data['items']) or !is_array($data['items'])) ? array() : $data['items'];

        $status = $request->query->get('twoStep', false) ? 'doing' : 'success';

        if ($status == 'doing') {
            $callback = $this->generateUrl('uploadfile_cloud_convert_callback', array('key' => $key, 'fullKey' => $hash), true);
            $file = $this->getUploadFileService()->convertFile($file['id'], $status, $data['items'], $callback);
        } else {
            $file = $this->getUploadFileService()->convertFile($file['id'], $status, $data['items']);
        }

        if (in_array($file['convertStatus'], array('success', 'error'))) {
            $this->getNotificationService()->notify($file['createdUserId'], 'cloud-file-converted', array(
                'file' => $file,
            ));
        }

        return $this->createJsonResponse($file['metas2']);
    }

    public function getMediaInfoAction(Request $request, $type)
    {
        $key = $request->query->get('key');
        $info = $this->getUploadFileService()->getMediaInfo($key, $type);
        return $this->createJsonResponse($info['format']['duration']);
    }

    private function getUploadFileService()
    {
        return $this->getServiceKernel()->createService('File.UploadFileService');
    }

    protected function getCourseService()
    {
        return $this->getServiceKernel()->createService('Course.CourseService');
    }

    protected function getNotificationService()
    {
        return $this->getServiceKernel()->createService('User.NotificationService');
    }

    private function getSettingService()
    {
        return $this->getServiceKernel()->createService('System.SettingService');
    }

    private function createFilesJsonResponse($files)
    {
        foreach ($files as &$file) {
            $file['updatedTime'] = date('Y-m-d H:i', $file['updatedTime']);
            $file['size'] = FileToolkit::formatFileSize($file['size']);
            unset($file);
        }
        return $this->createJsonResponse($files);
    }

}