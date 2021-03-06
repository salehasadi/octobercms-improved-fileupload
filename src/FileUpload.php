<?php
namespace Patroklo\FormWidgets;

use ApplicationException;
use Exception;
use Input;
use Request;
use Response;
use System\Models\File;
use ValidationException;
use Validator;


/**
 * Class FileUpload
 * @package Patroklo\FormWidgets
 *
 * Improves the file validation for the file uploads
 * getting the rules from the YAML configuration file or the model's attribute rules.
 * 
 * Supported options:
 * - mode: image-single, image-multi, file-single, file-multi, image-multi-big
 * - upload-label: Add file
 * - empty-label: No file uploaded
 */
class FileUpload extends \Backend\FormWidgets\FileUpload
{

    /** @var String Validation rules that will be used when uploading a file */
    public $rules;

    public function init()
    {

        // New validator that limits the max number of files that can be uploaded into this attribute
        Validator::extend('maxFiles', function ($attribute, $value, $parameters, $validator)
        {
            // Maybe in the future we will upload more than one file
            // at a time, so we will make this validator check the
            // number of files that already exist plus the uploaded ones
            $uploadedFile = Input::file($attribute);

            $num = 1;

            if (is_array($uploadedFile))
            {
                $num = count($uploadedFile);
            }

            return (count($this->getFileList()) + $num) <= $parameters[0];
        });


        parent::init();

    }


    /**
     * Checks the current request to see if it is a postback containing a file upload
     * for this particular widget.
     */
    protected function checkUploadPostback()
    {
        if (!($uniqueId = Request::header('X-OCTOBER-FILEUPLOAD')) || $uniqueId != $this->getId())
        {
            return;
        }

        // Adds the path to the lang files
        app('translator')->getLoader()->
        addNamespace('patroklo.formwidgets', base_path('vendor/patroklo/octobercms-improved-fileupload/src/lang'));

        try
        {
            if (!Input::hasFile('file_data'))
            {
                throw new ApplicationException('File missing from request');
            }

            $uploadedFile = Input::file('file_data');

            // Load the $this->rules parameter
            $this->setValidationRules();

            $validation = Validator::make(
                ['file_data' => $uploadedFile],
                ['file_data' => $this->rules],
                ['max_files' => e(trans('patroklo.formwidgets::messages.max_files'))]
            );

            if ($validation->fails())
            {
                throw new ValidationException($validation);
            }

            if (!$uploadedFile->isValid())
            {
                throw new ApplicationException('File is not valid');
            }

            $fileRelation = $this->getRelationObject();

            $file = new File();
            $file->data = $uploadedFile;
            $file->is_public = $fileRelation->isPublic();
            $file->save();

            $fileRelation->add($file, $this->sessionKey);

            $file = $this->decorateFileAttributes($file);

            $result = [
                'id' => $file->id,
                'thumb' => $file->thumbUrl,
                'path' => $file->pathUrl
            ];

            Response::json($result, 200)->send();

        } catch (Exception $ex)
        {
            Response::json($ex->getMessage(), 400)->send();
        }

        exit;
    }

    /**
     * Try to get the upload validation rules.
     * First it will try to load the rules declared in the YAML configuration file
     * If there aren't it will try to get the rules declared in the "fileUploadRules" model's method
     * Lastly, it will add the default rules from the original FileUpload form widget.
     *
     * @return array
     */
    protected function setValidationRules()
    {

        $this->fillFromConfig(['rules']);


        if (is_null($this->rules) && method_exists($this->model, 'fileUploadRules'))
        {

            // The rules will be stored in a different method than the form rules
            $modelRules = $this->model->fileUploadRules();

            if (is_array($modelRules) && array_key_exists($this->fieldName, $modelRules))
            {
                $this->rules = $modelRules[$this->fieldName];
            }
        }

        if (is_null($this->rules))
        {
            $this->rules = ['max:' . File::getMaxFilesize()];
            if ($fileTypes = $this->getAcceptedFileTypes())
            {
                $this->rules[] = 'extensions:' . $fileTypes;
            }

            if ($this->mimeTypes)
            {
                $this->rules[] = 'mimes:' . $this->mimeTypes;
            }
        }

        return $this->rules;
    }

    /**
     * Guess the package path for the called class.
     *
     * Since we want to use the same code than the
     * original FileUpload, we'll need to change this method
     * to load the default package instead of this one.
     *
     * @param string $suffix An extra path to attach to the end
     * @param bool $isPublic Returns public path instead of an absolute one
     * @return string
     */
    public function getViewPath($fileName, $viewPath = NULL)
    {
        $returnPaths = [];

        $class = get_called_class();

        $returnPaths[] = $this->guessViewPathFrom($class, '/partials', FALSE);

        $classParent = get_parent_class($this);

        $returnPaths[] = $this->guessViewPathFrom($classParent, '/partials', FALSE);

        return parent::getViewPath($fileName, $returnPaths);
    }


    /**
     * Guess the package path for the called class.
     * @param string $suffix An extra path to attach to the end
     * @param bool $isPublic Returns public path instead of an absolute one
     * @return string
     */
    public function guessViewPath($suffix = '', $isPublic = FALSE)
    {
        $class = get_parent_class($this);

        return $this->guessViewPathFrom($class, $suffix, $isPublic);
    }

}
