<?php
namespace FileSideload;

use FileSideload\Form\ConfigForm;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Module\AbstractModule;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->delete('file_sideload_directory');
        $settings->delete('file_sideload_delete_file');
        $settings->delete('file_sideload_max_files');
        $settings->delete('file_sideload_max_directories');
        $settings->delete('file_sideload_directory_depth_user');

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->executeStatement('DELETE FROM `user_setting` WHERE `id` LIKE "filesideload#_%" ESCAPE "#";');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.hydrate.pre',
            [$this, 'handleItemApiHydratePre']
        );

        // Add the user directory setting to the user form.
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this, 'addUserFormElement']
        );
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_input_filters',
            [$this, 'addUserFormElementFilter']
        );

        // Display the user directory in the user show admin pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.details',
            [$this, 'viewUserDetails']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.show.after',
            [$this, 'viewUserShowAfter']
        );
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $form = new ConfigForm;
        $form->init();
        $form->setData([
            'directory' => $settings->get('file_sideload_directory'),
            'delete_file' => $settings->get('file_sideload_delete_file', 'no'),
            'filesideload_max_files' => $settings->get('file_sideload_max_files', 1000),
            'filesideload_max_directories' => $settings->get('file_sideload_max_directories', 1000),
            'filesideload_directory_depth_user' => $settings->get('file_sideload_directory_depth_user', 2),
        ]);
        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $form = new ConfigForm;
        $form->init();
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }
        $formData = $form->getData();
        $settings->set('file_sideload_directory', $formData['directory']);
        $settings->set('file_sideload_delete_file', $formData['delete_file']);
        $settings->set('file_sideload_max_files', (int) $formData['filesideload_max_files']);
        $settings->set('file_sideload_max_directories', (int) $formData['filesideload_max_directories']);
        $settings->set('file_sideload_directory_depth_user', (int) $formData['filesideload_directory_depth_user']);
        return true;
    }

    public function handleItemApiHydratePre(Event $event)
    {
        static $isChecked;

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $request->getContent();
        if (empty($data['o:media'])) {
            return;
        }

        $services = $this->getServiceLocator();

        if (is_null($isChecked)) {
            $isChecked = false;
            $settings = $services->get('Omeka\Settings');
            $sideloadDir = (string) $settings->get('file_sideload_directory', '');
            if (!strlen($sideloadDir)) {
                return;
            }

            $sideloadDir = realpath($sideloadDir);
            if ($sideloadDir === false) {
                return;
            }

            $dir = new \SplFileInfo($sideloadDir);
            if (!$dir->isDir() || !$dir->isReadable() || !$dir->isExecutable()) {
                return;
            }

            $deleteFile = $settings->get('file_sideload_delete_file') === 'yes';

            $isChecked = true;
        }

        if (!$isChecked) {
            return;
        }

        $errorStore = $event->getParam('errorStore');

        /** @var \FileSideload\FileSideload\FileSystem $fileSystem */
        $fileSystem = $services->get('FileSideload\FileSystem');

        $newDataMedias = [];
        foreach ($data['o:media'] as $dataMedia) {
            $newDataMedias[] = $dataMedia;

            if (empty($dataMedia['o:ingester']) || $dataMedia['o:ingester'] !== 'sideload_dir') {
                continue;
            }

            if (!array_key_exists('ingest_directory', $dataMedia)) {
                $errorStore->addError('ingest_directory', 'No ingest directory specified.'); // @translate
                continue;
            }

            $ingestDirectory = (string) $dataMedia['ingest_directory'];

            // Some quick security checks are done here instead of ingester
            // to simplify conversion into multiple media.

            if (!strlen($ingestDirectory)) {
                $errorStore->addError('ingest_directory', 'No ingest directory specified.'); // @translate
                continue;
            }

            if ($ingestDirectory === '.' || $ingestDirectory === '..' || $ingestDirectory === '/') {
                $errorStore->addError('ingest_directory', 'Illegal ingest directory specified.'); // @translate
                continue;
            }

            $isAbsolutePathInsideDir = $sideloadDir && strpos($ingestDirectory, $sideloadDir) === 0;
            $directory = $isAbsolutePathInsideDir
                ? $ingestDirectory
                : $sideloadDir . DIRECTORY_SEPARATOR . $ingestDirectory;
            $fileinfo = new \SplFileInfo($directory);
            $directory = $fileSystem->verifyFileOrDir($fileinfo, true);

            if (is_null($directory)) {
                // Set a clearer message in some cases.
                if ($deleteFile && !$fileinfo->getPathInfo()->isWritable()) {
                    $errorStore->addError('ingest_directory', new Message(
                        'Ingest directory "%s" is not writeable but the config requires deletion after upload.', // @translate
                        $ingestDirectory
                    ));
                } elseif (!$fileinfo->isDir()) {
                    $errorStore->addError('ingest_directory', new Message(
                        'Invalid ingest directory "%s" specified: not a directory', // @translate
                        $ingestDirectory
                    ));
                } else {
                    $errorStore->addError('ingest_directory', new Message(
                        'Invalid ingest directory "%s" specified: incorrect path or insufficient permissions', // @translate
                        $ingestDirectory
                    ));
                }
                continue;
            }

            $listFiles = $fileSystem->listFiles($directory, !empty($dataMedia['ingest_directory_recursively']));
            if (!count($listFiles)) {
                $errorStore->addError('ingest_directory', new Message(
                    'Ingest directory "%s" is empty.',  // @translate
                    $ingestDirectory
                ));
                continue;
            }

            // Convert the media to a list of media for the item hydration.
            // Remove the added media directory from list of media.
            array_pop($newDataMedias);
            foreach ($listFiles as $filepath) {
                $dataMedia['ingest_filename'] = $filepath;
                $newDataMedias[] = $dataMedia;
            }
        }
        $data['o:media'] = $newDataMedias;
        $request->setContent($data);
    }

    public function addUserFormElement(Event $event): void
    {
        /** @var \Omeka\Form\UserForm $form */
        $form = $event->getTarget();

        /**
         * @var \Omeka\Entity\User $user
         * @var \FileSideload\FileSideload\FileSystem $fileSystem
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $entityManager = $services->get('Omeka\EntityManager');
        $fileSystem = $services->get('FileSideload\FileSystem');

        // The user is not the current user, but the user in the form.
        // It may be empty for a new user.
        $userId = $services->get('Application')->getMvcEvent()->getRouteMatch()->getParam('id');
        $user = $userId ? $entityManager->find(\Omeka\Entity\User::class, $userId) : null;

        // Manage a direct creation (no id).
        if ($user) {
            /** @var \Omeka\Settings\UserSettings $userSettings */
            $userSettings = $services->get('Omeka\Settings\User');
            $userSettings->setTargetId($userId);
            $userDir = $userSettings->get('filesideload_user_dir', '');
        } else {
            $userDir = '';
        }

        // Default 2 levels because it's the most common case: one level for the
        // user or institution, and when the first is the institution, a second
        // for the collections or the users. Maybe 3, but greater depths may
        // cause issues with big directories.
        // This option only applies to the user interface anyway.
        $sideloadDir = (string) $settings->get('file_sideload_directory', '');
        $hasMainDirectory = $sideloadDir !== '';
        if ($hasMainDirectory) {
            $maxDepth = (int) $settings->get('file_sideload_directory_depth_user', 2);
            $directories = $fileSystem->listDirs($sideloadDir, $maxDepth);
            $directories = array_combine($directories, $directories);
        } else {
            $directories = [];
        }

        $fieldset = $form->get('user-settings');
        $fieldset
            ->add([
                'name' => 'filesideload_user_dir',
                'type' => \Laminas\Form\Element\Select::class,
                'options' => [
                    'label' => 'User directory', // @translate
                    'empty_option' => '',
                    'value_options' => $directories,
                ],
                'attributes' => [
                    'id' => 'filesideload_user_dir',
                    'value' => $userDir,
                    'required' => false,
                    'disabled' => !$hasMainDirectory,
                    'class' => 'chosen-select',
                    'data-placeholder' => count($directories)
                        ? 'Select a sub-directory…' // @translate
                        : 'No sub-directory in main directory', // @translate
                ],
            ]);
    }

    public function addUserFormElementFilter(Event $event): void
    {
        $form = $event->getTarget();
        $inputFilter = $form->getInputFilter();
        $inputFilter->add([
            'name' => 'filesideload_user_dir',
            'required' => false,
            'filters' => [
                ['name' => 'StringTrim'],
            ],
            'validators' => [
                [
                    'name' => 'Callback',
                    'options' => [
                        'messages' => [
                            \Laminas\Validator\Callback::INVALID_VALUE => 'The provided sideload directory is not a directory or does not have sufficient permissions.', // @translate
                        ],
                        'callback' => [$this, 'userDirectoryIsValid'],
                    ],
                ],
            ],
        ]);
    }

    public function userDirectoryIsValid($dir, $context)
    {
        // The user directory is not required.
        if ($dir === '') {
            return true;
        }
        // For security, even if checked via haystack.
        if (mb_strpos($dir, '..') !== false) {
            return false;
        }
        // The user directory is stored as a sub-path of the main directory.
        $sideloadDir = (string) $this->getServiceLocator()->get('Omeka\Settings')->get('file_sideload_directory');
        if (!strlen($sideloadDir) || !realpath($sideloadDir)) {
            return false;
        }
        $userDirectory = $sideloadDir . DIRECTORY_SEPARATOR . $dir;
        $dir = new \SplFileInfo($userDirectory);
        $valid = $dir->isDir() && $dir->isExecutable() && $dir->isReadable();
        if (isset($context['delete_file']) && 'yes' === $context['delete_file']) {
            $valid = $valid && $dir->isWritable();
        }
        return $valid;
    }

    public function viewUserDetails(Event $event): void
    {
        $view = $event->getTarget();
        $user = $view->resource;
        $html = <<<'HTML'
<div class="meta-group">
    <h4>%1$s</h4>
    <div class="value">%2$s</div>
</div>

HTML;
        $this->viewUserData($view, $user, $html);
    }

    public function viewUserShowAfter(Event $event): void
    {
        $view = $event->getTarget();
        $user = $view->vars()->user;
        $html = <<<'HTML'
<div class="property">
    <dt>%1$s</dt>
    <dd class="value">%2$s</dd>
</div>

HTML;
        $this->viewUserData($view, $user, $html);
    }

    protected function viewUserData(PhpRenderer $view, UserRepresentation $user, string $html): void
    {
        $services = $this->getServiceLocator();
        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->id());

        $label = $view->translate('Server directory'); // @translate
        $userDirectory = $userSettings->get('filesideload_user_dir', '');
        $userDir = strlen($userDirectory) ? $userDirectory : $view->translate('[root]'); // @translate

        echo sprintf($html, $label, $userDir);
    }
}
