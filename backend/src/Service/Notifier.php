<?php

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\Site;
use App\Entity\SiteMembership;
use App\Repository\SiteMembershipRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/** Notifications e-mail (best-effort : un échec d'envoi n'interrompt jamais l'action). */
final class Notifier
{
    public function __construct(
        private MailerInterface $mailer,
        private SiteMembershipRepository $memberships,
        private LoggerInterface $logger,
        private string $mailFrom,
    ) {}

    public function datasetSubmitted(Dataset $d): void
    {
        $site = $d->getSite();
        $to = array_map(static fn (SiteMembership $m) => $m->getUser()->getEmail(), $this->memberships->findManagers($site));
        $this->send($to, "[{$site->getTitle()}] Jeu de données à valider",
            sprintf("Le jeu « %s » a été soumis par %s et attend votre validation.\n\nIl est visible dans l'onglet « Validation » du site.",
                $d->getTitle(), $d->getOwner()?->getDisplayName() ?? '—'));
    }

    public function datasetReviewed(Dataset $d, bool $accepted): void
    {
        if (!$d->getOwner()) {
            return;
        }
        $site = $d->getSite();
        $body = $accepted
            ? sprintf("Votre jeu de données « %s » est désormais publié sur %s.", $d->getTitle(), $site->getTitle())
            : sprintf("Votre jeu de données « %s » n'a pas été validé.\n\nCommentaire du responsable :\n%s", $d->getTitle(), $d->getReviewComment() ?: '—');
        $this->send([$d->getOwner()->getEmail()], "[{$site->getTitle()}] " . ($accepted ? 'Jeu publié' : 'Jeu à revoir'), $body);
    }

    public function joinRequested(Site $site, SiteMembership $m): void
    {
        $to = array_map(static fn (SiteMembership $x) => $x->getUser()->getEmail(), $this->memberships->findManagers($site));
        $this->send($to, "[{$site->getTitle()}] Demande de participation",
            sprintf("%s (%s) demande à contribuer au site.\n\nMessage : %s\n\nGérez les demandes dans l'onglet « Membres ».",
                $m->getUser()->getDisplayName(), $m->getUser()->getEmail(), $m->getMessage() ?: '—'));
    }

    public function membershipGranted(SiteMembership $m): void
    {
        $this->send([$m->getUser()->getEmail()], "[{$m->getSite()->getTitle()}] Accès accordé",
            sprintf("Vous êtes désormais « %s » sur le site %s.", $m->getRole(), $m->getSite()->getTitle()));
    }

    private function send(array $to, string $subject, string $text): void
    {
        $to = array_values(array_unique(array_filter($to)));
        if (!$to) {
            return;
        }
        try {
            $this->mailer->send((new Email())->from($this->mailFrom)->to(...$to)->subject($subject)->text($text));
        } catch (\Throwable $e) {
            $this->logger->warning('Mail not sent: ' . $e->getMessage());
        }
    }
}
