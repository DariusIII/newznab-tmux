<?php

namespace App\Jobs;

use App\Mail\SendInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendInviteEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $email;

    private $userId;

    private $url;

    /**
     * @var \App\Models\User
     */
    private $user;

    /**
     * SendInviteEmail constructor.
     *
     * @param $email
     * @param $user
     * @param $url
     */
    public function __construct($email, $user, $url)
    {
        $this->email = $email;
        $this->user = $user;
        $this->url = $url;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Mail::to($this->email)->send(new SendInvite($this->user, $this->url));
    }
}
