package storage

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// localStore writes blobs under a single root directory, mirroring the key path
// onto the filesystem (html/123/abcd.gz → <root>/html/123/abcd.gz).
type localStore struct {
	root string
	logf func(string, ...any)
}

func newLocal(root string, logf func(string, ...any)) (*localStore, error) {
	if logf == nil {
		logf = func(string, ...any) {}
	}
	// 0o777 (not 0o755) so the directory is writable by ANY uid : on un dev
	// poste WSL/Docker la racine est typiquement un bind-mount entre l'hôte et
	// le conteneur, et l'uid du process crawler ne matche pas toujours celui du
	// propriétaire côté hôte. Filtré par l'umask du process à l'exécution.
	if err := os.MkdirAll(root, 0o777); err != nil {
		return nil, fmt.Errorf("create local storage root %q: %w (set STORAGE_PATH to a writable directory, ou définis S3_BUCKET/S3_ACCESS_KEY_ID/S3_SECRET_ACCESS_KEY pour utiliser un object store)", root, err)
	}
	// Force perms même si le dossier existait déjà (l'umask du MkdirAll peut
	// avoir rogné les bits) — best effort, on ignore l'erreur si on n'est pas
	// proprio (cas d'un volume monté en lecture seule côté hôte, etc.).
	_ = os.Chmod(root, 0o777)
	abs, _ := filepath.Abs(root)
	logf("storage: local backend at %s", abs)
	return &localStore{root: root, logf: logf}, nil
}

func (l *localStore) path(key string) string {
	return filepath.Join(l.root, filepath.FromSlash(key))
}

func (l *localStore) Put(_ context.Context, key string, data []byte) error {
	p := l.path(key)
	// 0o777 pour les sous-dossiers comme la racine : le reader (conteneur web)
	// tourne souvent avec un uid différent du crawler et doit pouvoir lire.
	if err := os.MkdirAll(filepath.Dir(p), 0o777); err != nil {
		return err
	}
	// Write to a temp file then rename, so a concurrent reader never sees a
	// half-written blob (rename is atomic on the same filesystem).
	tmp := p + ".tmp"
	if err := os.WriteFile(tmp, data, 0o644); err != nil {
		return err
	}
	return os.Rename(tmp, p)
}

func (l *localStore) Get(_ context.Context, key string) ([]byte, bool, error) {
	b, err := os.ReadFile(l.path(key))
	if err != nil {
		if os.IsNotExist(err) {
			return nil, false, nil
		}
		return nil, false, err
	}
	return b, true, nil
}

func (l *localStore) DeletePrefix(_ context.Context, prefix string) error {
	target := strings.TrimRight(l.path(prefix), string(filepath.Separator))
	err := os.RemoveAll(target)
	if os.IsNotExist(err) {
		return nil
	}
	return err
}

func (l *localStore) Kind() string { return "local" }
