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

  case "setup":
    $url=(isset($argv[2]))?$argv[2]:"none";
    Cmder::setup($url);
  break;

  // NOTE: la commande "crawl" est désormais assurée par le worker Go (crawler-go/).
  // Le crawl PHP a été retiré (cf. refacto.md §11). Les jobs command='crawl' sont
  // claim par crawler-go ; le worker PHP ne gère plus que delete / batch-categorize
  // / bulk-ai. Voir aussi DELEGATE_CRAWL_TO_GO.

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

  case "analyse":
    $dir=(isset($argv[2]))?$argv[2]:"none";
    Cmder::inlinks($dir);
    Cmder::pagerank($dir);
    Cmder::semanticAnalysis($dir);
    Cmder::cat($dir);
    Cmder::logs($dir);
  break;

  case "inlinks":
    $dir=(isset($argv[2]))?$argv[2]:"none";
    Cmder::inlinks($dir);
  break;

  case "pagerank":
    $dir=(isset($argv[2]))?$argv[2]:"none";
    Cmder::pagerank($dir);
  break;
  
  case "dashboard":
    Cmder::dashboard();
  break;

  case "cat":
    $dir=(isset($argv[2]))?$argv[2]:"none";
    Cmder::cat($dir);
  break;

  case "logs":
    $dir=(isset($argv[2]))?$argv[2]:"none";
    Cmder::logs($dir);
  break;

  case "semantic-analyse":
    $dir=(isset($argv[2]))?$argv[2]:"none";
    Cmder::semanticAnalysis($dir);
  break;

  case "none":
    Cmder::info("Type scouter --help to know how to use it");
  break;

  default:
    Cmder::alert("Invalid option, enter scouter --help to know how to use it");
  break;

}