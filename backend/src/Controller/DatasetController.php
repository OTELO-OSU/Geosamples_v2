<?php

namespace App\Controller;

use App\Entity\Dataset;
use App\Entity\DatasetFile;
use App\Entity\Site;
use App\Exception\ApiException;
use App\Repository\DatasetRepository;
use App\Service\DatasetQuery;
use App\Service\DatasetWriter;
use App\Service\FileStorage;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET    /api/sites/{slug}/datasets            liste filtrée et paginée
 * GET    /api/sites/{slug}/map?zoom=&bbox=     points ou regroupements selon le zoom
 * GET    /api/sites/{slug}/facets              valeurs de filtres, emprise, histogramme temporel
 * POST   /api/sites/{slug}/datasets            création (membre) — {…, submit: bool}
 * GET    /api/sites/{slug}/datasets/{uuid}
 * PUT    /api/sites/{slug}/datasets/{uuid}     modification
 * DELETE /api/sites/{slug}/datasets/{uuid}
 * POST   /api/sites/{slug}/datasets/{uuid}/status   {action: submit|publish|reject|unpublish|draft, comment?}
 * POST   /api/sites/{slug}/datasets/{uuid}/files    multipart files[] + kind
 * DELETE /api/sites/{slug}/datasets/{uuid}/files/{id}
 * GET    /api/files/{id}[?inline=1]                 téléchargement (droits du jeu)
 */
final class DatasetController extends ApiController
{
    public function __construct(
        private DatasetRepository $datasets,
        private DatasetQuery $query,
        private DatasetWriter $writer,
        private FileStorage $storage,
        private Notifier $notifier,
        private EntityManagerInterface $em,
    ) {}

    #[Route('/api/sites/{slug}/datasets', methods: ['GET'])]
    public function list(string $slug, Request $request): JsonResponse
    {
        $site = $this->site($slug);
        return $this->json($this->query->list(
            $site,
            $this->query->filtersFromRequest($request),
            $request->query->getInt('page', 1),
            $request->query->getInt('perPage', 20),
            (string) $request->query->get('sort', 'updated'),
        ));
    }

    #[Route('/api/sites/{slug}/map', methods: ['GET'])]
    public function map(string $slug, Request $request): JsonResponse
    {
        $site = $this->site($slug);
        $resp = $this->json($this->query->map($site, $this->query->filtersFromRequest($request), $request->query->getInt('zoom', 6)));
        return $resp;
    }

    #[Route('/api/sites/{slug}/facets', methods: ['GET'])]
    public function facets(string $slug, Request $request): JsonResponse
    {
        $site = $this->site($slug);
        return $this->json($this->query->facets($site, $this->query->filtersFromRequest($request)));
    }

    #[Route('/api/sites/{slug}/datasets', methods: ['POST'])]
    public function create(string $slug, Request $request): JsonResponse
    {
        $site = $this->site($slug);
        $user = $this->perm->requireUser();
        $this->perm->denyUnless($this->perm->canContribute($site), 'Vous devez être membre du site pour déposer des données');
        $payload = $this->body($request);
        $d = new Dataset($site, $user);
        $this->writer->apply($d, $payload);
        $d->setUpdatedBy($user);
        $this->em->persist($d);
        $this->applySubmit($d, (bool) ($payload['submit'] ?? false));
        $this->em->flush();
        return $this->json($this->presenter->dataset($d), 201);
    }

    #[Route('/api/sites/{slug}/datasets/{uuid}', methods: ['GET'])]
    public function show(string $slug, string $uuid): JsonResponse
    {
        $d = $this->load($slug, $uuid);
        $this->perm->denyUnless($this->perm->canView($d));
        return $this->json($this->presenter->dataset($d));
    }

    #[Route('/api/sites/{slug}/datasets/{uuid}', methods: ['PUT'])]
    public function update(string $slug, string $uuid, Request $request): JsonResponse
    {
        $d = $this->load($slug, $uuid);
        $this->perm->denyUnless($this->perm->canEdit($d));
        $payload = $this->body($request);
        $this->writer->apply($d, $payload);
        $d->setUpdatedBy($this->perm->user());
        $d->touch();
        $manager = $this->perm->canManageSite($d->getSite());
        if (!$manager && $d->isPublished() && $d->getSite()->setting('moderation')) {
            // Toute modification d'un jeu publié par un contributeur repasse en validation.
            $d->setStatus(Dataset::STATUS_SUBMITTED);
            $this->notifier->datasetSubmitted($d);
        } elseif (!$d->isPublished()) {
            $this->applySubmit($d, (bool) ($payload['submit'] ?? false));
        }
        $this->em->flush();
        return $this->json($this->presenter->dataset($d));
    }

    #[Route('/api/sites/{slug}/datasets/{uuid}', methods: ['DELETE'])]
    public function delete(string $slug, string $uuid): JsonResponse
    {
        $d = $this->load($slug, $uuid);
        $this->perm->denyUnless($this->perm->canDelete($d), 'Un jeu publié ne peut être supprimé que par un responsable');
        $this->storage->removeAll($d);
        $this->em->remove($d);
        $this->em->flush();
        return $this->json(['ok' => true]);
    }

