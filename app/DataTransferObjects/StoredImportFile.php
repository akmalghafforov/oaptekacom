<?php

namespace App\DataTransferObjects;

final readonly class StoredImportFile
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalFilename,
        public string $mimeType,
        public int $size,
        public string $sha256,
    ) {}
}
