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

// Buffer all output so PHP warnings/notices don't corrupt JSON responses
ob_start();

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../config/i18n.php';

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
use App\Http\Controllers\SavedQueryController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\AICategorizationController;
use App\Http\Controllers\AISqlController;
use App\Http\Controllers\AIUrlFiltersController;
use App\Http\Controllers\AILinkFiltersController;
use App\Http\Controllers\DrBriefController;
use App\Http\Controllers\BulkGenerateController;
use App\Http\Controllers\ApiV1Controller;
use App\Http\Controllers\ApiKeyController;

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
    $router->post('/projects/{id}/schedule', [ProjectController::class, 'saveSchedule'], ['auth' => true]);
    $router->get('/projects/{id}/schedule', [ProjectController::class, 'getSchedule'], ['auth' => true]);

    // =============================================================================
    // CRAWLS
    // =============================================================================
    $router->get('/crawls/info', [CrawlController::class, 'info'], ['auth' => true]);
    $router->post('/crawls/start', [CrawlController::class, 'start'], ['auth' => true]);
    $router->post('/crawls/stop', [CrawlController::class, 'stop'], ['auth' => true]);
    $router->post('/crawls/resume', [CrawlController::class, 'resume'], ['auth' => true]);
    $router->post('/crawls/delete', [CrawlController::class, 'delete'], ['auth' => true]);
    $router->get('/crawls/running', [CrawlController::class, 'runningCrawls'], ['auth' => true]);
    $router->get('/crawls/fetch-sitemaps', [CrawlController::class, 'fetchSitemaps'], ['auth' => true]);

    // =============================================================================
    // JOBS
    // =============================================================================
    $router->get('/jobs/status', [JobController::class, 'status'], ['auth' => true]);
    $router->get('/jobs/logs', [JobController::class, 'logs'], ['auth' => true]);
    $router->get('/jobs/{id}', [JobController::class, 'show'], ['auth' => true]);

    // =============================================================================
    // QUERIES
    // =============================================================================
    $router->post('/query/execute', [QueryController::class, 'execute'], ['auth' => true]);
    $router->get('/query/url-details', [QueryController::class, 'urlDetails'], ['auth' => true]);
    $router->get('/query/url-inlinks', [QueryController::class, 'urlInlinks'], ['auth' => true]);
    $router->get('/query/url-outlinks', [QueryController::class, 'urlOutlinks'], ['auth' => true]);
    $router->get('/query/quick-search', [QueryController::class, 'quickSearch'], ['auth' => true]);
    $router->get('/query/html-source', [QueryController::class, 'htmlSource'], ['auth' => true]);

    // =============================================================================
    // EXPORTS
    // =============================================================================
    $router->post('/export/csv', [ExportController::class, 'csv'], ['auth' => true]);
    $router->post('/export/links-csv', [ExportController::class, 'linksCsv'], ['auth' => true]);
    $router->post('/export/redirect-chains-csv', [ExportController::class, 'redirectChainsCsv'], ['auth' => true]);

    // =============================================================================
    // MONITOR
    // =============================================================================
    $router->get('/monitor/preview', [MonitorController::class, 'preview'], ['auth' => true]);
    $router->get('/monitor/system', [MonitorController::class, 'systemMonitor'], ['auth' => true]);

    // =============================================================================
    // CATEGORIZATION
    // =============================================================================
    $router->post('/categorization/save', [CategorizationController::class, 'save'], ['auth' => true]);
    $router->post('/categorization/test', [CategorizationController::class, 'test'], ['auth' => true]);
    $router->get('/categorization/stats', [CategorizationController::class, 'stats'], ['auth' => true]);
    $router->get('/categorization/table', [CategorizationController::class, 'table'], ['auth' => true]);

    // =============================================================================
    // SAVED QUERIES (per-user SQL snippets dans SQL Explorer)
    // =============================================================================
    $router->get('/saved-queries', [SavedQueryController::class, 'index'], ['auth' => true]);
    $router->post('/saved-queries', [SavedQueryController::class, 'create'], ['auth' => true]);
    // Routes catégorie déclarées AVANT les routes /{id} pour ne pas matcher par erreur
    $router->put('/saved-queries/category/rename', [SavedQueryController::class, 'renameCategory'], ['auth' => true]);
    $router->delete('/saved-queries/category', [SavedQueryController::class, 'deleteCategory'], ['auth' => true]);
    $router->put('/saved-queries/{id}', [SavedQueryController::class, 'update'], ['auth' => true]);
    $router->delete('/saved-queries/{id}', [SavedQueryController::class, 'delete'], ['auth' => true]);

    // =============================================================================
    // SETTINGS (admin only) + AI categorization (all users with crawl mgmt rights)
    // =============================================================================
    $router->get('/settings', [SettingsController::class, 'show'], ['auth' => true, 'admin' => true]);
    $router->post('/settings/ai/test', [SettingsController::class, 'testAi'], ['auth' => true, 'admin' => true]);
    $router->post('/settings/ai/prompt', [SettingsController::class, 'saveDrBriefPrompt'], ['auth' => true, 'admin' => true]);
    $router->post('/settings', [SettingsController::class, 'save'], ['auth' => true, 'admin' => true]);
    $router->post('/settings/budget', [SettingsController::class, 'saveBudget'], ['auth' => true, 'admin' => true]);

    $router->post('/categorization/ai-suggest', [AICategorizationController::class, 'suggest'], ['auth' => true]);
    $router->post('/sql/ai-generate', [AISqlController::class, 'generate'], ['auth' => true]);
    $router->post('/url-explorer/ai-filters', [AIUrlFiltersController::class, 'suggest'], ['auth' => true]);
    $router->post('/link-explorer/ai-filters', [AILinkFiltersController::class, 'suggest'], ['auth' => true]);
    $router->post('/dr-brief/chat', [DrBriefController::class, 'chat'], ['auth' => true]);
    $router->post('/dr-brief/dismiss-greeting', [DrBriefController::class, 'dismissGreeting'], ['auth' => true]);

    // Bulk AI Generator — multi-item, multi-context generation in a batch job.
    $router->get( '/bulk-generate/context-fields', [BulkGenerateController::class, 'contextFields'], ['auth' => true]);
    $router->get( '/bulk-generate/existing-keys',  [BulkGenerateController::class, 'existingKeys'],  ['auth' => true]);
    $router->post('/bulk-generate/estimate',       [BulkGenerateController::class, 'estimate'],      ['auth' => true]);
    $router->post('/bulk-generate/preview',        [BulkGenerateController::class, 'preview'],       ['auth' => true]);
    $router->post('/bulk-generate/start',          [BulkGenerateController::class, 'start'],         ['auth' => true]);
    $router->get( '/bulk-generate/status',         [BulkGenerateController::class, 'status'],        ['auth' => true]);
    $router->post('/bulk-generate/stop',           [BulkGenerateController::class, 'stop'],          ['auth' => true]);

    // =============================================================================
    // API KEYS (session + admin) — managed from the Settings page
    // =============================================================================
    $router->get(   '/keys',      [ApiKeyController::class, 'index'],  ['auth' => true, 'admin' => true]);
    $router->post(  '/keys',      [ApiKeyController::class, 'create'], ['auth' => true, 'admin' => true]);
    $router->delete('/keys/{id}', [ApiKeyController::class, 'revoke'], ['auth' => true, 'admin' => true]);

    // =============================================================================
    // PUBLIC API v1 — Bearer token auth (acts as the key's owner)
    // =============================================================================
    $router->get( '/v1/projects',              [ApiV1Controller::class, 'projects'], ['token' => true]);
    // Scheduling (recurring crawls)
    $router->get(   '/v1/schedules',                  [ApiV1Controller::class, 'schedules'],            ['token' => true]);
    $router->get(   '/v1/projects/{id}/schedule',     [ApiV1Controller::class, 'getProjectSchedule'],   ['token' => true]);
    $router->put(   '/v1/projects/{id}/schedule',     [ApiV1Controller::class, 'saveProjectSchedule'],  ['token' => true]);
    $router->patch( '/v1/projects/{id}/schedule',     [ApiV1Controller::class, 'toggleProjectSchedule'],['token' => true]);
    $router->delete('/v1/projects/{id}/schedule',     [ApiV1Controller::class, 'deleteProjectSchedule'],['token' => true]);
    $router->post('/v1/crawls',                [ApiV1Controller::class, 'createCrawl'],  ['token' => true]);
    $router->get( '/v1/crawls/{id}/status',    [ApiV1Controller::class, 'crawlStatus'],  ['token' => true]);
    $router->post('/v1/crawls/{id}/stop',      [ApiV1Controller::class, 'stopCrawl'],    ['token' => true]);
    $router->post('/v1/crawls/{id}/start',     [ApiV1Controller::class, 'startCrawl'],   ['token' => true]);
    $router->get( '/v1/projects/{id}/crawls',  [ApiV1Controller::class, 'crawls'],   ['token' => true]);
    $router->get( '/v1/crawls/{id}',           [ApiV1Controller::class, 'crawl'],    ['token' => true]);
    $router->get( '/v1/crawls/{id}/schema',    [ApiV1Controller::class, 'schema'],   ['token' => true]);
    $router->get( '/v1/crawls/{id}/content',   [ApiV1Controller::class, 'content'],  ['token' => true]);
    $router->get( '/v1/crawls/{id}/html',      [ApiV1Controller::class, 'html'],     ['token' => true]);
    $router->post('/v1/crawls/{id}/query',     [ApiV1Controller::class, 'query'],    ['token' => true]);

    // =============================================================================
    // DISPATCH
    // =============================================================================
    $router->dispatch($request);

} catch (\Throwable $e) {
    // Log the error for debugging (stray output is captured by ob_start above)
    $strayOutput = ob_get_clean();
    if ($strayOutput) {
        error_log("[Scouter API] Stray output captured: " . substr($strayOutput, 0, 500));
    }
    error_log("[Scouter API] Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    App\Http\Response::serverError('An internal error occurred.');
}
