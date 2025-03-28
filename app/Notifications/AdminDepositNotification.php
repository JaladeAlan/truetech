<?php

namespace App\Notifications;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class AdminDepositNotification extends Notification
{
    protected $deposit;

    public function __construct($deposit)
    {
        $this->deposit = $deposit;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('New Manual Deposit Request')
            ->line('A new manual deposit has been initiated.')
            ->line('User: ' . $this->deposit->user->name)
            ->line('Amount: ₦' . number_format($this->deposit->amount, 2))
            ->line('Reference: ' . $this->deposit->reference)
            ->line('Funding Method: Manual Transfer')
            ->action('Approve or Reject', url('/admin/deposits'))
            ->line('Log in to the admin panel to review the request.');
    }

    public function toArray($notifiable)
    {
        return [
            'message' => 'A new manual deposit request from ' . $this->deposit->user->name,
            'amount' => $this->deposit->amount,
            'reference' => $this->deposit->reference,
        ];
    }
}
