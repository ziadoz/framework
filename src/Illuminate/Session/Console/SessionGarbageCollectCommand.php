<?php

namespace Illuminate\Session\Console;

use Illuminate\Console\Command;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\CookieSessionHandler;
use Illuminate\Session\NullSessionHandler;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'session:garbage-collect')]
class SessionGarbageCollectCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'session:garbage-collect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the session garbage collector';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $session = $this->laravel['session'];

        $handler = $session->driver()->getHandler();

        if (
            $handler instanceof CacheBasedSessionHandler ||
            $handler instanceof CookieSessionHandler ||
            $handler instanceof NullSessionHandler
        ) {
            $this->components->info('The ['.$session->getDefaultDriver().'] session driver does not require garbage collection.');

            return;
        }

        $handler->gc((int) $this->laravel['config']->get('session.lifetime') * 60);

        $this->components->info('Session garbage collector ran successfully.');
    }
}
