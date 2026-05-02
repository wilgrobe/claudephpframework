<?php
// modules/gdpr/Jobs/PurgeExpiredExportsJob.php
namespace Modules\Gdpr\Jobs;

use Core\Queue\Job;
use Modules\Gdpr\Services\DataExporter;

/**
 * Scheduled daily. Deletes any data_exports zip whose `expires_at`
 * has elapsed and marks the row status='expired'. The row itself
 * stays so admins can audit who exported when.
 *
 * Default TTL is 7 days — long enough for a user to come back and
 * fetch the file from a different device, short enough that a
 * forgotten export doesn't sit on disk indefinitely with PII.
 */
class PurgeExpiredExportsJob extends Job
{
    public function handle(): void
    {
        $exporter = new DataExporter();
        $count    = $exporter->purgeExpired();
        if ($count > 0) {
            error_log("[gdpr] purged {$count} expired data export file(s)");
        }
    }
}
