<?php

namespace App\Client;

use App\Entity\Client;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Stores per-client logo uploads on the local filesystem, under
 * public/uploads/client-logos/.
 */
class ClientLogoUploader
{
    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly Filesystem $filesystem,
        private readonly string $uploadDirectory,
    ) {
    }

    public function upload(Client $client, UploadedFile $file): void
    {
        $safeName = $this->slugger->slug($client->getSlug())->lower();
        $newFilename = sprintf('%s-%s.%s', $safeName, bin2hex(random_bytes(6)), $file->guessExtension() ?: 'png');

        $file->move($this->uploadDirectory, $newFilename);

        $this->remove($client);
        $client->setLogoFilename($newFilename);
    }

    public function remove(Client $client): void
    {
        $existing = $client->getLogoFilename();
        if ($existing !== null) {
            $this->filesystem->remove($this->uploadDirectory . '/' . $existing);
        }
    }
}
