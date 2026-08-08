<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Release;

class DeduplicateReleasesCommand extends Command
{
    protected $signature = 'releases:deduplicate {--dry-run : Count duplicates without deleting}';
    protected $description = 'Safely remove duplicate releases using Eloquent models';

    public function handle(): int
    {
        $this->info('[*] Scanning for duplicate releases...');

        // Find searchnames and sizes that have more than 1 entry
        $duplicateGroups = DB::table('releases')
            ->select('searchname', 'size', DB::raw('COUNT(*) as total'))
            ->where('searchname', '!=', '')
            ->whereNotNull('searchname')
            ->groupBy('searchname', 'size')
            ->having('total', '>', 1)
            ->get();

        if ($duplicateGroups->isEmpty()) {
            $this->info('[+] No duplicate releases found.');
            return Command::SUCCESS;
        }

        $this->warn("[!] Found {$duplicateGroups->count()} release group(s) with duplicates.");

        $totalDeleted = 0;

        foreach ($duplicateGroups as $group) {
            // Get all IDs for this group, ordered by ID ascending (keep lowest/oldest ID)
            $releaseIds = Release::query()
                ->where('searchname', $group->searchname)
                ->where('size', $group->size)
                ->orderBy('id', 'asc')
                ->pluck('id')
                ->toArray();

            $keepId = array_shift($releaseIds); // Keep the first (oldest) ID
            $deleteIds = $releaseIds;            // All remaining IDs are duplicates

            if ($this->option('dry-run')) {
                $this->line("  [Dry-Run] Would keep ID {$keepId} and remove " . count($deleteIds) . " duplicate(s) for '{$group->searchname}'");
                $totalDeleted += count($deleteIds);
            } else {
                // Delete via Eloquent destroy so model events & cascade logic fire
                $deletedCount = Release::destroy($deleteIds);
                $totalDeleted += $deletedCount;
                $this->info("  [+] Kept ID {$keepId}, deleted " . count($deleteIds) . " duplicate(s) for '{$group->searchname}'");
            }
        }

        $action = $this->option('dry-run') ? 'Would delete' : 'Successfully deleted';
        $this->info("[+] {$action} {$totalDeleted} duplicate release record(s) in total.");

        return Command::SUCCESS;
    }
}
