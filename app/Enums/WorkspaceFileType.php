<?php

namespace App\Enums;

enum WorkspaceFileType: string
{
    case Folder = 'folder';
    case File = 'file';

    public function label(): string
    {
        return match ($this) {
            self::Folder => __('Folder'),
            self::File => __('File'),
        };
    }
}
