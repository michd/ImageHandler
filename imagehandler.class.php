<?php
/**
  * This class eases handling image uploads a lot.
  * Firstly, it checks if the file is a valid image, that is no larger than a 
  * maximum you can set in the constants. Then it checks if the image file is 
  * actually valid, by loading it into an image resource.
  *
  * Then, you can resize said image. The image that will contain the resized 
  *	version gets its own ID. Resizing can be done in 3 ways: absolute stretch, 
  * shrink but keep aspect ratio, or crop center to resize to absolute 
  * dimensions. Then, files can be saved in either JPG, GIF or PNG. For JPG, 
  * you can specify a quality of 1-100.
  *
  * When working with many different versions of the image, the class stores the 
  * image that's not being worked with away as a full-quality PNG, in a 
  * temporary folder. These temporary files are deleted when the instance is 
  * unset or the script terminates, so it doesn't leave any waste behind.
  *	
  *
  * AR = Aspect Ratio
  */
  class ImageHandler
  {
    /**
    * Identifier for originnal image, when no custom one was made
    */
    const ORIGINAL_IMAGE = 'original_image';

    /**
    * Mode to stretch any image size in its enirity to a new size
    */
    const RESIZE_STRETCH = 0; 
    /**
    * Mode to shrink and image if necessary, keep the AR
    */
    const RESIZE_SHRINK_KEEP_ASPECT = 1;
    /**
    * Mode to resize by cropping to the right AR, then resizing
    */
    const RESIZE_CROP_CENTER = 2; 

    const TYPE_JPEG = 'jpg'; 
    const TYPE_GIF = 'gif'; 
    const TYPE_PNG = 'png'; 
    /**
    * All of the above (for validating input)
    */
    const TYPE_ALL = 'all';

    /**
    * Default quality setting for saving to JPEG
    */
    const DEFAULT_JPEG_QUALITY = 85;

    /**
    * The maximum input file size in bytes. 
    * Make sure to check post_max_size, as well as upload_max_filesize
    * in php.ini as well for this.
    */
    const MAX_INPUT_FILESIZE = 5242880; // 5MB 
    /**
    * Absolute path to the directory where working files will be stored
    * The files stored here will be deleted on termination of the script.
    */   
    const TEMP_FOLDER = '/temp/';


    // Working data
    /**
    * image resource, will hold the uploaded image when correctly initialized
    */
    protected $OriginalImage; 
    /**
    * Image resource, the one that's currently being worked with
    */
    protected $CurrentImage; 
    /**
    * String, ID thaty's been given for the currently loaded image
    */
    protected $CurrentImageID = '';
    /**
    * Array, with information about the different derivate images made
    */
    protected $DerivativeImages = array(); 
    /**
    * Array with complete paths to temporary files, to be cleaned up
    */
    protected $TempFiles = array();

    // Status information
    /**
    * boolean, whether an image was properly loaded with the constructor
    */
    protected $Initialized = FALSE;
    /**
    * Technical information about what went wrong (intended for dev only)
    */
    public $DebugErrorMessage = '';
    /**
    * Basic information on what went wrong (public view)
    */
    public $SafeErrorMessage = ''; 		
    

  /**
    * Set up a new Image upload handler, and checks for some errors
    *
    * This sets up a new image resource and will return errors if no image
      can be created using the implied image type.
      If the image is too large for the available memory to handle, 
      it will err as well.
    *
    * @param array $fileArray The $_FILES['name_of_file'] array from the upload 
      script
    * @param array $allowedFormats an array with image formats 
      (from TYPE_ constants), or the constant TYPE_ALL to indicate all of the 
      availeble ones (jpg, gif, png)
    */
    public function __construct ( $fileArray, $allowedFormats = self::TYPE_ALL )
    {
      if(is_array($fileArray) 
        && array_key_exists('name', $fileArray)
        && array_key_exists('type', $fileArray)
        && array_key_exists('size', $fileArray)
        && array_key_exists('tmp_name', $fileArray)
      )
      { //the file array passed looks valid
        $valid_allowedformats = TRUE;
        //Check the $allowedFormats parameter.
        if(is_array($allowedFormats))
        {
          foreach($allowedFormats as $curformat)
          {
            if(!in_array($curformat, 
              array(self::TYPE_JPEG, self::TYPE_GIF, self::TYPE_PNG))
            )
            {
              $valid_allowedformats = FALSE;
              break;
            }
          }
        }
        else
        {
          if(!in_array(
            $allowedFormats, 
            array(self::TYPE_JPEG, self::TYPE_GIF, self::TYPE_PNG, 
              self::TYPE_ALL)
            )
          )
          {
            $valid_allowedformats = FALSE;
          }
        }
        if($valid_allowedformats)
        {
          //now we know the given file formats are valid, 
          //let's form them into separate variables for easy boolean access
          $jpg_allowed = (
            (
              is_array($allowedFormats) 
              && in_array(self::TYPE_JPEG, $allowedFormats)
            )
            || in_array($allowedFormats, array(self::TYPE_JPEG, self::TYPE_ALL))
          );

          $gif_allowed = (
            (
              is_array($allowedFormats) 
              && in_array(self::TYPE_GIF, $allowedFormats)
            ) 
            || in_array($allowedFormats, array(self::TYPE_GIF, self::TYPE_ALL))
          );

          $png_allowed = (
            (
              is_array($allowedFormats) 
              && in_array(self::TYPE_PNG, $allowedFormats)
            ) 
            || in_array($allowedFormats, array(self::TYPE_PNG, self::TYPE_ALL))
          );

          //you can see why we wanted a shorted version.
          
          if($fileArray['size'] <= self::MAX_INPUT_FILESIZE)
          {
            //more checks
            $uploaded_file_type_allowed = FALSE; //init
            switch($fileArray['type'])
            {
              case 'image/jpeg':
              case 'image/pjpeg':
                $uploaded_file_type_allowed = $jpg_allowed;
                break;
              case 'image/gif':
                $uploaded_file_type_allowed = $gif_allowed;
                break;
              case 'image/png':
                $uploaded_file_type_allowed = $png_allowed;
                break;
              default:
                $uploaded_file_type_allowed = FALSE;
                break;
            }
            if($uploaded_file_type_allowed)
            {
              //now, try to fetch an image resource from what we have
              //ANOTHER SWITCH
              switch($fileArray['type'])
              {
                case 'image/jpeg':
                case 'image/pjpeg':
                  $this->OriginalImage = imagecreatefromjpeg(
                    $fileArray['tmp_name']);
                  break;
                case 'image/gif':
                  $this->OriginalImage = imagecreatefromgif(
                    $fileArray['tmp_name']);
                  break;
                case 'image/png':
                  $this->OriginalImage = imagecreatefrompng(
                    $fileArray['tmp_name']);
                  break;
                default:
                  $this->OriginalImage = FALSE;
                  break;
              }
              if($this->OriginalImage !== FALSE)
              {
                $this->CurrentImage = $this->OriginalImage;
                $this->CurrentImageID = self::ORIGINAL_IMAGE;
                $this->Initialized = TRUE;
              }
              else
              {
                $this->DebugErrorMessage = 
                  '(' . __METHOD__ . ') failed creating an image resource from '
                  . ' the file';
                $this->SafeErrorMessage = 'Something went wrong while trying '
                  . 'to parse the uploaded image.';
              }
            }
            else
            {
              $this->DebugErrorMessage = 
                '(' . __METHOD__ . ') invalid file type uploaded. $fileArray'
                . '[\'type\']: ' . var_export($fileArray['type'], TRUE) 
                . ' | $allowedFormats: ' . var_export($allowedFormats, TRUE);
              $this->SafeErrorMessage = 'You tried to upload an image of a type'
                . ' that is not allowed.';
            }
          }
          else
          {
            $this->DebugErrorMessage = 
              '(' . __METHOD__ . ') filesize(' . $fileArray['size'] . ') > '
              . 'MAX_INPUT_FILESIZE (' . self::MAX_INPUT_FILESIZE . ')';
            $this->SafeErrorMessage = 'The image you uploaded was too big to '
              . 'process. Please reduce its filesize so it\'s less '
              . 'than 5MB.';
          }
        }
        else
        {
          $this->DebugErrorMessage = '('. __METHOD__ . ') invalid '
            . '$allowedFormats: ' . var_export($allowedFormats);
          $this->SafeErrorMessage = 'Something went wrong while initializing '
            . 'the image resizer.';
        }
      }
      else
      {				
        $this->DebugErrorMessage = '(' . __METHOD__ . ') $fileArray invalid. '
          . 'dump: ' . "<br />\n" . var_export($fileArray);
        $this->SafeErrorMessage = 'Something went wrong while initializing the '
          . 'image resizer.';
      }
    }

  /**
    * Check whether there are any temporary files left in the TempFiles array,
      and thus on the disk, and removes any that are found.
    */
    public function __destruct()
    {
      if(!empty($this->TempFiles))
      {
        foreach($this->TempFiles as $path)
        {
          unlink($path);
        }
      }
    }

  /**
    *	Resize an image with a predefined resizing method
    *
    * The image needs to be either an ID used before (and /) or a new image ID.
    *
    * @param string $imageID identifier of the image being worked on; 
      can be used to create a new one
    * @param string $resizeMode The method to resize with, can be 
      ImageHandler::(RESIZE_STRETCH, RESIZE_SHRINK_KEEP_ASPECT, 
      RESIZE_CROP_CENTER)
    * @param int $width The absolute or maximum output width 
      (depending on resizeMode)
    * @param int $height The absolute or maximum output height 
      (depending on resizeMode)
    * @return bool True on success, False on error.
    */
    public function Resize ( $imageID, $resizeMode, $width, $height )
    {
      if($this->Initialized)
      {
        if(in_array($resizeMode, 
          array(
            self::RESIZE_STRETCH, self::RESIZE_SHRINK_KEEP_ASPECT, 
            self::RESIZE_CROP_CENTER
          )
        ))
        {
          if(is_numeric($width) && is_numeric($height))
          {
            if($imageID != $this->CurrentImageID 
              && $imageID != self::ORIGINAL_IMAGE
            )
            {
              if($this->StoreTemp())
              {
                unset($this->CurrentImage);
                $this->LoadStored($imageID);
              }
              else
              {
                $this->DebugErrorMessage = $this->DebugErrorMessage 
                  . '(' . __METHOD__ . ') Failed to store previous image away.';
                $this->SafeErrorMessage = 
                  'Something went wrong while trying to resize this image.';
                return FALSE;
              }
            }
            $this->CurrentImageID = $imageID;
            $input_width = imagesx($this->OriginalImage);
            $input_height = imagesy($this->OriginalImage);
            switch($resizeMode)
            {
              case self::RESIZE_STRETCH:
                //the resulting image will have the absolute size given by width
                $this->CurrentImage = imagecreatetruecolor($width, $height);
                if($this->CurrentImage !== FALSE)
                {
                  if(imagecopyresampled(
                    $this->CurrentImage, $this->OriginalImage, 
                    0, 0, 0, 0, $width, $height, $input_width, $input_height)
                  )
                  {
                    return TRUE;
                  }
                  else
                  {
                    $this->DebugErrorMessage = 
                      '(' .__METHOD__ . ') imagecopyresampled failed. '
                      . 'arguments dump: ' . var_export(func_get_args(), TRUE) 
                      . '| ' . var_export($this->CurrentImage, TRUE);
                    $this->SafeErrorMessage = 'Something went wrong while '
                      . 'trying to resize the image.';
                    return FALSE;
                  }
                }
                else
                {
                  $this->DebugErrorMessage = 
                    '(' . __METHOD__ . ') failed to set up new image '
                    . '(possible memory overload) param dump: ' 
                    . var_export(func_get_args(), TRUE);
                  $this->SafeErrorMessage = 'Something went wrong while trying '
                    . 'to resize the image.';
                  return FALSE;
                }
                break;
              case self::RESIZE_SHRINK_KEEP_ASPECT:
                if($input_width > $width || $input_height > $height)
                {
                  if(($input_width / $input_height) < 1)
                  {
                    $new_height = $height;
                    $new_width = floor(
                      ($input_width / ($input_height / $height))
                    );
                  }
                  else
                  {
                    $new_width = $width;
                    $new_height = floor(
                      ($input_height / ($input_width / $width))
                    );
                  }
                }
                else
                {
                  $new_width = $input_width;
                  $new_height = $input_height;
                }
                $this->CurrentImage = imagecreatetruecolor(
                  $new_width, $new_height
                );
                if($this->CurrentImage !== FALSE)
                {
                  if(imagecopyresampled(
                    $this->CurrentImage, $this->OriginalImage, 
                    0, 0, 0, 0, $new_width, $new_height, 
                    $input_width, $input_height
                  ))
                  {
                    return TRUE;
                  }
                  else
                  {
                    $this->DebugErrorMessage = 
                      '(' .__METHOD__ . ') imagecopyresampled failed. arguments'
                      . ' dump: ' . var_export(func_get_args(), TRUE);
                    $this->SafeErrorMessage = 'Something went wrong while '
                      . 'trying to resize the image.';
                    return FALSE;
                  }
                }
                else
                {
                  $this->DebugErrorMessage = '(' . __METHOD__ . ') failed to '
                    . 'set up new image (possible memory overload) param dump: ' 
                    . var_export(func_get_args(), TRUE);
                  $this->SafeErrorMessage = 'Something went wrong while trying '
                    . 'to resize the image.';
                  return FALSE;
                }
                break;
              case self::RESIZE_CROP_CENTER:
                $this->CurrentImage = imagecreatetruecolor($width, $height);
                if($this->CurrentImage !== FALSE)
                {
                  $o_ar = $width / $height; //output aspect ratio
                  $i_ar = $input_width / $input_height; //input aspect ratio
                  $height_match = (
                    ($o_ar <= 1 && $i_ar >= 1) 
                    || ($o_ar > 1 && $i_ar > 1 && $i_ar >= $o_ar)
                  );
                  $width_match = (
                    ($o_ar >= 1 && $i_ar <= 1) 
                    || ($o_ar < 1 && $i_ar < 1 && $o_ar >= $i_ar)
                  );
                  $grab_height = 0;
                  $grab_width = 0;
                  if($height_match)
                  {
                    $grab_height = $input_height;
                    if($width_match)
                    {
                      $grab_width = $input_width;
                    }
                    else
                    {
                      $grab_width = floor($o_ar * $input_height);
                    }
                  }
                  else
                  { //width match	(always need to have one of them)
                      $grab_width = $input_width;
                      $grab_height = floor((1 / $o_ar) * $input_width);
                  }
                  if(imagecopyresampled(
                      $this->CurrentImage,
                      $this->OriginalImage,
                      0,
                      0,
                      floor(($input_width - $grab_width) / 2),
                      floor(($input_height - $grab_height) / 2),
                      $width,
                      $height,
                      $grab_width,
                      $grab_height
                    )
                  )
                  {
                    return TRUE;
                  }
                  else
                  {
                    $this->DebugErrorMessage = '(' .__METHOD__ . ') ' 
                      .  'imagecopyresampled failed. arguments dump: ' 
                      . var_export(func_get_args(), TRUE);
                    $this->SafeErrorMessage = 'Something went wrong while '
                      . 'trying to resize the image.';
                    return FALSE;
                  }
                }
                else
                {
                  $this->DebugErrorMessage = '(' . __METHOD__ . ') '
                    . 'failed to set up new image (possible memory overload) '
                    . 'param dump: ' . var_export(func_get_args(), TRUE);
                  $this->SafeErrorMessage = 'Something went wrong while trying '
                    . 'to resize the image.';
                  return FALSE;
                }
                break;
              default:
                //nothing, but yeah.
                break;
            }
          }
          else
          {
            $this->DebugErrorMessage = '(' . __METHOD__ . ') non-numeric '
              . '$width and/or $height. $width: ' . var_export($width, TRUE) 
              . ', $height: ' . var_export($height, TRUE);
            $this->SafeErrorMessage = 'Something went wrong while trying to '
              . 'resize the image.';
            return FALSE;
          }
        }
        else
        {					
          $this->DebugErrorMessage = '(' . __METHOD__ . ') $resizeMode value '
            . 'not defined, was ' . $resizeMode;
          $this->SafeErrorMessage = 'Something went wrong while trying to '
            . 'resize the image.';
          return FALSE;
        }
      }
      else
      {
        $this->DebugErrorMessage = '(' . __METHOD__ . ') Not initialized';
        $this->SafeErrorMessage = 'Something went wrong while trying to resize '
          . 'the image.';
        return FALSE;
      }
    }

  /**
    *	Save image given by imageID to the disk, in a format of choice
    *
    * @param string $imageID identifier of the image we want to save
    * @param string $savePath Directory to save the image in
    * @param string $baseName Desired filename without extension can use the 
      following characters: [a-zA-Z0-9\-_]
    * @param string $type The image type we want to save as 
      (one of ImageHandler::(TYPE_JPEG, TYPE_GIF, TYPE_PNG))
    * @param int $jpgQuality In case type = jpg, this sets the quality 
      for said jpg.
    * @return bool True on success, False on error
    */
    public function Save ( $imageID, $savePath, $baseName, $type, 
      $jpgQuality = self::DEFAULT_JPEG_QUALITY )
    {
      if($this->Initialized)
      {
        if(file_exists($savePath) && is_dir($savePath))
        {
          if(preg_match("/^([a-zA-Z0-9\-_]+)$/", $baseName))
          {
            if(in_array($type, 
              array(self::TYPE_JPEG, self::TYPE_GIF, self::TYPE_PNG)
            ))
            {
              $image_loaded = FALSE;
              if($imageID != $this->CurrentImageID 
                && $imageID != self::ORIGINAL_IMAGE
              )
              {
                if($this->StoreTemp())
                {
                  unset($this->CurrentImage);
                }
                else
                {
                  $this->DebugErrorMessage = $this->DebugErrorMessage 
                    . '(' . __METHOD__ . ') Failed to store previous image '
                    . 'away.';
                  $this->SafeErrorMessage = 'Something went wrong while trying '
                    . 'to save this image.';
                  return FALSE;
                }
                $image_loaded = $this->LoadStored($imageID);
              }
              else
              {
                $image_loaded = TRUE;
              }
              if($image_loaded)
              {
                $this->CurrentImageID = $imageID;
                switch($type)
                {
                  case self::TYPE_JPEG:
                    if(imagejpeg($this->CurrentImage, 
                      $savePath . $baseName . '.jpg', $jpgQuality)
                    )
                    {											
                      return TRUE;
                    }
                    else
                    {
                      $this->DebugErrorMessage = '(' . __METHOD__ . ') '
                        . 'imagejpeg failed.';
                      $this->SafeErrorMessage = 'Something went wrong while '
                        . 'trying to save this image as a JPG.';
                      return FALSE;
                    }
                    break;
                  case self::TYPE_GIF:
                    if(imagegif($this->CurrentImage, 
                      $savePath . $baseName . '.gif')
                    )
                    {
                      return TRUE;
                    }
                    else
                    {
                      $this->DebugErrorMessage = '(' . __METHOD__ . ') '
                        . 'imagegif failed.';
                      $this->SafeErrorMessage = 'Something went wrong while '
                        . 'trying to save this image as a GIF.';
                      return FALSE;
                    }
                    break;
                  case self::TYPE_PNG:
                    if(imagepng($this->CurrentImage, 
                      $savePath . $baseName . '.png')
                    )
                    {
                      return TRUE;
                    }
                    else
                    {
                      $this->DebugErrorMessage = '(' . __METHOD__ . ') '
                        . 'imagepng failed.';
                      $this->SafeErrorMessage = 'Something went wrong while '
                        . 'trying to save this image as a PNG.';
                      return FALSE;
                    }
                    break;
                  default:
                      //Nope
                    break;
                }
              }
              else
              {
                $this->DebugErrorMessage =  $this->DebugErrorMessage 
                  . '(' . __METHOD__ . ') no image ' . $imageID . ' found.';
                $this->SafeErrorMessage = 'Something went wrong while trying '
                  . 'to save an image.';
                return FALSE;
              }
            }
            else
            {
              $this->DebugErrorMessage ='(' . __METHOD__ . ') invalid save '
                . 'type: ' . $type;
              $this->SafeErrorMessage = 'Invalid image type to save to.';
              return FALSE;
            }
          }
          else
          {
            $this->DebugErrorMessage = '(' . __METHOD__ . ') illegal $baseName,'
              . ' characters allowed: [a-zA-Z0-9\-_], given: ' . $baseName;
            $this->SafeErrorMessage = 'Invalid file name to save the image to.';
            return FALSE;
          }
        }
        else
        {
          $this->DebugErrorMessage = '(' . __METHOD__ . ') invalid savePath: ' 
            . $savePath;
          $this->SafeErrorMessage = 'Invalid saving location.';
          return FALSE;
        }
      }
      else
      {
        $this->DebugErrorMessage = '(' . __METHOD__ . ') Not initialized';
        $this->SafeErrorMessage = 'Something went wrong while trying to save '
          . 'the image.';
        return FALSE;
      }
    }

  /**
    *	Get simple array with width and height of the originally loaded image
    *
    * @return array ([0] => width, [1] => height)
    */
    public function OriginalSize ( )
    {
      if($this->Initialized)
      {
        return array(imagesx($this->OriginalImage), 
          imagesy($this->OriginalImage));
      }
      else
      {
        return FALSE;
      }
    }


  /**
    *	This private function stores the CurrentImage away as a PNG, 
      in a temporary folder, so it can be reloaded later.
    * This functionality is to optimize memory usage; it makes sure no more 
      than 2 image resources are loaded simultaneously.
    */
    private function StoreTemp ( )
    {
      if($this->Initialized)
      {
        if($this->CurrentImageID != self::ORIGINAL_IMAGE)
        {
          if(is_dir(self::TEMP_FOLDER))
          {
            $new_filename = self::TEMP_FOLDER . 'tmp' . md5(microtime()) 
              . '.png';
            if(imagepng($this->CurrentImage, $new_filename, 0))
            {
              $this->DerivativeImages[$this->CurrentImageID] = $new_filename;
              $this->TempFiles[] = $new_filename;
              return TRUE;
            }
            else
            {
              $this->DebugErrorMessage = '(' . __METHOD__ . ') imagepng failed';
              return FALSE;
            }
          }
          else
          {
            $this->DebugErrorMessage = '(' . __METHOD__ . ') ' 
            . self::TEMP_FOLDER  . ' is not a directory';
            return FALSE;
          }
        }
        else
        {
          return TRUE;
        }
      }
      else
      {
        $this->DebugErrorMessage = '(' . __METHOD__ . ') not initialized';
        return FALSE;
      }
    }

  /**
    *	This private method reloads a previously stored image again.
    */
    private function LoadStored ( $imageID )
    {
      if(isset($this->DerivativeImages[$imageID]))
      {
        if(file_exists($this->DerivativeImages[$imageID]))
        {
          $this->CurrentImage = 
            imagecreatefrompng($this->DerivativeImages[$imageID]);
          if($this->CurrentImage !== FALSE)
          {
            return TRUE;
          }
          else
          {
            $this->DebugErrorMessage = '(' . __METHOD__ . ') '
              . 'imagecreatefrompng failed';
            return FALSE;
          }
        }
        else
        {
          $this->DebugErrorMessage = '(' . __METHOD__ . ') '
            . 'file not found (' . $this->DerivativeImages[$imageID] . ')';
          return FALSE;
        }
      }
      else if($imageID == self::ORIGINAL_IMAGE)
      {
        $this->CurrentImage = &$this->OriginalImage;
        return TRUE;
      }
      else
      {
        unset($this->CurrentImage);
        $this->DebugErrorMessage = '(' . __METHOD__ . ') $imageID not set '
          . '(' . $imageID . ')';
        return FALSE;
      }
    }

  
  /**
    *	Returns true when the instance was succesfully initialized 
      (proper image was fed)
    *
    * @return bool
    */
    public function IsInitialized ( )
    {
      return $this->Initialized;
    }	 
  }
?>
