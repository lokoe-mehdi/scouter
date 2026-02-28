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

  case "crawl":
    $dir=(isset($argv[2]))?$argv[2]:"none";
    $jobManager = new \App\Job\JobManager();
    $job = $jobManager->getJobByProject($dir);
    
    try {
        // Le crawl inclut maintenant le post-traitement (inlinks, pagerank, semantic, categorization)
        // via CrawlDatabase::runPostProcessing() appelé dans Crawler::depthStarter()
        Cmder::crawl($dir);
        
        // Re-fetch job status (it may have changed during crawl - e.g., stop signal)
        $currentJob = $jobManager->getJobByProject($dir);
        $currentStatus = $currentJob ? $currentJob->status : null;
        
        // Only mark as completed if still running (not stopped/stopping)
        if ($currentJob && $currentStatus === 'running') {
            $jobManager->updateJobStatus($currentJob->id, 'completed');
            $jobManager->addLog($currentJob->id, "Crawl completed successfully", 'success');
        }
        // If status is 'stopping', the worker will handle setting it to 'stopped'
        
    } catch (Exception $e) {
        // Marquer le job comme échoué en cas d'erreur
        if ($job) {
            $jobManager->updateJobStatus($job->id, 'failed');
            $jobManager->setJobError($job->id, $e->getMessage());
            $jobManager->addLog($job->id, "Crawl failed: " . $e->getMessage(), 'error');
        }
        echo "\n\nERROR: " . $e->getMessage() . "\n";
    }
  break;

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
    } catch (Exception $e) {
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