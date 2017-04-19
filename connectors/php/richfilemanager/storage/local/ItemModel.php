<?php

namespace RFM\Storage\Local;

use RFM\Storage\StorageTrait;

class ItemModel
{
    use IdentityTrait;
    use StorageTrait;

    const TYPE_FILE = 'file';
    const TYPE_FOLDER = 'folder';

    /**
     * File item model template
     *
     * @var array
     */
    protected $file_model = [
        "id"    => '',
        "type"  => self::TYPE_FILE,
        "attributes" => [
            'name'      => '',
            'extension' => '',
            'path'      => '',
            'readable'  => 1,
            'writable'  => 1,
            'created'   => '',
            'modified'  => '',
            'timestamp' => '',
            'height'    => 0,
            'width'     => 0,
            'size'      => 0,
        ]
    ];

    /**
     * Folder item model template
     *
     * @var array
     */
    protected $folder_model = [
        "id"    => '',
        "type"  => self::TYPE_FOLDER,
        "attributes" => [
            'name'      => '',
            'path'      => '',
            'readable'  => 1,
            'writable'  => 1,
            'created'   => '',
            'modified'  => '',
            'timestamp' => '',
        ]
    ];

    /**
     * Absolute path for item model, based on relative path.
     *
     * @var string
     */
    public $pathAbsolute;

    /**
     * Relative path for item model, the only value required to create item model.
     *
     * @var string
     */
    public $pathRelative;

    /**
     * Whether item exists in file system on any other storage.
     * Defined and cached upon creating new item instance.
     *
     * @var bool
     */
    public $isExists;

    /**
     * Whether item is folder.
     * Defined and cached upon creating new item instance.
     *
     * @var bool
     */
    public $isDir;

    /**
     * Model for parent folder of the current item.
     * Return NULL if there is no parent folder (user storage root folder).
     *
     * @var null|ItemModel
     */
    private $parent;

    /**
     * Model for thumbnail file or folder of the current item.
     *
     * @var null|ItemModel
     */
    private $thumbnail;

    /**
     * ItemModel constructor.
     *
     * @param string $path
     */
    public function __construct($path)
    {
        $this->pathRelative = $path;
        $this->pathAbsolute = $this->getAbsolutePath($path);
        $this->isExists = $this->getIsExists();
        $this->isDir = $this->getIsDirectory();
    }

    /**
     * Build and return item details info.
     *
     * @return array
     */
    public function getInfo()
    {
        $pathInfo = pathinfo($this->pathAbsolute);
        $filemtime = filemtime($this->pathAbsolute);

        // check if file is readable
        $is_readable = $this->storage()->has_read_permission($this->pathAbsolute);

        // check if file is writable
        $is_writable = $this->storage()->has_write_permission($this->pathAbsolute);

        if($this->isDir) {
            $model = $this->folder_model;
        } else {
            $model = $this->file_model;
            $model['attributes']['extension'] = isset($pathInfo['extension']) ? $pathInfo['extension'] : '';

            if ($is_readable) {
                $model['attributes']['size'] = $this->storage()->get_real_filesize($this->pathAbsolute);

                if($this->storage()->is_image_file($this->pathAbsolute)) {
                    if($model['attributes']['size']) {
                        list($width, $height, $type, $attr) = getimagesize($this->pathAbsolute);
                    } else {
                        list($width, $height) = [0, 0];
                    }

                    $model['attributes']['width'] = $width;
                    $model['attributes']['height'] = $height;
                }
            }
        }

        $model['id'] = $this->pathRelative;
        $model['attributes']['name'] = $pathInfo['basename'];
        $model['attributes']['path'] = $this->storage()->getDynamicPath($this->pathAbsolute);
        $model['attributes']['readable'] = (int) $is_readable;
        $model['attributes']['writable'] = (int) $is_writable;
        $model['attributes']['timestamp'] = $filemtime;
        $model['attributes']['modified'] = $this->storage()->formatDate($filemtime);
        //$model['attributes']['created'] = $model['attributes']['modified']; // PHP cannot get create timestamp
        return $model;
    }

    /**
     * Return model for parent folder on the current item.
     * Create and cache if not existing yet.
     *
     * @return null|ItemModel
     */
    public function closest()
    {
        if (is_null($this->parent)) {
            $path = dirname($this->pathRelative);
            $path = $this->storage()->cleanPath($path);
            // dirname() trims trailing slash
            if ($path !== '/') {
                $path .= '/';
            }
            // can't get parent
            if ($path === $this->pathRelative) {
                return null;
            }
            $this->parent = new self($path);
        }

        return $this->parent;
    }

    /**
     * Return model for thumbnail of the current item.
     * Create and cache if not existing yet.
     *
     * @return null|ItemModel
     */
    public function thumbnail()
    {
        if (is_null($this->thumbnail)) {
            $this->thumbnail = new self($this->getThumbnailPath());
        }

        return $this->thumbnail;
    }

