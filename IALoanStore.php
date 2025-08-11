<?php
// LoanStore.php — track loans locally
class LoanStore {
    private string $path;
    private \PDO $pdo;

    public function __construct(string $path) {
        $this->path = $path;
        $this->pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->init();
    }

    private function init(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS loans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                identifier TEXT NOT NULL,
                title TEXT,
                creator TEXT,
                started_at TEXT NOT NULL,
                returned_at TEXT,
                UNIQUE(identifier, started_at)
            );
            CREATE INDEX IF NOT EXISTS ix_loans_open ON loans(identifier) WHERE returned_at IS NULL;
        ");
    }

    public function addBorrow(string $identifier, string $title = '', string $creator = ''): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO loans(identifier, title, creator, started_at) VALUES(?,?,?,datetime('now'))
        ");
        $stmt->execute([$identifier, $title, $creator]);
    }

    public function markReturned(string $identifier): void {
        $stmt = $this->pdo->prepare("
            UPDATE loans SET returned_at = datetime('now')
            WHERE identifier = ? AND returned_at IS NULL
        ");
        $stmt->execute([$identifier]);
    }

    /** @return array<int,array{identifier:string,title:string,creator:string,started_at:string,returned_at:?string}> */
    public function listOpen(): array {
        $stmt = $this->pdo->query("
            SELECT identifier, title, creator, started_at, returned_at
            FROM loans
            WHERE returned_at IS NULL
            ORDER BY started_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Use to seed title/creator after a borrow if you didn’t have them */
    public function upsertMeta(string $identifier, string $title, string $creator): void {
        $stmt = $this->pdo->prepare("
            UPDATE loans SET title = COALESCE(NULLIF(?,''), title),
                             creator = COALESCE(NULLIF(?,''), creator)
            WHERE identifier = ? AND returned_at IS NULL
        ");
        $stmt->execute([$title, $creator, $identifier]);
    }
}

