<?php

namespace App\EventListener;

use App\Exception\ApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Convertit les exceptions des routes /api en réponses JSON homogènes. */
#[AsEventListener(event: 'kernel.exception', priority: 10)]
final class ExceptionListener
{
    public function __construct(private LoggerInterface $logger, #[Autowire('%kernel.environment%')] private string $kernelEnvironment = 'prod') {}

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }
        $e = $event->getThrowable();

        if ($e instanceof ApiException) {
            $body = ['error' => $e->getMessage()];
            if ($e->getDetails()) {
                $body['details'] = $e->getDetails();
            }
            $event->setResponse(new JsonResponse($body, $e->getStatus()));
            return;
        }
        if ($e instanceof AccessDeniedException) {
            $event->setResponse(new JsonResponse(['error' => 'Accès refusé'], 403));
            return;
        }
        if ($e instanceof HttpExceptionInterface) {
            $event->setResponse(new JsonResponse(['error' => $e->getMessage() ?: 'Erreur'], $e->getStatusCode(), $e->getHeaders()));
            return;
        }

        $this->logger->error('Unhandled API error: ' . $e->getMessage(), ['exception' => $e]);
        $msg = $this->kernelEnvironment === 'dev' ? $e->getMessage() : 'Erreur interne';
        $event->setResponse(new JsonResponse(['error' => $msg], 500));
    }
}
