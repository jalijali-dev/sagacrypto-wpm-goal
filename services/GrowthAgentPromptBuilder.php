<?php
declare(strict_types=1);

/**
 * GrowthAgentPromptBuilder — Fase 3 "context-aware generate".
 *
 * Claude has no fine-tuning API, so "the agent learns the pattern I want"
 * is implemented as retrieval + prompt assembly instead of retraining:
 * every call pulls the currently-active style rules
 * (growth_agent_style_rules) and a handful of past jobs a human explicitly
 * approved without edits (growth_agent_jobs + growth_agent_feedback), and
 * folds them into the system prompt as a style guide + few-shot examples.
 *
 * Both tables start empty, so buildContext() returns '' until an admin
 * adds a style rule or approves a job on pages/growth-agent.php — zero
 * behavior change until then, same fallback philosophy as
 * cms_ai_resolve_agent()'s PromptLoader layering in ai-helpers.php.
 */
class GrowthAgentPromptBuilder
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Build the style-guide + few-shot block for one agent. Never throws —
     * callers should still wrap this in try/catch, since a schema-ensure
     * failure (e.g. no CREATE TABLE privilege) must never block generation.
     */
    public function buildContext(string $agentKey, string $jobType, int $maxRules = 5, int $maxExamples = 3): string
    {
        $parts = [];

        $rules = $this->activeStyleRules($maxRules);
        if ($rules !== []) {
            $parts[] = "House style guide — follow these rules:\n- " . implode("\n- ", $rules);
        }

        $examples = $this->approvedExamples($agentKey, $jobType, $maxExamples);
        if ($examples !== []) {
            $exampleBlocks = [];
            foreach ($examples as $i => $example) {
                $exampleBlocks[] = sprintf(
                    "Example %d — input:\n%s\nApproved output (a human editor accepted this as-is — match this style and quality):\n%s",
                    $i + 1,
                    $example['input_brief'],
                    $example['output_json']
                );
            }
            $parts[] = "Reference examples from past approved work:\n\n" . implode("\n\n", $exampleBlocks);
        }

        return implode("\n\n", $parts);
    }

    /** @return string[] */
    private function activeStyleRules(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rule_text FROM growth_agent_style_rules
              WHERE is_active = 1
              ORDER BY created_at DESC
              LIMIT ' . max(0, $limit)
        );
        $stmt->execute();

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
    }

    /**
     * Most recent jobs for this agent + job_type that a human approved
     * without edits. Filtering by job_type too (not just agent_key)
     * matters because seo_meta/article_draft/faq jobs share the same
     * underlying seo_agent but produce completely different output
     * shapes — mixing them would hand the model a few-shot example with
     * the wrong schema. "Best" here is recency among approved-as-is
     * jobs, not a quality score — the architecture doc flags moving to
     * embedding-based retrieval as a later refinement once the corpus is
     * large.
     *
     * @return array<int, array{input_brief: string, output_json: string}>
     */
    private function approvedExamples(string $agentKey, string $jobType, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.input_brief, j.output_json
               FROM growth_agent_jobs j
               INNER JOIN growth_agent_feedback f ON f.job_id = j.id
              WHERE j.agent_key = :agent_key
                AND j.job_type = :job_type
                AND j.status = :status
                AND f.action = :action
                AND j.output_json IS NOT NULL
              ORDER BY f.created_at DESC
              LIMIT ' . max(0, $limit)
        );
        $stmt->execute([
            'agent_key' => $agentKey,
            'job_type'  => $jobType,
            'status'    => 'succeeded',
            'action'    => 'approved_as_is',
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static fn (array $row): array => [
            'input_brief' => (string) $row['input_brief'],
            'output_json' => (string) $row['output_json'],
        ], $rows);
    }
}
