<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class DepositApprovedNotification extends Notification
{
    use Queueable;

    public $amount;

    public function __construct($amount)
    {
        $this->amount = $amount;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Deposit Approved')
            ->line("Your manual deposit of NGN {$this->amount} has been approved and credited to your balance.");
    }

    public function toArray($notifiable)
    {
        return [
            'title' => 'Deposit Approved',
            'message' => "Your manual deposit of NGN {$this->amount} has been approved and credited to your balance.",
        ];
    }
}
