<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class DepositRejectedNotification extends Notification
{
    use Queueable;

    public $amount;
    public $reason;

    /**
     * Create a new notification instance.
     */
    public function __construct($amount, $reason)
    {
        $this->amount = $amount;
        $this->reason = $reason;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable)
    {
        return ['mail', 'database']; // Sends via email and saves in the database
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Deposit Rejected')
            ->greeting("Hello, {$notifiable->name}")
            ->line("Your deposit of NGN {$this->amount} has been rejected.")
            ->line("Reason: {$this->reason}")
            ->line("If you believe this is a mistake, please contact support.")
            ->action('Contact Support', url('/support'))
            ->line('Thank you for using our platform.');
    }

    /**
     * Get the array representation of the notification (for database storage).
     */
    public function toArray($notifiable)
    {
        return [
            'title' => 'Deposit Rejected',
            'message' => "Your deposit of NGN {$this->amount} was rejected. Reason: {$this->reason}.",
        ];
    }
}
