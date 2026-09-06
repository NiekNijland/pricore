<?php

namespace App\Domains\Repository\Actions;

use App\Domains\Activity\Actions\RecordActivityTask;
use App\Domains\Activity\Contracts\Enums\ActivityType;
use App\Domains\Repository\Contracts\Data\UpdatePackagePathsResultData;
use App\Domains\Repository\Contracts\Enums\RepositorySyncStatus;
use App\Domains\Repository\Jobs\SyncRepositoryJob;
use App\Domains\Repository\Services\PackagePaths\PackagePathPattern;
use App\Models\Package;
use App\Models\Repository;
use App\Models\User;

class UpdatePackagePathsAction
{
    public function __construct(
        protected PurgeDistArchiveFilesTask $purgeDistArchiveFilesTask,
        protected RecordActivityTask $recordActivityTask,
    ) {}

    /**
     * Store new package paths for a repository. Packages whose directory the new
     * paths no longer select are removed, and a forced sync picks up the packages
     * the new paths do select.
     *
     * @param  array<int, string>|null  $patterns
     */
    public function handle(Repository $repository, ?array $patterns, ?User $actor = null): UpdatePackagePathsResultData
    {
        $current = $repository->package_paths ?: null;

        if ($patterns === $current) {
            return new UpdatePackagePathsResultData(changed: false, packagesRemoved: 0);
        }

        $removed = $this->removeUnselectedPackages($repository, $patterns, $actor);

        $repository->update([
            'package_paths' => $patterns,
            'sync_status' => RepositorySyncStatus::Pending,
        ]);

        SyncRepositoryJob::dispatch($repository, true);

        return new UpdatePackagePathsResultData(changed: true, packagesRemoved: $removed);
    }

    /**
     * @param  array<int, string>|null  $patterns
     */
    protected function removeUnselectedPackages(Repository $repository, ?array $patterns, ?User $actor): int
    {
        $selected = $patterns ?? [PackagePathPattern::ROOT];

        $packages = $repository->packages()
            ->get()
            ->reject(fn (Package $package) => PackagePathPattern::anyMatches($selected, $package->source_path));

        if ($packages->isEmpty()) {
            return 0;
        }

        // Versions and archive rows cascade at the database level once a package
        // goes, which fires no model events. Clear the files while the rows exist.
        $this->purgeDistArchiveFilesTask->handle($packages->pluck('uuid'));

        $organization = $repository->organization()->first();

        foreach ($packages as $package) {
            if ($organization) {
                $this->recordActivityTask->handle(
                    organization: $organization,
                    type: ActivityType::PackageRemoved,
                    subject: $package,
                    actor: $actor,
                    properties: [
                        'name' => $package->name,
                        'source_path' => $package->source_path,
                        'reason' => 'package_paths_changed',
                    ],
                );
            }

            $package->delete();
        }

        return $packages->count();
    }
}
