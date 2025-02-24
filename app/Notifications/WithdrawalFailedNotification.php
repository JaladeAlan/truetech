<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class WithdrawalFailedNotification extends Notification
{
    use Queueable;

    protected $withdrawal;

    public function __construct($withdrawal)
    {
        $this->withdrawal = $withdrawal;
    }

    public function via($notifiable)
    {
        return ['mail']; // You can also add 'database' or 'sms' if needed
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Withdrawal Failed')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line('Your withdrawal request of NGN ' . number_format($this->withdrawal->amount, 2) . ' has failed.')
            ->line('Please try again or contact support.')
            ->action('View Withdrawals', url('/withdrawals')) // Change to your frontend URL
            ->line('Thank you for using our service.');
    }
}
