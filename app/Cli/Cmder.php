<?php

namespace App\Cli;

use Spyc;
use App\Core\Crawler;
use App\Database\CrawlDatabase;
use PDO;

/**
 * Interface en ligne de commande (CLI) pour Scouter
 * 
 * Cette classe gère toutes les commandes CLI disponibles :
 * - `crawl <path>` : Lance un crawl
 * - `dashboard` : Démarre le serveur web local
 * - Affichage du header ASCII art
 * - Messages colorés (alert, info)
 * 
 * @package    Scouter
 * @subpackage CLI
 * @author     Mehdi Colin
 * @version    1.0.0
 * 
 * @example
 * ```bash
 * php scouter.php crawl lokoe-fr-20241201
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

  static function setup($startUrl)
  {
    self::alert("The 'setup' command is deprecated. Use the web interface to create crawls.");
    die();
  }

  static function crawl($arg)
  {
    if ($arg == 'none') {
        self::alert("Missing directory argument");
        die();
    }

    $projectDir = self::getDir($arg);
    
    // Récupérer le crawl depuis PostgreSQL
    $crawlRecord = CrawlDatabase::getCrawlByPath($projectDir);
    
    if (!$crawlRecord) {
        self::alert("Crawl not found in database for path: $projectDir");
        die();
    }
    
    // Récupérer la config depuis la base de données (JSONB)
    $data = json_decode($crawlRecord->config, true);
    
    if (empty($data) || !isset($data['general']) || !isset($data['general']['start'])) {
        self::alert("Config invalide ou manquante pour crawl ID: {$crawlRecord->id}. La config doit contenir 'general.start'.");
        die();
    }
    
    // Fusionner les configurations general et advanced
    $config = $data['advanced'] ?? [];
    $config['crawl_speed'] = $data['general']['crawl_speed'] ?? 'fast';
    $config['crawl_mode'] = $data['general']['crawl_mode'] ?? 'classic';
    $config['user-agent'] = $data['general']['user-agent'] ?? 'Scouter/0.3 (Crawler developed by Lokoe SASU; +https://lokoe.fr/scouter-crawler)';
    
    // INJECTION DES VARIABLES D'ENVIRONNEMENT (WORKER)
    // Si ces variables sont présentes (injectées par le worker), elles surchargent la config
    if (getenv('MAX_CONCURRENT_CURL')) {
        $config['max_concurrent_curl'] = (int)getenv('MAX_CONCURRENT_CURL');
    }
    if (getenv('MAX_CONCURRENT_CHROME')) {
        $config['max_concurrent_chrome'] = (int)getenv('MAX_CONCURRENT_CHROME');
    }
    
    // Ajouter xPathExtractors et regexExtractors s'ils existent
    if (isset($data['advanced']['xPathExtractors'])) {
        $config['xPathExtractors'] = $data['advanced']['xPathExtractors'];
    } else {
        $config['xPathExtractors'] = [];
    }
    if (isset($data['advanced']['regexExtractors'])) {
        $config['regexExtractors'] = $data['advanced']['regexExtractors'];
    } else {
        $config['regexExtractors'] = [];
    }
    
    $crawl = new Crawler([
        "crawl_id" => $crawlRecord->id,
        "depthMax" => $data['general']['depthMax'] ?? 5,
        "start" => $data['general']['start'],
        "pattern" => $data['general']['domains'] ?? [],
        "config" => $config
    ]);

    $crawl->run();
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


  static function inlinks($arg) {
    self::info("Post-processing is now automatic. Use 'crawl' command or web interface.");
  }

  static function pagerank($arg) {
    self::info("Post-processing is now automatic. Use 'crawl' command or web interface.");
  }

  static function cat($arg) {
    self::info("Post-processing is now automatic. Use 'crawl' command or web interface.");
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

              // Run categorization
              $postProcessor = new \App\Analysis\PostProcessor($crawl->id);
              $postProcessor->categorize();

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

  static function logs($arg) {
    self::info("Logs import is deprecated.");
  }

  static function semanticAnalysis($arg) {
    self::info("Post-processing is now automatic. Use 'crawl' command or web interface.");
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
}