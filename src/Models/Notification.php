<?php

namespace WebRegulate\LaravelAdministration\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use WebRegulate\LaravelAdministration\Classes\NotificationBase;
use WebRegulate\LaravelAdministration\Classes\WRLAHelper;

/**
 * Model: WrlaNotification
 * 
 * @property int $id bigint unsigned
 * @property string $type varchar(255)
 * @property string $user_id varchar(255)
 * @property string $data json
 * @property string $read_at timestamp
 * @property string $created_at timestamp
 * @property string $updated_at timestamp
 */
class Notification extends Model
{
    protected $table = 'wrla_notifications';

    protected $fillable = [
        'type',
        'user_id',
        'data',
        'read_at',
    ];

    /**
     * Make a new notification.
     */
    public static function make(string $notificationDefinitionClass, string $userId, array $data): Notification
    {
        $notification = Notification::create([
            'type' => $notificationDefinitionClass,
            'user_id' => $userId,
            'read_at' => null,
            'data' => json_encode($data),
        ]);

        $notification->getDefinition()->postCreated();

        return $notification;
    }

    /**
     * Get notification definition instance.
     */
    public function getDefinition(): NotificationBase
    {
        // Now we use cache instead
        return cache()->remember("notification.{$this->id}.definition", now()->addMinutes(5), function () {
            $notificationClass = $this->type;

            // If doesn't start with \, prepend it
            if (str_starts_with($notificationClass, '\\') === false) {
                $notificationClass = '\\'.$notificationClass;
            }

            return new $notificationClass($this->user_id, json_decode($this->data, true));
        });
    }

    public function getFinalButtons(): Collection
    {
        $definition = $this->getDefinition();

        return $definition->getButtons($this->defaultButtons(), $this);
    }

    private function defaultButtons(): Collection
    {
        return collect([
            NotificationBase::staticBuildNotificationActionButton(
                $this,
                'flipNotificationMarkedAsRead',
                [$this->id],
                $this->read_at === null ? 'Read' : 'Undo',
                $this->read_at === null ? 'fa fa-check' : 'fa fa-undo',
                [
                    'title' => $this->read_at === null ? 'Mark as read' : 'Mark as unread',
                ]
            ),
        ]);
    }

    /**
     * Mark notification as read.
     *
     * @return void
     */
    public function markAsRead(bool $delete = false)
    {
        if (User::current() === null) {
            return;
        }

        $this->read_at = now();

        if ($delete) {
            $this->delete();
        }

        $this->save();
    }

    /**
     * Mark all notifications as read with an optional soft delete.
     *
     * @return void
     */
    public static function markAllAsRead(bool $delete = false)
    {
        $user = User::current();

        if ($user === null) {
            return;
        }

        $query = self::where('user_id', $user->id)
            ->whereNull('read_at');

        if ($delete) {
            $query->delete();
        } else {
            $query->update(['read_at' => now()]);
        }
    }

    /**
     * Flip notification as read.
     */
    public function flipMarkedAsRead(): void
    {
        $this->read_at = $this->read_at === null ? now() : null;
        $this->save();
    }

    /* Relationships
    ---------------------------------------------------*/
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get eloquent builder for given userIds
     *
     * @return Builder|mixed
     */
    public static function baseBuilderForUserIds(array $userIds): mixed
    {
        $userIds = WRLAHelper::interpretUserGroupsArray($userIds);

        return once(function () use ($userIds) {
            return static::where(function ($query) use ($userIds): void {
                // Loop through user ids building where / or where's
                foreach ($userIds as $userId) {
                    $query->orWhere('user_id', $userId);
                }
            })
                ->orderBy('created_at', 'desc');
        });
    }
}