    #[Route('/api/sites/{slug}/datasets/{uuid}/status', methods: ['POST'])]
    public function status(string $slug, string $uuid, Request $request): JsonResponse
    {
        $d = $this->load($slug, $uuid);
        $body = $this->body($request);
        $action = (string) ($body['action'] ?? '');
        $manager = $this->perm->canManageSite($d->getSite());
        switch ($action) {
            case 'submit':
                $this->perm->denyUnless($this->perm->canEdit($d));
                $this->applySubmit($d, true);
                break;
            case 'draft':
                $this->perm->denyUnless($this->perm->canEdit($d) && ($manager || !$d->isPublished()));
                $d->setStatus(Dataset::STATUS_DRAFT);
                break;
            case 'publish':
                $this->perm->denyUnless($manager);
                $d->setStatus(Dataset::STATUS_PUBLISHED)->setReviewComment(trim((string) ($body['comment'] ?? '')) ?: null);
                $this->notifier->datasetReviewed($d, true);
                break;
            case 'reject':
                $this->perm->denyUnless($manager);
                $comment = trim((string) ($body['comment'] ?? ''));
                if ($comment === '') {
                    throw ApiException::validation(['comment' => 'Un commentaire est requis pour un refus']);
                }
                $d->setStatus(Dataset::STATUS_REJECTED)->setReviewComment($comment);
                $this->notifier->datasetReviewed($d, false);
                break;
            case 'unpublish':
                $this->perm->denyUnless($manager);
                $d->setStatus(Dataset::STATUS_DRAFT);
                break;
            default:
                throw new ApiException('Action inconnue');
        }
        $d->setUpdatedBy($this->perm->user());
        $this->em->flush();
        return $this->json($this->presenter->dataset($d));
    }

    #[Route('/api/sites/{slug}/datasets/{uuid}/files', methods: ['POST'])]
    public function upload(string $slug, string $uuid, Request $request): JsonResponse
    {
        $d = $this->load($slug, $uuid);
        $this->perm->denyUnless($this->perm->canEdit($d));
        $files = $request->files->all('files') ?: array_filter([$request->files->get('file')]);
        if (!$files) {
            throw new ApiException('Aucun fichier reçu');
        }
        $kind = $request->request->get('kind');
        $out = [];
        foreach ($files as $f) {
            $out[] = $this->storage->store($d, $f, $kind ? (string) $kind : null);
        }
        $d->touch();
        $this->em->flush();
        return $this->json(array_map(fn ($f) => $this->presenter->file($f), $out), 201);
    }

    #[Route('/api/sites/{slug}/datasets/{uuid}/files/{id}', methods: ['DELETE'])]
    public function deleteFile(string $slug, string $uuid, int $id): JsonResponse
    {
        $d = $this->load($slug, $uuid);
        $this->perm->denyUnless($this->perm->canEdit($d));
        $f = $this->em->find(DatasetFile::class, $id);
        if (!$f || $f->getDataset()?->getId() !== $d->getId()) {
            throw ApiException::notFound();
        }
        $this->storage->remove($f);
        $d->removeFile($f);
        $this->em->flush();
        return $this->json(['ok' => true]);
    }

    #[Route('/api/files/{id}', methods: ['GET'])]
    public function download(int $id, Request $request): BinaryFileResponse
    {
        $f = $this->em->find(DatasetFile::class, $id);
        if (!$f) {
            throw ApiException::notFound();
        }
        $d = $f->getDataset();
        if (!$this->perm->canView($d)) {
            throw ApiException::notFound();
        }
        $this->perm->denyUnless($this->perm->canDownload($d), 'Fichier réservé aux membres du site');
        $path = $this->storage->path($f);
        if (!is_file($path)) {
            throw ApiException::notFound('Fichier absent du stockage');
        }
        $resp = new BinaryFileResponse($path);
        $resp->headers->set('Content-Type', $f->getMimeType());
        $resp->headers->set('X-Content-Type-Options', 'nosniff');
        $inline = $request->query->getBoolean('inline') && str_starts_with($f->getMimeType(), 'image/');
        $resp->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $f->getOriginalName(),
            preg_replace('/[^\x20-\x7e]/', '_', $f->getOriginalName()));
        if ($d->isPublished() && $d->getAccessRight() === Dataset::ACCESS_OPEN) {
            $resp->setPublic();
            $resp->setMaxAge(3600);
        } else {
            $resp->setPrivate();
        }
        return $resp;
    }

    private function load(string $slug, string $uuid): Dataset
    {
        $site = $this->site($slug);
        $d = $this->datasets->findOneInSite($site, $uuid);
        if (!$d) {
            throw ApiException::notFound('Jeu de données introuvable');
        }
        return $d;
    }

    /** Soumission : publication directe si pas de modération ou si l'auteur est responsable. */
    private function applySubmit(Dataset $d, bool $submit): void
    {
        if (!$submit) {
            return;
        }
        if (!$d->getSite()->setting('moderation') || $this->perm->canManageSite($d->getSite())) {
            $d->setStatus(Dataset::STATUS_PUBLISHED);
        } else {
            $d->setStatus(Dataset::STATUS_SUBMITTED);
            $this->notifier->datasetSubmitted($d);
        }
    }
}
