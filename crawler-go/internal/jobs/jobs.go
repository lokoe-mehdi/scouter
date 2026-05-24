// Package jobs ports the crawl-job slice of app/Job/JobManager.php and the
// claim/recover logic of app/bin/worker.php. The Go worker owns ONLY
// command='crawl' jobs; the PHP worker keeps batch/delete/bulk-ai jobs.
package jobs

import (
	"context"
	"errors"

	"github.com/jackc/pgx/v5"
	"scouter-crawler/internal/db"
)

// Job is a row of the jobs table (crawl jobs only).
type Job struct {
	ID          int
	ProjectDir  string
	ProjectName string
	Command     string
	Status      string
}

type Manager struct {
	pool     *db.Pool
	workerID string
}

func New(pool *db.Pool, workerID string) *Manager {
	return &Manager{pool: pool, workerID: workerID}
}

// crawlStatusMap mirrors JobManager::updateJobStatus.
var crawlStatusMap = map[string]string{
	"queued": "queued", "running": "running", "stopping": "stopping",
	"stopped": "stopped", "completed": "finished", "failed": "error", "pending": "pending",
}

// RecoverOrphans re-queues crawl jobs left 'running' by a crashed worker, and
// resets their crawls row to queued. Scoped to command='crawl'.
func (m *Manager) RecoverOrphans(ctx context.Context) error {
	rows, err := m.pool.Query(ctx, `
		UPDATE jobs SET status='queued', started_at=NULL, pid=NULL
		WHERE id IN (SELECT id FROM jobs WHERE status='running' AND command='crawl' FOR UPDATE SKIP LOCKED)
		RETURNING project_dir`)
	if err != nil {
		return err
	}
	var dirs []string
	for rows.Next() {
		var d string
		if err := rows.Scan(&d); err != nil {
			rows.Close()
			return err
		}
		dirs = append(dirs, d)
	}
	rows.Close()
	for _, d := range dirs {
		_, _ = m.pool.Exec(ctx, "UPDATE crawls SET status='queued', in_progress=1 WHERE path=$1", d)
	}
	return nil
}

// ClaimNext atomically claims the oldest queued crawl job (FOR UPDATE SKIP
// LOCKED) and marks it running. Returns (nil,nil) when the queue is empty.
func (m *Manager) ClaimNext(ctx context.Context) (*Job, error) {
	tx, err := m.pool.Begin(ctx)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback(ctx)

	var j Job
	err = tx.QueryRow(ctx, `
		SELECT id, project_dir, project_name, command, status FROM jobs
		WHERE status='queued' AND command='crawl'
		ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED`).
		Scan(&j.ID, &j.ProjectDir, &j.ProjectName, &j.Command, &j.Status)
	if err != nil {
		if isNoRows(err) {
			return nil, tx.Commit(ctx)
		}
		return nil, err
	}
	if _, err := tx.Exec(ctx,
		"UPDATE jobs SET status='running', pid=$1, started_at=CURRENT_TIMESTAMP WHERE id=$2",
		0, j.ID); err != nil {
		return nil, err
	}
	if err := tx.Commit(ctx); err != nil {
		return nil, err
	}
	j.Status = "running"
	// keep crawls row in sync
	_, _ = m.pool.Exec(ctx, "UPDATE crawls SET status='running', in_progress=1 WHERE path=$1", j.ProjectDir)
	return &j, nil
}

// UpdateStatus updates the job and (for crawl jobs) syncs the crawls row.
func (m *Manager) UpdateStatus(ctx context.Context, jobID int, status, projectDir string) error {
	sql := "UPDATE jobs SET status=$1"
	switch status {
	case "completed", "failed", "stopped":
		sql += ", finished_at=CURRENT_TIMESTAMP"
	}
	sql += " WHERE id=$2"
	if _, err := m.pool.Exec(ctx, sql, status, jobID); err != nil {
		return err
	}
	cs := crawlStatusMap[status]
	if cs == "" {
		cs = status
	}
	inProgress := 0
	switch status {
	case "queued", "running", "stopping", "pending":
		inProgress = 1
	}
	csql := "UPDATE crawls SET status=$1, in_progress=$2"
	switch status {
	case "completed", "failed", "stopped":
		csql += ", finished_at=CURRENT_TIMESTAMP"
	}
	csql += " WHERE path=$3"
	_, err := m.pool.Exec(ctx, csql, cs, inProgress, projectDir)
	return err
}

func (m *Manager) AddLog(ctx context.Context, jobID int, message, typ string) {
	_, _ = m.pool.Exec(ctx, "INSERT INTO job_logs (job_id, message, type) VALUES ($1,$2,$3)", jobID, message, typ)
}

func (m *Manager) SetError(ctx context.Context, jobID int, errMsg string) {
	_, _ = m.pool.Exec(ctx, "UPDATE jobs SET error=$1, status='failed' WHERE id=$2", errMsg, jobID)
}

func isNoRows(err error) bool {
	return errors.Is(err, pgx.ErrNoRows)
}
