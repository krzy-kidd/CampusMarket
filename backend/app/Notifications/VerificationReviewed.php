<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerificationReviewed extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $decision,
        private readonly ?string $adminNotes,
        private readonly string $academicYear,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('CampusTrade COR verification update')
            ->greeting('Hello '.$notifiable->first_name.',')
            ->line('Your COR submission for '.$this->academicYear.' was '.$this->decision.'.');

        if ($this->decision === 'rejected' && $this->adminNotes) {
            $mail->line('Review notes: '.$this->adminNotes);
        }

        return $mail->line('Sign in to CampusTrade to view your verification status.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'cor_verification_reviewed',
            'decision' => $this->decision,
            'academic_year' => $this->academicYear,
            'admin_notes' => $this->adminNotes,
        ];
    }
}
