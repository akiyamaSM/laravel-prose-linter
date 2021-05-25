<?php

namespace Beyondcode\LaravelProseLinter\Console\Commands;

use Beyondcode\LaravelProseLinter\Exceptions\LinterException;
use Illuminate\Console\Command;
use Beyondcode\LaravelProseLinter\Linter\ViewLinter;
use Illuminate\Support\Facades\File;

class ProseViewLinter extends Command
{
    protected $signature = 'lint:blade {bladeTemplate?} {--exclude= : directories to exclude in format dir1,dir2,dir3 } {--json}';

    protected $description = "Lints blade templates.";

    public function handle()
    {
        $viewLinter = new ViewLinter();

        // analyze argument and options input and ask for confirmation if necessary / abort if invalid
        $singleBladeTemplate = $this->argument("bladeTemplate");
        $directoriesToExclude = $this->option("exclude") !== null ? explode(",", $this->option("exclude")) : [];

        $outputAsJson = $this->option("json") ? true : false;

        if ($singleBladeTemplate === null && empty($directoriesToExclude)) {
            if (!$this->confirm("Are you sure you want to lint all blade files in your application?")) {
                $this->line("❌ Linting aborted.");
                return;
            }
        } elseif ($singleBladeTemplate !== null && !empty($directoriesToExclude)) {
            $this->error("Invalid parameters. Please provide either a single file to lint or directories to exclude or no further options to lint all blade files.");
            return;
        }

        // collect blade files to lint
        $templatesToLint = [];
        if ($singleBladeTemplate !== null) {
            $this->line("Linting single blade template with key '{$singleBladeTemplate}'.");
            $templatesToLint[] = $singleBladeTemplate;
            $totalFilesToLint = count($templatesToLint);
        } else {
            $templatesToLint = $viewLinter->readBladeKeys($directoriesToExclude);
            $totalFilesToLint = count($templatesToLint);

            $message = "Linting all blade templates";

            if (!empty($directoriesToExclude)) {
                $message .= ", excluding: " . implode(", ", $directoriesToExclude);
            }
            $this->line($message);

            $this->line("Found {$totalFilesToLint} blade files.");
        }

        $this->info("🗣  Start linting ...");
        $startTime = microtime(true);

        // create progress bar
        $progressBar = $this->output->createProgressBar($totalFilesToLint);


        $results = [];
        foreach ($templatesToLint as $templateToLint) {

            try {
                $progressBar->advance();

                $filePath = $viewLinter->createLintableCopy($templateToLint);
                $viewLinter->lintFile($filePath, $templateToLint);
            } catch (LinterException $linterException) {
                $results = array_merge($results, $linterException->getResult()->toArray());
            } catch (\Exception $exception) {
                $this->warn("({$templateToLint}) Unexpected error.");
            } finally {
                $viewLinter->deleteLintableCopy();
            }

        }

        $lintingDuration = round(microtime(true) - $startTime, 2);
        $progressBar->finish();
        $this->newLine();

        if (count($results) > 0) {
            if ($outputAsJson) {
                $filePath = storage_path("linting_result_" . date("Y-m-d-H-i-s") . ".json");
                File::put($filePath, json_encode($results, JSON_UNESCAPED_SLASHES));

                $this->warn("Linting errors were found. For detail, check results in file");
                $this->warn($filePath);
            } else {
                $this->table(
                    ['Template Key', 'Line', 'Position', 'Message', 'Severity', 'Condition'],
                    $results
                );
            }
        } else {
            $this->info("✅ No errors, warnings or suggestions found.");
        }

        $this->info("🏁 Finished linting in {$lintingDuration} seconds.");

    }

}