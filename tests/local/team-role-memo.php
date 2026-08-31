<?php

/**
 * Prueft die Memoisierung in TeamRole::isOwnerOrAdmin().
 *
 * Hintergrund: Der Gate-Callback `asset-manager.manage` laeuft ueber diese Methode, und Laravels
 * Gate cacht Callback-Ergebnisse NICHT. Ein Aufruf kostet zwei Queries — die Pivot-Abfrage und,
 * verdeckt, `$user->currentTeam` (im Core ein Accessor ohne Attribute-Cache, der intern
 * `Module::where('key', …)->first()` ausfuehrt). Auf der gruppierten Kostenpositionen-Liste
 * (17 Gruppen + 50 Detailzeilen, je zwei @can-Bloecke) waren das ~280 Queries pro Render.
 *
 * Getestet wird ohne DB: ein Stub-User zaehlt, wie oft die Rollen-Abfrage wirklich ausgefuehrt wird.
 */

require __DIR__ . '/bootstrap.php';

use Platform\AssetManager\Support\TeamRole;

/** Zaehlt Zugriffe auf teams() und auf den currentTeam-Accessor. */
function am_stub_user(?string $role, int $userId = 1, int $teamId = 7): object
{
    return new class($role, $userId, $teamId) implements \Illuminate\Contracts\Auth\Authenticatable {
        public int $teamsCalls       = 0;
        public int $currentTeamCalls = 0;
        public ?int $current_team_id;

        public function __construct(private ?string $role, private int $userId, private int $teamId)
        {
            $this->current_team_id = $teamId;
        }

        /** Fluent-Stub fuer $user->teams()->where(...)->first()?->pivot?->role */
        public function teams(): object
        {
            $this->teamsCalls++;
            $role = $this->role;

            return new class($role) {
                public function __construct(private ?string $role) {}
                public function where($column, $value = null): static { return $this; }
                public function first(): ?object
                {
                    if ($this->role === null) {
                        return null;
                    }
                    return new class($this->role) {
                        public object $pivot;
                        public function __construct(string $role)
                        {
                            $this->pivot = new class($role) {
                                public function __construct(public string $role) {}
                            };
                        }
                    };
                }
            };
        }

        /** Steht fuer den teuren Core-Accessor. */
        public function __get(string $name): mixed
        {
            if ($name === 'currentTeam') {
                $this->currentTeamCalls++;
                return new class($this->teamId) {
                    public function __construct(public int $id) {}
                };
            }
            return null;
        }

        public function getAuthIdentifierName(): string { return 'id'; }
        public function getAuthIdentifier(): int { return $this->userId; }
        public function getAuthPassword(): string { return ''; }
        public function getAuthPasswordName(): string { return 'password'; }
        public function getRememberToken(): string { return ''; }
        public function setRememberToken($value): void {}
        public function getRememberTokenName(): string { return 'remember_token'; }
    };
}

// --- 1) Owner: 140 Aufrufe (17 Gruppen + 50 Zeilen, je zwei) duerfen EINMAL aufloesen ----------
TeamRole::flushResolved();
$owner = am_stub_user('owner');

for ($i = 0; $i < 140; $i++) {
    $result = TeamRole::isOwnerOrAdmin($owner);
}

check('Owner: Ergebnis korrekt',                    true, $result);
check('Owner: Rollen-Abfrage nur 1x bei 140 @can',  1,    $owner->teamsCalls);
check('Owner: currentTeam-Accessor nur 1x',         1,    $owner->currentTeamCalls);

// --- 2) Member wird weiter verweigert (Memoisierung darf nichts aufweichen) --------------------
TeamRole::flushResolved();
$member = am_stub_user('member', userId: 2);

$denied = TeamRole::isOwnerOrAdmin($member);
TeamRole::isOwnerOrAdmin($member);

check('Member: verweigert',                         false, $denied);
check('Member: Rollen-Abfrage nur 1x',              1,     $member->teamsCalls);

// --- 3) Admin erlaubt, Gast verweigert --------------------------------------------------------
TeamRole::flushResolved();
check('Admin: erlaubt',                             true,  TeamRole::isOwnerOrAdmin(am_stub_user('admin', userId: 3)));
check('Ohne Rolle im Team: verweigert',             false, TeamRole::isOwnerOrAdmin(am_stub_user(null, userId: 4)));
check('Gast (null): verweigert',                    false, TeamRole::isOwnerOrAdmin(null));

// --- 4) Zwei verschiedene User teilen den Cache NICHT -----------------------------------------
TeamRole::flushResolved();
$a = am_stub_user('owner',  userId: 10);
$b = am_stub_user('member', userId: 11);

check('User A (owner) erlaubt',                     true,  TeamRole::isOwnerOrAdmin($a));
check('User B (member) trotz warmem Cache verweigert', false, TeamRole::isOwnerOrAdmin($b));

// --- 5) Teamwechsel im selben Request loest neu auf -------------------------------------------
TeamRole::flushResolved();
$switcher = am_stub_user('owner', userId: 20, teamId: 7);
TeamRole::isOwnerOrAdmin($switcher);
$switcher->current_team_id = 8;                  // anderes Team -> anderer Schluessel
TeamRole::isOwnerOrAdmin($switcher);

check('Teamwechsel: erneute Abfrage (2x)',          2,     $switcher->teamsCalls);

// --- 6) flushResolved wirkt --------------------------------------------------------------------
TeamRole::flushResolved();
$fresh = am_stub_user('owner', userId: 30);
TeamRole::isOwnerOrAdmin($fresh);
TeamRole::flushResolved();
TeamRole::isOwnerOrAdmin($fresh);

check('flushResolved: loest danach neu auf',        2,     $fresh->teamsCalls);

check_summary();
