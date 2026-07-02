<?php
/**
 * Migration: create the async jobs tables at schema-migration time.
 *
 * JobManager also creates these tables lazily, but workers and the Go crawler can
 * start before any request instantiates JobManager on a fresh install.
 */
$pdo = \App\Database\PostgresDatabase::getInstance()->getConnection();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jobs (
            id SERIAL PRIMARY KEY,
            project_dir TEXT NOT NULL,
            project_name TEXT NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            progress INTEGER DEFAULT 0,
            pid INTEGER DEFAULT NULL,
            command TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP DEFAULT NULL,
            finished_at TIMESTAMP DEFAULT NULL,
            error TEXT DEFAULT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS job_logs (
            id SERIAL PRIMARY KEY,
            job_id INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
            message TEXT,
            type VARCHAR(20) DEFAULT 'info',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_jobs_project_dir ON jobs(project_dir)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_job_logs_job_id ON job_logs(job_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status_created ON jobs(status, created_at)");

    echo "(created jobs tables) ";
    return true;
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    return false;
}
