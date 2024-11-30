<?php

namespace FileSideload\Media\Ingester;

use FileSideload\FileSideload\FileSystem;
use Laminas\Form\Element;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\File\Validator;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\Message;

class SideloadDir implements IngesterInterface
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
        return 'Sideload directory'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    /**
     * Ingest from a directory on the server.
     *
     * Accepts the following non-prefixed keys:
     * - ingest_directory: (required) The source directory where the file to ingest is.
     * - ingest_filename: (required) The filename to ingest.
     * - ingest_directory_recursively: (optional, default false) Ingest directory recursively?
     * - store_original: (optional, default true) Store the original file?
     *
     * {@inheritDoc}
     */
    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();

        // Checks are already done during pre-hydration, but another check is
        // needed when the ingester is called directly.

        if (!isset($data['ingest_directory'])) {
            $errorStore->addError('ingest_directory', 'No ingest directory specified'); // @translate
            return;
        }

        // This is the checked full real path inside the main directory.
        $realIngestDirectory = $this->checkIngestDir((string) $data['ingest_directory'], $errorStore);
        if (is_null($realIngestDirectory)) {
            return;
        }

        if (!isset($data['ingest_filename'])) {
            $errorStore->addError('ingest_filename', 'No ingest filename specified'); // @translate
            return;
        }

        // The check is done against the directory, but the file is relative to the
        // main directory.
        $isAbsolutePathInsideDir = strpos((string) $data['ingest_filename'], $realIngestDirectory) === 0;
        $filepath = $isAbsolutePathInsideDir
            ? $data['ingest_filename']
            : $this->directory . DIRECTORY_SEPARATOR . $data['ingest_filename'];
        $fileinfo = new \SplFileInfo($filepath);
        $realPath = $this->fileSystem->verifyFileOrDir($fileinfo);
        if (is_null($realPath)) {
            $errorStore->addError('ingest_filename', new Message(
                'Cannot sideload file "%s". File does not exist or is not inside main directory or does not have sufficient permissions', // @translate
                $data['ingest_filename']
            ));
            return;
        }

        // When recursive is not set, check if the file is a root file.
        if (empty($data['ingest_directory_recursively']) && pathinfo($realPath, PATHINFO_DIRNAME) !== $realIngestDirectory) {
            $errorStore->addError('ingest_filename', new Message(
                'Cannot sideload file "%s": ingestion of directory "%s" is not set recursive', // @translate
                $data['ingest_filename'],
                $data['ingest_directory']
            ));
        }

        // Processing ingestion.

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

        if (!$this->deleteFile) {
            return;
        }
        unlink($realPath);

        // Check if this is the last file of the ingest directory.
        if (!$this->fileSystem->dirHasNoFileAndIsRemovable($realIngestDirectory)) {
            return;
        }

        // The ingest directory may have empty directories, so recursive remove it.
        $this->rrmdir($realIngestDirectory);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        $listDirs = $this->fileSystem->listDirs($this->userDirectory);
        $hasMoreDirectories = $this->fileSystem->hasMoreDirectories();

        // When the user dir is different from the main dir, prepend the main
        // dir path to simplify hydration.
        if ($this->userDirectory !== $this->directory) {
            $prependPath = mb_substr($this->userDirectory, mb_strlen($this->directory) + 1) .  DIRECTORY_SEPARATOR;
            $length = mb_strlen($prependPath);
            $result = [];
            foreach ($listDirs as $dir) {
                $result[$dir] = mb_substr($dir, $length);
            }
            $listDirs = $result;
        }

        $isEmptyDirs = !count($listDirs);
        if ($isEmptyDirs) {
            $emptyOptionDir = 'No directory: add directories in the directory or check its path'; // @translate
        } elseif ($hasMoreDirectories) {
            $emptyOptionDir = 'Select a directory to sideload all files inside… (only first ones are listed)'; // @translate
        } else {
            $emptyOptionDir = 'Select a directory to sideload all files inside…'; // @translate
        }

        $select = new Element\Select('o:media[__index__][ingest_directory]');
        $select
            ->setOptions([
                'label' => 'Directory', // @translate
                'info' => 'Directories and files without sufficient permissions are skipped.', // @translate
                'value_options' => $listDirs,
                'empty_option' => '',
            ])
            ->setAttributes([
                'id' => 'media-sideload-ingest-directory-__index__',
                'required' => true,
                'class' => 'media-sideload-select chosen-select',
                'data-placeholder' => $emptyOptionDir,
            ]);

        $recursive = new Element\Checkbox('o:media[__index__][ingest_directory_recursively]');
        $recursive
            ->setOptions([
                'label' => 'Ingest directory recursively', // @translate
            ])
            ->setAttributes([
                'id' => 'media-sideload-ingest-directory-recursive-__index__',
                'required' => false,
            ]);

        return $view->formRow($select)
            // Ideally should be in a js file of the module or Omeka.
            . '<script>$(".media-sideload-select").chosen(window.chosenOptions);</script>'
            . $view->formRow($recursive);
    }

    protected function checkIngestDir(string $directory, ErrorStore $errorStore): ?string
    {
        if (!strlen($directory)) {
            $errorStore->addError('ingest_directory', 'No ingest directory specified.'); // @translate
            return null;
        }

        // Quick security checks.
        if ($directory === '.' || $directory === '..' || $directory === '/') {
            $errorStore->addError('ingest_directory', 'Illegal ingest directory specified.'); // @translate
            return null;
        }

        $isAbsolutePathInsideDir = $this->directory && strpos($directory, $this->directory) === 0;
        $directory = $isAbsolutePathInsideDir
            ? $directory
            : $this->directory . DIRECTORY_SEPARATOR . $directory;
        $fileinfo = new \SplFileInfo($directory);
        $directory = $this->fileSystem->verifyFileOrDir($fileinfo, true);
        if (is_null($directory)) {
            // Set a clearer message in some cases.
            if ($this->deleteFile && !$fileinfo->getPathInfo()->isWritable()) {
                $errorStore->addError('ingest_directory', new Message(
                    'Ingest directory "%s" is not writeable but the config requires deletion after upload.', // @translate
                    $directory
                ));
            } elseif (!$fileinfo->isDir()) {
                $errorStore->addError('ingest_directory', new Message(
                    'Invalid ingest directory "%s" specified: not a directory', // @translate
                    $directory
                ));
            } else {
                $errorStore->addError('ingest_directory', new Message(
                    'Invalid ingest directory "%s" specified: incorrect path or insufficient permissions', // @translate
                    $directory
                ));
            }
            return null;
        }

        return $directory;
    }

    /**
     * Removes directories recursively and any files inside them.
     */
    private function rrmdir(string $dir): bool
    {
        if (!file_exists($dir)
            || !is_dir($dir)
            || !is_readable($dir)
            || !is_writeable($dir)
        ) {
            return false;
        }

        $scandir = scandir($dir);
        if (!is_array($scandir)) {
            return false;
        }

        $files = array_diff($scandir, ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }
}
