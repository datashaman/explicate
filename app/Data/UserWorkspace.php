<?php

namespace App\Data;

readonly class UserWorkspace
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?bool $isCurrent = null,
    ) {
        //
    }
}
