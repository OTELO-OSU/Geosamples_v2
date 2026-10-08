<?php

namespace App\Exception;

/**
 * Erreur métier renvoyée au client sous forme JSON {error, details?}.
 */
class ApiException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 400, private array $details = [])
    {
        parent::__construct($message, $status);
    }

    public function getStatus(): int { return $this->status; }
    public function getDetails(): array { return $this->details; }

    public static function notFound(string $what = 'Ressource introuvable'): self { return new self($what, 404); }
    public static function forbidden(string $msg = 'Accès refusé'): self { return new self($msg, 403); }
    public static function unauthorized(string $msg = 'Authentification requise'): self { return new self($msg, 401); }
    public static function validation(array $details, string $msg = 'Données invalides'): self { return new self($msg, 422, $details); }
}
