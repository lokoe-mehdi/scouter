<?php
/**
 * Point d'entrée unique pour l'API REST Scouter
 * 
 * Toutes les requêtes API sont routées vers ce fichier via .htaccess.
 * Le routeur dispatch ensuite vers le controller approprié.
 * 
 * @package    Scouter
 * @subpackage Api
 * @author     Mehdi Colin
 * @version    1.0.0
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Http\Router;
use App\Http\Request;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\CrawlController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\MonitorController;
use App\Http\Controllers\CategorizationController;

$request = new Request();

try {
    $router = new Router();

    // =============================================================================
    // CATEGORIES
    // =============================================================================
    $router->get('/categories', [CategoryController::class, 'index'], ['auth' => true]);
    $router->post('/categories', [CategoryController::class, 'create'], ['auth' => true]);
    $router->put('/categories/{id}', [CategoryController::class, 'update'], ['auth' => true]);
    $router->delete('/categories/{id}', [CategoryController::class, 'delete'], ['auth' => true]);
    $router->delete('/categories', [CategoryController::class, 'deleteFromBody'], ['auth' => true]);
    $router->post('/categories/assign', [CategoryController::class, 'assign'], ['auth' => true]);

    // =============================================================================
    // USERS (admin only)
    // =============================================================================
    $router->get('/users', [UserController::class, 'index'], ['auth' => true, 'admin' => true]);
    $router->post('/users', [UserController::class, 'create'], ['auth' => true, 'admin' => true]);
    $router->put('/users/{id}', [UserController::class, 'update'], ['auth' => true, 'admin' => true]);
    $router->delete('/users/{id}', [UserController::class, 'delete'], ['auth' => true, 'admin' => true]);
    $router->put('/users', [UserController::class, 'updateFromBody'], ['auth' => true, 'admin' => true]);
    $router->get('/logout', [UserController::class, 'logout'], ['auth' => true]);
    $router->post('/logout', [UserController::class, 'logout'], ['auth' => true]);

    // =============================================================================
    // PROJECTS
    // =============================================================================
    $router->get('/projects', [ProjectController::class, 'index'], ['auth' => true]);
    $router->get('/projects/{id}', [ProjectController::class, 'show'], ['auth' => true]);
    $router->post('/projects', [ProjectController::class, 'create'], ['auth' => true, 'canCreate' => true]);
    $router->put('/projects/{id}', [ProjectController::class, 'update'], ['auth' => true]);
    $router->delete('/projects/{id}', [ProjectController::class, 'delete'], ['auth' => true]);
    $router->delete('/projects', [ProjectController::class, 'deleteFromBody'], ['auth' => true]);
    $router->get('/projects/{id}/shares', [ProjectController::class, 'shares'], ['auth' => true]);
    $router->post('/projects/{id}/share', [ProjectController::class, 'share'], ['auth' => true]);
    $router->post('/projects/{id}/unshare', [ProjectController::class, 'unshare'], ['auth' => true]);
    $router->get('/projects/{id}/stats', [ProjectController::class, 'stats'], ['auth' => true]);
    $router->post('/projects/duplicate', [ProjectController::class, 'duplicate'], ['auth' => true]);
    // Note: share/unshare sont gérés via POST /projects avec action=share|unshare dans le body

    // =============================================================================
    // CRAWLS
    // =============================================================================
    $router->get('/crawls/info', [CrawlController::class, 'info'], ['auth' => true]);
    $router->post('/crawls/start', [CrawlController::class, 'start'], ['auth' => true]);
    $router->post('/crawls/stop', [CrawlController::class, 'stop'], ['auth' => true]);
    $router->post('/crawls/resume', [CrawlController::class, 'resume'], ['auth' => true]);
    $router->post('/crawls/delete', [CrawlController::class, 'delete'], ['auth' => true]);
    $router->get('/crawls/running', [CrawlController::class, 'runningCrawls'], ['auth' => true]);

    // =============================================================================
    // JOBS
    // =============================================================================
    $router->get('/jobs/status', [JobController::class, 'status'], ['auth' => true]);
    $router->get('/jobs/logs', [JobController::class, 'logs'], ['auth' => true]);
    $router->get('/jobs/:id', [JobController::class, 'show'], ['auth' => true]);

    // =============================================================================
    // QUERIES
    // =============================================================================
    $router->post('/query/execute', [QueryController::class, 'execute'], ['auth' => true]);
    $router->get('/query/url-details', [QueryController::class, 'urlDetails'], ['auth' => true]);
    $router->get('/query/quick-search', [QueryController::class, 'quickSearch'], ['auth' => true]);
    $router->get('/query/html-source', [QueryController::class, 'htmlSource'], ['auth' => true]);

    // =============================================================================
    // EXPORTS
    // =============================================================================
    $router->post('/export/csv', [ExportController::class, 'csv'], ['auth' => true]);
    $router->post('/export/links-csv', [ExportController::class, 'linksCsv'], ['auth' => true]);

    // =============================================================================
    // MONITOR
    // =============================================================================
    $router->get('/monitor/preview', [MonitorController::class, 'preview'], ['auth' => true]);
    $router->get('/monitor/system', [MonitorController::class, 'systemMonitor'], ['auth' => true]);
    $router->post('/monitor/test-crawls', [MonitorController::class, 'launchTestCrawls'], ['auth' => true, 'admin' => true]);

    // =============================================================================
    // CATEGORIZATION
    // =============================================================================
    $router->post('/categorization/save', [CategorizationController::class, 'save'], ['auth' => true]);
    $router->post('/categorization/test', [CategorizationController::class, 'test'], ['auth' => true]);
    $router->get('/categorization/stats', [CategorizationController::class, 'stats'], ['auth' => true]);
    $router->get('/categorization/table', [CategorizationController::class, 'table'], ['auth' => true]);

    // =============================================================================
    // DISPATCH
    // =============================================================================
    $router->dispatch($request);

} catch (\Throwable $e) {
    App\Http\Response::serverError($e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
}
