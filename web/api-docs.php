<?php
/**
 * Interactive API documentation (Swagger UI) — rendered from web/openapi.yaml.
 *
 * Admin-only. Lets you browse the endpoints, see request/response schemas and
 * examples, and "Try it out" against the live API: click Authorize, paste a
 * `sctr_…` key generated in Settings, and fire real requests from the browser
 * (same-origin → no CORS).
 */

require_once(__DIR__ . '/init.php');
$auth->requireAdmin(false);
?>
<!DOCTYPE html>
<html lang="<?= I18n::getInstance()->getLang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scouter — API docs</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>
        body { margin: 0; background: #fafafa; }
        .api-docs-bar {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.7rem 1.2rem; background: #1f2937; color: #fff;
            font-family: system-ui, -apple-system, sans-serif;
        }
        .api-docs-bar a { color: #93c5fd; text-decoration: none; font-size: 0.9rem; }
        .api-docs-bar .title { font-weight: 600; }
        .api-docs-bar .spacer { flex: 1; }
        /* Swagger injects its own styles; keep the top bar hidden (we have ours). */
        .swagger-ui .topbar { display: none; }
        #swagger-ui { max-width: 1100px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="api-docs-bar">
        <span class="material-symbols-outlined" style="font-size:20px;">api</span>
        <span class="title">Scouter API</span>
        <span class="spacer"></span>
        <a href="pages/settings.php">← Settings (manage keys)</a>
    </div>

    <div id="swagger-ui"></div>

    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin></script>
    <script>
        window.addEventListener('load', function () {
            window.ui = SwaggerUIBundle({
                url: 'openapi.yaml',           // served from web root, same origin
                dom_id: '#swagger-ui',
                deepLinking: true,
                persistAuthorization: true,    // keep the pasted token across reloads
                tryItOutEnabled: true,
                defaultModelsExpandDepth: 0,
                presets: [SwaggerUIBundle.presets.apis],
            });
        });
    </script>
</body>
</html>
