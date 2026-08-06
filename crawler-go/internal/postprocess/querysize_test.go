package postprocess

import (
	"fmt"
	"testing"
)

// chMaxQuerySize is ClickHouse's default `max_query_size` (262 144 octets). Toute
// requête plus longue est rejetée AVANT exécution, avec un SYNTAX_ERROR trompeur
// ("failed at position 262143 … Max query size exceeded") qui pointe un caractère
// arbitraire au lieu de la vraie cause.
const chMaxQuerySize = 262144

// TestQuoteListChunksStayUnderMaxQuerySize verrouille le découpage des IN-lists.
//
// Régression visée : redirectChainAnalysis rendait TOUS les ids d'un coup dans
// `id IN (…)`. Un id fait 8 caractères, rendu en 11 octets ('xxxxxxxx',), donc la
// requête dépassait max_query_size au-delà de ~24 000 ids — ce qui arrive dès
// qu'un site a quelques dizaines de milliers d'URLs en redirection. L'étape
// ch-redirect échouait alors intégralement, et comme le DROP PARTITION s'exécute
// avant, redirect_chains restait VIDE au lieu de garder l'ancien contenu.
func TestQuoteListChunksStayUnderMaxQuerySize(t *testing.T) {
	// Bien au-delà du seuil de casse historique (~24k) pour que le test échoue si
	// quelqu'un retire le chunk() ou remonte la taille de paquet.
	ids := make([]string, 200000)
	for i := range ids {
		ids[i] = fmt.Sprintf("%08x", i)
	}

	// Le préfixe SQL le plus long construit autour d'une IN-list dans ce fichier
	// (redirectChainAnalysis), avec un crawl_id volontairement large.
	const prefix = "SELECT toString(id), url, toString(code), toString(compliant) FROM " +
		"scouter.pages WHERE crawl_id=999999999 AND id IN ("

	chunks := chunk(ids, 5000)
	if len(chunks) < 2 {
		t.Fatalf("chunk() a rendu %d paquet(s) pour %d ids — le découpage ne se fait plus", len(chunks), len(ids))
	}

	var total int
	for i, c := range chunks {
		size := len(prefix) + len(quoteList(c)) + 1 // +1 pour la parenthèse fermante
		if size >= chMaxQuerySize {
			t.Errorf("paquet %d : requête de %d octets, au-dessus de max_query_size (%d)", i, size, chMaxQuerySize)
		}
		total += len(c)
	}

	// Le découpage ne doit rien perdre : chaque id doit apparaître exactement une
	// fois, sinon des pages sortiraient des chaînes de redirection sans erreur.
	if total != len(ids) {
		t.Errorf("les paquets couvrent %d ids, attendu %d", total, len(ids))
	}
}

// TestChunkEmptyYieldsNoQuery garantit qu'un crawl sans redirection ne déclenche
// aucune requête : la boucle par paquets a remplacé un `if len(allIDs) > 0`, donc
// chunk(nil) DOIT rendre zéro paquet.
func TestChunkEmptyYieldsNoQuery(t *testing.T) {
	if got := chunk(nil, 5000); len(got) != 0 {
		t.Errorf("chunk(nil) = %d paquet(s), attendu 0", len(got))
	}
	if got := chunk([]string{}, 5000); len(got) != 0 {
		t.Errorf("chunk([]) = %d paquet(s), attendu 0", len(got))
	}
}
