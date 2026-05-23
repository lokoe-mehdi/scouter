<?php
ini_set("memory_limit",-1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

require("vendor/autoload.php");
use App\Cli\Cmder;

Cmder::init();

$module = (isset($argv[1]))?$argv[1]:"none";

// Toujours afficher le header en premier
Cmder::header();

switch($module){

  // Le crawl est assuré par le worker Go (crawler-go/). Ce dispatcher CLI ne sert
  // plus qu'aux jobs non-crawl lancés par le worker PHP (delete / batch-categorize /
  // bulk-ai) et à la commande dashboard (serveur de dev local).

  case "batch-categorize-project":
    $arg = (isset($argv[2])) ? $argv[2] : "none";
    $jobManager = new \App\Job\JobManager();

    try {
        Cmder::batchCategorizeProject($arg);

        // Get job ID from environment variable (set by worker)
        $jobId = getenv('JOB_ID');
        if ($jobId) {
            $jobManager->updateJobStatus($jobId, 'completed');
            $jobManager->addLog($jobId, "Batch categorization completed successfully", 'success');
        }
    } catch (\Throwable $e) {
        // Mark job as failed on error
        $jobId = getenv('JOB_ID');
        if ($jobId) {
            $jobManager->updateJobStatus($jobId, 'failed');
            $jobManager->setJobError($jobId, $e->getMessage());
            $jobManager->addLog($jobId, "Batch categorization failed: " . $e->getMessage(), 'error');
        }
        echo "\n\nERROR: " . $e->getMessage() . "\n";
    }
  break;

  case "bulk-ai-generate":
    $arg = (isset($argv[2])) ? $argv[2] : "none";
    $jobManager = new \App\Job\JobManager();

    try {
        Cmder::bulkAiGenerate($arg);

        $jobId = getenv('JOB_ID');
        if ($jobId) {
            $jobManager->updateJobStatus($jobId, 'completed');
            $jobManager->addLog($jobId, "Bulk AI generation completed", 'success');
        }
    } catch (\Throwable $e) {
        $jobId = getenv('JOB_ID');
        if ($jobId) {
            $jobManager->updateJobStatus($jobId, 'failed');
            $jobManager->setJobError($jobId, $e->getMessage());
            $jobManager->addLog($jobId, "Bulk AI generation failed: " . $e->getMessage(), 'error');
        }
        echo "\n\nERROR: " . $e->getMessage() . "\n";
    }
  break;

  case "delete-crawl":
  case "delete-project":
    $arg = (isset($argv[2])) ? $argv[2] : "none";
    $jobManager = new \App\Job\JobManager();
    $cmdMethod = $module === 'delete-crawl' ? 'deleteCrawl' : 'deleteProject';

    try {
        Cmder::$cmdMethod($arg);

        $jobId = getenv('JOB_ID');
        if ($jobId) {
            $jobManager->updateJobStatus($jobId, 'completed');
            $jobManager->addLog($jobId, ucfirst(str_replace('-', ' ', $module)) . " completed successfully", 'success');
        }
    } catch (\Throwable $e) {
        $jobId = getenv('JOB_ID');
        if ($jobId) {
            $jobManager->updateJobStatus($jobId, 'failed');
            $jobManager->setJobError($jobId, $e->getMessage());
            $jobManager->addLog($jobId, ucfirst(str_replace('-', ' ', $module)) . " failed: " . $e->getMessage(), 'error');
        }
        echo "\n\nERROR: " . $e->getMessage() . "\n";
    }
  break;

  case "dashboard":
    Cmder::dashboard();
  break;

  case "none":
    Cmder::info("Type scouter --help to know how to use it");
  break;

  default:
    Cmder::alert("Invalid option, enter scouter --help to know how to use it");
  break;

}