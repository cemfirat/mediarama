<?php

declare(strict_types=1);

namespace Mediarama\Upload\Application;

use Mediarama\Upload\Domain\UploadSession;
use Symfony\Component\Uid\Uuid;

interface UploadSessionRepository
{
    public function save(UploadSession $session): void;

    public function get(Uuid $id): UploadSession;

    public function delete(UploadSession $session): void;
}
