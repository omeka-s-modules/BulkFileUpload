<?php declare(strict_types=1);

namespace FileSideload\Service;

use FileSideload\FileSideload\FileSystem;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class FileSystemFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        return new FileSystem(
            $settings->get('file_sideload_directory'),
            $settings->get('file_sideload_delete_file') === 'yes',
            (int) $settings->get('file_sideload_max_directories'),
            (int) $settings->get('file_sideload_max_files')
        );
    }
}
