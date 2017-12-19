<?php

namespace dlds\galleryManager;

use Imagine\Image\Box;
use yii\base\Behavior;
use yii\base\Exception;
use yii\db\ActiveRecord;
use yii\db\Query;
use yii\imagine\Image;

/**
 * Behavior for adding gallery to any model.
 *
 * @author Bogdan Savluk <savluk.bogdan@gmail.com>
 * @author Bogdan Savluk <jiri.svoboda@dlds.cz>
 *
 * @property string $galleryId
 */
class GalleryBehavior extends Behavior
{

    /**
     * Dirs
     */
    const DIR_ORIGINALS = 'originals';
    const DIR_THUMBS = 'thumbnails';

    /**
     * Default ids
     */
    const DEFAULT_IMAGE_ID = 'default';

    /**
     * Default versions
     */
    const VERSION_ORIGINAL = 'original';
    const VERSION_PREVIEW = 'preview';

    /**
     * @var string Type name assigned to model in image attachment action
     * @see GalleryManagerAction::$type
     * @example $type = 'Post' where 'Post' is the model name
     */
    public $type;

    /**
     * @var ActiveRecord the owner of this behavior
     * @example $owner = Post where Post is the ActiveRecord with GalleryBehavior attached under public function behaviors()
     */
    public $owner;

    /**
     * @var string
     */
    public $attrUploads = false;

    /**
     * Widget preview height
     * @var int
     */
    public $previewHeight = 200;

    /**
     * Widget preview width
     * @var int
     */
    public $previewWidth = 200;

    /**
     * @var int original max width
     */
    public $originalWidth = 1920;

    /**
     * @var int original max height
     */
    public $originalHeight = 1080;

    /**
     * Extension for saved images
     * @var string
     */
    public $extension;

    /**
     * Path to directory where to save uploaded images
     * @var string
     */
    public $directory;

    /**
     * Directory Url, without trailing slash
     * @var string
     */
    public $url;

    /**
     * @var array Functions to generate image versions
     * @note Be sure to not modify image passed to your version function,
     *       because it will be reused in all other versions,
     *       Before modification you should copy images as in examples below
     * @note 'preview' & self::VERSION_ORIGINAL versions names are reserved for image preview in widget
     *       and original image files, if it is required - you can override them
     * @example
     * [
     *  'small' => function ($img) {
     *      return $img
     *          ->copy()
     *          ->resize($img->getSize()->widen(200));
     *  },
     *  'medium' => function ($img) {
     *      $dstSize = $img->getSize();
     *      $maxWidth = 800;
     * ]
     */
    public $versions;

    /**
     * @var string host to be used
     */
    public $host;

    /**
     * name of query param for modification time hash
     * to avoid using outdated version from cache - set it to false
     * @var string
     */
    public $timeHash = '_';

    /**
     * @var array saving options
     */
    public $saveOptions = ['quality' => 100];

    /**
     * Table name of Gallery
     * @var string
     */
    public $tableName = 'app_gallery_image';

    /**
     * @var \Closure
     */
    public $pkParser = null;

    /**
     * @var array images
     */
    protected $_images = null;

    /**
     * @var int gallery id
     */
    protected $_galleryId;

