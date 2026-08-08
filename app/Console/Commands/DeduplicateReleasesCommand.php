<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Release;

class DeduplicateReleasesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'releases:deduplicate 
                            {--dry-run : Count duplicates without deleting} 
                            {--batch=500 : Number of duplicate groups to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely remove duplicate releases using Eloquent models';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('[*] Scanning database for duplicate release groups...');

        // 1. Calculate total duplicate groups for progress bar
        $duplicateGroupsQuery = DB::table('releases')
            ->select('searchname', 'size', DB::raw('COUNT(*) as total'))
            ->where('searchname', '!=', '')
            ->whereNotNull('searchname')
            ->groupBy('searchname', 'size')
            ->having('total', '>', 1);

        $totalGroups = $duplicateGroupsQuery->get()->count();

        if ($totalGroups === 0) {
            $this->info('[+] No duplicate releases found.');
            return Command::SUCCESS;
        }

        $this->warn("[!] Found {$totalGroups} release group(s) containing duplicate records.");

        $batchSize = (int) $this->option('batch');
        $isDryRun = (bool) $this->option('dry-run');

        // Progress bar for clean CLI feedback
        $bar = $this->output->createProgressBar($totalGroups);
        $bar->start();

        $totalDeleted = 0;

        // 2. Chunk processing to conserve PHP memory
        $duplicateGroupsQuery->orderBy('total', 'desc')
            ->chunk($batchSize, function ($duplicateGroups) use ($isDryRun, &$totalDeleted, $bar) {
                foreach ($duplicateGroups as $group) {
                    // Fetch IDs for this group (ordered by ID ascending so oldest/lowest ID is kept)
                    $releaseIds = Release::query()
                        ->where('searchname', $group->searchname)
                        ->where('size', $group->size)
                        ->orderBy('id', 'asc')
                        ->pluck('id')
                        ->toArray();

                    $keepId = array_shift($releaseIds); // Keep the first (oldest) ID
                    $deleteIds = $releaseIds;           // All remaining IDs are duplicates
                    $deleteCount = count($deleteIds);

                    if ($isDryRun) {
                        $totalDeleted += $deleteCount;
                        if ($this->output->isVerbose()) {
                            $this->line("  [Dry-Run] Keep ID {$keepId} | Remove {$deleteCount} dupes for '{$group->searchname}'");
                        }
                    } else {
                        // Batch deletion in chunks of 100 to keep SQL WHERE IN clauses small
                        foreach (array_chunk($deleteIds, 100) as $chunk) {
                            $deletedCount = Release::destroy($chunk);
                            $totalDeleted += $deletedCount;
                        }

                        if ($this->output->isVerbose()) {
                            $this->info("  [+] Kept ID {$keepId}, deleted {$deleteCount} dupes for '{$group->searchname}'");
                        }
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->output->newLine(2);

        $action = $isDryRun ? 'Would delete' : 'Successfully deleted';
        $this->info("[+] {$action} {$totalDeleted} duplicate release record(s) across {$totalGroups} title group(s).");

        return Command::SUCCESS;
    }
}