    /**
     * Define whether item is file or folder.
     * In case item doesn't exists we check the trailing slash.
     * That is why it's important to add slashes to the wnd of folders path.
     *
     * @return bool
     */
    public function getIsDirectory()
    {
        if ($this->isExists) {
            return is_dir($this->pathAbsolute);
        } else {
            return substr($this->pathRelative, -1, 1) === '/';
        }
    }

    /**
     * Check if file or folder exists.
     *
     * @return bool
     */
    public function getIsExists()
    {
        return file_exists($this->pathAbsolute);
    }

    /**
     * Return absolute path to item.
     *
     * @return string
     */
    public function getAbsolutePath()
    {
        return rtrim($this->storage()->getRoot(), '/') . '/' . $this->pathRelative;
    }

    /**
     * Return thumbnail relative path from given path.
     * Work for both files and dirs paths.
     *
     * @return string
     */
    public function getThumbnailPath()
    {
        $path =  '/' . $this->config('images.thumbnail.dir') . '/' . $this->pathRelative;

        return $this->storage()->cleanPath($path);
    }

    /**
     * Check whether the item is root folder.
     *
     * @return bool
     */
    public function isRoot()
    {
        return rtrim($this->storage()->getRoot(), '/') === rtrim($this->pathAbsolute, '/');
    }

    /**
     * Remove current file or folder.
     */
    public function remove()
    {
        if ($this->isDir) {
            $this->storage()->unlinkRecursive($this->pathAbsolute);
        } else {
            unlink($this->pathAbsolute);
        }
    }

    /**
     * Check that item exists and path is valid.
     *
     * @return void
     */
    public function check_path()
    {
        if(!$this->isExists || !$this->storage()->is_valid_path($this->pathAbsolute)) {
            $langKey = $this->isDir ? 'DIRECTORY_NOT_EXIST' : 'FILE_DOES_NOT_EXIST';
            app()->error($langKey, [$this->pathRelative]);
        }
    }

    /**
     * Check that item has read permission.
     *
     * @return void
     */
    public function check_restrictions()
    {
        $path = $this->pathRelative;
        if (!$this->isDir) {
            if ($this->storage()->is_allowed_extension($path) === false) {
                app()->error('FORBIDDEN_NAME', [$path]);
            }
        }

        if ($this->storage()->is_allowed_extension($path) === false) {
            app()->error('INVALID_FILE_TYPE');
        }

        // Nothing is restricting access to this file or dir, so it is readable.
        return;
    }

    /**
     * Check the global blacklists for this file path.
     *
     * @return bool
     */
    public function is_unrestricted()
    {
        $valid = true;

        if (!$this->isDir) {
            $valid = $valid && $this->storage()->is_allowed_extension($this->pathRelative);
        }

        return $valid && $this->storage()->is_allowed_path($this->pathRelative);
    }

    /**
     * Check that item has read permission.
     *
     * @return void -- exits with error response if the permission is not allowed
     */
    public function check_read_permission()
    {
        // Check system permission (O.S./filesystem/NAS)
        if ($this->storage()->has_system_read_permission($this->pathAbsolute) === false) {
            app()->error('NOT_ALLOWED_SYSTEM');
        }

        // Check the user's Auth API callback:
        if (fm_has_read_permission($this->pathAbsolute) === false) {
            app()->error('NOT_ALLOWED');
        }

        // Nothing is restricting access to this file or dir, so it is readable
        return;
    }

    /**
     * Check that item can be written to.
     * If the filepath does not exist, this assumes we want to CREATE a new
     * dir entry at $filepath (a new file or new subdir), and thus it checks the
     * parent dir for write permissions.
     *
     * @return void -- exits with error response if the permission is not allowed
     */
    public function check_write_permission()
    {
        // path to check
        $path = $this->pathAbsolute;

        if (!$this->isExists) {
            // It does not exist (yet). Check to see if we could write to this
            // path, by seeing if we can write new entries into its parent dir.
            $path = pathinfo($this->pathAbsolute, PATHINFO_DIRNAME);
        }

        //
        // The filepath (file or dir) does exist, so check its permissions:
        //

        // Check system permission (O.S./filesystem/NAS)
        if ($this->storage()->has_system_write_permission($path) === false) {
            app()->error('NOT_ALLOWED_SYSTEM');
        }

//        // Check the global blacklists:
//        if ($this->is_unrestricted($filepath) === false) {
//            app()->error('FORBIDDEN_NAME', [$filepath]);
//        }

        // Check the global read_only config flag:
        if ($this->config('security.read_only') !== false) {
            app()->error('NOT_ALLOWED');
        }

        // Check the user's Auth API callback:
        if (fm_has_write_permission($path) === false) {
            app()->error('NOT_ALLOWED');
        }

        // Nothing is restricting access to this file, so it is writable
        return;
    }
}