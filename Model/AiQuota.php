<?php

namespace Kanboard\Plugin\AiConnector\Model;

/**
 * The suite's shared AI spend guard. Every plugin that sends a request to a provider calls
 * consume() first, with the signed-in user's id, a feature name and the text it will send:
 *
 *   $refusal = (new AiQuota($this->container))->consume($userId, 'kensho.clarify', $text);
 *   if ($refusal !== null) { show $refusal to the user; do not call the provider }
 *
 * consume() refuses input longer than the input cap, and requests past the per-user hourly
 * quota (one quota shared by every feature, over a sliding hour). A request it lets through
 * is counted at once, so a provider error still costs one request. The check and the count
 * run in their own transaction, which first updates the user's row in aiconnector_quota_users:
 * a row lock on MySQL/MariaDB and Postgres, the write lock on SQLite. A user's parallel
 * requests therefore queue there, and each counts what the ones before it committed. Inside
 * a caller's transaction consume() throws instead, since a rollback would erase the count.
 * An unknown user id is refused, and so is a request whose count could not be stored.
 *
 * Both limits are admin settings in `settings`; a stored value that is missing, not a whole
 * number of 1 or more, or above its ceiling reads as the default, so neither limit can be
 * switched off.
 *
 * Consumers feature-detect this class (class_exists) and keep AI off when it is missing.
 */
final class AiQuota
{
    public const TABLE = 'aiconnector_requests';
    public const LOCK_TABLE = 'aiconnector_quota_users';
    public const REQUESTS_PER_HOUR = 'aiconnector_requests_per_hour';
    public const MAX_INPUT_CHARS = 'aiconnector_max_input_chars';
    public const WINDOW_SECONDS = 3600;

    /** Every limit with its label (translated where shown), in the settings form's order. */
    public const FIELDS = [
        self::REQUESTS_PER_HOUR => 'AI requests per person per hour',
        self::MAX_INPUT_CHARS => 'Longest AI request (characters)',
    ];

    private const DEFAULTS = [
        self::REQUESTS_PER_HOUR => 30,
        self::MAX_INPUT_CHARS => 32000,
    ];

    /** The highest value each limit accepts, so neither can be set high enough to mean "off". */
    public const CEILINGS = [
        self::REQUESTS_PER_HOUR => 10000,
        self::MAX_INPUT_CHARS => 1000000,
    ];

    /** @var callable(): int */
    private $clock;

    public function __construct(private $container, ?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
    }

