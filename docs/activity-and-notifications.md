# Activity log, sign-in log and notifications

## Activity log: what changed, who changed it

**Automatic for models.** Add the trait and every create, edit, delete and restore is recorded
with the old and new value of each changed column:

```php
use App\Models\Concerns\LogsActivity;

class Invoice extends Model
{
    use LogsActivity;

    protected array $activityIgnore = ['views_count'];   // optional: never logged

    public function activityLabel(): string               // optional: how it shows in the log
    {
        return $this->number;
    }
}
```

Passwords, remember tokens, two-factor secrets, API tokens and the model's `$hidden` columns are never written.

**Explicit entries** for actions that are not a plain change:

```php
use App\Support\Activity;

Activity::log('approved', $invoice, 'Approved for sending', ['amount' => $invoice->total]);
Activity::log('exported', null, 'Exported the invoice list');
```

The action name is shown translated if `ActivityLog::ACTIONS` has a label for it. Add yours there.

**Safety net.** `RecordUnloggedActions` writes a generic entry ("POST invoices/12/approve") for any
change or file download that logged nothing, so nothing goes unrecorded while you build.

The **Activity Log** page (`activity.view`) filters by person, action, type and date, and exports CSV (`activity.export`).
Each user's page has the same feed for that person. Rows older than **Settings → Retention** are deleted daily.

## Sign-in log

Every sign-in attempt is stored (`signins.view`): success, wrong password, unknown account, locked out,
two-factor failures, deactivated account, with the device, browser and IP.

- A sign-in from a device the person has not used before emails them (**Settings → Security → New-device alerts**).
- Repeated lockouts email the alert address (**Settings → Mail**) so admins see attacks early.

## Notifications (the bell)

For anything a module needs to tell someone:

```php
use App\Notifications\SimpleNotification;

$user->notify(new SimpleNotification(
    title: 'Invoice approved',
    body: "Invoice {$invoice->number} is ready to send.",
    url: route('invoices.show', $invoice),
));

// everyone who may approve invoices
Notification::send(User::withPermission('invoices.approve'), new SimpleNotification('New invoice to approve'));
```

It shows in the bell (the counter updates every minute) and on the notifications page; clicking opens `url`.
Pass `icon:` and `color:` to change the look and `mail: true` to also send an email. For anything richer, write a normal Laravel notification with
the `database` channel and a `toArray()` that returns `title`, `body`, `url` and `icon`.

## Announcements and "What's new"

- **Announcements** (`announcements.manage`): a message for everyone, by type (information, good news, heads-up, important, update),
  with an optional start and end date. It shows in the bell until seen.
- **What's new** (`changelog.manage`): release notes for your users, linked in the page footer.

## Presence

`TrackPresence` and a heartbeat every 60 seconds keep "last seen". The **Login Activity** page (`presence.view`)
separates *online* (a tab is open) from *active* (clicked or typed in the last minutes) and shows the page each person is on.
