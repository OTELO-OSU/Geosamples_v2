<?php

namespace App\Controller;

use App\Entity\Dataset;
use App\Exception\ApiException;
use App\Repository\DatasetRepository;
use App\Service\DatasetQuery;
use App\Service\ImportService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET  /api/sites/{slug}/import/columns          colonnes du template (personnalisées par le site)
 * POST /api/sites/{slug}/import/preview          {rows[]} → contrôle sans écriture
 * POST /api/sites/{slug}/import/commit           {rows[], submit} → import tout-ou-rien
 * GET  /api/sites/{slug}/export?format=json|csv  jeux filtrés au format du template
 *        &ids=uuid,uuid  &template=1 (sans identifiants : modèle "d'après un jeu existant")
 */
final class ImportController extends ApiController
{
    public function __construct(
        private ImportService $import,
        private DatasetQuery $query,
        private DatasetRepository $datasets,
    ) {}

    #[Route('/api/sites/{slug}/import/columns', methods: ['GET'])]
    public function columns(string $slug): JsonResponse
    {
        $site = $this->site($slug);
        return $this->json([
            'columns' => $this->import->columns($site),
            'instructions' => $site->getImportTemplate()['instructions'] ?? null,
        ]);
    }

    #[Route('/api/sites/{slug}/import/preview', methods: ['POST'])]
    public function preview(string $slug, Request $request): JsonResponse
    {
        $site = $this->site($slug);
        $user = $this->perm->requireUser();
        $this->perm->denyUnless($this->perm->canContribute($site));
        return $this->json($this->import->preview($site, $this->rows($request), $user));
    }

    #[Route('/api/sites/{slug}/import/commit', methods: ['POST'])]
    public function commit(string $slug, Request $request): JsonResponse
    {
        $site = $this->site($slug);
        $user = $this->perm->requireUser();
        $this->perm->denyUnless($this->perm->canContribute($site));
        $body = $this->body($request);
        $submit = (bool) ($body['submit'] ?? false);
        $status = Dataset::STATUS_DRAFT;
        if ($submit) {
            $status = (!$site->setting('moderation') || $this->perm->canManageSite($site)) ? Dataset::STATUS_PUBLISHED : Dataset::STATUS_SUBMITTED;
        }
        $res = $this->import->commit($site, $this->rows($request), $user, $status);
        return $this->json($res + ['status' => $status], 201);
    }

    #[Route('/api/sites/{slug}/export', methods: ['GET'])]
    public function export(string $slug, Request $request): JsonResponse|StreamedResponse
    {
        $site = $this->site($slug);
        $ids = array_filter(explode(',', (string) $request->query->get('ids', '')));
        if ($ids) {
            $list = [];
            foreach ($ids as $uuid) {
                $d = $this->datasets->findOneInSite($site, trim($uuid));
                if ($d && $this->perm->canView($d)) {
                    $list[] = $d;
                }
            }
        } else {
            $list = $this->datasets->findByIdsOrdered($this->query->ids($site, $this->query->filtersFromRequest($request)));
        }
        $asTemplate = $request->query->getBoolean('template');
        // Les identifiants ne sont utiles que pour une réimport en mise à jour.
        $withIds = !$asTemplate && array_reduce($list, fn ($c, $d) => $c && $this->perm->canEdit($d), true);
        $rows = $this->import->exportRows($site, $list, $withIds);
        if ($asTemplate) {
            foreach ($rows as &$r) {
                $r['dataset_id'] = '';
                $r['title'] = ($r['title'] ?? '') . ' (copie)';
            }
            unset($r);
        }
        $columns = array_values(array_filter($this->import->columns($site), static fn ($c) => $c['include']));

        if ($request->query->get('format') === 'csv') {
            $resp = new StreamedResponse(function () use ($rows, $columns) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, array_map(static fn ($c) => $c['key'], $columns), ';', '"', '\\');
                foreach ($rows as $r) {
                    fputcsv($out, array_map(static fn ($c) => $r[$c['key']] ?? '', $columns), ';', '"', '\\');
                }
                fclose($out);
            });
            $resp->headers->set('Content-Type', 'text/csv; charset=utf-8');
            $resp->headers->set('Content-Disposition', 'attachment; filename="' . $site->getSlug() . '-export-' . date('Ymd') . '.csv"');
            return $resp;
        }
        return $this->json(['columns' => $columns, 'rows' => $rows]);
    }

    private function rows(Request $request): array
    {
        $rows = $this->body($request)['rows'] ?? null;
        if (!\is_array($rows) || !$rows) {
            throw new ApiException('Aucune ligne à importer');
        }
        return $rows;
    }
}
