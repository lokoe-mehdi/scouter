<?php

namespace App\Cli;

use Spyc;
use App\Database\CrawlDatabase;
use PDO;

/**
 * Interface en ligne de commande (CLI) pour Scouter
 * 
 * Dispatcher des jobs CLI non-crawl (le crawl est assuré par crawler-go) :
 * - `batch-categorize-project:<id>` : re-catégorise tous les crawls d'un projet
 * - `bulk-ai-generate:<id>`         : génération IA en masse
 * - `delete-crawl:<id>` / `delete-project:<id>` : suppression asynchrone
 * - `dashboard`                     : serveur web local (dev)
 *
 * @package    Scouter
 * @subpackage CLI
 * @author     Mehdi Colin
 * @version    2.0.0
 *
 * @example
 * ```bash
 * php scouter.php batch-categorize-project:42
 * php scouter.php dashboard
 * ```
 */
class Cmder
{
  static $dir;

  static function init(){
    self::$dir = dirname(__DIR__,1).DIRECTORY_SEPARATOR;
  }

  static function getDir($arg)
  {
    if(strpos($arg,DIRECTORY_SEPARATOR) === false) {
      $dirname = $arg;
    } else
    {
      preg_match("#.*[\\\/](.*)$#",$arg,$match);
      if(empty($match[1]))
      {
        preg_match("#.*[\\\/](.*)[\\\/]$#",$arg,$match); 
      }
      if(!isset($match[1])) { $dirname = ''; }
      else { $dirname = $match[1]; }
    }
    return $dirname;
  }

  static function header()
  {
    echo "\r\n";
    echo "\033[38;5;49m   ███████╗ ██████╗ ██████╗ ██╗   ██╗████████╗███████╗██████╗ \r\n";
    echo "\033[38;5;48m   ██╔════╝██╔════╝██╔═══██╗██║   ██║╚══██╔══╝██╔════╝██╔══██╗\r\n";
    echo "\033[38;5;47m   ███████╗██║     ██║   ██║██║   ██║   ██║   █████╗  ██████╔╝\r\n";
    echo "\033[38;5;46m   ╚════██║██║     ██║   ██║██║   ██║   ██║   ██╔══╝  ██╔══██╗\r\n";
    echo "\033[38;5;45m   ███████║╚██████╗╚██████╔╝╚██████╔╝   ██║   ███████╗██║  ██║\r\n";
    echo "\033[38;5;44m   ╚══════╝ ╚═════╝ ╚═════╝  ╚═════╝    ╚═╝   ╚══════╝╚═╝  ╚═╝\033[0m\r\n";
    echo "\033[90m   ════════════════════════════════════════════════════════════\033[0m\r\n";
    echo "\033[36m   Crédit:\033[0m Mehdi Colin (lokoe.fr)\r\n";
    echo "\r\n";
  }

