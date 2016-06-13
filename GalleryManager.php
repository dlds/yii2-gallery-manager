<?php

namespace dlds\galleryManager;

use Yii;
use yii\base\Exception;
use yii\base\Widget;
use yii\db\ActiveRecord;
use yii\helpers\Json;
use yii\helpers\Url;

/**
 * Widget to manage gallery.
 * Requires Twitter Bootstrap styles to work.
 *
 * @author Bogdan Savluk <savluk.bogdan@gmail.com>
 */
class GalleryManager extends Widget {

    /** @var ActiveRecord */
    public $model;

    /** @var string */
    public $behaviorName;

    /** @var GalleryBehavior Model of gallery to manage */
    protected $behavior;

    /** @var string Route to gallery controller */
    public $apiRoute = false;
    public $options = array();
    public $sort = SORT_ASC;
    public $prepend = false;
    public $view;

    public function init()
    {
        parent::init();
        $this->behavior = $this->model->getBehavior($this->behaviorName);
        $this->registerTranslations();
    }

    public function registerTranslations()
    {
        $i18n = Yii::$app->i18n;
        $i18n->translations['galleryManager/*'] = [
            'class' => 'yii\i18n\PhpMessageSource',
            'sourceLanguage' => 'en-US',
            //'basePath' => '@dlds/galleryManager/messages',
            'fileMap' => [],
        ];
    }

    /** Render widget */
    public function run()
    {
        if ($this->apiRoute === null)
        {
            throw new Exception('$apiRoute must be set.', 500);
        }

        $images = array();
        foreach ($this->behavior->getImages($this->sort) as $image)
        {
            $images[] = array(
                'id' => $image->id,
                'rank' => $image->rank,
                'name' => (string) $image->name,
                'description' => (string) $image->description,
                'preview' => $image->getUrl('preview'),
            );
        }

        $baseUrl = [
            $this->apiRoute,
            'type' => $this->behavior->type,
            'behaviorName' => $this->behaviorName,
            'galleryId' => $this->model->getPrimaryKey()
        ];

        $opts = array(
            'hasName' => $this->behavior->hasName ? true : false,
            'hasDesc' => $this->behavior->hasDescription ? true : false,
            'uploadUrl' => Url::to($baseUrl + ['action' => 'ajaxUpload']),
            'deleteUrl' => Url::to($baseUrl + ['action' => 'delete']),
            'updateUrl' => Url::to($baseUrl + ['action' => 'changeData']),
            'arrangeUrl' => Url::to($baseUrl + ['action' => 'order']),
            'nameLabel' => Yii::t('galleryManager/main', 'Name'),
            'descriptionLabel' => Yii::t('galleryManager/main', 'Description'),
            'photos' => $images,
            'prepend' => $this->prepend,
        );

        $opts = Json::encode($opts);
        $view = $this->getView();
        GalleryManagerAsset::register($view);
        $view->registerJs("(function(){\$('#{$this->id}').galleryManager({$opts});})();", \yii\web\View::POS_READY);

        $this->options['id'] = $this->id;
        $this->options['class'] = 'gallery-manager';

        if ($this->view)
        {
            return $this->render($this->view);
        }

        return $this->render('galleryManager');
    }
}