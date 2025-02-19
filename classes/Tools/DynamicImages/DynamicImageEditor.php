<?php

// Copyright (c) 2016 Interfacelab LLC. All rights reserved.
//
// Released under the GPLv3 license
// http://www.gnu.org/licenses/gpl-3.0.html
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// **********************************************************************

namespace ILAB\MediaCloud\Tools\DynamicImages;

use ILAB\MediaCloud\Tools\Storage\StorageImageEditor;
use WP_Error;

if (!defined('ABSPATH')) { header('Location: /'); die; }

/**
 * Class DynamicImageEditor
 * 
 * Replaces WordPress's WP_Image_Editor with one that supports all of the features
 * of Imgix.
 * 
 */
class DynamicImageEditor extends \WP_Image_Editor
{
    protected $currentSize;
    protected $sourceFile;
    protected $isGif;
    protected $mime;

    protected $storageEditor;

    public function __construct($file) {
        parent::__construct($file);

        $this->storageEditor = new StorageImageEditor($file);
    }

    public static function test( $args = array() )
    {
        return true;
    }

    public static function supports_mime_type( $mime_type )
    {
        return true;
    }

    private function loadFromFile() {
	    if (!file_exists($this->sourceFile))
		    return false;

	    $mime = wp_check_filetype($this->sourceFile);
	    if (!empty($mime) && isset($mime['type'])) {
	    	$this->mime = $mime['type'];
	    }
	    $this->isGif=($this->mime=='image/gif');
	    $size=getimagesize($this->sourceFile);

	    if (!$size)
		    return false;

	    $this->size=[
		    'width'=>$size[0], 'height'=>$size[1]
	    ];

	    return true;
    }

    public function load()
    {
        $this->storageEditor->load();

        $url=parse_url($this->file);
        if (($url!==false) && (isset($url['scheme'])) && ($url['scheme']!='file'))
        {
	        global $wpdb;
	        $pid = $wpdb->get_var($wpdb->prepare("select ID from $wpdb->posts where post_type ='attachment' and guid like %s", '%'.$url['path']));
	        if (!empty($pid)) {
		        $meta = wp_get_attachment_metadata($pid);
		        if (isset($meta['width']) && isset($meta['height'])) {
			        $this->size=[
				        'width'=>$meta['width'], 'height'=>$meta['height']
			        ];

			        return true;
		        }
	        }

	        $info=pathinfo($url['path']);
	        $tmpPath='/tmp'.$info['dirname'];
	        @mkdir($tmpPath,0777,true);
	        $this->sourceFile=$tmpPath.'/'.preg_replace('/[^\x20-\x7E]/','', $info['basename']);
	        if (!file_exists($this->sourceFile))
	        {
		        file_put_contents($this->sourceFile,file_get_contents($this->file));
	        }

	        file_put_contents($this->sourceFile,file_get_contents($this->file));

	        return $this->loadFromFile();
        }
        else {
	        return $this->loadFromFile();
        }
    }

    /**
     * Rotates current image counter-clockwise by $angle.
     *
     * @since 3.5.0
     * @access public
     * @abstract
     *
     * @param float $angle
     * @return bool|WP_Error
     */
    public function rotate( $angle )
    {
        $this->storageEditor->rotate($angle);
        return true;
    }

    public function flip( $horz, $vert )
    {
        $this->storageEditor->flip($horz, $vert);
        return true;
    }

    /**
     * Streams current image to browser.
     *
     * @since 3.5.0
     * @access public
     * @abstract
     *
     * @param string $mime_type
     * @return bool|WP_Error
     */
    public function stream( $mime_type = null ) {
        $this->storageEditor->stream($mime_type);
        return true;
    }

    public function resize( $max_w, $max_h, $crop = false )
    {
        $this->storageEditor->resize($max_w, $max_h, $crop);

        if ( ( $this->size['width'] == $max_w ) && ( $this->size['height'] == $max_h ) )
        {
            return true;
        }


        if (!$crop)
        {
            $newSize=sizeToFitSize($this->size['width'],$this->size['height'],$max_w,$max_h);
            list($newWidth,$newHeight)=$newSize;

            return $this->update_size( $newWidth, $newHeight );
        }
        else
        {
            $newSize=sizeToFitSize($max_w,$max_h,$this->size['width'],$this->size['height']);
            $width=$this->size['width'];
            $height=$this->size['height'];
            list($newWidth,$newHeight)=$newSize;

            $x=round(($width/2)-($newWidth/2));
            $y=round(($height/2)-($newHeight/2));

            return $this->crop($x,$y,$newWidth,$newHeight,$max_w,$max_h,false);
        }
    }

    public function multi_resize( $sizes ) {
        $url = parse_url($this->file);

        if (!empty($url['scheme'])) {
            return $this->storageEditor->multi_resize($sizes);
        } else {
            $metadata = array();
            $orig_size = $this->size;

            foreach ($sizes as $size => $size_data) {
                $this->currentSize = $size;

                if (!isset($size_data['width']) && !isset($size_data['height'])) {
                    continue;
                }

                if (!isset($size_data['width'])) {
                    $size_data['width'] = null;
                }
                if (!isset($size_data['height'])) {
                    $size_data['height'] = null;
                }

                if (!isset($size_data['crop'])) {
                    $size_data['crop'] = false;
                }

                $this->resize($size_data['width'], $size_data['height'], $size_data['crop']);
                $resized = $this->getMetadata();
                $metadata[$size] = $resized;

                $this->size = $orig_size;
            }

            return $metadata;
        }
    }

    public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false )
    {
        $this->storageEditor->crop($src_x, $src_y, $src_w, $src_h, $dst_w, $dst_h, $src_abs);

        return $this->update_size($dst_w,$dst_h);
    }

    protected function getMetadata(){
        $mime=($this->isGif) ? 'image/gif' : 'image/jpeg';

        /** This filter is documented in wp-includes/class-wp-image-editor-gd.php */
        $result = array(
            'file'      => wp_basename( $this->file ),
            'width'     => $this->size['width'],
            'height'    => $this->size['height'],
            'mime-type' => $mime
        );

	    return $result;
    }

    public function save( $destfilename = null, $mime_type = null ) {
        $url = parse_url($destfilename);

        if (!empty($url['scheme'])) {
            return $this->storageEditor->save($destfilename,$mime_type);
        } else if (wp_doing_ajax() && isset($_POST['action']) && ($_POST['action'] != 'ilab_perform_crop')) {
	        return $this->storageEditor->save($destfilename,$mime_type);
        } else {
            return $this->getMetadata();
        }
    }
}