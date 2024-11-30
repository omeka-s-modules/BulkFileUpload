<?php
namespace FileSideload\Media\Ingester;

use FileSideload\FileSideload\FileSystem;
use Laminas\Form\Element\Select;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\File\Validator;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;

class Sideload implements IngesterInterface
{
    /**
     * @var string
     */
    protected $directory;

    /**
     * @var string
     */
    protected $userDirectory;

    /**
     * @var bool
     */
    protected $deleteFile;

    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var Validator
     */
    protected $validator;

    /**
     * @var FileSystem
     */
    protected $fileSystem;

    /**
     * @param string $directory
     * @param bool $deleteFile
     * @param TempFileFactory $tempFileFactory
     * @param Validator $validator
     * @param string $userDirectory
     * @param FileSystem $fileSystem
     */
    public function __construct(
        $directory,
        $deleteFile,
        TempFileFactory $tempFileFactory,
        Validator $validator,
        $userDirectory,
        FileSystem $fileSystem
    ) {
        // Only work on the resolved real directory path.
        $this->directory = $directory ? realpath($directory) : '';
        // The user directory is stored as a sub-path of the main directory.
        // The main directory may have been updated.
        $userDir = realpath($this->directory . DIRECTORY_SEPARATOR . $userDirectory);
        $this->userDirectory = $this->directory && mb_strpos($userDirectory, '..') === false && strlen($userDirectory) && is_dir($userDir) && is_readable($userDir)
            ? $userDir
            : $this->directory;
        $this->deleteFile = $deleteFile;
        $this->tempFileFactory = $tempFileFactory;
        $this->validator = $validator;
        $this->fileSystem = $fileSystem;
    }

    public function getLabel()
    {
        return 'Sideload'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    /**
     * Ingest from a URL.
     *
     * Accepts the following non-prefixed keys:
     *
     * + ingest_filename: (required) The filename to ingest.
     * + store_original: (optional, default true) Store the original file?
     *
     * {@inheritDoc}
     */
    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();
        if (!isset($data['ingest_filename'])) {
            $errorStore->addError('ingest_filename', 'No ingest filename specified'); // @translate
            return;
        }

        $isAbsolutePathInsideDir = $this->directory && strpos($data['ingest_filename'], $this->directory) === 0;
        $filepath = $isAbsolutePathInsideDir
            ? $data['ingest_filename']
            : $this->directory . DIRECTORY_SEPARATOR . $data['ingest_filename'];
        $fileinfo = new \SplFileInfo($filepath);
        $realPath = $this->fileSystem->verifyFileOrDir($fileinfo);
        if (null === $realPath) {
            $errorStore->addError('ingest_filename', sprintf(
                'Cannot sideload file "%s". File does not exist or does not have sufficient permissions', // @translate
                $filepath
            ));
            return;
        }

        $tempFile = $this->tempFileFactory->build();
        $tempFile->setSourceName($data['ingest_filename']);

        // Copy the file to a temp path, so it is managed as a real temp file (#14).
        $copySuccess = copy($realPath, $tempFile->getTempPath());
        if (!$copySuccess) {
            $errorStore->addError('ingest_filename', sprintf(
                'Cannot sideload file "%s". File cannot be copied to the temp directory', // @translate
                $filepath
            ));
            return;
        }

        if (!$this->validator->validate($tempFile, $errorStore)) {
            return;
        }

        if (!array_key_exists('o:source', $data)) {
            $media->setSource($data['ingest_filename']);
        }
        $storeOriginal = (!isset($data['store_original']) || $data['store_original']);
        $tempFile->mediaIngestFile($media, $request, $errorStore, $storeOriginal, true, true, true);

        if ($this->deleteFile) {
            unlink($realPath);
        }
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        $listFiles = $this->fileSystem->listFiles($this->userDirectory, true);
        $hasMoreFiles = $this->fileSystem->hasMoreFiles();

        // When the user dir is different from the main dir, prepend the main
        // dir path to simplify hydration.
        if ($this->userDirectory !== $this->directory) {
            $prependPath = mb_substr($this->userDirectory, mb_strlen($this->directory) + 1) . DIRECTORY_SEPARATOR;
            $length = mb_strlen($prependPath);
            $result = [];
            foreach ($listFiles as $file) {
                $result[$file] = mb_substr($file, $length);
            }
            $listFiles = $result;
        }

        $isEmptyFiles = !count($listFiles);
        if ($isEmptyFiles) {
            $emptyOption = 'No file: add files in the directory or check its path'; // @translate
        } elseif ($hasMoreFiles) {
            $emptyOption = 'Select a file to sideload… (only first ones are listed)'; // @translate
        } else {
            $emptyOption = 'Select a file to sideload…'; // @translate
        }

        $select = new Select('o:media[__index__][ingest_filename]');
        $select->setOptions([
            'label' => 'File', // @translate
            'value_options' => $listFiles,
            'empty_option' => '',
        ]);
        $select->setAttributes([
            'id' => 'media-sideload-ingest-filename-__index__',
            'required' => true,
            'class' => 'media-sideload-select chosen-select',
            'data-placeholder' => $emptyOption,
        ]);
        return $view->formRow($select)
            // Ideally should be in a js file of the module or Omeka.
            . '<script>$(".media-sideload-select").chosen(window.chosenOptions);</script>';
    }
}
