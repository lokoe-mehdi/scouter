package db

import (
	"context"

	"github.com/jackc/pgx/v5"
)

// CrawlRecord is the crawls-table row needed to launch a crawl.
type CrawlRecord struct {
	ID        int
	ProjectID *int
	Domain    string
	Path      string
	Status    string
	DepthMax  int
	CrawlType string
	Config    []byte // raw JSONB
}

func (p *Pool) GetCrawlByPath(ctx context.Context, path string) (*CrawlRecord, error) {
	return p.scanCrawl(ctx, "SELECT id, project_id, domain, path, status, depth_max, crawl_type, config FROM crawls WHERE path=$1", path)
}

func (p *Pool) GetCrawlByID(ctx context.Context, id int) (*CrawlRecord, error) {
	return p.scanCrawl(ctx, "SELECT id, project_id, domain, path, status, depth_max, crawl_type, config FROM crawls WHERE id=$1", id)
}

func (p *Pool) scanCrawl(ctx context.Context, sql string, arg any) (*CrawlRecord, error) {
	var r CrawlRecord
	err := p.QueryRow(ctx, sql, arg).Scan(
		&r.ID, &r.ProjectID, &r.Domain, &r.Path, &r.Status, &r.DepthMax, &r.CrawlType, &r.Config)
	if err == pgx.ErrNoRows {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &r, nil
}
