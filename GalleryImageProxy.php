<?php

namespace dlds\galleryManager;

use yii\db\ActiveRecord;

class GalleryImageProxy extends ActiveRecord {

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'app_gallery_image';
    }

}
