<?php
namespace FileSideload\Form;

use Laminas\Form\Form;
use Laminas\Validator\Callback;

class ConfigForm extends Form
{
    public function init()
    {
        $this->add([
            'type' => 'text',
            'name' => 'directory',
            'options' => [
                'label' => 'Sideload directory', // @translate
                'info' => 'Enter the absolute path to the directory where files to be sideloaded will be added. The directory can be anywhere on your server. The users may choose any directory inside it as root.', // @translate
            ],
            'attributes' => [
                'required' => true,
                'id' => 'directory',
            ],
        ]);
        $this->add([
            'type' => 'checkbox',
            'name' => 'delete_file',
            'options' => [
                'label' => 'Delete sideloaded file?', // @translate
                'info' => 'Do you want to delete a file from the sideload directory after it has been sideloaded? If so, the directory must be server-writable.', // @translate
                'use_hidden_element' => true,
                'checked_value' => 'yes',
                'unchecked_value' => 'no',
            ],
            'attributes' => [
                'id' => 'delete-file',
            ],
        ]);
        $this->add([
            'type' => 'number',
            'name' => 'filesideload_max_files',
            'options' => [
                'label' => 'Maximum number of files to list', // @translate
            ],
            'attributes' => [
                'id' => 'filesideload-max-files',
                'min' => 0,
            ],
        ]);
        $this->add([
            'type' => 'number',
            'name' => 'filesideload_max_directories',
            'options' => [
                'label' => 'Maximum number of directories to list', // @translate
            ],
            'attributes' => [
                'id' => 'filesideload-max-directories',
                'min' => 0,
            ],
        ]);
        $this->add([
            'type' => 'number',
            'name' => 'filesideload_directory_depth_user',
            'options' => [
                'label' => 'Max directory depth for user setting', // @translate
            ],
            'attributes' => [
                'id' => 'filesideload-directory-depth-user',
                'min' => 0,
            ],
        ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'directory',
            'required' => true,
            'filters' => [
                ['name' => 'StringTrim'],
            ],
            'validators' => [
                [
                    'name' => 'Callback',
                    'options' => [
                        'messages' => [
                            Callback::INVALID_VALUE => 'The provided sideload directory is not a directory or does not have sufficient permissions.', // @translate
                        ],
                        'callback' => [$this, 'directoryIsValid'],
                    ],
                ],
            ],
        ]);
    }

    public function directoryIsValid($dir, $context)
    {
        $dir = new \SplFileInfo($dir);
        $valid = $dir->isDir() && $dir->isExecutable() && $dir->isReadable();
        if (isset($context['delete_file']) && 'yes' === $context['delete_file']) {
            $valid = $valid && $dir->isWritable();
        }
        return $valid;
    }
}