    /**
     * @inheritdoc
     */
    public function attach($owner)
    {
        parent::attach($owner);

        $this->attachDefaultVersions();
    }

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
            ActiveRecord::EVENT_AFTER_INSERT => 'handleUploads',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
        ];
    }

    /**
     * @inheritdoc
     */
    public function beforeDelete()
    {
        $this->deleteAllImgFiles();
    }

    /**
     * @inheritdoc
     */
    public function afterFind()
    {
        $this->_galleryId = $this->getGalleryId();
    }

    /**
     * @inheritdoc
     */
    public function afterUpdate()
    {
        $id = $this->getGalleryId();

        if ($this->_galleryId != $id) {
            $this->renameDirectory($id);
        }

        $this->handleUploads();
    }

    public function handleUploads()
    {
        if (!$this->attrUploads || !isset($this->owner->{$this->attrUploads})) {
            return false;
        }

        if ($this->owner->validate()) {
            $this->owner->{$this->attrUploads} = \yii\web\UploadedFile::getInstances($this->owner, $this->attrUploads);

            foreach ($this->owner->{$this->attrUploads} as $att) {
                $this->addImage($att->tempName);
            }
        }
    }

    /**
     * @return GalleryImage[]
     */
    public function getImages($sort = SORT_ASC)
    {
        if ($this->_images === null) {
            $query = new \yii\db\Query();

            $imagesData = $query
                ->select(['id', 'rank'])
                ->from($this->tableName)
                ->where(['owner_type' => $this->type, 'owner_id' => $this->getGalleryId()])
                ->orderBy(['rank' => $sort])
                ->all();

            $this->_images = [];

            foreach ($imagesData as $imageData) {
                $this->_images[] = new GalleryImage($this, $imageData);
            }
        }

        return $this->_images;
    }

    /**
     * Get Gallery Id
     *
     * @return mixed as string or integer
     * @throws Exception
     */
    public function getGalleryId()
    {
        $pk = $this->owner->getPrimaryKey();

        if (is_callable($this->pkParser)) {
            $pk = call_user_func($this->pkParser, $pk);
        }

        if (is_array($pk)) {
            throw new Exception('Composite pk not supported');
        }

        return $pk;
    }

    /**
     * Retrieves image url
     * @param GalleryImageProxy $image given image
     * @param string $version given image version
     * @return string image url
     */
    public function getImageUrl($image, $version = self::VERSION_ORIGINAL)
    {
        if (null === $image || !($image instanceof GalleryImageProxy)) {
            return $this->getDefaultImageUrl($version);
        }

        $path = $this->getImageFilePath($image->id, $version);

        if (!file_exists($path) && !$this->generateVersion($image->id, $version)) {
            return $this->getDefaultImageUrl($version);
        }

        return $this->getFileUrl($image->id, $path, $version);
    }

    /**
     * Retrieves default image url
     * @param mixed $version given version
     */
    public function getDefaultImageUrl($version, $image_id = self::DEFAULT_IMAGE_ID)
    {
        $path = $this->getImageFilePath($image_id, $version, true);

        if (!file_exists($path) && !$this->generateVersion($image_id, $version)) {
            return null;
        }

        return $this->getFileUrl($image_id, $path, $version);
    }

    /**
     * Retrieves image file path
     * @param int $image_id given image id
     * @param string $version given version
     * @return string file path
     */
    public function getImageFilePath($image_id, $version = self::VERSION_ORIGINAL)
    {
        $subDir = $this->getVersionSubDir($version, $image_id);

        $path = implode(DIRECTORY_SEPARATOR, [
            $this->directory,
            $subDir,
            $this->getFilePath($image_id, $version)
        ]);

        if (!is_writable($path)) {
            return $path;
        }

        return $path;
    }

    /**
     * Replace existing image by specified file
     * @param $image_id
     * @param $path
     */
    public function replaceImage($image_id, $path)
    {
        $this->deleteAllImageVersions($image_id);

        $this->generateVersion($image_id, self::VERSION_ORIGINAL, null, $path);
    }

    /**
     * Delete image based on given id
     * @param int $image_id
     */
    public function deleteImage($image_id)
    {
        $this->deleteAllImageVersions($image_id);

        $filePath = $this->getImageFilePath($image_id, self::VERSION_ORIGINAL);

        $dirPath = $this->getFileDir($filePath);
        @rmdir($dirPath);

        $db = \Yii::$app->db;
        $db->createCommand()
            ->delete($this->tableName, ['id' => $image_id])
            ->execute();
    }

    /**
     * Deletes all image versions
     * @param int $image_id
     */
    public function deleteAllImageVersions($image_id)
    {
        $dirPath = $this->getFileDir($this->getImageFilePath($image_id, self::VERSION_PREVIEW));

        foreach ($this->versions as $version => $fn) {
            $filePath = $this->getImageFilePath($image_id, $version);
            $this->removeFile($filePath);
        }

        @rmdir($dirPath);
    }

    /**
     * Delete multiple images
     * @param array $imageIds images ids
     */
    public function deleteAllImages(array $imageIds)
    {
        foreach ($imageIds as $imageId) {
            $this->deleteImage($imageId);
        }

        if ($this->_images !== null) {
            $removed = array_combine($imageIds, $imageIds);
            $this->_images = array_filter(
                $this->_images, function ($image) use (&$removed) {
                return !isset($removed[$image->id]);
            }
            );
        }
    }

    /**
     * Adds image
     * @param string $fileName
     * @return \dlds\galleryManager\GalleryImage
     */
    public function addImage($fileName, $attrs = [])
    {
        $db = \Yii::$app->db;

        $db->createCommand()
            ->insert($this->tableName, \yii\helpers\ArrayHelper::merge($attrs, [
                    'owner_type' => $this->type,
                    'owner_id' => $this->getGalleryId()
            ]))
            ->execute();

        $id = $db->getLastInsertID();

        $db->createCommand()
            ->update($this->tableName, ['rank' => $id], ['id' => $id])
            ->execute();

        $this->replaceImage($id, $fileName);

        $galleryImage = new GalleryImage($this, ['id' => $id]);

        if ($this->_images !== null) {
            $this->_images[] = $galleryImage;
        }

        return $galleryImage;
    }

    /**
     * Aranges images
     * @param array $order
     * @return array
     */
    public function arrange($order, $sort = SORT_ASC)
    {
        $orders = [];
        $i = 0;
        foreach ($order as $k => $v) {
            if (!$v) {
                $order[$k] = $k;
            }
            $orders[] = $order[$k];
            $i++;
        }

        if (SORT_ASC == $sort) {
            sort($orders);
        } else {
            rsort($orders);
        }

        $i = 0;
        foreach ($order as $k => $v) {
            \Yii::$app->db->createCommand()
                ->update($this->tableName, ['rank' => $orders[$i]], ['id' => $k])
                ->execute();

            $i++;
        }

        // todo: arrange images if presented
        return $order;
    }

    /**
     * @param array $imagesData
     *
     * @return GalleryImage[]
     */
    public function updateImagesData($imagesData)
    {
        $imageIds = array_keys($imagesData);
        $imagesToUpdate = [];

        if ($this->_images !== null) {
            $selected = array_combine($imageIds, $imageIds);

            foreach ($this->_images as $img) {
                if (isset($selected[$img->id])) {
                    $imagesToUpdate[] = $selected[$img->id];
                }
            }
        } else {
            $rawImages = (new Query())
                ->select(['id', 'rank'])
                ->from($this->tableName)
                ->where(['owner_type' => $this->type, 'owner_id' => $this->getGalleryId()])
                ->andWhere(['in', 'id', $imageIds])
                ->orderBy(['rank' => 'asc'])
                ->all();

            foreach ($rawImages as $image) {
                $imagesToUpdate[] = new GalleryImage($this, $image);
            }
        }


        foreach ($imagesToUpdate as $image) {
            \Yii::$app->db->createCommand()
                ->update($this->tableName, [
                    ], [
                    'id' => $image->id
                ])
                ->execute();
        }

        return $imagesToUpdate;
    }

    /**
     * Generates image version
     * @param int $image_id given image id
     * @param string $version given version
     * @param \Closure $fn callable fn
     * @param string $originalFilePath original image
     * @return mixed return value of callback
     */
    public function generateVersion($image_id, $version, $fn = null, $originalFilePath = null)
    {
        if (!isset($this->versions[$version])) {
            throw new Exception('Unsupported image version');
        }

        if (null === $fn) {
            $fn = $this->versions[$version];
        }

        if (null === $originalFilePath) {
            $originalFilePath = $this->getImageFilePath($image_id);
        }

        try {
            $original = Image::getImagine()->open($originalFilePath);
        } catch (\Imagine\Exception\InvalidArgumentException $ex) {
            //throw $ex;
            return false;
        }

        $this->createFolders($image_id);

        return call_user_func($fn, $original)->save($this->getImageFilePath($image_id, $version), $this->saveOptions);
    }

    /**
     * Retrieves version subdir
     * @param mixed $version version name
     */
    private function getVersionSubDir($version)
    {
        if (self::VERSION_ORIGINAL === $version) {
            return self::DIR_ORIGINALS;
        }

        return self::DIR_THUMBS;
    }

    /**
     * Generates all versions
     */
    private function generateAllVersions($image_id, $originalFilePath = null)
    {
        foreach ($this->versions as $version => $fn) {
            $this->generateVersion($image_id, $version, $fn, $originalFilePath);
        }
    }

    /**
     * Attaches default versions
     */
    private function attachDefaultVersions()
    {
        if (!isset($this->versions[self::VERSION_ORIGINAL])) {
            $this->versions[self::VERSION_ORIGINAL] = function ($img) {

                if ($this->originalWidth && $this->originalHeight) {
                    $size = $this->calculateSize($img, $this->originalWidth, $this->originalHeight);

                    if (false !== $size) {
                        return $img->resize($size);
                    }
                }

                return $img;
            };
        }

        if (!isset($this->versions[self::VERSION_PREVIEW])) {
            $this->versions[self::VERSION_PREVIEW] = function ($img) {

                return $img->thumbnail(new Box($this->previewWidth, $this->previewHeight));
            };
        }
    }

    /**
     * Deletes all attaches img files
     */
    private function deleteAllImgFiles()
    {
        $images = $this->getImages();

        foreach ($images as $image) {
            $this->deleteImage($image->id);
        }

        $dirPath = implode(DIRECTORY_SEPARATOR, [
            $this->directory,
            $this->getGalleryId(),
        ]);

        @rmdir($dirPath);
    }

    /**
     * Renames gallery directory based on given new id
     * @param int $gallery_id given id
     */
    private function renameDirectory($gallery_id)
    {
        $dirPath1 = implode(DIRECTORY_SEPARATOR, [
            $this->directory,
            $this->_galleryId,
        ]);

        $dirPath2 = implode(DIRECTORY_SEPARATOR, [
            $this->directory,
            $gallery_id,
        ]);

        rename($dirPath1, $dirPath2);
    }

    /**
     * Retrieves file url
     * @param string $path file path
     * @param mixed $version given version
     * @return string file url
     */
    public function getFileUrl($image_id, $path, $version)
    {
        if (!file_exists($path)) {
            return null;
        }

        if (!empty($this->timeHash)) {

            $time = filemtime($path);
            $suffix = '?' . $this->timeHash . '=' . crc32($time);
        } else {
            $suffix = '';
        }

        $urlParts = [
            $this->url,
            $this->getVersionSubDir($version),
            sprintf('%s%s', $this->getFilePath($image_id, $version), $suffix)
        ];

        $url = str_replace('\\', '/', implode('/', $urlParts));

        if ($this->host) {
            return $this->host . $url;
        }

        return $url;
    }

    /**
     * Retrieves image file name
     * @param int $image_id given image id
     * @param string $version given version
     * @return string filename
     */
    protected function getFilePath($image_id, $version = self::VERSION_ORIGINAL)
    {
        if (self::DEFAULT_IMAGE_ID !== $image_id) {
            $path[] = $this->getGalleryId();
        }

        if (self::VERSION_ORIGINAL !== $version) {
            $path[] = $image_id;

            $path[] = sprintf('%s.%s', $version, $this->extension);
        } else {
            $path[] = sprintf('%s.%s', $image_id, $this->extension);
        }

        return implode(DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Removes given file
     * @param string $filename
     */
    private function removeFile($filename)
    {
        if (file_exists($filename)) {
            @unlink($filename);
        }
    }

    /**
     * Creates folders
     * @param string $filepath
     */
    private function createFolders($image_id)
    {
        $filepaths = [
            $this->getImageFilePath($image_id),
            $this->getImageFilePath($image_id, self::VERSION_PREVIEW),
        ];

        foreach ($filepaths as $filepath) {
            $this->createFolder($filepath);
        }
    }

    /**
     * Creates folder based on given filepath
     * @param string $filePath given filepath
     */
    private function createFolder($filePath)
    {
        $dirPath = $this->getFileDir($filePath);

        $path = realpath($dirPath);

        if (!$path) {
            $result = @mkdir($dirPath, 0777, true);

            if (!$result) {
                @mkdir($dirPath, 0777, true);
            }
        }
    }

    /**
     * Retrieves file dir
     * @param string $filepath
     * @return string dir path
     */
    private function getFileDir($filepath)
    {
        $parts = explode(DIRECTORY_SEPARATOR, $filepath);
        $parts = array_slice($parts, 0, count($parts) - 1);

        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    /**
     * Calculates appropriate size based on given width / height ratio
     * @param \Imagine\Image\ImageInterface $img
     * @param int$width
     * @param int $height
     * @return boolean|Box
     */
    protected function calculateSize(\Imagine\Image\ImageInterface $img, $width, $height)
    {
        if ($img->getSize()->getWidth() > $width || $img->getSize()->getHeight() > $height) {
            if ($img->getSize()->getWidth() > $img->getSize()->getHeight()) {
                $calculatedHeight = $width * $img->getSize()->getHeight() / $img->getSize()->getWidth();

                return new Box($width, $calculatedHeight);
            } else {
                $calculatedWidth = $height * $img->getSize()->getWidth() / $img->getSize()->getHeight();

                return new Box($calculatedWidth, $height);
            }
        }

        return false;
    }

}
