<?php

namespace dlds\galleryManager;

use yii\db\ActiveRecord;

class GalleryImage extends GalleryImageProxy {

    public $name;
    public $description;
    public $id;
    public $rank;

    /**
     * @var GalleryBehavior
     */
    protected $galleryBehavior;

    /**
     * @param GalleryBehavior $galleryBehavior
     * @param array           $props
     */
    public function __construct(GalleryBehavior $galleryBehavior, array $props)
    {

        $this->galleryBehavior = $galleryBehavior;

        $this->name = isset($props['name']) ? $props['name'] : '';
        $this->description = isset($props['description']) ? $props['description'] : '';
        $this->id = isset($props['id']) ? $props['id'] : '';
        $this->rank = isset($props['rank']) ? $props['rank'] : '';
    }

    /**
     * @param string $version
     *
     * @return string
     */
    public function getUrl($version)
    {
        return $this->galleryBehavior->getImageUrl($this, $version);
    }

    /**
     * @return GalleryBehavior
     */
    public function getGalleryBehavior()
    {
        return $this->galleryBehavior;
    }

}
