<?php
namespace FileSideload;

use Osii\Service\MediaIngesterMapper\MediaIngesterMapperFactory;

return [
    'service_manager' => [
        'factories' => [
            'FileSideload\FileSystem' => Service\FileSystemFactory::class,
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            'sideload' => Service\MediaIngesterSideloadFactory::class,
            'sideload_dir' => Service\MediaIngesterSideloadDirFactory::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => __DIR__ . '/../language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'csv_import' => [
        'media_ingester_adapter' => [
            'sideload' => CSVImport\SideloadMediaIngesterAdapter::class,
        ],
    ],
    'osii_media_ingester_mappers' => [
        'factories' => [
            Osii\MediaIngesterMapper\Sideload::class => MediaIngesterMapperFactory::class,
        ],
        'aliases' => [
            'sideload' => Osii\MediaIngesterMapper\Sideload::class,
        ],
    ],
];
