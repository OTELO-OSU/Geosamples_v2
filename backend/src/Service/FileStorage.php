<?php

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\DatasetFile;
use App\Exception\ApiException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Stockage des fichiers sur disque : {UPLOAD_DIR}/{site}/{uuid}/{aléatoire}.{ext} */
final class FileStorage
{
    private const BLOCKED_EXT = ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'js', 'html', 'htm', 'svg'];
    private const PICTURE_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/tiff'];

    public function __construct(private string $uploadDir, private int $maxUploadBytes) {}

    public function store(Dataset $d, UploadedFile $file, ?string $kind = null): DatasetFile
    {
        if (!$file->isValid()) {
            throw new ApiException('Échec du téléversement : ' . $file->getErrorMessage());
        }
        if ($file->getSize() > $this->maxUploadBytes) {
            throw new ApiException(sprintf('Fichier trop volumineux (max. %d Mo)', $this->maxUploadBytes / 1048576));
        }
        $original = $this->safeName($file->getClientOriginalName());
        $ext = mb_strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (\in_array($ext, self::BLOCKED_EXT, true)) {
            throw new ApiException("Extension .$ext non autorisée");
        }
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if ($kind === null || !\in_array($kind, DatasetFile::KINDS, true)) {
            $kind = \in_array($mime, self::PICTURE_MIME, true) ? DatasetFile::KIND_PICTURE : DatasetFile::KIND_DATA;
        }
        if ($kind === DatasetFile::KIND_PICTURE && !\in_array($mime, self::PICTURE_MIME, true)) {
            throw new ApiException('Image attendue (JPEG, PNG, WebP, GIF, TIFF)');
        }
        $dir = $this->dirFor($d);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Impossible de créer $dir");
        }
        $stored = bin2hex(random_bytes(16)) . ($ext ? ".$ext" : '');
        $size = (int) $file->getSize();
        $file->move($dir, $stored);
        $df = new DatasetFile($original, $stored, $mime, $size, $kind);
        $d->addFile($df);
        return $df;
    }

    public function path(DatasetFile $f): string
    {
        return $this->dirFor($f->getDataset()) . '/' . $f->getStoredName();
    }

    public function remove(DatasetFile $f): void
    {
        $p = $this->path($f);
        if (is_file($p)) {
            @unlink($p);
        }
    }

    public function removeAll(Dataset $d): void
    {
        foreach ($d->getFiles() as $f) {
            $this->remove($f);
        }
        @rmdir($this->dirFor($d));
    }

    public function logoDir(): string
    {
        return rtrim($this->uploadDir, '/') . '/_logos';
    }

    private function dirFor(Dataset $d): string
    {
        return rtrim($this->uploadDir, '/') . '/' . $d->getSite()->getSlug() . '/' . $d->getUuid();
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', basename($name)) ?? 'fichier';
        return mb_substr(trim($name, '. ') ?: 'fichier', 0, 200);
    }
}