    /**
     * Checks one AI request and, when it may go ahead, counts it.
     *
     * @param string|list<string> $input the text that will be sent (parts are summed)
     * @return string|null null when the request may go ahead; otherwise the refusal to show
     */
    public function consume(int $userId, string $feature, string|array $input): ?string
    {
        if ($userId <= 0) {
            return t('Sign in to use AI features.');
        }

        $length = 0;
        foreach ((array) $input as $part) {
            $length += mb_strlen((string) $part, 'UTF-8');
        }
        $cap = $this->maxInputChars();
        if ($length > $cap) {
            $this->log($userId, $feature, 'input too long');
            return t('This AI request is too long: %d characters, and the limit is %d. Shorten it and try again.', $length, $cap);
        }

        $db = $this->container['db'];
        if ($db->getConnection()->inTransaction()) {
            // A caller's rollback would erase the count after the provider was paid.
            throw new \LogicException('AiQuota::consume() must not run inside a database transaction.');
        }

        $now = ($this->clock)();
        // Pruning stays outside the transaction: a range delete there would lock other users' rows.
        $db->table(self::TABLE)->lte('created_at', $now - self::WINDOW_SECONDS)->remove();
        if (! $db->table(self::LOCK_TABLE)->eq('user_id', $userId)->exists()) {
            // Two first requests can race here; the loser's insert fails as a duplicate, which is fine.
            $db->table(self::LOCK_TABLE)->insert(['user_id' => $userId, 'locked_at' => $now]);
            if (! $db->table(self::LOCK_TABLE)->eq('user_id', $userId)->exists()) {
                // No such user (the foreign key refused the row): nothing to count against, so refuse.
                $this->log($userId, $feature, 'unknown user');
                return t('Sign in to use AI features.');
            }
        }

        $db->startTransaction();
        try {
            $db->table(self::LOCK_TABLE)->eq('user_id', $userId)->update(['locked_at' => $now]);

            $perHour = $this->requestsPerHour();
            if ($this->usedThisHour($userId) >= $perHour) {
                $oldest = (int) $db->table(self::TABLE)->eq('user_id', $userId)->gt('created_at', $now - self::WINDOW_SECONDS)
                    ->asc('created_at')->findOneColumn('created_at');
                $db->cancelTransaction();
                $minutes = max(1, (int) ceil(($oldest + self::WINDOW_SECONDS - $now) / 60));
                $this->log($userId, $feature, 'hourly quota reached');
                return t('You have reached the AI limit of %d requests per hour. Try again in %d minutes.', $perHour, $minutes);
            }

            $counted = $db->table(self::TABLE)->insert([
                'user_id' => $userId,
                'feature' => mb_substr($feature, 0, 64, 'UTF-8'),
                'created_at' => $now,
            ]);
            if (! $counted) {
                // Never let a request through that was not counted.
                $db->cancelTransaction();
                $this->log($userId, $feature, 'count not stored');
                return t('AI is unavailable right now. Try again later.');
            }
            $db->closeTransaction();
        } catch (\Throwable $e) {
            $db->cancelTransaction();
            throw $e;
        }
        return null;
    }

    /** Requests $userId made in the last hour. */
    public function usedThisHour(int $userId): int
    {
        return $this->container['db']->table(self::TABLE)
            ->eq('user_id', $userId)
            ->gt('created_at', ($this->clock)() - self::WINDOW_SECONDS)
            ->count();
    }

    public function requestsPerHour(): int
    {
        return $this->int(self::REQUESTS_PER_HOUR);
    }

    public function maxInputChars(): int
    {
        return $this->int(self::MAX_INPUT_CHARS);
    }

    /** @return array<string, int> each limit's value now, by key (as FIELDS) */
    public function values(): array
    {
        return [
            self::REQUESTS_PER_HOUR => $this->requestsPerHour(),
            self::MAX_INPUT_CHARS => $this->maxInputChars(),
        ];
    }

    /**
     * Saves every limit in $posted (key => value) when each is a whole number of 1 or more;
     * otherwise saves nothing and returns the error per key. A missing key is an error too.
     *
     * @return array<string, string> errors by key, empty when saved
     */
    public function save(array $posted): array
    {
        $errors = [];
        $values = [];
        foreach (self::FIELDS as $key => $label) {
            $value = $posted[$key] ?? null;
            $value = is_string($value) ? trim($value) : '';
            if (! ctype_digit($value) || (int) $value < 1 || strlen(ltrim($value, '0')) > 9 || (int) $value > self::CEILINGS[$key]) {
                $errors[$key] = t('%s must be a whole number from 1 to %d.', t($label), self::CEILINGS[$key]);
                continue;
            }
            $values[$key] = (string) (int) $value;
        }
        if ($errors === []) {
            $this->container['configModel']->save($values);
        }
        return $errors;
    }

    private function int(string $key): int
    {
        $value = trim((string) $this->container['configModel']->get($key, ''));
        return ctype_digit($value) && (int) $value > 0 && strlen(ltrim($value, '0')) <= 9 && (int) $value <= self::CEILINGS[$key]
            ? (int) $value : self::DEFAULTS[$key];
    }

    private function log(int $userId, string $feature, string $reason): void
    {
        $this->container['logger']->info(sprintf('[AiConnector] AI request refused (%s): user %d, feature %s',
            $reason, $userId, preg_replace('/[^a-z0-9._-]/i', '', $feature)));
    }
}
