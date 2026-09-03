<?php

namespace App\Filament\Resources\ResourceFiles\Schemas;

use App\Models\ResourceFile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ResourceFileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Resource')
                    ->schema([
                        TextInput::make('display_name')
                            ->label('Display name')
                            ->required()
                            ->maxLength(255),
                        FileUpload::make('file_path')
                            ->label('File')
                            ->disk(ResourceFile::Disk)
                            ->directory(ResourceFile::Directory)
                            ->visibility('private')
                            ->storeFileNamesIn('original_filename')
                            ->acceptedFileTypes(ResourceFile::AcceptedMimeTypes)
                            ->rules(['extensions:'.implode(',', ResourceFile::AcceptedExtensions)])
                            ->maxSize(ResourceFile::MaxUploadSizeKilobytes)
                            ->required()
                            ->preventFilePathTampering()
                            ->helperText('PDF, Office, CSV, text and common image files up to 10 MB.')
                            ->columnSpanFull(),
                    ])
                    ->columns(1),
            ]);
    }
}
