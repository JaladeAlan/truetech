<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Withdrawal;

class WithdrawalConfirmed extends Notification
{
    use Queueable;

    protected $withdrawal;

    public function __construct(Withdrawal $withdrawal)
    {
        $this->withdrawal = $withdrawal;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Withdrawal Confirmation')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your withdrawal of ₦' . number_format($this->withdrawal->amount, 2) . ' has been successfully processed.')
            ->line('Transaction Reference: ' . $this->withdrawal->reference)
            ->action('View Account', url('/account'))
            ->line('Thank you for using our application!')
            ->salutation('Best regards, ' . config('app.name'));
    }
}
