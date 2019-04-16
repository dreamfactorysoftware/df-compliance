<?php
namespace DreamFactory\Core\Compliance\Handlers\Events;

use DreamFactory\Core\Compliance\Models\AdminUser;
use DreamFactory\Core\Events\UserCreatingEvent;
use DreamFactory\Core\Events\BaseUserEvent;
use Illuminate\Contracts\Events\Dispatcher;

class EventHandler
{
    /**
     * Register the listeners for the subscriber.
     *
     * @param  Dispatcher $events
     */
    public function subscribe($events)
    {
        $events->listen(
            [
                UserCreatingEvent::class,
            ],
            static::class . '@handleUserCreatingEvent'
        );
    }

    /**
     * Handle User creating events.
     *
     * @param BaseUserEvent $event
     *
     * @return void
     */
    public function handleUserCreatingEvent($event)
    {
        if (!AdminUser::adminExists()) {
            AdminUser::setRoot($event->user);
        }
    }
}