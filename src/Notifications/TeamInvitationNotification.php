<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitTeams\Notifications;

use AlfaSix\Alfakit\Mail\Concerns\UsesEditableTemplate;
use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\Alfakit\Mail\MailMessage;
use AlfaSix\Alfakit\Mail\MailTheme;
use AlfaSix\Alfakit\Support\Credit;
use AlfaSix\AlfakitTeams\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage as BaseMailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation e-mail, queued, in the AlfaKit mail theme and editable
 * (`teams.invitation`). The signed accept URL is built when the invitation is sent
 * (inside the panel request), so the queue worker needs no panel context.
 */
class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesEditableTemplate;

    public const TEMPLATE = 'teams.invitation';

    public string $teamName;

    public ?string $inviterName;

    public string $roleLabel;

    public ?string $expiresAt;

    public function __construct(public TeamInvitation $invitation, public string $acceptUrl)
    {
        $team = $invitation->team()->first();
        $inviter = $invitation->inviter()->first();

        $this->teamName = $team instanceof Model ? (string) $team->getAttribute('name') : '';
        $this->inviterName = $inviter instanceof Model && is_string($name = $inviter->getAttribute('name')) ? $name : null;
        $this->roleLabel = $invitation->getRole()?->getLabel() ?? $invitation->role;
        $this->expiresAt = $invitation->expires_at?->toDayDateTimeString();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): BaseMailMessage
    {
        $message = (new MailMessage)
            ->subject(__('alfakit-teams::teams.mail.subject', ['team' => $this->teamName]))
            ->greeting(__('alfakit-teams::teams.mail.greeting'))
            ->line($this->inviterName === null
                ? __('alfakit-teams::teams.mail.intro', ['team' => $this->teamName, 'role' => $this->roleLabel])
                : __('alfakit-teams::teams.mail.intro_by', ['inviter' => $this->inviterName, 'team' => $this->teamName, 'role' => $this->roleLabel]))
            ->action(__('alfakit-teams::teams.mail.action'), $this->acceptUrl)
            ->line(__('alfakit-teams::teams.mail.account'));

        if ($this->expiresAt !== null) {
            $message->line(__('alfakit-teams::teams.mail.expires', ['date' => $this->expiresAt]));
        }

        return $this->templateMailMessage(Credit::addToMail($message->line(__('alfakit-teams::teams.mail.ignore'))), Credit::emailLine());
    }

    public function templateKey(): string
    {
        return self::TEMPLATE;
    }

    public function templateData(): array
    {
        return [
            'team' => $this->teamName,
            'inviter' => $this->inviterName ?? '',
            'role' => $this->roleLabel,
            'button' => EditableTemplates::button($this->acceptUrl, (string) __('alfakit-teams::teams.mail.action')),
            'url' => $this->acceptUrl,
            'expires' => $this->expiresAt ?? '',
            'app' => MailTheme::brand()->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->getKey(),
            'team' => $this->teamName,
            'role' => $this->invitation->role,
            'accept_url' => $this->acceptUrl,
        ];
    }
}
