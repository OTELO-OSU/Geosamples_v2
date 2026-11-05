<?php

namespace App\Service;

use App\Entity\Dataset;
use App\Entity\Site;
use App\Entity\SiteMembership;
use App\Repository\SiteMembershipRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/** Email notifications (best effort: sending failures never interrupt the action). */
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
        $this->send($to, "[{$site->getTitle()}] Dataset pending review",
            sprintf("The dataset “%s” was submitted by %s and is awaiting your review.\n\nIt is available in the site's “Pending review” tab.",
                $d->getTitle(), $d->getOwner()?->getDisplayName() ?? '—'));
    }

    public function datasetReviewed(Dataset $d, bool $accepted): void
    {
        if (!$d->getOwner()) {
            return;
        }
        $site = $d->getSite();
        $body = $accepted
            ? sprintf("Your dataset “%s” has been published on %s.", $d->getTitle(), $site->getTitle())
            : sprintf("Your dataset “%s” was not approved.\n\nManager's comment:\n%s", $d->getTitle(), $d->getReviewComment() ?: '—');
        $this->send([$d->getOwner()->getEmail()], "[{$site->getTitle()}] " . ($accepted ? 'Dataset published' : 'Dataset needs revision'), $body);
    }

    public function joinRequested(Site $site, SiteMembership $m): void
    {
        $to = array_map(static fn (SiteMembership $x) => $x->getUser()->getEmail(), $this->memberships->findManagers($site));
        $this->send($to, "[{$site->getTitle()}] Membership request",
            sprintf("%s (%s) requested access to contribute to the site.\n\nMessage: %s\n\nManage requests in the “Members” tab.",
                $m->getUser()->getDisplayName(), $m->getUser()->getEmail(), $m->getMessage() ?: '—'));
    }

    public function membershipGranted(SiteMembership $m): void
    {
        $role = match ($m->getRole()) {
            SiteMembership::ROLE_MANAGER => 'site manager',
            SiteMembership::ROLE_CONTRIBUTOR => 'contributor',
            default => $m->getRole(),
        };
        $this->send([$m->getUser()->getEmail()], "[{$m->getSite()->getTitle()}] Access granted",
            sprintf("You are now a %s on the site %s.", $role, $m->getSite()->getTitle()));
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
