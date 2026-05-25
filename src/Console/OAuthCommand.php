<?php

namespace Zapol\Booking\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class OAuthCommand extends Command
{
    protected $signature = 'booking:google-auth {--ttl=60 : Minutes the connection link stays valid}';
    protected $description = 'Print a signed link to the Google connection UI for this install.';

    public function handle(): int
    {
        $ttl = max(5, (int) $this->option('ttl'));
        $url = URL::temporarySignedRoute('booking.google.connect', now()->addMinutes($ttl));

        $this->info('Open this URL in your browser to connect Google:');
        $this->line('');
        $this->line($url);
        $this->line('');
        $this->comment("Link expires in {$ttl} minutes.");
        $this->line('');
        $this->line('Then in Google Cloud Console, register this Authorised redirect URI on your OAuth client:');
        $this->line('  ' . URL::route('booking.google.callback'));

        return self::SUCCESS;
    }
}
