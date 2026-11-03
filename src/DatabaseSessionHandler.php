<?php
declare(strict_types=1);
/** Lab session store shared by all web nodes, with a MySQL per-session lock. */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?string $lock = null;
    public function __construct(private PDO $db, private int $ttl = 1440) {}
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool {
        if ($this->lock !== null) {
            $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->lock]);
            $this->lock = null;
        }
        return true;
    }
    public function read(string $id): string|false {
        $lock = 'team-sid-'.substr(hash('sha256', $id), 0, 48);
        $s = $this->db->prepare('SELECT GET_LOCK(?, 5)'); $s->execute([$lock]);
        if ((int)$s->fetchColumn() !== 1) throw new RuntimeException('Session lock timeout');
        $this->lock = $lock;
        $s = $this->db->prepare('SELECT data FROM app_sessions WHERE id=? AND expires_at>?');
        $s->execute([$id,time()]);
        $data = $s->fetchColumn();
        return $data === false ? '' : (string)$data;
    }
    public function write(string $id, string $data): bool {
        $expiry = time()+$this->ttl;
        return $this->db->prepare('INSERT INTO app_sessions(id,data,expires_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data=?,expires_at=?')
            ->execute([$id,$data,$expiry,$data,$expiry]);
    }
    public function destroy(string $id): bool {
        return $this->db->prepare('DELETE FROM app_sessions WHERE id=?')->execute([$id]);
    }
    public function gc(int $max_lifetime): int|false {
        $s = $this->db->prepare('DELETE FROM app_sessions WHERE expires_at<=?'); $s->execute([time()]);
        return $s->rowCount();
    }
    public function validateId(string $id): bool {
        $s = $this->db->prepare('SELECT id FROM app_sessions WHERE id=? AND expires_at>?'); $s->execute([$id,time()]);
        return $s->fetchColumn() !== false;
    }
    public function updateTimestamp(string $id, string $data): bool {
        return $this->db->prepare('UPDATE app_sessions SET expires_at=? WHERE id=?')->execute([time()+$this->ttl,$id]);
    }
}
