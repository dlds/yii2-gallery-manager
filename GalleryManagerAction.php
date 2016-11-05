<?php

namespace dlds\galleryManager;


use Yii;
use yii\base\Action;
use yii\db\ActiveRecord;
use yii\helpers\Json;
use yii\web\HttpException;
use yii\web\UploadedFile;

/**
 * Backend controller for GalleryManager widget.
 * Provides following features:
 *  - Image removal
 *  - Image upload/Multiple upload
 *  - Arrange images in gallery
 *
 * @author Bogdan Savluk <savluk.bogdan@gmail.com>
 */
class GalleryManagerAction extends Action
{

    /**
     * $types to be defined at Controller::actions()
     * @var array Mapping between types and model class names
     * @example 'post'=>'common\models\Post'
     * @see GalleryManagerAction::run
     */
    public $types = [];
    public $sort = SORT_ASC;

    protected $type;
    protected $behaviorName;
    protected $galleryId;

    /** @var  ActiveRecord */
    protected $owner;
    /** @var  GalleryBehavior */
    protected $behavior;


    public function run($action)
    {
        $this->type = Yii::$app->request->get('type');
        $this->behaviorName = Yii::$app->request->get('behaviorName');
        $this->galleryId = Yii::$app->request->get('galleryId');

        $this->owner = call_user_func([$this->types[$this->type], 'findOne'], $this->galleryId);
        $this->behavior = $this->owner->getBehavior($this->behaviorName);

        switch ($action) {
            case 'delete':
                return $this->actionDelete(Yii::$app->request->post('id'));
                break;
            case 'ajaxUpload':
                return $this->actionAjaxUpload();
                break;
            case 'changeData':
                return $this->actionChangeData(Yii::$app->request->post('photo'));
                break;
            case 'order':
                return $this->actionOrder(Yii::$app->request->post('order'));
                break;
            default:
                throw new HttpException(400, 'Action do not exists');
                break;
        }
    }

    /**
     * Removes image with ids specified in post request.
     * On success returns 'OK'
     *
     * @param $ids
     *
     * @throws HttpException
     * @return string
     */
    protected function actionDelete($ids)
    {

        $this->behavior->deleteAllImages($ids);

        return 'OK';
    }

    /**
     * Method to handle file upload thought XHR2
     * On success returns JSON object with image info.
     *
     * @param $gallery_id string Gallery Id to upload images
     *
     * @return string
     * @throws HttpException
     */
    public function actionAjaxUpload()
    {

        $imageFile = UploadedFile::getInstanceByName('image');

        $fileName = $imageFile->tempName;
        $image = $this->behavior->addImage($fileName);

        // not "application/json", because  IE8 trying to save response as a file

        Yii::$app->response->headers->set('Content-Type', 'text/html');

        return Json::encode(
            array(
                'id' => $image->id,
                'rank' => $image->rank,
                'preview' => $image->getUrl('preview'),
            )
        );
    }

    /**
     * Saves images order according to request.
     * Variable $_POST['order'] - new arrange of image ids, to be saved
     * @throws HttpException
     */
    public function actionOrder($order)
    {
        if (count($order) == 0) {
            throw new HttpException(400, 'No data, to save');
        }
        $res = $this->behavior->arrange($order, $this->sort);

        return Json::encode($res);

    }

    /**
     * Method to update images name/description via AJAX.
     * On success returns JSON array of objects with new image info.
     *
     * @param $imagesData
     *
     * @throws HttpException
     * @return string
     */
    public function actionChangeData($imagesData)
    {
        if (count($imagesData) == 0) {
            throw new HttpException(400, 'Nothing to save');
        }
        $images = $this->behavior->updateImagesData($imagesData);
        $resp = array();
        foreach ($images as $model) {
            $resp[] = array(
                'id' => $model->id,
                'rank' => $model->rank,
                'preview' => $model->getUrl('preview'),
            );
        }

        return Json::encode($resp);
    }
}
