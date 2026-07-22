<?php
declare(strict_types=1);

/**
 * PromptLoader — reads AI agent prompt layers from the `agent_prompts` table
 * (managed via cms-admin/pages/prompt-control*.php) and merges them into a
 * single system prompt for a given agent.
 *
 * Merge order (missing layers are skipped):
 *   global/base -> global/guardrail -> {agent}/guardrail ->
 *   {agent}/instruction -> {agent}/output_schema -> runtime context
 *
 * If no active `instruction` row exists in the DB for an agent, callers
 * should fall back to their own PHP-defined default prompt — this class
 * only ever returns what's stored in the database, plus '' when nothing
 * is configured yet, so production behavior is never broken by an empty
 * Prompt Control setup.
 */
class PromptLoader
{
    /**
     * Agent keys selectable in Prompt Control. `global` holds shared layers
     * (base identity, safety guardrails) applied to every agent.
     */
    public const ALLOWED_AGENT_KEYS = [
        'global',
        'seo_agent',
        'account_recovery',
        'prompt_control',
    ];

    /**
     * Prompt layer types, applied in this order when merging.
     */
    public const ALLOWED_PROMPT_TYPES = [
        'base',
        'guardrail',
        'instruction',
        'output_schema',
    ];

    /** @var array<string, string|null> In-process cache of active prompt content, keyed "agent_key/prompt_type". */
    private static array $cache = [];

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Clear the in-process prompt cache. Call this right after activating,
     * archiving, or otherwise changing which row is "active" so the same
     * request (and any immediately following ones sharing this process)
     * see the update.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Fetch the active prompt content for one (agent_key, prompt_type) pair.
     * Returns null when no active row exists — callers should fall back to
     * a PHP-defined default in that case.
     */
    public function getPrompt(string $agentKey, string $promptType = 'instruction'): ?string
    {
        $cacheKey = $agentKey . '/' . $promptType;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        if ($this->pdo === null) {
            self::$cache[$cacheKey] = null;
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT content FROM agent_prompts
              WHERE agent_key = :agent_key AND prompt_type = :prompt_type AND status = :status
              ORDER BY version DESC
              LIMIT 1'
        );
        $stmt->execute([
            'agent_key'   => $agentKey,
            'prompt_type' => $promptType,
            'status'      => 'active',
        ]);
        $content = $stmt->fetchColumn();

        $result = $content === false ? null : (string) $content;
        self::$cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Build the full merged system prompt for an agent: global base,
     * global guardrail, agent guardrail, agent instruction, agent
     * output_schema — skipping any layer that has no active row.
     */
    public function buildMergedPrompt(string $agentKey): string
    {
        $layers = [
            $this->getPrompt('global', 'base'),
            $this->getPrompt('global', 'guardrail'),
            $this->getPrompt($agentKey, 'guardrail'),
            $this->getPrompt($agentKey, 'instruction'),
            $this->getPrompt($agentKey, 'output_schema'),
        ];

        $layers = array_filter($layers, static fn ($layer): bool => $layer !== null && trim($layer) !== '');

        return implode("\n\n", $layers);
    }

    /**
     * Return every prompt row (all agents/types/statuses) — mainly useful
     * for admin listings or diagnostics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPrompts(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $stmt = $this->pdo->query(
            'SELECT id, agent_key, prompt_type, title, version, status, created_by, created_at, updated_at
               FROM agent_prompts
              ORDER BY agent_key ASC, prompt_type ASC, version DESC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Save a new draft prompt version for an agent/type pair. Returns the
     * new row's id, or null on failure (e.g. invalid agent_key/prompt_type).
     */
    public function savePrompt(
        string $agentKey,
        string $promptType,
        string $title,
        string $content,
        string $notes = '',
        string $createdBy = 'system'
    ): ?int {
        if ($this->pdo === null) {
            return null;
        }
        if (!in_array($agentKey, self::ALLOWED_AGENT_KEYS, true)) {
            return null;
        }
        if (!in_array($promptType, self::ALLOWED_PROMPT_TYPES, true)) {
            return null;
        }

        $vStmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(version), 0) + 1 AS next_ver
               FROM agent_prompts
              WHERE agent_key = :agent_key AND prompt_type = :prompt_type'
        );
        $vStmt->execute(['agent_key' => $agentKey, 'prompt_type' => $promptType]);
        $nextVer = (int) $vStmt->fetchColumn();

        $ins = $this->pdo->prepare(
            'INSERT INTO agent_prompts
               (agent_key, prompt_type, title, content, version, status, notes, created_by)
             VALUES
               (:agent_key, :prompt_type, :title, :content, :version, :status, :notes, :created_by)'
        );
        $ins->execute([
            'agent_key'   => $agentKey,
            'prompt_type' => $promptType,
            'title'       => $title,
            'content'     => $content,
            'version'     => $nextVer,
            'status'      => 'draft',
            'notes'       => $notes,
            'created_by'  => $createdBy,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