  static function dashboard(){
    $web = self::$dir."web".DIRECTORY_SEPARATOR;
    
    echo "Your dashboard is available here : http://localhost:4849";
    //mac && linux : open
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
      exec("explorer http://localhost:4849 & php -S localhost:4849 -t ".$web);
    } else {
      exec("open http://localhost:4849 & php -S localhost:4849 -t ".$web);
    }
  }


  /**
   * Batch categorization for all crawls in a project
   *
   * @param string $arg Format: batch-categorize-project:<project_id>
   */
  static function batchCategorizeProject($arg)
  {
      if (strpos($arg, ':') === false) {
          self::alert("Invalid command format. Expected: batch-categorize-project:<project_id>");
          die();
      }

      list($command, $projectId) = explode(':', $arg, 2);
      $projectId = (int)$projectId;

      if ($projectId <= 0) {
          self::alert("Invalid project ID: $projectId");
          die();
      }

      self::info("Starting batch categorization for project ID: $projectId");

      // Get project config
      $db = \App\Database\PostgresDatabase::getInstance()->getConnection();
      $stmt = $db->prepare("SELECT categorization_config, name FROM projects WHERE id = :id");
      $stmt->execute([':id' => $projectId]);
      $project = $stmt->fetch(\PDO::FETCH_OBJ);

      if (!$project || !$project->categorization_config) {
          self::alert("Project $projectId has no categorization config");
          die();
      }

      $yamlConfig = $project->categorization_config;
      $projectName = $project->name ?? "Project $projectId";

      // Get all crawls
      $crawlRepo = new \App\Database\CrawlRepository();
      $crawls = $crawlRepo->getByProjectId($projectId);

      if (empty($crawls)) {
          self::alert("No crawls found for project $projectId");
          die();
      }

      $totalCrawls = count($crawls);
      $processed = 0;
      $errors = 0;

      self::info("Found $totalCrawls crawl(s) to categorize for project: $projectName");

      // Get job_id from environment (set by worker)
      $jobId = getenv('JOB_ID');
      $jobManager = $jobId ? new \App\Job\JobManager() : null;

      foreach ($crawls as $crawl) {
          $processed++;

          try {
              echo "\r \033[36mProcessing crawl {$processed}/{$totalCrawls} (ID: {$crawl->id}, Domain: {$crawl->domain})...\033[0m                    ";
              flush();

              // Save to crawl-level config (backward compat)
              $stmt = $db->prepare("
                  INSERT INTO categorization_config (crawl_id, config)
                  VALUES (:crawl_id, :config)
                  ON CONFLICT (crawl_id) DO UPDATE SET config = :config2
              ");
              $stmt->execute([
                  ':crawl_id' => $crawl->id,
                  ':config' => $yamlConfig,
                  ':config2' => $yamlConfig
              ]);

              // Run categorization. PostProcessor a été retiré avec le crawl PHP
              // (cf. refacto.md §11) ; on appelle directement CategorizationService
              // (qui reste — partagé UI/API/IA/batch).
              $service = new \App\Analysis\CategorizationService($db);
              $service->applyCategorization($crawl->id, $yamlConfig, $projectId);

              // Update job progress
              if ($jobManager) {
                  $progress = round(($processed / $totalCrawls) * 100);
                  $jobManager->updateJobProgress($jobId, $progress);
                  $jobManager->addLog($jobId,
                      "Categorized crawl {$crawl->id} - {$crawl->domain} ({$processed}/{$totalCrawls})",
                      'info'
                  );
              }

          } catch (\Exception $e) {
              $errors++;
              echo "\r \033[31mError crawl {$crawl->id}: {$e->getMessage()}\033[0m\n";

              if ($jobManager) {
                  $jobManager->addLog($jobId,
                      "Error crawl {$crawl->id} - {$crawl->domain}: {$e->getMessage()}",
                      'error'
                  );
              }
          }
      }

      echo "\n";
      if ($errors === 0) {
          self::info("Batch categorization completed successfully: {$processed}/{$totalCrawls} crawls");
      } else {
          self::alert("Batch categorization completed with errors: {$errors}/{$totalCrawls} failed");
      }
  }

  /**
   * Async crawl deletion — drops partitions then deletes the crawl record.
   * @param string $arg Format: delete-crawl:<crawl_id>
   */
  static function deleteCrawl($arg)
  {
      if (strpos($arg, ':') === false) {
          self::alert("Invalid format. Expected: delete-crawl:<crawl_id>");
          die();
      }

      [, $crawlId] = explode(':', $arg, 2);
      $crawlId = (int)$crawlId;

      if ($crawlId <= 0) {
          self::alert("Invalid crawl ID: $crawlId");
          die();
      }

      self::info("Starting async deletion for crawl ID: $crawlId");

      $db = \App\Database\PostgresDatabase::getInstance()->getConnection();
      $jobId = getenv('JOB_ID');
      $jobManager = $jobId ? new \App\Job\JobManager() : null;

      try {
          // Drop partitions first (instant, regardless of row count)
          self::info("Dropping partitions for crawl $crawlId...");
          $db->exec("SELECT drop_crawl_partitions($crawlId)");

          // Purge the crawl's HTML blobs (S3/local) — the page-HTML moved out of
          // the DB into the blob store, so its prefix must be cleaned up too.
          try {
              $removed = \App\Storage\Storage::instance()->deletePrefix("html/{$crawlId}/");
              self::info("Removed $removed HTML blob(s) for crawl $crawlId");
          } catch (\Throwable $e) {
              self::alert("HTML blob purge failed for crawl $crawlId: " . $e->getMessage());
          }
          if ($jobManager) $jobManager->updateJobProgress($jobId, 50);

          // Delete categorization config
          $stmt = $db->prepare("DELETE FROM categorization_config WHERE crawl_id = :id");
          $stmt->execute([':id' => $crawlId]);

          // Delete crawl record (lightweight now — no partition data left)
          $stmt = $db->prepare("DELETE FROM crawls WHERE id = :id");
          $stmt->execute([':id' => $crawlId]);
          if ($jobManager) $jobManager->updateJobProgress($jobId, 100);

          self::info("Crawl $crawlId deleted successfully");
      } catch (\Exception $e) {
          self::alert("Error deleting crawl $crawlId: " . $e->getMessage());
          throw $e;
      }
  }

  /**
   * Async project deletion — drops all crawl partitions then deletes everything.
   * @param string $arg Format: delete-project:<project_id>
   */
  static function deleteProject($arg)
  {
      if (strpos($arg, ':') === false) {
          self::alert("Invalid format. Expected: delete-project:<project_id>");
          die();
      }

      [, $projectId] = explode(':', $arg, 2);
      $projectId = (int)$projectId;

      if ($projectId <= 0) {
          self::alert("Invalid project ID: $projectId");
          die();
      }

      self::info("Starting async deletion for project ID: $projectId");

      $db = \App\Database\PostgresDatabase::getInstance()->getConnection();
      $jobId = getenv('JOB_ID');
      $jobManager = $jobId ? new \App\Job\JobManager() : null;

      // Get all crawls for this project (including 'deleting' ones)
      $stmt = $db->prepare("SELECT id, domain FROM crawls WHERE project_id = :pid");
      $stmt->execute([':pid' => $projectId]);
      $crawls = $stmt->fetchAll(\PDO::FETCH_OBJ);

      $totalCrawls = count($crawls);
      $processed = 0;

      self::info("Found $totalCrawls crawl(s) to delete");

      foreach ($crawls as $crawl) {
          $processed++;

          try {
              // Drop partitions (instant)
              $db->exec("SELECT drop_crawl_partitions({$crawl->id})");

              // Purge the crawl's HTML blobs (S3/local) too.
              try {
                  \App\Storage\Storage::instance()->deletePrefix("html/{$crawl->id}/");
              } catch (\Throwable $e) {
                  self::alert("HTML blob purge failed for crawl {$crawl->id}: " . $e->getMessage());
              }

              // Delete categorization config
              $stmt = $db->prepare("DELETE FROM categorization_config WHERE crawl_id = :id");
              $stmt->execute([':id' => $crawl->id]);

              // Delete crawl record
              $stmt = $db->prepare("DELETE FROM crawls WHERE id = :id");
              $stmt->execute([':id' => $crawl->id]);

              echo "\r \033[36mDeleted crawl {$processed}/{$totalCrawls} (ID: {$crawl->id}, {$crawl->domain})\033[0m                    ";
              flush();

              if ($jobManager) {
                  $progress = round(($processed / max($totalCrawls, 1)) * 90); // 90% for crawls
                  $jobManager->updateJobProgress($jobId, $progress);
                  $jobManager->addLog($jobId, "Deleted crawl {$crawl->id} - {$crawl->domain} ({$processed}/{$totalCrawls})", 'info');
              }
          } catch (\Exception $e) {
              self::alert("Error deleting crawl {$crawl->id}: " . $e->getMessage());
              if ($jobManager) {
                  $jobManager->addLog($jobId, "Error crawl {$crawl->id}: {$e->getMessage()}", 'error');
              }
          }
      }

      // Delete project-level data
      self::info("Cleaning up project data...");
      $db->prepare("DELETE FROM crawl_schedules WHERE project_id = :pid")->execute([':pid' => $projectId]);
      $db->prepare("DELETE FROM crawl_categories WHERE project_id = :pid")->execute([':pid' => $projectId]);
      $db->prepare("DELETE FROM project_category_links WHERE project_id IN (SELECT id FROM projects WHERE id = :pid)")->execute([':pid' => $projectId]);
      $db->prepare("DELETE FROM project_shares WHERE project_id = :pid")->execute([':pid' => $projectId]);
      $db->prepare("DELETE FROM projects WHERE id = :pid")->execute([':pid' => $projectId]);

      if ($jobManager) $jobManager->updateJobProgress($jobId, 100);

      self::info("Project $projectId deleted successfully");
  }

  static function alert($msg) {
    self::wred("    --------------------------------------------\r\n");
    self::wred("   - $msg\r\n");
    self::wred("   --------------------------------------------\r\n");
  }

  static function info($msg) {
    self::wgreen("    *******************************************\r\n");
    self::wgreen("   *\r\n");
    self::wgreen("   * $msg\r\n");
    self::wgreen("   *\r\n");
    self::wgreen("   *******************************************\r\n");
  }

  static function wpink($msg) { echo "\033[35m$msg\033[0m "; }
  static function wblue($msg) { echo "\033[36m$msg\033[0m "; }
  static function wyellow($msg) { echo "\033[33m$msg\033[0m "; }
  static function wgreen($msg) { echo "\033[32m$msg\033[0m "; }
  static function wred($msg) { echo "\033[31m$msg\033[0m "; }
  static function wblack($msg) { echo "\033[30m$msg\033[0m "; }

  /**
   * Bulk AI generation worker entrypoint. Receives a bulk_generation_jobs.id
   * via the CLI arg, hands off to the BulkGenerator orchestrator.
   *
   * @param string $arg Format: bulk-ai-generate:<bulk_job_id>
   */
  static function bulkAiGenerate($arg)
  {
      if (strpos($arg, ':') === false) {
          self::alert("Invalid command format. Expected: bulk-ai-generate:<bulk_job_id>");
          die();
      }
      [, $bulkJobId] = explode(':', $arg, 2);
      $bulkJobId = (int)$bulkJobId;
      if ($bulkJobId <= 0) {
          self::alert("Invalid bulk job ID: $bulkJobId");
          die();
      }

      self::info("Starting bulk AI generation for bulk_job_id: $bulkJobId");

      // Bridge progress updates to the JobManager so the existing Jobs UI
      // shows the percentage in real time too.
      $jobMgrId = getenv('JOB_ID');
      $jobManager = $jobMgrId ? new \App\Job\JobManager() : null;

      $gen = new \App\AI\BulkGenerator();
      $gen->run($bulkJobId, function (int $processed, int $total) use ($jobManager, $jobMgrId) {
          if (!$jobManager || $total <= 0) return;
          $jobManager->updateJobProgress($jobMgrId, (int)round($processed * 100 / $total));
      });

      self::info("Bulk AI generation finished for bulk_job_id: $bulkJobId");
  }
}