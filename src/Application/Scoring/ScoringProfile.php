<?php
declare(strict_types=1);

namespace JobSearch\Application\Scoring;

/**
 * Локальный профиль exclusions / prompt_version из config/scoring/profile.json.
 */
final class ScoringProfile
{
    /**
     * @param list<string> $fitHardExclusions
     * @param array<string, bool> $rolePreferences
     */
    public function __construct(
        public readonly string $promptVersion,
        public readonly array $fitHardExclusions,
        public readonly array $rolePreferences,
        public readonly string $policyText,
    ) {}

    public static function load(string $projectRoot): self
    {
        $root = rtrim($projectRoot, '/');
        $profilePath = $root . '/config/scoring/profile.json';
        $policyPath = $root . '/docs/scoring-policy.md';
        $raw = is_readable($profilePath) ? file_get_contents($profilePath) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = [];
        }
        $prefs = is_array($data['role_preferences'] ?? null) ? $data['role_preferences'] : [];
        $exclusions = [];
        foreach ($data['fit_hard_exclusions'] ?? [] as $item) {
            if (is_string($item) && trim($item) !== '') {
                $exclusions[] = trim($item);
            }
        }
        $rolePrefs = [];
        foreach ($prefs as $k => $v) {
            if (is_string($k)) {
                $rolePrefs[$k] = (bool) $v;
            }
        }
        $policy = is_readable($policyPath) ? (string) file_get_contents($policyPath) : '';

        return new self(
            promptVersion: trim((string) ($data['prompt_version'] ?? 'job-search-2-devai-v1')),
            fitHardExclusions: $exclusions,
            rolePreferences: $rolePrefs,
            policyText: $policy,
        );
    }

    public function hardSkipSalesManagersLeadership(): bool
    {
        return (bool) ($this->rolePreferences['hard_skip_sales_managers_leadership'] ?? true);
    }

    public function hardSkipLeadgenPartnershipsPrimary(): bool
    {
        return (bool) ($this->rolePreferences['hard_skip_leadgen_partnerships_primary'] ?? true);
    }
}
